<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFirstAdminRequest;
use App\Models\InstallationClaim;
use App\Services\BootstrapInstallation;
use App\Services\SmtpBootstrapConfiguration;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstallationBootstrapController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        if (! $this->claim()->isAvailable()) {
            return to_route('login');
        }

        return Inertia::render('Auth/FirstAdminBootstrap');
    }

    public function store(
        StoreFirstAdminRequest $request,
        BootstrapInstallation $bootstrap,
        SmtpBootstrapConfiguration $smtp,
    ): RedirectResponse {
        if (! $this->claim()->isAvailable()) {
            return to_route('login');
        }

        $data = $request->validated();
        $smtp->testDelivery($data);
        $user = $bootstrap->claim($data, $smtp);
        $smtp->applyStored();

        Auth::login($user);

        try {
            event(new Registered($user));
        } catch (Throwable) {
            return to_route('verification.notice')
                ->with('status', 'verification-link-send-failed');
        }

        return to_route('verification.notice')
            ->with('status', 'bootstrap-smtp-ready');
    }

    private function claim(): InstallationClaim
    {
        return InstallationClaim::query()->whereKey(1)->firstOrFail();
    }
}
