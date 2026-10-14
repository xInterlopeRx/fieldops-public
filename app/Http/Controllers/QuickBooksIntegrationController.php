<?php

namespace App\Http\Controllers;

use App\Exceptions\QuickBooksApiException;
use App\Http\Requests\UpdateQuickBooksSyncControlRequest;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\IntegrationConnection;
use App\Services\ActivityLogService;
use App\Services\ApplicationSetupStepService;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksItemCatalogSyncService;
use App\Services\QuickBooksOAuthService;
use App\Services\QuickBooksSyncControl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use UnexpectedValueException;

class QuickBooksIntegrationController extends Controller
{
    private const OAUTH_STATE_SESSION_KEY = 'quickbooks.oauth_state';

    private const OAUTH_STATE_TTL_SECONDS = 600;

    public function show(
        Request $request,
        QuickBooksOAuthService $quickBooks,
        QuickBooksApiClient $quickBooksApi,
        QuickBooksItemCatalogSyncService $itemCatalog,
        QuickBooksSyncControl $syncControl,
    ): Response {
        $missingConfiguration = $quickBooks->missingConfiguration();
        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->first();
        $setupState = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $quickBooksOptedIn = ! $setupState->onboarding_required || $setupState->quickbooks_sync_opted_in;
        $bwsConnection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();
        $syncEnableBlocker = match (true) {
            ! $quickBooksOptedIn => 'Opt into QuickBooks in First-run setup before enabling synchronization.',
            ! $quickBooks->apiModeEnabled() => 'Select QuickBooks API as the Customer/Site source before enabling synchronization.',
            $setupState->onboarding_required && ! ($bwsConnection?->isVerifiedForCurrentEnvironment() ?? false) => 'Verify both Intuit app credentials and Microsoft Graph credentials in BWS.',
            ! $quickBooksApi->isConnectedForCurrentEnvironment() => 'Connect the intended QuickBooks company before enabling synchronization.',
            default => null,
        };

        return Inertia::render('Admin/QuickBooksIntegration', [
            'configured' => $quickBooksOptedIn && $missingConfiguration === [],
            'apiModeEnabled' => $quickBooks->apiModeEnabled(),
            'environment' => $quickBooks->environment(),
            'redirectUri' => $quickBooks->redirectUri(),
            'oauthRequiresLocalhost' => $this->oauthRequiresLocalhost($request, $quickBooks),
            'missingConfiguration' => $missingConfiguration,
            'connection' => $connection === null ? null : [
                'environment' => $connection->environment,
                'realmId' => $connection->realm_id,
                'connectedAt' => $connection->connected_at?->toIso8601String(),
                'connected' => $quickBooksApi->isConnectedForCurrentEnvironment(),
            ],
            'quickBooksSyncEnabled' => $syncControl->isEnabled(),
            'quickBooksOptedIn' => $quickBooksOptedIn,
            'quickBooksSyncEnableBlocker' => $syncEnableBlocker,
            'quickBooksSyncControlAvailable' => $syncControl->canControlQueue(),
            'quickBooksPausedJobs' => $syncControl->heldJobsSummary(),
            'itemCatalog' => $itemCatalog->snapshot(),
            'status' => session('status'),
            'error' => session('error'),
        ]);
    }

    public function updateSyncControl(
        UpdateQuickBooksSyncControlRequest $request,
        QuickBooksSyncControl $syncControl,
        ActivityLogService $activityLog,
        QuickBooksApiClient $quickBooks,
    ): RedirectResponse {
        try {
            $changed = $syncControl->setEnabled(
                $request->boolean('enabled'),
                $request->user(),
                $activityLog,
                $quickBooks,
            );
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        $status = match (true) {
            ! $changed => 'QuickBooks sync was already in that state.',
            $request->boolean('enabled') => 'QuickBooks sync enabled. Held QuickBooks jobs have resumed.',
            default => 'QuickBooks sync paused. Queued QuickBooks jobs are held; in-flight requests may finish.',
        };

        return to_route('admin.integrations.quickbooks.show')->with('status', $status);
    }

    public function syncItems(Request $request, QuickBooksItemCatalogSyncService $itemCatalog): RedirectResponse
    {
        $request->validate([
            'confirm_sync' => ['required', 'accepted'],
            'password' => ['required', 'current_password'],
        ]);

        try {
            $run = $itemCatalog->start($request->user(), allowRefresh: true);
        } catch (QuickBooksApiException $exception) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks Item catalog sync could not start. Check the connection and retry ('.$exception->reason.').');
        } catch (ConnectionException) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks Item catalog sync could not reach Intuit. No local Item records were changed; retry when the connection is available.');
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return to_route('admin.integrations.quickbooks.show')->with(
            'status',
            $run->status === 'in_progress'
                ? 'QuickBooks Item catalog sync was queued. Progress will refresh while it runs.'
                : 'QuickBooks Item catalog sync is already in progress.',
        );
    }

