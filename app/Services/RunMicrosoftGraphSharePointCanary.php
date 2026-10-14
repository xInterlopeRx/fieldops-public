<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingCanary as GraphCanary;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary as SharePointCanary;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RunMicrosoftGraphSharePointCanary
{
    private const IN_FLIGHT_RECONCILIATION_GRACE_MINUTES = 5;

    public function __construct(private readonly MicrosoftGraphApiClient $graph) {}

    public function createAndCleanup(User $actor): SharePointCanary
    {
        $this->assertFirstAdmin($actor);
        $this->assertOnboardingWritesEnabled();

        $canary = DB::transaction(function () use ($actor): SharePointCanary {
            $target = MicrosoftGraphOnboardingTarget::query()
                ->where('environment', app()->environment())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertTargetReady($target);

            $graphCanaryUnresolved = GraphCanary::query()
                ->where('target_id', $target->getKey())
                ->whereIn('status', GraphCanary::ACTIVE_STATUSES)
                ->exists();
            $sharePointCanaryUnresolved = SharePointCanary::query()
                ->where('target_id', $target->getKey())
                ->whereIn('status', SharePointCanary::ACTIVE_STATUSES)
                ->exists();

            if ($graphCanaryUnresolved || $sharePointCanaryUnresolved) {
                throw ValidationException::withMessages([
                    'confirm_canary_action' => 'Reconcile existing Graph canaries before creating another temporary resource.',
                ]);
            }

            $canaryId = (string) Str::uuid();
            $canary = SharePointCanary::query()->create([
                'id' => $canaryId,
                'target_id' => $target->getKey(),
                'actor_user_id' => $actor->getKey(),
                'environment' => $target->environment,
                'tenant_id' => $target->tenant_id,
                'client_id' => $target->client_id,
                'site_id' => $target->business_attachments_site_id,
                'drive_id' => $target->business_attachments_drive_id,
                'status' => SharePointCanary::STATUS_CREATING,
                'operation_id' => (string) Str::uuid(),
                'folder_name' => 'FieldOps Setup Canary '.substr($canaryId, 0, 8),
                'attempted_at' => now(),
            ]);

            $this->audit($actor, $canary, 'microsoft_graph_sharepoint_canary_create_started');

            return $canary;
        });

        $currentTarget = MicrosoftGraphOnboardingTarget::query()->findOrFail($canary->target_id);
        if (! $canary->matchesTarget($currentTarget) || $currentTarget->validation_status !== 'read_verified') {
            $canary->forceFill([
                'status' => SharePointCanary::STATUS_FAILED,
                'failure_code' => 'graph_sharepoint_canary_targets_changed_before_create',
            ])->save();
            $this->audit($actor, $canary, 'microsoft_graph_sharepoint_canary_create_aborted');

            throw new MicrosoftGraphApiException($canary->failure_code, 409);
        }

        try {
            $created = $this->graph->postJsonForOnboardingTarget(
                $currentTarget,
                'drives/'.rawurlencode($canary->drive_id).'/root/children',
                [
                    'name' => $canary->folder_name,
                    'folder' => new \stdClass,
                    '@microsoft.graph.conflictBehavior' => 'fail',
                ],
            );
        } catch (MicrosoftGraphApiException $exception) {
            $unknown = in_array($exception->reason, [
                'graph_unavailable',
                'graph_request_failed',
                'graph_invalid_response',
            ], true);
            $failureCode = $unknown
                ? 'graph_sharepoint_canary_create_unknown'
                : $this->safeFailureCode($exception->reason);
            $canary->forceFill([
                'status' => $unknown ? SharePointCanary::STATUS_CREATE_UNKNOWN : SharePointCanary::STATUS_FAILED,
                'failure_code' => $failureCode,
            ])->save();
            $this->audit($actor, $canary, $unknown
                ? 'microsoft_graph_sharepoint_canary_create_unknown'
                : 'microsoft_graph_sharepoint_canary_create_failed');

            throw new MicrosoftGraphApiException($failureCode, $exception->httpStatus, $exception->retryAfterSeconds);
        }

        $remoteId = $created['id'] ?? null;
        if (! is_string($remoteId) || trim($remoteId) === '') {
            $canary->forceFill([
                'status' => SharePointCanary::STATUS_CREATE_UNKNOWN,
                'failure_code' => 'graph_sharepoint_canary_create_response_invalid',
            ])->save();
            $this->audit($actor, $canary, 'microsoft_graph_sharepoint_canary_create_unknown');

            throw new MicrosoftGraphApiException('graph_sharepoint_canary_create_response_invalid', 502);
        }

        $canary->forceFill([
            'status' => SharePointCanary::STATUS_CREATED,
            'remote_item_id' => $remoteId,
            'remote_etag' => is_string($created['eTag'] ?? null)
                ? $created['eTag']
                : (is_string($created['@odata.etag'] ?? null) ? $created['@odata.etag'] : null),
            'created_remotely_at' => now(),
            'failure_code' => null,
        ])->save();
        $this->audit($actor, $canary, 'microsoft_graph_sharepoint_canary_created');

        return $this->cleanup($canary, $actor, $currentTarget);
    }

    public function retryCleanup(User $actor, SharePointCanary $canary): SharePointCanary
    {
        $this->assertFirstAdmin($actor);
        $this->assertOnboardingWritesEnabled();
        $this->assertCurrentEnvironment($canary);

        if (! $canary->matchesTarget($canary->target)) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'The selected tenant, app, Site, or Drive changed after this canary. Reconcile it manually; FieldOps will not delete through a different target.',
            ]);
        }

        return $this->cleanup($canary, $actor);
    }

    public function manuallyReconcile(User $actor, SharePointCanary $canary): SharePointCanary
    {
        $this->assertFirstAdmin($actor);
        $this->assertCurrentEnvironment($canary);

        if (! in_array($canary->status, SharePointCanary::ACTIVE_STATUSES, true)
            || ($this->isRecentInFlight($canary)
                && in_array($canary->status, [SharePointCanary::STATUS_CREATING, SharePointCanary::STATUS_CLEANUP_PENDING], true))) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'Only a settled, unresolved SharePoint canary can be manually reconciled.',
            ]);
        }

        $canary->forceFill([
            'status' => SharePointCanary::STATUS_MANUALLY_RECONCILED,
            'reconciled_at' => now(),
            'reconciled_by_user_id' => $actor->getKey(),
        ])->save();
        $this->audit($actor, $canary, 'microsoft_graph_sharepoint_canary_manually_reconciled');

        return $canary->fresh();
    }

    private function cleanup(
        SharePointCanary $canary,
        User $actor,
        ?MicrosoftGraphOnboardingTarget $target = null,
    ): SharePointCanary {
        if (! is_string($canary->remote_item_id) || $canary->remote_item_id === ''
            || ! is_string($canary->remote_etag) || $canary->remote_etag === '') {
            return $this->recordCleanupFailure(
                $canary,
                $actor,
                SharePointCanary::STATUS_CLEANUP_CONFLICT,
                ! is_string($canary->remote_item_id) || $canary->remote_item_id === ''
                    ? 'graph_sharepoint_canary_remote_id_missing'
                    : 'graph_sharepoint_canary_etag_missing',
            );
        }

        $canary = DB::transaction(function () use ($actor, $canary): SharePointCanary {
            $current = SharePointCanary::query()->whereKey($canary->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status === SharePointCanary::STATUS_CLEANED) {
                return $current;
            }

            $retryable = in_array($current->status, [
                SharePointCanary::STATUS_CREATED,
                SharePointCanary::STATUS_CLEANUP_FAILED,
                SharePointCanary::STATUS_CLEANUP_UNKNOWN,
            ], true) || ($current->status === SharePointCanary::STATUS_CLEANUP_PENDING && ! $this->isRecentInFlight($current));

            if (! $retryable) {
                throw ValidationException::withMessages([
                    'confirm_canary_action' => 'This SharePoint canary is not eligible for a conditional cleanup retry.',
                ]);
            }

            $current->forceFill([
                'status' => SharePointCanary::STATUS_CLEANUP_PENDING,
                'failure_code' => null,
            ])->save();
            $this->audit($actor, $current, 'microsoft_graph_sharepoint_canary_cleanup_started');

            return $current;
        });

        if ($canary->status === SharePointCanary::STATUS_CLEANED) {
            return $canary;
        }

        try {
            $this->graph->deleteForOnboardingTarget(
                $target ?? $canary->target,
                'drives/'.rawurlencode($canary->drive_id).'/items/'.rawurlencode($canary->remote_item_id),
                ['If-Match' => $canary->remote_etag],
            );
        } catch (MicrosoftGraphApiException $exception) {
            $status = match (true) {
                $exception->reason === 'graph_resource_changed' => SharePointCanary::STATUS_CLEANUP_CONFLICT,
                in_array($exception->reason, ['graph_unavailable', 'graph_request_failed', 'graph_invalid_response'], true) => SharePointCanary::STATUS_CLEANUP_UNKNOWN,
                default => SharePointCanary::STATUS_CLEANUP_FAILED,
            };
            $failureCode = $status === SharePointCanary::STATUS_CLEANUP_UNKNOWN
                ? 'graph_sharepoint_canary_cleanup_unknown'
                : $this->safeFailureCode($exception->reason);

            return $this->recordCleanupFailure($canary, $actor, $status, $failureCode);
        }

        $canary = DB::transaction(function () use ($actor, $canary): SharePointCanary {
            $current = SharePointCanary::query()->whereKey($canary->getKey())->lockForUpdate()->firstOrFail();
            if ($current->status !== SharePointCanary::STATUS_MANUALLY_RECONCILED) {
                $current->forceFill([
                    'status' => SharePointCanary::STATUS_CLEANED,
                    'cleaned_at' => now(),
                    'failure_code' => null,
                ])->save();
                $this->audit($actor, $current, 'microsoft_graph_sharepoint_canary_cleaned');
            }

            return $current;
        });

        return $canary->fresh();
    }

    private function recordCleanupFailure(SharePointCanary $canary, User $actor, string $status, string $failureCode): SharePointCanary
    {
        $canary = DB::transaction(function () use ($actor, $canary, $failureCode, $status): SharePointCanary {
            $current = SharePointCanary::query()->whereKey($canary->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, [SharePointCanary::STATUS_CLEANED, SharePointCanary::STATUS_MANUALLY_RECONCILED], true)) {
                $current->forceFill(['status' => $status, 'failure_code' => $failureCode])->save();
                $this->audit($actor, $current, 'microsoft_graph_sharepoint_canary_cleanup_failed');
            }

            return $current;
        });

        return $canary->fresh();
    }

    private function assertTargetReady(MicrosoftGraphOnboardingTarget $target): void
    {
        if (! $target->target_is_dedicated_shared_mailbox
            || ! $target->non_target_is_user_mailbox
            || $target->validation_status !== 'read_verified'
            || $target->read_access_verified_at === null
            || ! is_string($target->business_attachments_site_id)
            || $target->business_attachments_site_id === ''
            || ! is_string($target->business_attachments_drive_id)
            || $target->business_attachments_drive_id === '') {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'Configure and verify the M365-approved Business Attachments Site and Drive before creating a canary.',
            ]);
        }

        if (! $this->permissionVerificationFor($target)->exists()) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'Record the M365-admin permission-boundary verification for this exact Site and Drive before creating a canary.',
            ]);
        }
    }

    private function permissionVerificationFor(MicrosoftGraphOnboardingTarget $target): Builder
    {
        return MicrosoftGraphOnboardingPermissionVerification::query()
            ->where('target_id', $target->getKey())
            ->where('environment', $target->environment)
            ->where('tenant_id', $target->tenant_id)
            ->where('client_id', $target->client_id)
            ->where('mailbox_id', $target->notification_mailbox_id)
            ->where('non_target_mailbox_email', $target->non_target_mailbox_email)
            ->where('target_is_dedicated_shared_mailbox', true)
            ->where('non_target_is_user_mailbox', true)
            ->where('business_site_id', $target->business_attachments_site_id)
            ->where('business_drive_id', $target->business_attachments_drive_id)
            ->where('mail_send_target_verified', true)
            ->where('mail_send_non_target_denied', true)
            ->where('calendar_write_target_verified', true)
            ->where('calendar_write_non_target_denied', true)
            ->where('contacts_boundary_verified', true)
            ->where('sharepoint_boundary_verified', true)
            ->where('sharepoint_retention_reviewed', true)
            ->where('unscoped_app_roles_reviewed', true);
    }

    private function assertCurrentEnvironment(SharePointCanary $canary): void
    {
        if ($canary->environment !== app()->environment()) {
            abort(404);
        }
    }

    private function assertFirstAdmin(User $actor): void
    {
        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()
            || ! $actor->can('integrations.manage')) {
            abort(403);
        }
    }

    private function assertOnboardingWritesEnabled(): void
    {
        if (! config('services.microsoft_graph.onboarding_writes_enabled')) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'Microsoft Graph onboarding writes are disabled by deployment configuration.',
            ]);
        }
    }

    private function isRecentInFlight(SharePointCanary $canary): bool
    {
        return $canary->updated_at !== null
            && $canary->updated_at->greaterThan(now()->subMinutes(self::IN_FLIGHT_RECONCILIATION_GRACE_MINUTES));
    }

    private function audit(User $actor, SharePointCanary $canary, string $event): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $canary->target->getMorphClass(),
            'subject_id' => $canary->target_id,
            'event' => $event,
            'summary' => $actor->name.' managed a temporary FieldOps SharePoint setup folder canary.',
            'metadata' => [
                'environment' => $canary->environment,
                'target_id' => $canary->target_id,
                'canary_id' => $canary->getKey(),
                'status' => $canary->status,
                'failure_code' => $canary->failure_code,
            ],
            'created_at' => now(),
        ]);
    }

    private function safeFailureCode(string $reason): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $reason) === 1
            ? $reason
            : 'graph_sharepoint_canary_failed';
    }
}
