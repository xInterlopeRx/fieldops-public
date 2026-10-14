<?php

namespace App\Services;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Exceptions\TimeEntryTimerException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class TimeEntryTimerService
{
    public function activeFor(User $user): ?TimeEntry
    {
        return TimeEntry::query()
            ->where('user_id', $user->getKey())
            ->whereNull('ended_at')
            ->first();
    }

    /** @param array<string, mixed> $attributes */
    public function start(User $user, array $attributes): TimeEntry
    {
        try {
            return DB::transaction(function () use ($user, $attributes): TimeEntry {
                $actor = User::query()->lockForUpdate()->findOrFail($user->getKey());

                if (TimeEntry::query()
                    ->where('user_id', $actor->getKey())
                    ->whereNull('ended_at')
                    ->exists()) {
                    throw new TimeEntryTimerException('You already have an active timer.', 409);
                }

                $context = $this->resolveContext($attributes);
                $startedAt = now();
                $timeEntry = new TimeEntry([
                    'user_id' => $actor->getKey(),
                    'customer_id' => $context['customer_id'],
                    'project_id' => $context['project_id'],
                    'job_id' => $context['job_id'],
                    'visit_id' => $context['visit_id'],
                    'activity_type' => TimeEntryActivityType::from($attributes['activity_type']),
                    'started_at' => $startedAt,
                    'ended_at' => null,
                    'duration_minutes' => null,
                    'billable' => (bool) ($attributes['billable'] ?? false),
                    'notes' => $attributes['notes'] ?? null,
                    'approval_status' => TimeEntryApprovalStatus::Draft,
                ]);
                $timeEntry->forceFill([
                    'created_by' => $actor->getKey(),
                    'updated_by' => null,
                ])->save();

                return $timeEntry;
            });
        } catch (QueryException $exception) {
            if ($this->isActiveTimerConstraintViolation($exception)) {
                throw new TimeEntryTimerException('You already have an active timer.', 409);
            }

            throw $exception;
        }
    }

    public function stop(User $user, int $timeEntryId): TimeEntry
    {
        return DB::transaction(function () use ($user, $timeEntryId): TimeEntry {
            $timeEntry = TimeEntry::query()
                ->whereKey($timeEntryId)
                ->where('user_id', $user->getKey())
                ->lockForUpdate()
                ->first();

            if ($timeEntry === null) {
                throw new TimeEntryTimerException('Active timer not found.', 404);
            }

            if ($timeEntry->ended_at !== null || $timeEntry->approval_status !== TimeEntryApprovalStatus::Draft) {
                throw new TimeEntryTimerException('This timer is no longer active.', 409);
            }

            $endedAt = now();

            if ($endedAt->lessThanOrEqualTo($timeEntry->started_at)) {
                throw new TimeEntryTimerException('The timer cannot be stopped before it has started.', 422);
            }

            $elapsedSeconds = (int) $timeEntry->started_at->diffInSeconds($endedAt);
            $timeEntry->forceFill([
                'ended_at' => $endedAt,
                'duration_minutes' => intdiv($elapsedSeconds, 60),
                'updated_by' => $user->getKey(),
            ])->save();

            return $timeEntry->refresh();
        });
    }

    /** @param array<string, mixed> $attributes
     * @return array{customer_id: int, project_id: int|null, job_id: int|null, visit_id: int|null}
     */
    private function resolveContext(array $attributes): array
    {
        $customer = Customer::query()
            ->lockForUpdate()
            ->find($attributes['customer_id']);

        if ($customer === null) {
            throw new TimeEntryTimerException('Choose an active Customer.', 422);
        }

        $projectId = isset($attributes['project_id']) ? (int) $attributes['project_id'] : null;
        $jobId = isset($attributes['job_id']) ? (int) $attributes['job_id'] : null;
        $visitId = isset($attributes['visit_id']) ? (int) $attributes['visit_id'] : null;
        $project = null;
        $job = null;

        if ($projectId !== null) {
            $project = Project::query()->lockForUpdate()->find($projectId);

            if ($project === null || (int) $project->customer_id !== (int) $customer->getKey()) {
                throw new TimeEntryTimerException('Choose a Project belonging to the selected Customer.', 422);
            }
        }

        if ($jobId !== null) {
            $job = WorkOrder::query()
                ->with('site')
                ->lockForUpdate()
                ->find($jobId);

            if ($job === null || (int) $job->site?->customer_id !== (int) $customer->getKey()) {
                throw new TimeEntryTimerException('Choose a Job belonging to the selected Customer.', 422);
            }

            if ($projectId === null && $job->project_id !== null) {
                $projectId = (int) $job->project_id;
                $project = Project::query()->lockForUpdate()->find($projectId);

                if ($project === null || (int) $project->customer_id !== (int) $customer->getKey()) {
                    throw new TimeEntryTimerException('The selected Job is linked to an unavailable Project.', 422);
                }
            } elseif ($projectId !== null && (int) $job->project_id !== $projectId) {
                throw new TimeEntryTimerException('Choose the Project linked to the selected Job.', 422);
            }
        }

        if ($visitId !== null) {
            if ($job === null) {
                throw new TimeEntryTimerException('Choose a Job before associating a Visit.', 422);
            }

            $visit = Visit::query()
                ->where('work_order_id', $job->getKey())
                ->lockForUpdate()
                ->find($visitId);

            if ($visit === null) {
                throw new TimeEntryTimerException('Choose a Visit belonging to the selected Job.', 422);
            }
        }

        return [
            'customer_id' => (int) $customer->getKey(),
            'project_id' => $projectId,
            'job_id' => $jobId,
            'visit_id' => $visitId,
        ];
    }

    private function isActiveTimerConstraintViolation(QueryException $exception): bool
    {
        if (str_contains($exception->getMessage(), 'time_entries_one_active_timer_per_user_unique')) {
            return true;
        }

        return DB::getDriverName() === 'sqlite'
            && str_contains($exception->getMessage(), 'time_entries.user_id');
    }
}
