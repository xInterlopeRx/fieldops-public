<?php

namespace App\Http\Controllers;

use App\Http\Requests\OpenAdminOnboardingAreaRequest;
use App\Services\AdminOnboardingAreaAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class AdminOnboardingAreaAccessController extends Controller
{
    public function show(Request $request, AdminOnboardingAreaAccess $access): RedirectResponse|InertiaResponse
    {
        if ($access->isUnlocked($request, $request->user(), recordActivity: true)) {
            return to_route('admin.onboarding');
        }

        if (! $request->session()->has(AdminOnboardingAreaAccess::INTENDED_ROUTE_KEY)) {
            $request->session()->put(AdminOnboardingAreaAccess::INTENDED_ROUTE_KEY, 'admin.onboarding');
        }

        return Inertia::render('Admin/OnboardingAreaAccess');
    }

    public function store(OpenAdminOnboardingAreaRequest $request, AdminOnboardingAreaAccess $access): RedirectResponse
    {
        $landingRoute = $access->safeLandingRoute(
            $request->session()->pull(AdminOnboardingAreaAccess::INTENDED_ROUTE_KEY),
        );

        $request->session()->regenerate();
        $access->grant($request, $request->user());

        return to_route($landingRoute);
    }
}
