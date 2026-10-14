<?php

namespace App\Services;

use App\Models\User;
use Laravel\Fortify\Http\Responses\TwoFactorConfirmedResponse;
use Symfony\Component\HttpFoundation\Response;

class TrustedDeviceTwoFactorConfirmedResponse extends TwoFactorConfirmedResponse
{
    public function __construct(
        private readonly TrustedDeviceService $trustedDevices,
        private readonly ApplicationSetupStepService $setupSteps,
    ) {}

    public function toResponse($request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->trustedDevices->markSessionVerified($request, $user);
            $this->setupSteps->markFirstAdminReadyIfEligible($user);
        }

        return parent::toResponse($request);
    }
}
