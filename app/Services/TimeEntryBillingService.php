<?php

namespace App\Services;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TimeEntryBillingService
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** @param list<int> $ids */
    public function bulkMarkInvoiced(User $actor, array $ids): void
    {
        DB::transaction(function () use ($actor, $ids): void {
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
                if (! $actor->can('markInvoiced', $entry)) {
                    throw ValidationException::withMessages([
                        'ids' => 'Select only completed, Billable, Approved entries to mark Invoiced.',
                    ]);
                }
            }

            foreach ($entries as $entry) {
                $this->markEntryInvoiced($actor, $entry);
            }
        });
    }

    public function markInvoiced(User $actor, TimeEntry $timeEntry): TimeEntry
    {
        return DB::transaction(function () use ($actor, $timeEntry): TimeEntry {
            $lockedEntry = TimeEntry::query()->lockForUpdate()->findOrFail($timeEntry->getKey());
            Gate::forUser($actor)->authorize('markInvoiced', $lockedEntry);
            $this->markEntryInvoiced($actor, $lockedEntry);

            return $lockedEntry->refresh();
        });
    }

    private function markEntryInvoiced(User $actor, TimeEntry $timeEntry): void
    {
        $timeEntry->forceFill([
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
            'updated_by' => $actor->getKey(),
        ])->save();

        $this->activityLog->record(
            $timeEntry,
            'marked_invoiced',
            ['changed_fields' => ['approval_status']],
            $actor,
        );
    }
}
