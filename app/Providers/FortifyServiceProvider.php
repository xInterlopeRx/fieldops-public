<?php

namespace App\Providers;

use App\Models\User;
use App\Services\RedirectIfTwoFactorAuthenticatableWithTrustedDevice;
use App\Services\TrustedDeviceTwoFactorConfirmedResponse;
use App\Services\TrustedDeviceTwoFactorLoginResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(TwoFactorConfirmedResponse::class, TrustedDeviceTwoFactorConfirmedResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, TrustedDeviceTwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatableWithTrustedDevice::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $request->merge(['remember' => false]);

            $user = User::query()
                ->where('email', $request->string('email')->toString())
                ->where('status', 'active')
                ->first();

            if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }

            return $user;
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(5)->by(
                (string) $request->session()->get('login.id', 'unknown').'|'.$request->ip(),
            );
        });
    }
}
