<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\ApplicationSetupStep;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;

class ValidateMicrosoftGraphOnboardingTargets
{
    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly ApplicationSetupStepService $setupSteps,
    ) {}

    public function validate(User $actor): MicrosoftGraphOnboardingTarget
    {
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->firstOrFail();

        if (! $target->target_is_dedicated_shared_mailbox) {
            throw new MicrosoftGraphApiException('graph_onboarding_shared_mailbox_confirmation_required', 422);
        }

        $hasBusinessSite = is_string($target->business_attachments_site_id)
            && trim($target->business_attachments_site_id) !== '';
        $hasBusinessDrive = is_string($target->business_attachments_drive_id)
            && trim($target->business_attachments_drive_id) !== '';

        if ($hasBusinessSite !== $hasBusinessDrive) {
            throw new MicrosoftGraphApiException('graph_business_attachments_target_incomplete', 422);
        }

        $checksTotal = 3 + ($hasBusinessSite ? 2 : 0);
        $this->setupSteps->begin(ApplicationSetupStep::GRAPH_VALIDATION, $actor);
        $target->forceFill([
            'validation_status' => 'validating_read_access',
            'last_validation_attempted_at' => now(),
            'read_access_verified_at' => null,
            'read_access_check_count' => 0,
            'last_error_code' => null,
        ])->save();

        $checksCompleted = 0;

        try {
            $calendarPath = 'users/'.rawurlencode($target->notification_mailbox_id)
                .($target->notification_calendar_id === null || trim($target->notification_calendar_id) === ''
                    ? '/calendar'
                    : '/calendars/'.rawurlencode($target->notification_calendar_id));
            $calendar = $this->graph->getJsonForOnboardingTarget(
                $target,
                $calendarPath,
                ['$select' => 'id,name'],
            );

            if (! is_string($calendar['id'] ?? null) || $calendar['id'] === '') {
                throw new MicrosoftGraphApiException('graph_onboarding_calendar_metadata_invalid', 502);
            }

            if (is_string($target->notification_calendar_id)
                && $target->notification_calendar_id !== ''
                && ! $this->sameIdentifier($calendar['id'], $target->notification_calendar_id)) {
                throw new MicrosoftGraphApiException('graph_onboarding_calendar_mismatch', 502);
            }

            $this->recordProgress($actor, ++$checksCompleted, $checksTotal, $target);

            $workSchedulesSite = $this->graph->getJsonForOnboardingTarget(
                $target,
                'sites/'.rawurlencode($target->work_schedules_site_id),
                ['$select' => 'id,displayName,webUrl'],
            );
            $this->assertTargetId($workSchedulesSite, $target->work_schedules_site_id);
            $this->recordProgress($actor, ++$checksCompleted, $checksTotal, $target);

            $workSchedulesDrive = $this->graph->getJsonForOnboardingTarget(
                $target,
                'drives/'.rawurlencode($target->work_schedules_drive_id),
                ['$select' => 'id,name,driveType,webUrl,parentReference'],
            );
            $this->assertTargetId($workSchedulesDrive, $target->work_schedules_drive_id);
            $this->recordProgress($actor, ++$checksCompleted, $checksTotal, $target);

            if ($hasBusinessSite && $hasBusinessDrive) {
                $businessSite = $this->graph->getJsonForOnboardingTarget(
                    $target,
                    'sites/'.rawurlencode($target->business_attachments_site_id),
                    ['$select' => 'id,displayName,webUrl'],
                );
                $this->assertTargetId($businessSite, $target->business_attachments_site_id);
                $this->recordProgress($actor, ++$checksCompleted, $checksTotal, $target);

                $businessDrive = $this->graph->getJsonForOnboardingTarget(
                    $target,
                    'drives/'.rawurlencode($target->business_attachments_drive_id),
                    ['$select' => 'id,name,driveType,webUrl,parentReference'],
                );
                $this->assertTargetId($businessDrive, $target->business_attachments_drive_id);
                $this->recordProgress($actor, ++$checksCompleted, $checksTotal, $target);
            }
        } catch (MicrosoftGraphApiException $exception) {
            $failureCode = $this->safeFailureCode($exception->reason);
            $target->forceFill([
                'validation_status' => 'failed',
                'last_error_code' => $failureCode,
            ])->save();
            $this->setupSteps->fail(ApplicationSetupStep::GRAPH_VALIDATION, $actor, $failureCode);

            throw new MicrosoftGraphApiException($failureCode, $exception->httpStatus, $exception->retryAfterSeconds);
        }

        $target->forceFill([
            'validation_status' => $hasBusinessSite ? 'read_verified' : 'read_verified_partial',
            'read_access_verified_at' => now(),
            'read_access_check_count' => $checksCompleted,
            'last_error_code' => null,
        ])->save();

        return $target;
    }

    /** @param array<string, mixed> $response */
    private function assertTargetId(array $response, string $expectedId): void
    {
        $actualId = $response['id'] ?? null;

        if (! is_string($actualId) || ! $this->sameIdentifier($actualId, $expectedId)) {
            throw new MicrosoftGraphApiException('graph_onboarding_target_mismatch', 502);
        }
    }

    private function sameIdentifier(string $actual, string $expected): bool
    {
        return hash_equals(strtolower($expected), strtolower($actual));
    }

    private function recordProgress(User $actor, int $current, int $total, MicrosoftGraphOnboardingTarget $target): void
    {
        $this->setupSteps->progress(ApplicationSetupStep::GRAPH_VALIDATION, $actor, $current, $total);
        $target->forceFill(['read_access_check_count' => $current])->save();
    }

    private function safeFailureCode(string $reason): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $reason) === 1
            ? $reason
            : 'graph_onboarding_validation_failed';
    }
}
