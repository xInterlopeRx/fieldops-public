<?php

namespace App\Services;

use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\GraphOnboardingChallenge;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SaveMicrosoftGraphOnboardingTargets
{
    public function __construct(private readonly ApplicationSetupStepService $setupSteps) {}

    /** @param array<string, mixed> $targets */
    public function save(User $actor, array $targets): MicrosoftGraphOnboardingTarget
    {
        $environment = config('app.env');

        if (! is_string($environment) || trim($environment) === '' || strlen($environment) > 20) {
            throw new InvalidArgumentException('The configured application environment is invalid.');
        }

        return DB::transaction(function () use ($actor, $environment, $targets): MicrosoftGraphOnboardingTarget {
            $target = MicrosoftGraphOnboardingTarget::query()
                ->where('environment', $environment)
                ->lockForUpdate()
                ->first();

            if (! $target instanceof MicrosoftGraphOnboardingTarget) {
                $target = new MicrosoftGraphOnboardingTarget;
            }

            $runtimeActivationInvalidated = $target->runtime_activated_at !== null;

            $target->fill([
                'environment' => $environment,
                ...$targets,
            ]);
            $target->non_target_mailbox_id = null;
            $target->configured_by_user_id = $actor->getKey();
            $target->configured_at = now();
            $target->validation_status = 'pending';
            $target->last_validation_attempted_at = null;
            $target->read_access_verified_at = null;
            $target->read_access_check_count = 0;
            $target->last_error_code = null;
            $target->non_target_mailbox_calendar_status = 'not_checked';
            $target->non_target_site_status = 'not_checked';
            $target->non_target_drive_status = 'not_checked';
            $target->boundary_validation_attempted_at = null;
            $target->boundary_verified_at = null;
            $target->boundary_error_code = null;
            $target->target_contacts_status = 'not_checked';
            $target->non_target_contacts_status = 'not_checked';
            $target->contacts_boundary_validation_attempted_at = null;
            $target->contacts_boundary_verified_at = null;
            $target->contacts_boundary_error_code = null;
            $target->runtime_activated_at = null;
            $target->runtime_activated_by_user_id = null;
            $target->save();

            GraphOnboardingChallenge::query()
                ->where('target_id', $target->getKey())
                ->whereIn('status', [
                    GraphOnboardingChallenge::STATUS_SENDING,
                    GraphOnboardingChallenge::STATUS_SENT,
                    GraphOnboardingChallenge::STATUS_UNKNOWN,
                    GraphOnboardingChallenge::STATUS_VERIFIED,
                ])
                ->update([
                    'status' => GraphOnboardingChallenge::STATUS_REPLACED,
                    'failure_code' => 'graph_targets_changed',
                    'updated_at' => now(),
                ]);

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->getKey(),
                'event' => 'microsoft_graph_onboarding_targets_configured',
                'summary' => $actor->name.' configured Microsoft Graph onboarding target identifiers for the '.$environment.' environment.',
                'metadata' => [
                    'environment' => $environment,
                    'tenant_id' => $target->tenant_id,
                    'client_id' => $target->client_id,
                    'notification_mailbox_id' => $target->notification_mailbox_id,
                    'notification_mailbox_address' => $target->notification_mailbox_address,
                    'target_is_dedicated_shared_mailbox' => $target->target_is_dedicated_shared_mailbox,
                    'notification_calendar_id' => $target->notification_calendar_id,
                    'work_schedules_group_id' => $target->work_schedules_group_id,
                    'work_schedules_site_id' => $target->work_schedules_site_id,
                    'work_schedules_drive_id' => $target->work_schedules_drive_id,
                    'business_attachments_site_id' => $target->business_attachments_site_id,
                    'business_attachments_drive_id' => $target->business_attachments_drive_id,
                    'non_target_mailbox_email' => $target->non_target_mailbox_email,
                    'non_target_is_user_mailbox' => $target->non_target_is_user_mailbox,
                    'non_target_site_id' => $target->non_target_site_id,
                    'non_target_drive_id' => $target->non_target_drive_id,
                    'validation_status' => $target->validation_status,
                    'live_graph_validation_performed' => false,
                    'runtime_activation_invalidated' => $runtimeActivationInvalidated,
                ],
                'created_at' => now(),
            ]);

            $this->setupSteps->resetPending(ApplicationSetupStep::GRAPH_VALIDATION, $actor);

            return $target;
        });
    }
}