    public function redirect(
        Request $request,
        QuickBooksOAuthService $quickBooks,
        ApplicationSetupStepService $setupSteps,
    ): RedirectResponse {
        $setupState = ApplicationSetupState::query()->whereKey(1)->firstOrFail();

        if ($setupState->onboarding_required && ! $setupState->quickbooks_sync_opted_in) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'Opt into QuickBooks in First-run setup before connecting a company.');
        }

        if (! $quickBooks->apiModeEnabled()) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks API access is disabled in CSV import mode. Imported records remain local-only.');
        }

        if (! $quickBooks->isConfigured()) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'Verify the BWS runtime connection and complete the QuickBooks configuration before connecting.');
        }

        if ($this->oauthRequiresLocalhost($request, $quickBooks)) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'This QuickBooks callback is localhost-only. Start or reconnect QuickBooks from the host computer at localhost; other devices need a registered HTTPS callback.');
        }

        $state = Str::random(64);

        try {
            $authorizationUrl = $quickBooks->authorizationUrl($state);
        } catch (UnexpectedValueException) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks app credentials could not be resolved from the verified BWS project.');
        }

        $setupSteps->begin(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user());

        $request->session()->put(self::OAUTH_STATE_SESSION_KEY, [
            'value' => $state,
            'created_at' => time(),
        ]);

        return redirect()->away($authorizationUrl);
    }

    public function callback(
        Request $request,
        QuickBooksOAuthService $quickBooks,
        ApplicationSetupStepService $setupSteps,
    ): RedirectResponse {
        if (! $quickBooks->apiModeEnabled()) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks API access is disabled in CSV import mode.');
        }

        $pendingAuthorization = $request->session()->pull(self::OAUTH_STATE_SESSION_KEY);
        $expectedState = is_array($pendingAuthorization)
            ? ($pendingAuthorization['value'] ?? null)
            : null;
        $createdAt = is_array($pendingAuthorization)
            ? ($pendingAuthorization['created_at'] ?? null)
            : null;
        $returnedState = $request->query('state');
        $now = time();

        if (
            ! is_string($expectedState) || $expectedState === '' ||
            ! is_string($returnedState) || ! hash_equals($expectedState, $returnedState) ||
            ! is_int($createdAt) || $createdAt > $now || $createdAt < $now - self::OAUTH_STATE_TTL_SECONDS
        ) {
            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'The QuickBooks authorization expired or could not be verified. Please try again.');
        }

        if ($request->query('error') !== null) {
            $setupSteps->fail(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user(), 'quickbooks_authorization_denied');

            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks authorization was cancelled or denied.');
        }

        $code = $request->query('code');
        $realmId = $request->query('realmId');

        if (
            ! is_string($code) || trim($code) === '' || strlen($code) > 4096 ||
            ! is_string($realmId) || trim($realmId) === '' || strlen($realmId) > 255
        ) {
            $setupSteps->fail(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user(), 'quickbooks_authorization_response_invalid');

            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks returned an incomplete authorization response. Please try again.');
        }

        if (! $quickBooks->isConfigured()) {
            $setupSteps->fail(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user(), 'quickbooks_configuration_unavailable');

            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks OAuth configuration is incomplete.');
        }

        try {
            $tokens = $quickBooks->exchangeAuthorizationCode($code);
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            $setupSteps->fail(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user(), 'quickbooks_token_exchange_failed');

            return to_route('admin.integrations.quickbooks.show')
                ->with('error', 'QuickBooks authorization could not be completed. Check the sandbox credentials and try again.');
        }

        $connection = new IntegrationConnection;
        $connection->fill([
            'provider' => 'quickbooks',
            'environment' => $quickBooks->environment(),
            'realm_id' => $realmId,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'access_token_expires_at' => $tokens['access_token_expires_at'],
            'refresh_token_expires_at' => $tokens['refresh_token_expires_at'],
            'connected_by_user_id' => $request->user()->id,
            'connected_at' => now(),
        ]);
        $connection->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $connectionAttributes = $connection->getAttributes();

        DB::table('integration_connections')->upsert(
            [$connectionAttributes],
            ['provider'],
            array_values(array_diff(array_keys($connectionAttributes), ['provider', 'created_at'])),
        );
        $setupSteps->pass(ApplicationSetupStep::QUICKBOOKS_OAUTH, $request->user());

        return to_route('admin.integrations.quickbooks.show')
            ->with('status', "QuickBooks {$quickBooks->environment()} connection established. Fresh-install Customer/Site import is available from Setup Progress; the Item catalog sync reads existing Items only and creates no QuickBooks records.");
    }

    private function oauthRequiresLocalhost(Request $request, QuickBooksOAuthService $quickBooks): bool
    {
        $redirect = parse_url($quickBooks->redirectUri());
        $callbackHost = is_array($redirect) ? strtolower((string) ($redirect['host'] ?? '')) : '';

        return $callbackHost === 'localhost' && strtolower($request->getHost()) !== 'localhost';
    }
}
