<?php

namespace App\Http\Middleware;

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
        $canViewSyncLogs = $request->user()?->can('integrations.manage') ?? false;

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'is_admin' => $request->user()?->isAdmin() ?? false,
                'can_view_sync_logs' => $canViewSyncLogs,
                'onboarding_area_unlocked' => app(AdminOnboardingAreaAccess::class)
                    ->isUnlocked($request, $request->user()),
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
            ],
        ];
    }
}
