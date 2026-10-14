<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateMicrosoftGraphPermissionBoundary
{
    public const STATUS_NOT_CHECKED = 'not_checked';

    public const STATUS_DENIED = 'denied';

    public const STATUS_ACCESSIBLE = 'accessible';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public function __construct(private readonly MicrosoftGraphApiClient $graph) {}

    public function validate(User $actor): MicrosoftGraphOnboardingTarget
    {
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->firstOrFail();

        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()) {
            abort(403);
        }

        if (! in_array($target->validation_status, ['read_verified', 'read_verified_partial'], true)
            || $target->read_access_verified_at === null) {
            throw ValidationException::withMessages([
                'confirm_boundary_check' => 'Complete the read-only target preflight before testing the permission boundary.',
            ]);
        }

        if (! $target->target_is_dedicated_shared_mailbox || ! $target->non_target_is_user_mailbox) {
            throw ValidationException::withMessages([
                'confirm_boundary_check' => 'Confirm the selected target is a dedicated shared mailbox and the non-target control is an existing user mailbox before checking access boundaries.',
            ]);
        }

        if (! $this->hasDistinctControlTargets($target)) {
            throw ValidationException::withMessages([
                'confirm_boundary_check' => 'Save three distinct, M365-admin-approved non-target control IDs before running this check.',
            ]);
        }

        $statuses = [
            'non_target_mailbox_calendar_status' => self::STATUS_NOT_CHECKED,
            'non_target_site_status' => self::STATUS_NOT_CHECKED,
            'non_target_drive_status' => self::STATUS_NOT_CHECKED,
        ];

        $target->forceFill([
            ...$statuses,
            'boundary_validation_attempted_at' => now(),
            'boundary_verified_at' => null,
            'boundary_error_code' => null,
            'target_contacts_status' => self::STATUS_NOT_CHECKED,
            'non_target_contacts_status' => self::STATUS_NOT_CHECKED,
            'contacts_boundary_validation_attempted_at' => now(),
            'contacts_boundary_verified_at' => null,
            'contacts_boundary_error_code' => null,
        ])->save();
        $this->audit($actor, $target, 'microsoft_graph_onboarding_non_target_denial_started', $statuses, null);

        $checks = [
            'non_target_mailbox_calendar_status' => [
                'path' => 'users/'.rawurlencode($target->non_target_mailbox_email).'/calendar',
                'query' => ['$select' => 'id'],
            ],
            'non_target_site_status' => [
                'path' => 'sites/'.rawurlencode($target->non_target_site_id),
                'query' => ['$select' => 'id'],
            ],
            'non_target_drive_status' => [
                'path' => 'drives/'.rawurlencode($target->non_target_drive_id),
                'query' => ['$select' => 'id'],
            ],
        ];

        $failureCode = null;

        foreach ($checks as $field => $check) {
            try {
                $this->graph->getJsonForOnboardingTarget($target, $check['path'], $check['query']);
                $statuses[$field] = self::STATUS_ACCESSIBLE;
                $failureCode ??= 'graph_non_target_access_allowed';
            } catch (MicrosoftGraphApiException $exception) {
                if ($exception->reason === 'graph_permission_denied') {
                    $statuses[$field] = self::STATUS_DENIED;

                    continue;
                }

                $statuses[$field] = self::STATUS_INCONCLUSIVE;
                $failureCode ??= $exception->reason === 'graph_record_not_found'
                    ? 'graph_non_target_control_not_found'
                    : $this->safeFailureCode($exception->reason);

                if ($exception->reason !== 'graph_record_not_found') {
                    break;
                }
            }
        }

        $targetContactsStatus = self::STATUS_NOT_CHECKED;
        $nonTargetContactsStatus = self::STATUS_NOT_CHECKED;
        $contactsFailureCode = null;

        try {
            $this->graph->getJsonForOnboardingTarget(
                $target,
                'users/'.rawurlencode($target->notification_mailbox_id).'/contactFolders',
                ['$top' => 1, '$select' => 'id'],
            );
            // The v1.0 collection lists folders under the default Contacts folder; an empty
            // value is valid when there are no child folders. A successful GET establishes
            // selected-mailbox endpoint access, while the control mailbox must return 403.
            $targetContactsStatus = self::STATUS_ACCESSIBLE;
        } catch (MicrosoftGraphApiException $exception) {
            $targetContactsStatus = $exception->reason === 'graph_permission_denied'
                ? self::STATUS_DENIED
                : self::STATUS_INCONCLUSIVE;
            $contactsFailureCode = $exception->reason === 'graph_permission_denied'
                ? 'graph_target_contacts_access_denied'
                : ($exception->reason === 'graph_record_not_found'
                    ? 'graph_target_contacts_folder_not_found'
                    : $this->safeFailureCode($exception->reason));
        }

        try {
            $this->graph->getJsonForOnboardingTarget(
                $target,
                'users/'.rawurlencode($target->non_target_mailbox_email).'/contactFolders',
                ['$top' => 1, '$select' => 'id'],
            );
            $nonTargetContactsStatus = self::STATUS_ACCESSIBLE;
            $contactsFailureCode = 'graph_non_target_contacts_access_allowed';
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_permission_denied') {
                $nonTargetContactsStatus = self::STATUS_DENIED;
            } else {
                $nonTargetContactsStatus = self::STATUS_INCONCLUSIVE;
                $contactsFailureCode ??= $exception->reason === 'graph_record_not_found'
                    ? 'graph_non_target_contacts_control_not_found'
                    : $this->safeFailureCode($exception->reason);
            }
        }

        $contactsBoundaryVerified = $targetContactsStatus === self::STATUS_ACCESSIBLE
            && $nonTargetContactsStatus === self::STATUS_DENIED;

        if (! $contactsBoundaryVerified && $contactsFailureCode === null) {
            $contactsFailureCode = 'graph_contacts_boundary_inconclusive';
        }

        $allDenied = collect($statuses)->every(fn (string $status): bool => $status === self::STATUS_DENIED);

        $updatedTarget = DB::transaction(function () use (
            $actor,
            $allDenied,
            $contactsBoundaryVerified,
            $contactsFailureCode,
            $failureCode,
            $nonTargetContactsStatus,
            $statuses,
            $target,
            $targetContactsStatus,
        ): ?MicrosoftGraphOnboardingTarget {
            $currentTarget = MicrosoftGraphOnboardingTarget::query()
                ->whereKey($target->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->sameControlConfiguration($target, $currentTarget)) {
                $this->audit(
                    $actor,
                    $currentTarget,
                    'microsoft_graph_onboarding_non_target_denial_stale_result_discarded',
                    $statuses,
                    'graph_onboarding_targets_changed_during_check',
                );

                return null;
            }

            $currentTarget->forceFill([
                ...$statuses,
                'boundary_verified_at' => $allDenied ? now() : null,
                'boundary_error_code' => $allDenied ? null : ($failureCode ?? 'graph_non_target_boundary_inconclusive'),
                'target_contacts_status' => $targetContactsStatus,
                'non_target_contacts_status' => $nonTargetContactsStatus,
                'contacts_boundary_verified_at' => $contactsBoundaryVerified ? now() : null,
                'contacts_boundary_error_code' => $contactsBoundaryVerified ? null : ($contactsFailureCode ?? 'graph_contacts_boundary_inconclusive'),
            ])->save();

            $this->audit(
                $actor,
                $currentTarget,
                $allDenied
                    ? 'microsoft_graph_onboarding_non_target_denial_verified'
                    : 'microsoft_graph_onboarding_non_target_denial_failed',
                $statuses,
                $allDenied ? null : ($failureCode ?? 'graph_non_target_boundary_inconclusive'),
            );

            $this->audit(
                $actor,
                $currentTarget,
                $contactsBoundaryVerified
                    ? 'microsoft_graph_onboarding_contacts_boundary_verified'
                    : 'microsoft_graph_onboarding_contacts_boundary_failed',
                [
                    'target_contacts_status' => $targetContactsStatus,
                    'non_target_contacts_status' => $nonTargetContactsStatus,
                ],
                $contactsBoundaryVerified ? null : ($contactsFailureCode ?? 'graph_contacts_boundary_inconclusive'),
            );

            return $currentTarget;
        });

        if (! $updatedTarget instanceof MicrosoftGraphOnboardingTarget) {
            throw new MicrosoftGraphApiException('graph_onboarding_targets_changed_during_check', 409);
        }

        return $updatedTarget->fresh();
    }

    private function hasDistinctControlTargets(MicrosoftGraphOnboardingTarget $target): bool
    {
        return is_string($target->non_target_mailbox_email)
            && $target->non_target_mailbox_email !== ''
            && ! $this->sameIdentifier($target->non_target_mailbox_email, $target->notification_mailbox_address)
            && is_string($target->non_target_site_id)
            && $target->non_target_site_id !== ''
            && ! $this->matchesAny($target->non_target_site_id, array_filter([
                $target->work_schedules_site_id,
                $target->business_attachments_site_id,
            ]))
            && is_string($target->non_target_drive_id)
            && $target->non_target_drive_id !== ''
            && ! $this->matchesAny($target->non_target_drive_id, array_filter([
                $target->work_schedules_drive_id,
                $target->business_attachments_drive_id,
            ]));
    }

    /** @param list<string> $identifiers */
    private function matchesAny(string $identifier, array $identifiers): bool
    {
        foreach ($identifiers as $candidate) {
            if ($this->sameIdentifier($identifier, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function sameIdentifier(string $first, string $second): bool
    {
        return hash_equals(strtolower($first), strtolower($second));
    }

    private function sameControlConfiguration(
        MicrosoftGraphOnboardingTarget $checked,
        MicrosoftGraphOnboardingTarget $current,
    ): bool {
        foreach ([
            'environment',
            'tenant_id',
            'client_id',
            'validation_status',
            'read_access_verified_at',
            'notification_mailbox_id',
            'notification_calendar_id',
            'work_schedules_site_id',
            'work_schedules_drive_id',
            'business_attachments_site_id',
            'business_attachments_drive_id',
            'non_target_mailbox_email',
            'non_target_mailbox_id',
            'non_target_site_id',
            'non_target_drive_id',
        ] as $attribute) {
            if ($checked->getRawOriginal($attribute) !== $current->getRawOriginal($attribute)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string> $statuses */
    private function audit(
        User $actor,
        MicrosoftGraphOnboardingTarget $target,
        string $event,
        array $statuses,
        ?string $failureCode,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
            'event' => $event,
            'summary' => $actor->name.' checked read access against explicitly identified Microsoft Graph non-target controls.',
            'metadata' => [
                'environment' => $target->environment,
                'target_id' => $target->getKey(),
                'boundary_statuses' => $statuses,
                'failure_code' => $failureCode,
            ],
            'created_at' => now(),
        ]);
    }

    private function safeFailureCode(string $reason): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $reason) === 1
            ? $reason
            : 'graph_non_target_boundary_check_failed';
    }
}
