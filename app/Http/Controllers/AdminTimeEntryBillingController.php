<?php

namespace App\Http\Controllers;

use App\Http\Requests\BillingReportRequest;
use App\Http\Requests\BulkMarkTimeEntriesInvoicedRequest;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryBillingReportService;
use App\Services\TimeEntryBillingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AdminTimeEntryBillingController extends Controller
{
    public function index(BillingReportRequest $request, TimeEntryBillingReportService $reports): InertiaResponse
    {
        Gate::authorize('reviewAny', TimeEntry::class);

        $filters = $request->validated();

        return Inertia::render('Admin/TimeTracking/Billing', [
            'report' => $reports->build($filters['date_from'], $filters['date_to'], $request->user()),
            'filters' => $filters,
            'status' => session('status'),
        ]);
    }

    public function markInvoiced(
        Request $request,
        TimeEntry $timeEntry,
        TimeEntryBillingService $billing,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $billing->markInvoiced($actor, $timeEntry);

        return back()->with('status', 'Time entry marked Invoiced locally. No QuickBooks record was changed.');
    }

    public function bulkMarkInvoiced(
        BulkMarkTimeEntriesInvoicedRequest $request,
        TimeEntryBillingService $billing,
    ): RedirectResponse {
        /** @var User $actor */
        $actor = $request->user();
        $billing->bulkMarkInvoiced($actor, array_map('intval', $request->validated('ids')));

        return back()->with('status', 'Selected time entries marked Invoiced locally. No QuickBooks records were changed.');
    }
}
