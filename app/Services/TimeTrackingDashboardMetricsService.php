<?php

namespace App\Services;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class TimeTrackingDashboardMetricsService
{
    /** @return array<string, int|float> */
    public function metrics(): array
    {
        $approvedStatuses = [
            TimeEntryApprovalStatus::Approved->value,
            TimeEntryApprovalStatus::Invoiced->value,
            TimeEntryApprovalStatus::Paid->value,
        ];
        $staleTimerHours = (int) config('time_tracking.dashboard.stale_timer_hours', 12);
        $unapprovedAgeDays = (int) config('time_tracking.dashboard.unapproved_entry_age_days', 7);
        $staleTimerCutoff = now()->subHours($staleTimerHours)->utc();
        $unapprovedCutoff = now()->subDays($unapprovedAgeDays)->utc();

        $totals = TimeEntry::query()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NOT NULL AND approval_status = ? THEN duration_minutes ELSE 0 END), 0) AS unapproved_minutes',
                [TimeEntryApprovalStatus::Draft->value],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NOT NULL AND approval_status IN (?, ?, ?) THEN duration_minutes ELSE 0 END), 0) AS approved_minutes',
                $approvedStatuses,
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NOT NULL AND billable = ? AND approval_status IN (?, ?, ?) THEN duration_minutes ELSE 0 END), 0) AS billable_minutes',
                [true, ...$approvedStatuses],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NOT NULL AND billable = ? AND approval_status = ? THEN duration_minutes ELSE 0 END), 0) AS unbilled_minutes',
                [true, TimeEntryApprovalStatus::Approved->value],
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN ended_at IS NULL THEN 1 ELSE 0 END), 0) AS active_timers')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NULL AND started_at <= ? THEN 1 ELSE 0 END), 0) AS stale_timers',
                [$staleTimerCutoff],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ended_at IS NOT NULL AND approval_status = ? AND ended_at <= ? THEN 1 ELSE 0 END), 0) AS aged_drafts',
                [TimeEntryApprovalStatus::Draft->value, $unapprovedCutoff],
            )
            ->first();

        $staleTimers = (int) ($totals?->stale_timers ?? 0);
        $agedDrafts = (int) ($totals?->aged_drafts ?? 0);

        return [
            'unapprovedHours' => $this->hours($totals?->unapproved_minutes),
            'approvedHours' => $this->hours($totals?->approved_minutes),
            'billableHours' => $this->hours($totals?->billable_minutes),
            'unbilledHours' => $this->hours($totals?->unbilled_minutes),
            'activeTimers' => (int) ($totals?->active_timers ?? 0),
            'exceptions' => $staleTimers + $agedDrafts,
            'staleTimers' => $staleTimers,
            'agedUnapprovedEntries' => $agedDrafts,
            'staleTimerHours' => $staleTimerHours,
            'unapprovedEntryAgeDays' => $unapprovedAgeDays,
        ];
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function exceptions(): LengthAwarePaginator
    {
        $now = CarbonImmutable::now(config('app.timezone', 'UTC'));
        $staleTimerHours = (int) config('time_tracking.dashboard.stale_timer_hours', 12);
        $unapprovedAgeDays = (int) config('time_tracking.dashboard.unapproved_entry_age_days', 7);
        $staleTimerCutoff = $now->subHours($staleTimerHours);
        $unapprovedCutoff = $now->subDays($unapprovedAgeDays);

        return TimeEntry::query()
            ->where(function (Builder $query) use ($staleTimerCutoff, $unapprovedCutoff): void {
                $query->where(function (Builder $activeQuery) use ($staleTimerCutoff): void {
                    $activeQuery->whereNull('ended_at')
                        ->where('started_at', '<=', $staleTimerCutoff);
                })->orWhere(function (Builder $draftQuery) use ($unapprovedCutoff): void {
                    $draftQuery->whereNotNull('ended_at')
                        ->where('approval_status', TimeEntryApprovalStatus::Draft->value)
                        ->where('ended_at', '<=', $unapprovedCutoff);
                });
            })
            ->with([
                'user:id,name',
                'customer:id,name,company_name',
                'project:id,project_number,name',
                'job:id,job_number,title',
            ])
            ->orderByRaw('CASE WHEN ended_at IS NULL THEN 0 ELSE 1 END')
            ->orderBy('started_at')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(function (TimeEntry $entry) use ($now, $staleTimerHours, $unapprovedAgeDays): array {
                $isStaleTimer = $entry->ended_at === null;
                $ageMinutes = $isStaleTimer
                    ? (int) $entry->started_at->diffInMinutes($now)
                    : (int) $entry->ended_at->diffInMinutes($now);

                return [
                    'id' => $entry->getKey(),
                    'type' => $isStaleTimer ? 'stale_timer' : 'aged_unapproved',
                    'label' => $isStaleTimer
                        ? "Active timer over {$staleTimerHours} hours"
                        : "Draft entry awaiting review over {$unapprovedAgeDays} days",
                    'ageMinutes' => $ageMinutes,
                    'startedAt' => $entry->started_at?->toIso8601String(),
                    'date' => $entry->started_at?->timezone(config('app.timezone'))->toDateString(),
                    'durationMinutes' => $entry->ended_at === null
                        ? $ageMinutes
                        : $entry->duration_minutes,
                    'technician' => $entry->user?->name ?? 'Former user',
                    'customer' => $entry->customer?->company_name ?: ($entry->customer?->name ?? 'Unavailable customer'),
                    'project' => $entry->project === null
                        ? null
                        : trim(($entry->project->project_number ? $entry->project->project_number.' · ' : '').$entry->project->name),
                    'job' => $entry->job === null
                        ? null
                        : trim(($entry->job->job_number ? $entry->job->job_number.' · ' : '').$entry->job->title),
                    'activityLabel' => $entry->activity_type->label(),
                ];
            });
    }

    private function hours(mixed $minutes): float
    {
        return round(((float) ($minutes ?? 0)) / 60, 4);
    }
}
