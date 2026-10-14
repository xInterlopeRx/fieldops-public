<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingCanary as GraphOnboardingCanary;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RunMicrosoftGraphOnboardingCanary
{
    private const IN_FLIGHT_RECONCILIATION_GRACE_MINUTES = 5;

    public function __construct(private readonly MicrosoftGraphApiClient $graph) {}

    public function createCalendarAndCleanup(User $actor): GraphOnboardingCanary
    {
        return $this->createAndCleanup($actor, GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT);
    }

    public function createContactAndCleanup(User $actor): GraphOnboardingCanary
    {
        return $this->createAndCleanup($actor, GraphOnboardingCanary::RESOURCE_CONTACT);
    }

    private function createAndCleanup(User $actor, string $resourceType): GraphOnboardingCanary
    {
        $this->assertOnboardingWritesEnabled();
        $target = $this->verifiedTargetFor($actor, $resourceType);
        $canary = DB::transaction(function () use ($actor, $resourceType, $target): GraphOnboardingCanary {
            $currentTarget = MicrosoftGraphOnboardingTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertCanaryReady($currentTarget, $resourceType);

            $unresolved = GraphOnboardingCanary::query()
                ->where('target_id', $currentTarget->getKey())
                ->whereIn('status', GraphOnboardingCanary::ACTIVE_STATUSES)
                ->exists();

            if ($unresolved) {
                throw ValidationException::withMessages([
                    'confirm_canary_action' => 'Reconcile the existing canary before creating another.',
                ]);
            }

            $canaryId = (string) Str::uuid();
            $transactionId = (string) Str::uuid();
            $subject = $resourceType === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
                ? 'FieldOps setup canary '.$canaryId
                : 'FieldOps Setup Canary '.substr($canaryId, 0, 8);
            $canary = GraphOnboardingCanary::query()->create([
                'id' => $canaryId,
                'target_id' => $currentTarget->getKey(),
                'actor_user_id' => $actor->getKey(),
                'environment' => app()->environment(),
                'resource_type' => $resourceType,
                'status' => GraphOnboardingCanary::STATUS_CREATING,
                'transaction_id' => $transactionId,
                'tenant_id' => $currentTarget->tenant_id,
                'client_id' => $currentTarget->client_id,
                'mailbox_id' => $currentTarget->notification_mailbox_id,
                'calendar_id' => $resourceType === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
                    ? $currentTarget->notification_calendar_id
                    : null,
                'subject' => $subject,
                'attempted_at' => now(),
            ]);

            $this->audit($actor, $canary, $this->eventName($resourceType, 'create_started'));

            return $canary;
        });

        $target = MicrosoftGraphOnboardingTarget::query()->findOrFail($canary->target_id);

        if (! $this->matchesCanaryTarget($target, $canary)
            || ! in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            || ! $this->canaryBoundaryIsVerified($target, $resourceType)) {
            $canary->forceFill([
                'status' => GraphOnboardingCanary::STATUS_FAILED,
                'failure_code' => $this->resourceFailureCode($resourceType, 'targets_changed_before_create'),
            ])->save();
            $this->audit($actor, $canary, $this->eventName($resourceType, 'create_aborted'));

            throw new MicrosoftGraphApiException($canary->failure_code, 409);
        }

        try {
            $created = $this->graph->postJsonForOnboardingTarget(
                $target,
                $this->resourceCollectionPath($canary),
                $this->resourcePayload($canary),
            );
        } catch (MicrosoftGraphApiException $exception) {
            $isUnknown = in_array($exception->reason, [
                'graph_unavailable',
                'graph_request_failed',
                'graph_invalid_response',
            ], true);
            $failureCode = $isUnknown
                ? $this->resourceFailureCode($resourceType, 'create_unknown')
                : $this->safeFailureCode($exception->reason);
            $canary->forceFill([
                'status' => $isUnknown
                    ? GraphOnboardingCanary::STATUS_CREATE_UNKNOWN
                    : GraphOnboardingCanary::STATUS_FAILED,
                'failure_code' => $failureCode,
            ])->save();
            $this->audit($actor, $canary, $this->eventName($resourceType, 'create_failed'));

            throw new MicrosoftGraphApiException($failureCode, $exception->httpStatus, $exception->retryAfterSeconds);
        }

        $remoteId = $created['id'] ?? null;

        if (! is_string($remoteId) || trim($remoteId) === '') {
            $canary->forceFill([
                'status' => GraphOnboardingCanary::STATUS_CREATE_UNKNOWN,
                'failure_code' => $this->resourceFailureCode($resourceType, 'create_response_invalid'),
            ])->save();
            $this->audit($actor, $canary, $this->eventName($resourceType, 'create_unknown'));

            throw new MicrosoftGraphApiException($canary->failure_code, 502);
        }

        $canary->forceFill([
            'status' => GraphOnboardingCanary::STATUS_CREATED,
            'remote_resource_id' => $remoteId,
            'remote_etag' => is_string($created['@odata.etag'] ?? null) ? $created['@odata.etag'] : null,
            'created_remotely_at' => now(),
            'failure_code' => null,
        ])->save();
        $this->audit($actor, $canary, $this->eventName($resourceType, 'created'));

        return $this->cleanup($canary, $actor, $target);
    }

    public function retryCleanup(User $actor, GraphOnboardingCanary $canary): GraphOnboardingCanary
    {
        $this->assertFirstAdmin($actor);
        $this->assertOnboardingWritesEnabled();

        if ($canary->environment !== app()->environment()
            || ! in_array($canary->resource_type, [GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT, GraphOnboardingCanary::RESOURCE_CONTACT], true)) {
            abort(404);
        }

        if (! $this->matchesCanaryTarget($canary->target, $canary)) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'The selected tenant, app, or target changed after this canary. Reconcile it manually; FieldOps will not delete an object through a different target configuration.',
            ]);
        }

        return $this->cleanup($canary, $actor);
    }

    public function manuallyReconcile(User $actor, GraphOnboardingCanary $canary): GraphOnboardingCanary
    {
        $this->assertFirstAdmin($actor);

        if ($canary->environment !== app()->environment()
            || ! in_array($canary->resource_type, [GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT, GraphOnboardingCanary::RESOURCE_CONTACT], true)) {
            abort(404);
        }

        if (! in_array($canary->status, GraphOnboardingCanary::ACTIVE_STATUSES, true)
            || ($this->isRecentInFlight($canary)
                && in_array($canary->status, [
                    GraphOnboardingCanary::STATUS_CREATING,
                    GraphOnboardingCanary::STATUS_CLEANUP_PENDING,
                ], true))) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => 'Only a settled, unresolved canary can be manually reconciled.',
            ]);
        }

        $canary->forceFill([
            'status' => GraphOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            'reconciled_at' => now(),
            'reconciled_by_user_id' => $actor->getKey(),
        ])->save();
        $this->audit($actor, $canary, $this->eventName($canary->resource_type, 'manually_reconciled'));

        return $canary->fresh();
    }

    private function cleanup(
        GraphOnboardingCanary $canary,
        User $actor,
        ?MicrosoftGraphOnboardingTarget $target = null,
    ): GraphOnboardingCanary {
        if (! is_string($canary->remote_resource_id) || $canary->remote_resource_id === '') {
            return $this->recordCleanupFailure($canary, $actor, GraphOnboardingCanary::STATUS_CLEANUP_CONFLICT, $this->resourceFailureCode($canary->resource_type, 'remote_id_missing'));
        }

        if (! is_string($canary->remote_etag) || $canary->remote_etag === '') {
            return $this->recordCleanupFailure($canary, $actor, GraphOnboardingCanary::STATUS_CLEANUP_CONFLICT, $this->resourceFailureCode($canary->resource_type, 'etag_missing'));
        }

        $canary = DB::transaction(function () use ($actor, $canary): GraphOnboardingCanary {
            $currentCanary = GraphOnboardingCanary::query()
                ->whereKey($canary->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentCanary->status === GraphOnboardingCanary::STATUS_CLEANED) {
                return $currentCanary;
            }

            $retryable = in_array($currentCanary->status, [
                GraphOnboardingCanary::STATUS_CREATED,
                GraphOnboardingCanary::STATUS_CLEANUP_FAILED,
                GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN,
            ], true) || ($currentCanary->status === GraphOnboardingCanary::STATUS_CLEANUP_PENDING
                && ! $this->isRecentInFlight($currentCanary));

            if (! $retryable) {
                throw ValidationException::withMessages([
                    'confirm_canary_action' => 'This Calendar canary is not eligible for a conditional cleanup retry. Review its status and reconcile it manually.',
                ]);
            }

            $currentCanary->forceFill([
                'status' => GraphOnboardingCanary::STATUS_CLEANUP_PENDING,
                'failure_code' => null,
            ])->save();
            $this->audit($actor, $currentCanary, $this->eventName($currentCanary->resource_type, 'cleanup_started'));

            return $currentCanary;
        });

        if ($canary->status === GraphOnboardingCanary::STATUS_CLEANED) {
            return $canary;
        }

        try {
            $this->graph->deleteForOnboardingTarget(
                $target ?? $canary->target,
                $this->resourceItemPath($canary),
                ['If-Match' => $canary->remote_etag],
            );
        } catch (MicrosoftGraphApiException $exception) {
            $status = match (true) {
                $exception->reason === 'graph_resource_changed' => GraphOnboardingCanary::STATUS_CLEANUP_CONFLICT,
                in_array($exception->reason, ['graph_unavailable', 'graph_request_failed', 'graph_invalid_response'], true) => GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN,
                default => GraphOnboardingCanary::STATUS_CLEANUP_FAILED,
            };
            $failureCode = $status === GraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN
                ? $this->resourceFailureCode($canary->resource_type, 'cleanup_unknown')
                : $this->safeFailureCode($exception->reason);

            return $this->recordCleanupFailure($canary, $actor, $status, $failureCode);
        }

        $canary = DB::transaction(function () use ($actor, $canary): GraphOnboardingCanary {
            $currentCanary = GraphOnboardingCanary::query()
                ->whereKey($canary->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($currentCanary->status === GraphOnboardingCanary::STATUS_MANUALLY_RECONCILED) {
                return $currentCanary;
            }

            $currentCanary->forceFill([
                'status' => GraphOnboardingCanary::STATUS_CLEANED,
                'cleaned_at' => now(),
                'failure_code' => null,
            ])->save();
            $this->audit($actor, $currentCanary, $this->eventName($currentCanary->resource_type, 'cleaned'));

            return $currentCanary;
        });

        return $canary->fresh();
    }

    private function recordCleanupFailure(
        GraphOnboardingCanary $canary,
        User $actor,
        string $status,
        string $failureCode,
    ): GraphOnboardingCanary {
        $canary = DB::transaction(function () use ($actor, $canary, $failureCode, $status): GraphOnboardingCanary {
            $currentCanary = GraphOnboardingCanary::query()
                ->whereKey($canary->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($currentCanary->status, [
                GraphOnboardingCanary::STATUS_CLEANED,
                GraphOnboardingCanary::STATUS_MANUALLY_RECONCILED,
            ], true)) {
                return $currentCanary;
            }

            $currentCanary->forceFill([
                'status' => $status,
                'failure_code' => $failureCode,
            ])->save();
            $this->audit($actor, $currentCanary, $this->eventName($currentCanary->resource_type, 'cleanup_failed'));

            return $currentCanary;
        });

        return $canary->fresh();
    }

    private function verifiedTargetFor(User $actor, string $resourceType): MicrosoftGraphOnboardingTarget
    {
        $this->assertFirstAdmin($actor);

        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->firstOrFail();

        $this->assertCanaryReady($target, $resourceType);

        return $target;
    }

    private function assertCanaryReady(MicrosoftGraphOnboardingTarget $target, string $resourceType): void
    {
        if (! $target->target_is_dedicated_shared_mailbox
            || ! $target->non_target_is_user_mailbox
            || ! in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            || $target->read_access_verified_at === null
            || ! $this->canaryBoundaryIsVerified($target, $resourceType)) {
            throw ValidationException::withMessages([
                'confirm_canary_action' => $resourceType === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
                    ? 'Complete selected-target metadata checks and verify non-target Calendar denial before creating a canary.'
                    : 'Complete selected-target metadata checks, verify a successful selected Contacts-folder collection request, and verify non-target Contacts-folder access is denied before creating a canary.',
            ]);
        }
    }

    private function canaryBoundaryIsVerified(MicrosoftGraphOnboardingTarget $target, string $resourceType): bool
    {
        if ($resourceType === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT) {
            return $target->boundary_verified_at !== null
                && $target->non_target_mailbox_calendar_status === 'denied';
        }

        return $resourceType === GraphOnboardingCanary::RESOURCE_CONTACT
            && $target->contacts_boundary_verified_at !== null
            && $target->target_contacts_status === 'accessible'
            && $target->non_target_contacts_status === 'denied';
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

    private function calendarPath(GraphOnboardingCanary $canary): string
    {
        $mailboxPath = 'users/'.rawurlencode($canary->mailbox_id);

        return is_string($canary->calendar_id) && $canary->calendar_id !== ''
            ? $mailboxPath.'/calendars/'.rawurlencode($canary->calendar_id)
            : $mailboxPath.'/calendar';
    }

    private function matchesCanaryTarget(
        MicrosoftGraphOnboardingTarget $target,
        GraphOnboardingCanary $canary,
    ): bool {
        return $target->environment === $canary->environment
            && $target->tenant_id === $canary->tenant_id
            && $target->client_id === $canary->client_id
            && $target->notification_mailbox_id === $canary->mailbox_id
            && ($canary->resource_type === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
                ? $target->notification_calendar_id === $canary->calendar_id
                : $canary->resource_type === GraphOnboardingCanary::RESOURCE_CONTACT && $canary->calendar_id === null);
    }

    private function resourceCollectionPath(GraphOnboardingCanary $canary): string
    {
        return $canary->resource_type === GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT
            ? $this->calendarPath($canary).'/events'
            : 'users/'.rawurlencode($canary->mailbox_id).'/contacts';
    }

    private function resourceItemPath(GraphOnboardingCanary $canary): string
    {
        return $this->resourceCollectionPath($canary).'/'.rawurlencode((string) $canary->remote_resource_id);
    }

    /** @return array<string, mixed> */
    private function resourcePayload(GraphOnboardingCanary $canary): array
    {
        return match ($canary->resource_type) {
            GraphOnboardingCanary::RESOURCE_CALENDAR_EVENT => $this->eventPayload($canary),
            GraphOnboardingCanary::RESOURCE_CONTACT => $this->contactPayload($canary),
            default => throw new MicrosoftGraphApiException('graph_onboarding_canary_resource_unsupported', 422),
        };
    }

    /** @return array<string, string> */
    private function contactPayload(GraphOnboardingCanary $canary): array
    {
        return [
            'givenName' => 'FieldOps Setup',
            'surname' => 'Canary '.substr($canary->getKey(), 0, 8),
            'companyName' => 'FieldOps Setup Canary',
            'personalNotes' => 'Temporary FieldOps Contacts write-permission canary. No personal or business data.',
        ];
    }

    private function eventName(string $resourceType, string $event): string
    {
        $resourceName = $resourceType === GraphOnboardingCanary::RESOURCE_CONTACT
            ? 'contact'
            : 'calendar';

        return 'microsoft_graph_'.$resourceName.'_canary_'.$event;
    }

    private function resourceFailureCode(string $resourceType, string $failure): string
    {
        $resourceName = $resourceType === GraphOnboardingCanary::RESOURCE_CONTACT
            ? 'contact'
            : 'calendar';

        return 'graph_'.$resourceName.'_canary_'.$failure;
    }

    private function isRecentInFlight(GraphOnboardingCanary $canary): bool
    {
        return $canary->updated_at !== null
            && $canary->updated_at->greaterThan(now()->subMinutes(self::IN_FLIGHT_RECONCILIATION_GRACE_MINUTES));
    }

    /** @return array<string, mixed> */
    private function eventPayload(GraphOnboardingCanary $canary): array
    {
        $start = now('UTC')->addMinutes(20)->startOfMinute();
        $end = $start->copy()->addMinutes(10);

        return [
            'subject' => $canary->subject,
            'body' => [
                'contentType' => 'text',
                'content' => 'Temporary FieldOps integration setup canary. No customer, Job, or staff data is included.',
            ],
            'start' => [
                'dateTime' => $start->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $end->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'showAs' => 'free',
            'sensitivity' => 'private',
            'isReminderOn' => false,
            'attendees' => [],
            'transactionId' => $canary->transaction_id,
        ];
    }

    private function audit(User $actor, GraphOnboardingCanary $canary, string $event): void
    {
        $resourceLabel = $canary->resource_type === GraphOnboardingCanary::RESOURCE_CONTACT
            ? 'Contacts'
            : 'Calendar';

        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $canary->target->getMorphClass(),
            'subject_id' => $canary->target_id,
            'event' => $event,
            'summary' => $actor->name.' managed a temporary FieldOps Microsoft Graph '.$resourceLabel.' canary.',
            'metadata' => [
                'environment' => $canary->environment,
                'target_id' => $canary->target_id,
                'canary_id' => $canary->getKey(),
                'resource_type' => $canary->resource_type,
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
            : 'graph_calendar_canary_failed';
    }
}
