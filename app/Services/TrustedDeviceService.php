<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

class TrustedDeviceService
{
    public const COOKIE_NAME = 'fieldops_trusted_device';

    public const SESSION_PROOF_KEY = 'auth.two_factor_proof';

    public const LIFETIME_DAYS = 14;

    private const TOKEN_LENGTH = 80;

    public function issue(User $user): string
    {
        abort_unless($user->status === 'active' && $user->hasEnabledTwoFactorAuthentication(), 403);

        $user->trustedDevices()
            ->where('expires_at', '<=', now())
            ->delete();

        $token = Str::random(self::TOKEN_LENGTH);

        $user->trustedDevices()->create([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::LIFETIME_DAYS),
        ]);

        return $token;
    }

    public function isTrusted(User $user, Request $request): bool
    {
        $user->trustedDevices()
            ->where('expires_at', '<=', now())
            ->delete();

        $token = $request->cookie(self::COOKIE_NAME);

        if (! is_string($token) || strlen($token) !== self::TOKEN_LENGTH) {
            return false;
        }

        $device = $user->trustedDevices()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();

        if (! $device || $user->status !== 'active' || ! $user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        $device->forceFill(['last_used_at' => now()])->save();

        return true;
    }

    public function revokeAll(User $user): int
    {
        return $user->trustedDevices()->delete();
    }

    public function markSessionVerified(Request $request, User $user): void
    {
        $request->session()->put(self::SESSION_PROOF_KEY, $this->sessionProof($user));
    }

    public function sessionIsVerified(Request $request, User $user): bool
    {
        $storedProof = $request->session()->get(self::SESSION_PROOF_KEY);

        return is_string($storedProof)
            && hash_equals($this->sessionProof($user), $storedProof);
    }

    public function cookie(string $token): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE_NAME,
            $token,
            self::LIFETIME_DAYS * 24 * 60,
            '/',
            config('session.domain'),
            true,
            true,
            false,
            'strict',
        );
    }

    public function forgetCookie(): SymfonyCookie
    {
        return Cookie::make(
            self::COOKIE_NAME,
            '',
            -60,
            '/',
            config('session.domain'),
            true,
            true,
            false,
            'strict',
        );
    }

    private function sessionProof(User $user): string
    {
        return hash('sha256', implode("\0", [
            (string) $user->getKey(),
            (string) $user->getRawOriginal('two_factor_secret'),
            (string) $user->getRawOriginal('two_factor_confirmed_at'),
        ]));
    }
}
