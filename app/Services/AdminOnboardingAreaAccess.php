<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AdminOnboardingAreaAccess
{
    public const SESSION_KEY = 'admin_onboarding_area_access';

    public const INTENDED_ROUTE_KEY = 'admin_onboarding_area_intended_route';

    public const IDLE_TIMEOUT_SECONDS = 3600;

    public function grant(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_KEY, [
            'user_id' => (string) $user->getKey(),
            'last_activity_at' => now()->timestamp,
            'password_hash' => hash('sha256', (string) $user->getAuthPassword()),
            'two_factor_secret_hash' => hash('sha256', (string) $user->getRawOriginal('two_factor_secret')),
        ]);
    }

    public function isUnlocked(Request $request, ?User $user, bool $recordActivity = false): bool
    {
        $access = $request->session()->get(self::SESSION_KEY);

        if (! is_array($access) || $user === null || $user->status !== 'active') {
            $request->session()->forget(self::SESSION_KEY);

            return false;
        }

        $lastActivityAt = $access['last_activity_at'] ?? null;
        $passwordHash = hash('sha256', (string) $user->getAuthPassword());
        $secretHash = hash('sha256', (string) $user->getRawOriginal('two_factor_secret'));

        if ((string) ($access['user_id'] ?? '') !== (string) $user->getKey()
            || ! is_int($lastActivityAt)
            || now()->timestamp - $lastActivityAt < 0
            || now()->timestamp - $lastActivityAt >= self::IDLE_TIMEOUT_SECONDS
            || ! is_string($access['password_hash'] ?? null)
            || ! hash_equals($passwordHash, $access['password_hash'])
            || ! is_string($access['two_factor_secret_hash'] ?? null)
            || ! hash_equals($secretHash, $access['two_factor_secret_hash'])) {
            $request->session()->forget(self::SESSION_KEY);

            return false;
        }

        if ($recordActivity) {
            $access['last_activity_at'] = now()->timestamp;
            $request->session()->put(self::SESSION_KEY, $access);
        }

        return true;
    }

    public function landingRouteFor(string $routeName): string
    {
        return match (true) {
            str_starts_with($routeName, 'admin.integrations.quickbooks.') => 'admin.integrations.quickbooks.show',
            str_starts_with($routeName, 'admin.integrations.bitwarden.') => 'admin.integrations.bitwarden.show',
            str_starts_with($routeName, 'admin.integrations.microsoft-graph.') => 'admin.integrations.microsoft-graph.onboarding-targets.show',
            default => 'admin.onboarding',
        };
    }

    public function safeLandingRoute(?string $routeName): string
    {
        return in_array($routeName, [
            'admin.integrations.quickbooks.show',
            'admin.integrations.bitwarden.show',
            'admin.integrations.microsoft-graph.onboarding-targets.show',
            'admin.onboarding',
        ], true) ? $routeName : 'admin.onboarding';
    }
}
