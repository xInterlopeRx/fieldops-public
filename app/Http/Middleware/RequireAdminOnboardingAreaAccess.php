<?php

namespace App\Http\Middleware;

use App\Services\AdminOnboardingAreaAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdminOnboardingAreaAccess
{
    public function __construct(private readonly AdminOnboardingAreaAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->access->isUnlocked($request, $request->user(), recordActivity: true)) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        $request->session()->put(
            AdminOnboardingAreaAccess::INTENDED_ROUTE_KEY,
            $this->access->landingRouteFor(is_string($routeName) ? $routeName : ''),
        );

        return to_route('admin.onboarding.access');
    }
}
