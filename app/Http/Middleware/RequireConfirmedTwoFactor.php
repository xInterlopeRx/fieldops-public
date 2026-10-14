<?php

namespace App\Http\Middleware;

use App\Models\InstallationClaim;
use App\Services\TrustedDeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireConfirmedTwoFactor
{
    public function __construct(private readonly TrustedDeviceService $trustedDevices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->status === 'inactive') {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return to_route('login')->withCookie($this->trustedDevices->forgetCookie());
        }

        $installationClaim = InstallationClaim::query()->whereKey(1)->first();

        if ($installationClaim?->claimed_at === null) {
            return $next($request);
        }

        if ($request->routeIs('profile.edit', 'profile.update', 'profile.trusted-devices.revoke', 'logout.all')) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return to_route('profile.edit')
                ->with('status', 'Set up and confirm two-factor authentication before using FieldOps.');
        }

        if ($this->trustedDevices->sessionIsVerified($request, $user)) {
            return $next($request);
        }

        if ($this->trustedDevices->isTrusted($user, $request)) {
            $this->trustedDevices->markSessionVerified($request, $user);

            return $next($request);
        }

        $userId = $user->getKey();
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put([
            'login.id' => $userId,
            'login.remember' => false,
            'url.intended' => route('calendar'),
        ]);

        return to_route('two-factor.login')
            ->with('status', 'Verify your authenticator code to continue with FieldOps.')
            ->withCookie($this->trustedDevices->forgetCookie());
    }
}
