<?php

namespace App\Services;

use App\Enums\TimeEntryActivityType;
use App\Exceptions\TimeEntryTimerException;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Validation\ValidationException;

class WorkOrderTimerAutomationService
{
    public function __construct(private readonly TimeEntryTimerService $timers) {}

    /** @param array<string, mixed> $timerContext */
    public function applyTransition(
        WorkOrder $workOrder,
        User $actor,
        string $oldStatus,
        string $newStatus,
        array $timerContext = [],
    ): void {
        if ($oldStatus === $newStatus) {
            return;
        }

        $statuses = config('work_orders.statuses', []);

        if (! is_array($statuses) || ! array_key_exists($newStatus, $statuses)) {
            throw ValidationException::withMessages(['status' => 'Choose a configured Job status.']);
        }

        $statusRules = config("work_orders.timer_automations.{$newStatus}", []);

        if (! is_array($statusRules) || $statusRules === []) {
            return;
        }

        $isAssigned = $workOrder->assignedUsers()
            ->whereKey($actor->getKey())
            ->exists()
            || $workOrder->visits()
                ->whereHas('assignedUsers', fn ($query) => $query->whereKey($actor->getKey()))
                ->exists();

        if (! $isAssigned) {
            return;
        }

        $activeTimer = TimeEntry::query()
            ->where('user_id', $actor->getKey())
            ->whereNull('ended_at')
            ->lockForUpdate()
            ->first();
        $activeForJob = $activeTimer !== null && (int) $activeTimer->job_id === (int) $workOrder->getKey();
        $shouldStop = $activeForJob && (bool) ($statusRules['stop_active_timer_for_job'] ?? false);

        $startActivityType = $statusRules['start_activity_type'] ?? null;

        // An active-work status always begins a fresh segment, even when its
        // activity type is unchanged. Only this actor's timer for this Job may
        // be stopped; an unrelated active timer is never touched.
        if (is_string($startActivityType) && $startActivityType !== '' && $activeForJob) {
            $shouldStop = true;
        }

        if ($shouldStop && $activeTimer !== null) {
            $this->timers->stop($actor, (int) $activeTimer->getKey());
            $activeTimer = null;
        }

        if (! is_string($startActivityType) || $startActivityType === '') {
            return;
        }

        $activityType = TimeEntryActivityType::tryFrom($startActivityType);

        if ($activityType === null) {
            throw new \LogicException("Unknown configured time-entry activity type [{$startActivityType}].");
        }

        if ($activeTimer !== null) {
            throw ValidationException::withMessages([
                'timer_start_conflict' => 'Stop your active timer before starting '.$activityType->label().' for this Job.',
            ]);
        }

        $customerId = $workOrder->site()->value('customer_id');

        if ($customerId === null) {
            throw ValidationException::withMessages([
                'timer_start_conflict' => 'This Job has no active Customer site for time tracking.',
            ]);
        }

        try {
            $this->timers->start($actor, [
                'customer_id' => (int) $customerId,
                'project_id' => $workOrder->project_id,
                'job_id' => (int) $workOrder->getKey(),
                'visit_id' => $timerContext['timer_visit_id'] ?? null,
                'activity_type' => $activityType->value,
                'billable' => false,
            ]);
        } catch (TimeEntryTimerException $exception) {
            if (in_array($exception->statusCode, [409, 422], true)) {
                $errorField = isset($timerContext['timer_visit_id']) && $exception->statusCode === 422
                    ? 'timer_visit_id'
                    : 'timer_start_conflict';

                throw ValidationException::withMessages([
                    $errorField => $exception->statusCode === 409
                        ? 'Stop your active timer before starting '.$activityType->label().' for this Job.'
                        : $exception->getMessage(),
                ]);
            }

            throw $exception;
        }
    }
}
