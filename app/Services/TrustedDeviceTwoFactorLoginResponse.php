<?php

namespace App\Services;

use App\Models\User;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

class TrustedDeviceTwoFactorLoginResponse extends TwoFactorLoginResponse
{
    public function __construct(private readonly TrustedDeviceService $trustedDevices) {}

    public function toResponse($request): Response
    {
        $response = parent::toResponse($request);
        $user = $request->user();

        if ($user instanceof User) {
            $this->trustedDevices->markSessionVerified($request, $user);
        }

        if (! $request->boolean('trust_device')
            || ! $request->filled('code')
            || $request->filled('recovery_code')
            || ! $user instanceof User) {
            return $response;
        }

        return $response->withCookie($this->trustedDevices->cookie($this->trustedDevices->issue($user)));
    }
}
