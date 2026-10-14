<?php

namespace App\Services;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

class TimeEntryBillingReportService
{
    /** @return array<string, mixed> */
    public function build(string $dateFrom, string $dateTo, User $viewer): array
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $from = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay();
        $toExclusive = CarbonImmutable::parse($dateTo, $timezone)->addDay()->startOfDay();
        $eligibleStatuses = [
            TimeEntryApprovalStatus::Approved->value,
            TimeEntryApprovalStatus::Invoiced->value,
            TimeEntryApprovalStatus::Paid->value,
        ];
        $entries = TimeEntry::query()
            ->whereIn('approval_status', $eligibleStatuses)
            ->whereNotNull('ended_at')
            ->where('started_at', '>=', $from->utc())
            ->where('started_at', '<', $toExclusive->utc())
            ->with([
                'user:id,name',
                'customer:id,name,company_name',
                'project:id,project_number,name',
                'job:id,job_number,title',
            ])
            ->orderBy('customer_id')
            ->orderBy('project_id')
            ->orderBy('job_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        $customers = [];
        $totalBillableHours = 0.0;
        $totalUnbilledHours = 0.0;

        foreach ($entries as $entry) {
            $customerKey = (int) $entry->customer_id;
            $projectKey = $entry->project_id === null ? 'none' : (string) $entry->project_id;
            $jobKey = $entry->job_id === null ? 'none' : (string) $entry->job_id;
            $hours = ((float) $entry->duration_minutes) / 60;
            $billableHours = $entry->billable ? $hours : 0.0;
            $unbilledHours = $entry->billable && $entry->approval_status === TimeEntryApprovalStatus::Approved
                ? $hours
                : 0.0;

            $customers[$customerKey] ??= [
                'id' => $customerKey,
                'name' => $entry->customer?->company_name ?: ($entry->customer?->name ?? 'Unavailable customer'),
                'totalBillableHours' => 0.0,
                'totalUnbilledHours' => 0.0,
                'projects' => [],
            ];
            $customers[$customerKey]['projects'][$projectKey] ??= [
                'id' => $entry->project_id,
                'name' => $entry->project === null
                    ? 'No Project'
                    : trim(($entry->project->project_number ? $entry->project->project_number.' · ' : '').$entry->project->name),
                'totalBillableHours' => 0.0,
                'totalUnbilledHours' => 0.0,
                'jobs' => [],
            ];
            $customers[$customerKey]['projects'][$projectKey]['jobs'][$jobKey] ??= [
                'id' => $entry->job_id,
                'name' => $entry->job !== null
                    ? trim(($entry->job->job_number ? $entry->job->job_number.' · ' : '').$entry->job->title)
                    : ($entry->project_id === null ? 'Customer-level entries (no Project or Job)' : 'Project-level entries (no Job)'),
                'totalBillableHours' => 0.0,
                'totalUnbilledHours' => 0.0,
                'entries' => [],
            ];

            $customers[$customerKey]['totalBillableHours'] += $billableHours;
            $customers[$customerKey]['totalUnbilledHours'] += $unbilledHours;
            $customers[$customerKey]['projects'][$projectKey]['totalBillableHours'] += $billableHours;
            $customers[$customerKey]['projects'][$projectKey]['totalUnbilledHours'] += $unbilledHours;
            $customers[$customerKey]['projects'][$projectKey]['jobs'][$jobKey]['totalBillableHours'] += $billableHours;
            $customers[$customerKey]['projects'][$projectKey]['jobs'][$jobKey]['totalUnbilledHours'] += $unbilledHours;

            $totalBillableHours += $billableHours;
            $totalUnbilledHours += $unbilledHours;
            $customers[$customerKey]['projects'][$projectKey]['jobs'][$jobKey]['entries'][] = [
                'id' => $entry->getKey(),
                'date' => $entry->started_at?->timezone($timezone)->toDateString(),
                'startedAt' => $entry->started_at?->toIso8601String(),
                'technician' => $entry->user?->name ?? 'Former user',
                'activityType' => $entry->activity_type->value,
                'activityLabel' => $entry->activity_type->label(),
                'hours' => $this->roundedHours($hours),
                'billable' => $entry->billable,
                'approvalStatus' => $entry->approval_status->value,
                'approvalStatusLabel' => $entry->approval_status->label(),
                'billableStatus' => $this->billableStatusLabel($entry),
                'canMarkInvoiced' => $viewer->can('markInvoiced', $entry),
            ];
        }

        $customerRows = array_values($customers);

        foreach ($customerRows as &$customer) {
            $customer['projects'] = array_values($customer['projects']);

            foreach ($customer['projects'] as &$project) {
                $project['jobs'] = array_values($project['jobs']);

                foreach ($project['jobs'] as &$job) {
                    $job['totalBillableHours'] = $this->roundedHours($job['totalBillableHours']);
                    $job['totalUnbilledHours'] = $this->roundedHours($job['totalUnbilledHours']);
                }
                unset($job);

                usort($project['jobs'], static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
                $project['totalBillableHours'] = $this->roundedHours($project['totalBillableHours']);
                $project['totalUnbilledHours'] = $this->roundedHours($project['totalUnbilledHours']);
            }
            unset($project);

            usort($customer['projects'], static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
            $customer['totalBillableHours'] = $this->roundedHours($customer['totalBillableHours']);
            $customer['totalUnbilledHours'] = $this->roundedHours($customer['totalUnbilledHours']);
        }
        unset($customer);

        usort($customerRows, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return [
            'dateFrom' => $from->toDateString(),
            'dateTo' => $toExclusive->subDay()->toDateString(),
            'timezone' => $timezone,
            'customers' => $customerRows,
            'totalBillableHours' => $this->roundedHours($totalBillableHours),
            'totalUnbilledHours' => $this->roundedHours($totalUnbilledHours),
        ];
    }

    private function billableStatusLabel(TimeEntry $entry): string
    {
        if (! $entry->billable) {
            return 'Non-billable · '.$entry->approval_status->label();
        }

        return match ($entry->approval_status) {
            TimeEntryApprovalStatus::Approved => 'Billable · Unbilled',
            TimeEntryApprovalStatus::Invoiced => 'Billable · Invoiced',
            TimeEntryApprovalStatus::Paid => 'Billable · Paid',
            default => 'Not ready for billing',
        };
    }

    private function roundedHours(float $hours): float
    {
        return round($hours, 6);
    }
}
