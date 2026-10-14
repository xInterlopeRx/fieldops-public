<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Auth\StatefulGuard;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Laravel\Fortify\TwoFactorAuthenticatable;

class RedirectIfTwoFactorAuthenticatableWithTrustedDevice extends RedirectIfTwoFactorAuthenticatable
{
    public function __construct(
        StatefulGuard $guard,
        LoginRateLimiter $limiter,
        private readonly TrustedDeviceService $trustedDevices,
    ) {
        parent::__construct($guard, $limiter);
    }

    public function handle($request, $next)
    {
        $user = $this->validateCredentials($request);
        $hasTwoFactorSecret = $user->two_factor_secret
            && in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true);

        if (Fortify::confirmsTwoFactorAuthentication()) {
            $requiresChallenge = $hasTwoFactorSecret && $user->two_factor_confirmed_at !== null;
        } else {
            $requiresChallenge = (bool) $hasTwoFactorSecret;
        }

        if (! $requiresChallenge) {
            return $next($request);
        }

        if ($this->trustedDevices->isTrusted($user, $request)) {
            $response = $next($request);
            $authenticatedUser = $request->user();

            if ($authenticatedUser instanceof User && $authenticatedUser->is($user)) {
                $this->trustedDevices->markSessionVerified($request, $user);
            }

            return $response;
        }

        return $this->twoFactorChallengeResponse($request, $user);
    }
}
