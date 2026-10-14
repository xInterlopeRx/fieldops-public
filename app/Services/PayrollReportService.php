<?php

namespace App\Services;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

class PayrollReportService
{
    /** @return array<string, mixed> */
    public function build(string $dateFrom, string $dateTo): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay();
        $to = CarbonImmutable::parse($dateTo, $timezone)->startOfDay();
        $selectedEnd = $to->addDay();
        $weekStartIso = (int) config('time_tracking.payroll.workweek_starts_on', 1);
        $weekStartDay = $weekStartIso % 7;
        $workweekWindowStart = $from->startOfWeek($weekStartDay);
        $workweekWindowEnd = $to->startOfWeek($weekStartDay)->addWeek();
        $approvedStatus = (string) config(
            'time_tracking.payroll.included_approval_status',
            TimeEntryApprovalStatus::Approved->value,
        );
        $overtimeThresholdMinutes = (float) config('time_tracking.payroll.weekly_overtime_threshold_minutes', 2400);
        $overtimeThresholdHours = $overtimeThresholdMinutes / 60;

        $entries = TimeEntry::query()
            ->where('approval_status', $approvedStatus)
            ->whereNotNull('ended_at')
            ->where('started_at', '<', $workweekWindowEnd->utc())
            ->where('ended_at', '>', $workweekWindowStart->utc())
            ->with([
                'user:id,name',
                'customer:id,name,company_name',
                'project:id,project_number,name',
                'job:id,job_number,title',
            ])
            ->orderBy('user_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $segments = [];

        foreach ($entries as $entry) {
            foreach ($this->segmentsWithinWindow(
                $entry,
                $workweekWindowStart,
                $workweekWindowEnd,
                $timezone,
                $weekStartDay,
            ) as $segment) {
                $segments[] = $segment;
            }
        }

        usort($segments, static function (array $left, array $right): int {
            return ($left['technicianId'] <=> $right['technicianId'])
                ?: strcmp($left['weekStart'], $right['weekStart'])
                ?: ($left['startTimestamp'] <=> $right['startTimestamp'])
                ?: ($left['entryId'] <=> $right['entryId']);
        });

        $minutesUsedByWeek = [];
        $technicians = [];
        $rangeStart = $from->toDateString();
        $rangeEnd = $to->toDateString();

        foreach ($segments as $segment) {
            $weekKey = $segment['technicianId'].'|'.$segment['weekStart'];
            $minutesUsed = $minutesUsedByWeek[$weekKey] ?? 0.0;
            $regularMinutes = min(
                $segment['durationMinutes'],
                max(0.0, $overtimeThresholdMinutes - $minutesUsed),
            );
            $overtimeMinutes = $segment['durationMinutes'] - $regularMinutes;
            $minutesUsedByWeek[$weekKey] = $minutesUsed + $segment['durationMinutes'];

            if ($segment['date'] < $rangeStart || $segment['date'] > $rangeEnd) {
                continue;
            }

            $technicianId = $segment['technicianId'];
            $day = $segment['date'];
            $regularHours = $regularMinutes / 60;
            $overtimeHours = $overtimeMinutes / 60;
            $durationHours = $segment['durationMinutes'] / 60;

            $technicians[$technicianId] ??= [
                'id' => $technicianId,
                'name' => $segment['technician'],
                'regularHours' => 0.0,
                'overtimeHours' => 0.0,
                'days' => [],
            ];
            $technicians[$technicianId]['days'][$day] ??= [
                'date' => $day,
                'regularHours' => 0.0,
                'overtimeHours' => 0.0,
                'entries' => [],
            ];

            $technicians[$technicianId]['regularHours'] += $regularHours;
            $technicians[$technicianId]['overtimeHours'] += $overtimeHours;
            $technicians[$technicianId]['days'][$day]['regularHours'] += $regularHours;
            $technicians[$technicianId]['days'][$day]['overtimeHours'] += $overtimeHours;
            $technicians[$technicianId]['days'][$day]['entries'][] = [
                'id' => $segment['entryId'],
                'date' => $day,
                'startAt' => $segment['startAt'],
                'endAt' => $segment['endAt'],
                'customer' => $segment['customer'],
                'project' => $segment['project'],
                'job' => $segment['job'],
                'activityType' => $segment['activityType'],
                'activityLabel' => $segment['activityLabel'],
                'billable' => $segment['billable'],
                'durationHours' => $this->roundedHours($durationHours),
                'regularHours' => $this->roundedHours($regularHours),
                'overtimeHours' => $this->roundedHours($overtimeHours),
                'totalHours' => $this->roundedHours($durationHours),
            ];
        }

        $technicianRows = [];
        $totals = ['regularHours' => 0.0, 'overtimeHours' => 0.0];

        foreach ($technicians as $technician) {
            ksort($technician['days']);
            $dailyRows = [];

            foreach ($technician['days'] as $day) {
                $dailyRows[] = [
                    ...$day,
                    'regularHours' => $this->roundedHours($day['regularHours']),
                    'overtimeHours' => $this->roundedHours($day['overtimeHours']),
                    'totalHours' => $this->roundedHours($day['regularHours'] + $day['overtimeHours']),
                ];
            }

            $totals['regularHours'] += $technician['regularHours'];
            $totals['overtimeHours'] += $technician['overtimeHours'];
            $technicianRows[] = [
                'id' => $technician['id'],
                'name' => $technician['name'],
                'regularHours' => $this->roundedHours($technician['regularHours']),
                'overtimeHours' => $this->roundedHours($technician['overtimeHours']),
                'totalHours' => $this->roundedHours($technician['regularHours'] + $technician['overtimeHours']),
                'days' => $dailyRows,
            ];
        }

        usort($technicianRows, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        $excludedEntriesCount = TimeEntry::query()
            ->whereNotNull('ended_at')
            ->where('approval_status', '!=', $approvedStatus)
            ->where('started_at', '<', $selectedEnd->utc())
            ->where('ended_at', '>', $from->utc())
            ->count();

        $weekStartLabels = [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ];
        $weekEndIso = (($weekStartIso + 5) % 7) + 1;

        return [
            'dateFrom' => $rangeStart,
            'dateTo' => $rangeEnd,
            'timezone' => $timezone,
            'includedApprovalStatus' => $approvedStatus,
            'excludedEntriesCount' => $excludedEntriesCount,
            'overtimeThresholdHours' => $overtimeThresholdHours,
            'workweekStartsOn' => $weekStartLabels[$weekStartIso] ?? 'Monday',
            'workweekEndsOn' => $weekStartLabels[$weekEndIso] ?? 'Sunday',
            'technicians' => $technicianRows,
            'totals' => [
                'regularHours' => $this->roundedHours($totals['regularHours']),
                'overtimeHours' => $this->roundedHours($totals['overtimeHours']),
                'totalHours' => $this->roundedHours($totals['regularHours'] + $totals['overtimeHours']),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function segmentsWithinWindow(
        TimeEntry $entry,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
        string $timezone,
        int $weekStartDay,
    ): array {
        $entryStart = $entry->started_at->toImmutable()->setTimezone($timezone);
        $entryEnd = $entry->ended_at->toImmutable()->setTimezone($timezone);
        $elapsedSeconds = max(0.000001, (float) $entryStart->diffInSeconds($entryEnd));
        $start = $entryStart->greaterThan($windowStart) ? $entryStart : $windowStart;
        $end = $entryEnd->lessThan($windowEnd) ? $entryEnd : $windowEnd;
        $durationMinutes = (float) ($entry->duration_minutes ?? 0);
        $segments = [];

        for ($cursor = $start; $cursor->lessThan($end);) {
            $nextDay = $cursor->startOfDay()->addDay();
            $segmentEnd = $nextDay->lessThan($end) ? $nextDay : $end;
            $segmentSeconds = (float) $cursor->diffInSeconds($segmentEnd);

            $segments[] = [
                'entryId' => (int) $entry->getKey(),
                'technicianId' => (int) $entry->user_id,
                'technician' => $entry->user?->name ?? 'Former user',
                'date' => $cursor->toDateString(),
                'weekStart' => $cursor->startOfWeek($weekStartDay)->toDateString(),
                'startTimestamp' => (float) $cursor->format('U.u'),
                'startAt' => $cursor->format('Y-m-d\TH:i:sP'),
                'endAt' => $segmentEnd->format('Y-m-d\TH:i:sP'),
                'durationMinutes' => $durationMinutes * $segmentSeconds / $elapsedSeconds,
                'customer' => $entry->customer?->company_name ?: ($entry->customer?->name ?? 'Unavailable customer'),
                'project' => $entry->project === null
                    ? null
                    : trim(($entry->project->project_number ? $entry->project->project_number.' · ' : '').$entry->project->name),
                'job' => $entry->job === null
                    ? null
                    : trim(($entry->job->job_number ? $entry->job->job_number.' · ' : '').$entry->job->title),
                'activityType' => $entry->activity_type->value,
                'activityLabel' => $entry->activity_type->label(),
                'billable' => $entry->billable,
            ];

            $cursor = $segmentEnd;
        }

        return $segments;
    }

    private function roundedHours(float $hours): float
    {
        return round($hours, 6);
    }
}
