<?php

namespace App\Services;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TimeEntryReviewService
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, TimeEntry $timeEntry, array $attributes): TimeEntry
    {
        return DB::transaction(function () use ($actor, $timeEntry, $attributes): TimeEntry {
            $lockedEntry = TimeEntry::query()->lockForUpdate()->findOrFail($timeEntry->getKey());
            Gate::forUser($actor)->authorize('update', $lockedEntry);

            $wasApproved = $lockedEntry->approval_status === TimeEntryApprovalStatus::Approved;
            $lockedEntry->forceFill([
                'started_at' => $attributes['started_at'],
                'ended_at' => $attributes['ended_at'],
                'duration_minutes' => $attributes['duration_minutes'],
                'activity_type' => $attributes['activity_type'],
                'billable' => $attributes['billable'],
                'notes' => $attributes['notes'] ?? null,
                'approval_status' => TimeEntryApprovalStatus::Draft,
                'updated_by' => $actor->getKey(),
            ])->save();

            $changedFields = array_keys($attributes);

            if ($wasApproved) {
                $changedFields[] = 'approval_status';
            }

            $this->activityLog->record(
                $lockedEntry,
                'edited',
                ['changed_fields' => array_values(array_unique($changedFields))],
                $actor,
            );

            return $lockedEntry->refresh();
        });
    }

    public function approve(User $actor, TimeEntry $timeEntry): TimeEntry
    {
        return $this->changeApprovalStatus(
            $actor,
            $timeEntry,
            'approve',
            TimeEntryApprovalStatus::Approved,
            'approved',
        );
    }

    public function unapprove(User $actor, TimeEntry $timeEntry): TimeEntry
    {
        return $this->changeApprovalStatus(
            $actor,
            $timeEntry,
            'unapprove',
            TimeEntryApprovalStatus::Draft,
            'unapproved',
        );
    }

    /** @param list<int> $ids
     * @return list<TimeEntry>
     */
    public function bulkApprove(User $actor, array $ids): array
    {
        return DB::transaction(function () use ($actor, $ids): array {
            Gate::forUser($actor)->authorize('reviewAny', TimeEntry::class);
            $entries = TimeEntry::query()
                ->whereKey($ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($entries->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'ids' => 'One or more selected time entries are no longer available.',
                ]);
            }

            foreach ($entries as $entry) {
                if (! $actor->can('approve', $entry)) {
                    throw ValidationException::withMessages([
                        'ids' => 'Select only completed Draft entries for bulk approval.',
                    ]);
                }
            }

            foreach ($entries as $entry) {
                $this->setApprovalStatus($actor, $entry, TimeEntryApprovalStatus::Approved, 'approved');
            }

            return $entries->all();
        });
    }

    public function delete(User $actor, TimeEntry $timeEntry): void
    {
        DB::transaction(function () use ($actor, $timeEntry): void {
            // Timer starts lock the performer before checking the active-entry index.
            User::query()->lockForUpdate()->findOrFail($timeEntry->user_id);
            $lockedEntry = TimeEntry::query()->lockForUpdate()->findOrFail($timeEntry->getKey());
            Gate::forUser($actor)->authorize('delete', $lockedEntry);

            $this->activityLog->record($lockedEntry, 'deleted', [
                'was_running' => $lockedEntry->ended_at === null,
            ], $actor);

            $lockedEntry->delete();
        });
    }

    private function changeApprovalStatus(
        User $actor,
        TimeEntry $timeEntry,
        string $ability,
        TimeEntryApprovalStatus $status,
        string $event,
    ): TimeEntry {
        return DB::transaction(function () use ($actor, $timeEntry, $ability, $status, $event): TimeEntry {
            $lockedEntry = TimeEntry::query()->lockForUpdate()->findOrFail($timeEntry->getKey());
            Gate::forUser($actor)->authorize($ability, $lockedEntry);

            $this->setApprovalStatus($actor, $lockedEntry, $status, $event);

            return $lockedEntry->refresh();
        });
    }

    private function setApprovalStatus(
        User $actor,
        TimeEntry $timeEntry,
        TimeEntryApprovalStatus $status,
        string $event,
    ): void {
        $timeEntry->forceFill([
            'approval_status' => $status,
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->activityLog->record($timeEntry, $event, ['changed_fields' => ['approval_status']], $actor);
    }
}
