<?php

namespace App\Http\Controllers;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Services\TimeTrackingDashboardMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdminTimeTrackingController extends Controller
{
    public function dashboard(Request $request, TimeTrackingDashboardMetricsService $metrics): Response
    {
        Gate::authorize('viewAny', TimeEntry::class);

        if (! $request->user()->can('viewFinancial', TimeEntry::class)) {
            $viewerId = $request->user()->getKey();
            $entries = TimeEntry::query()
                ->visibleTo($request->user())
                ->with([
                    'user:id,name',
                    'customer:id,name,company_name',
                    'project:id,project_number,name',
                    'job:id,job_number,title',
                ])
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString()
                ->through(function (TimeEntry $entry) use ($viewerId): array {
                    $isRunning = $entry->ended_at === null;

                    return [
                        'id' => $entry->getKey(),
                        'startedAt' => $entry->started_at?->toIso8601String(),
                        'durationMinutes' => $isRunning
                            ? (int) $entry->started_at?->diffInMinutes(now())
                            : $entry->duration_minutes,
                        'isRunning' => $isRunning,
                        'isOwn' => (int) $entry->user_id === (int) $viewerId,
                        'technician' => $entry->user?->name ?? 'Former user',
                        'customer' => $entry->customer?->company_name ?: ($entry->customer?->name ?? 'Unavailable customer'),
                        'project' => $entry->project === null
                            ? null
                            : trim(($entry->project->project_number ? $entry->project->project_number.' · ' : '').$entry->project->name),
                        'job' => $entry->job === null
                            ? null
                            : trim(($entry->job->job_number ? $entry->job->job_number.' · ' : '').$entry->job->title),
                        'activityLabel' => $entry->activity_type->label(),
                        'statusLabel' => $isRunning
                            ? 'In progress'
                            : ($entry->approval_status === TimeEntryApprovalStatus::Draft ? 'Needs review' : 'Reviewed'),
                    ];
                });

            return Inertia::render('Admin/TimeTracking/Team', [
                'entries' => $entries,
            ]);
        }

        return Inertia::render('Admin/TimeTracking/Dashboard', [
            'metrics' => $metrics->metrics(),
        ]);
    }

    public function exceptions(TimeTrackingDashboardMetricsService $metrics): Response
    {
        Gate::authorize('viewExceptions', TimeEntry::class);

        return Inertia::render('Admin/TimeTracking/Exceptions', [
            'entries' => $metrics->exceptions(),
            'metrics' => $metrics->metrics(),
        ]);
    }
}
