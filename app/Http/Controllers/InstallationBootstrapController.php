<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBootstrapSmtpTestRequest;
use App\Http\Requests\StoreFirstAdminRequest;
use App\Models\InstallationClaim;
use App\Services\BootstrapInstallation;
use App\Services\SmtpBootstrapConfiguration;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class InstallationBootstrapController extends Controller
{
    public function create(SmtpBootstrapConfiguration $smtp): Response|RedirectResponse
    {
        if (! $this->claim()->isAvailable()) {
            return to_route('login');
        }

        return Inertia::render('Auth/FirstAdminBootstrap', [
            'smtp_backup' => $smtp->bootstrapSummary(),
        ]);
    }

    public function testSmtp(
        StoreBootstrapSmtpTestRequest $request,
        SmtpBootstrapConfiguration $smtp,
    ): RedirectResponse {
        if (! $this->claim()->isAvailable()) {
            return to_route('login');
        }

        $smtp->testAndStoreBootstrapConfiguration($request->validated());

        return to_route('installation.bootstrap.create');
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

        if (! $smtp->hasMatchingBootstrapTest($data)) {
            throw ValidationException::withMessages([
                'smtp_host' => 'Test and save SMTP settings for this email address before creating the first Admin.',
            ]);
        }

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
