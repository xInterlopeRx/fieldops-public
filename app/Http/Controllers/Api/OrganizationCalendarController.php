<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Visit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class OrganizationCalendarController extends Controller
{
    /**
     * Fetch visits for the authenticated user's calendar.
     */
    public function getVisits(Request $request)
    {
        $userId = Auth::id();

        // Date range from query params, default to current month if not provided
        $startDate = $request->query('start_date', now()->startOfMonth());
        $endDate = $request->query('end_date', now()->endOfMonth());

        /**
         * Query logic:
         * Find visits where the user is directly assigned OR
         * find visits belonging to work orders that are assigned to this user.
         */
        $visits = Visit::where(function ($query) use ($userId) {
            // Case A: Directly assigned to visit
            $query->whereHas('assignedUsers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });

            // OR
            $query->orWhereHas('workOrder.assignedUsers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        })
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['workOrder.site.customer', 'assignedUsers']) // Eager load for UI context
            ->orderBy('scheduled_at')
            ->get();

        return response()->json([
            'data' => $visits,
            'meta' => [
                'count' => $visits->count(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }

    /**
     * Render the calendar page.
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        $startDate = $request->query('start_date', now()->startOfMonth());
        $endDate = $request->query('end_date', now()->endOfMonth());

        /**
         * Query logic:
         * Find visits where the user is directly assigned OR
         * find visits belonging to work orders that are assigned to this user.
         */
        $visits = Visit::where(function ($query) use ($userId) {
            // Case A: Directly assigned to visit
            $query->whereHas('assignedUsers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });

            // OR
            $query->orWhereHas('workOrder.assignedUsers', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });
        })
            ->whereBetween('scheduled_at', [$startDate, $endDate])
            ->with(['workOrder.site.customer', 'assignedUsers']) // Eager load for UI context
            ->orderBy('scheduled_at')
            ->get();

        return Inertia::render('Calendar', [
            'initialData' => $visits,
        ]);
    }
}
