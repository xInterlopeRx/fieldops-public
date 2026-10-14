<?php

namespace App\Http\Controllers;

use App\Exceptions\BwsProjectReadAccessException;
use App\Http\Requests\StoreBitwardenSecretManagerConnectionRequest;
use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use App\Services\ConfigureBitwardenSecretManagerConnection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BitwardenSecretManagerConnectionController extends Controller
{
    public function show(Request $request): Response
    {
        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', config('app.env'))
            ->first();
        $requiredSecretsVerified = $connection?->isVerifiedForCurrentEnvironment() ?? false;
        $setupState = ApplicationSetupState::query()->whereKey(1)->first();
        $quickBooksOptedIn = $setupState === null
            || ! $setupState->onboarding_required
            || $setupState->quickbooks_sync_opted_in;
        $verificationProfile = $quickBooksOptedIn
            ? BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED
            : BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY;
        $requiredSecretNames = $verificationProfile === BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY
            ? BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES
            : BitwardenSecretManagerConnection::ALL_REQUIRED_SECRET_NAMES;

        return Inertia::render('Admin/BitwardenSecretManagerConnection', [
            'environment' => config('app.env'),
            'apiUrl' => $connection?->api_url ?? config('services.bitwarden.api_url'),
            'identityUrl' => $connection?->identity_url ?? config('services.bitwarden.identity_url'),
            'twoFactorEnabled' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'requiredSecretsVerified' => $requiredSecretsVerified,
            'verificationProfile' => $verificationProfile,
            'requiredSecretNames' => $requiredSecretNames,
            'endpointConfigurationChanged' => $connection !== null && (
                $connection->api_url !== config('services.bitwarden.api_url')
                || $connection->identity_url !== config('services.bitwarden.identity_url')
            ),
            'connection' => $connection === null ? null : [
                'organizationId' => $connection->organization_id,
                'projectId' => $connection->project_id,
                'apiUrl' => $connection->api_url,
                'identityUrl' => $connection->identity_url,
                'verificationStatus' => $connection->verification_status,
                'verificationProfile' => $connection->verification_profile,
                'configuredAt' => $connection->configured_at?->toIso8601String(),
                'lastVerifiedAt' => $connection->last_verified_at?->toIso8601String(),
                'lastErrorCode' => $connection->last_error_code,
            ],
            'status' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function store(
        StoreBitwardenSecretManagerConnectionRequest $request,
        ConfigureBitwardenSecretManagerConnection $configure,
    ): RedirectResponse {
        $data = $request->validated();

        try {
            $configure->configure(
                $request->user(),
                $data['runtime_access_token'],
                $data['organization_id'],
                $data['project_id'],
            );
        } catch (BwsProjectReadAccessException $exception) {
            $message = match ($exception->reason) {
                BwsProjectReadAccessException::HELPER_UNAVAILABLE => 'The local BWS helper is not built. Run docker/bws-helper/build-host.sh, then retry; no BWS request was made and no token was saved.',
                BwsProjectReadAccessException::REQUIRED_SECRETS_INVALID => 'The BWS project is readable, but one or more secrets required for the selected import mode is missing, empty, or invalid. Add the names shown on this page, then retry; no new token was saved.',
                default => 'BWS read access could not be verified. No new token was saved; check the environment project and Can Read machine-account access.',
            };

            return back()->with('error', $message);
        }

        return back()->with('status', 'The Can Read token verified the selected secret profile; the runtime token was saved securely. Values were not displayed or returned.');
    }
}
