<?php

namespace App\Http\Middleware;

use App\Models\TimeEntry;
use App\Services\AdminOnboardingAreaAccess;
use App\Services\SyncHealthService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $canViewSyncLogs = $user?->can('integrations.view_logs') ?? false;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'is_admin' => $user?->isAdmin() ?? false,
                'has_admin_account_role' => $user?->hasRole('admin') ?? false,
                'can_manage_users' => $user?->can('users.manage') ?? false,
                'can_manage_integrations' => $user?->can('integrations.manage') ?? false,
                'can_view_sync_logs' => $canViewSyncLogs,
                'can_view_time_tracking' => $user?->can('viewAny', TimeEntry::class) ?? false,
                'can_view_financial_time_entries' => $user?->can('viewFinancial', TimeEntry::class) ?? false,
                'can_view_time_entry_exceptions' => $user?->can('viewExceptions', TimeEntry::class) ?? false,
                'can_review_time_entries' => $user?->can('reviewAny', TimeEntry::class) ?? false,
                'onboarding_area_unlocked' => app(AdminOnboardingAreaAccess::class)
                    ->isUnlocked($request, $user),
            ],
            'sync_health' => $canViewSyncLogs ? app(SyncHealthService::class)->summary() : null,
            'work_order_default_status' => config('work_orders.default_status'),
            'smtp_plain_transport_allowed' => app()->environment('local'),
            'work_order_statuses' => collect(config('work_orders.statuses', []))
                ->map(fn (array $status, string $value): array => ['value' => $value, ...$status])
                ->values()
                ->all(),
            'flash' => [
                'invitation_url' => $request->session()->get('invitation_url'),
                'admin_transfer_url' => $request->session()->get('admin_transfer_url'),
                'success' => $request->session()->get('success'),
            ],
        ];
    }
}
