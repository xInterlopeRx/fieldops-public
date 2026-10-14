<?php

namespace App\Http\Controllers;

use App\Models\TimeEntry;
use App\Services\TimeTrackingDashboardMetricsService;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdminTimeTrackingController extends Controller
{
    public function dashboard(TimeTrackingDashboardMetricsService $metrics): Response
    {
        Gate::authorize('reviewAny', TimeEntry::class);

        return Inertia::render('Admin/TimeTracking/Dashboard', [
            'metrics' => $metrics->metrics(),
        ]);
    }

    public function exceptions(TimeTrackingDashboardMetricsService $metrics): Response
    {
        Gate::authorize('reviewAny', TimeEntry::class);

        return Inertia::render('Admin/TimeTracking/Exceptions', [
            'entries' => $metrics->exceptions(),
            'metrics' => $metrics->metrics(),
        ]);
    }
}
