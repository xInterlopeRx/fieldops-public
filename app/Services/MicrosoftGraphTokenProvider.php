<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\MicrosoftGraphOnboardingTarget;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Encryption\DecryptException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MicrosoftGraphTokenProvider
{
    private const SCOPE = 'https://graph.microsoft.com/.default';

    private const AUTHORITY = 'https://login.microsoftonline.com';

    public function __construct(
        private readonly BwsSecretReader $bwsSecretReader,
        private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget,
    ) {}

    public function accessToken(): string
    {
        $activeTarget = $this->runtimeTarget->activeTarget();

        if ($this->runtimeTarget->requiresManagedTarget() && $activeTarget === null) {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        try {
            $tokenContext = $this->tokenContext($activeTarget?->tenant_id);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason !== 'graph_configuration_incomplete') {
                throw $exception;
            }

            $configuredTenantId = $activeTarget?->tenant_id ?? config('services.microsoft_graph.tenant_id');
            $tenantId = is_string($configuredTenantId) && trim($configuredTenantId) !== ''
                ? trim($configuredTenantId)
                : 'not configured';
            $debugContext = $this->tokenFailureDebugContext($tenantId, null, '', $exception->reason);

            throw new MicrosoftGraphApiException($exception->reason, $exception->httpStatus, $exception->retryAfterSeconds, $debugContext);
        }

        return $this->fetchAccessToken($tokenContext, $activeTarget?->client_id);
    }

    public function accessTokenForOnboardingTarget(MicrosoftGraphOnboardingTarget $target): string
    {
        if (! $target->exists
            || $target->environment !== app()->environment()
            || ! Str::isUuid($target->tenant_id)
            || ! Str::isUuid($target->client_id)) {
            throw new MicrosoftGraphApiException('graph_onboarding_target_unavailable', 503);
        }

        return $this->fetchAccessToken(
            $this->tokenContext($target->tenant_id, allowOnboardingRead: true),
            $target->client_id,
        );
    }

    /** @param array{tenant_id: string, connection: BitwardenSecretManagerConnection} $tokenContext */
    private function fetchAccessToken(array $tokenContext, ?string $expectedClientId): string
    {
        $cacheKey = $this->cacheKey($tokenContext, $expectedClientId);

        try {
            return Cache::lock($cacheKey.':lock', 30)->block(10, function () use ($tokenContext, $cacheKey, $expectedClientId): string {
                $cachedToken = $this->cachedToken($cacheKey);

                if ($cachedToken !== null) {
                    $this->logSuccessfulTokenAcquisition($cachedToken, $tokenContext['tenant_id'], 'cache');

                    return $cachedToken;
                }

                $credentials = $this->credentials($tokenContext, $expectedClientId);

                try {
                    $response = Http::asForm()
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(15)
                        ->post(self::AUTHORITY.'/'.rawurlencode($credentials['tenant_id']).'/oauth2/v2.0/token', [
                            'client_id' => $credentials['client_id'],
                            'client_secret' => $credentials['client_secret'],
                            'scope' => self::SCOPE,
                            'grant_type' => 'client_credentials',
                        ]);
                } catch (\Throwable) {
                    $debugContext = $this->tokenFailureDebugContext(
                        $credentials['tenant_id'],
                        null,
                        $credentials['client_secret'],
                        'graph_auth_unavailable',
                    );

                    throw new MicrosoftGraphApiException('graph_auth_unavailable', 503, null, $debugContext);
                }

                if (! $response->successful()) {
                    $retryAfter = $response->status() === 429 && is_numeric($response->header('Retry-After'))
                        ? min(3600, max(1, (int) $response->header('Retry-After')))
                        : null;
                    $debugContext = $this->tokenFailureDebugContext(
                        $credentials['tenant_id'],
                        $response,
                        $credentials['client_secret'],
                        'graph_auth_failed',
                    );

                    throw new MicrosoftGraphApiException('graph_auth_failed', 503, $retryAfter, $debugContext);
                }

                $payload = $response->json();
                $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
                $expiresIn = is_array($payload) ? ($payload['expires_in'] ?? null) : null;

                if (! is_string($accessToken) || $accessToken === '' || ! is_numeric($expiresIn) || (int) $expiresIn < 1) {
                    $debugContext = $this->tokenFailureDebugContext(
                        $credentials['tenant_id'],
                        $response,
                        $credentials['client_secret'],
                        'graph_invalid_token_response',
                    );

                    throw new MicrosoftGraphApiException('graph_invalid_token_response', 502, null, $debugContext);
                }

                $cacheSeconds = max(1, (int) $expiresIn - 60);
                Cache::put($cacheKey, Crypt::encryptString($accessToken), now()->addSeconds($cacheSeconds));
                $this->logSuccessfulTokenAcquisition($accessToken, $credentials['tenant_id'], 'aad', (int) $expiresIn);

                return $accessToken;
            });
        } catch (LockTimeoutException) {
            $debugContext = $this->tokenFailureDebugContext(
                $tokenContext['tenant_id'],
                null,
                '',
                'graph_auth_busy',
            );

            throw new MicrosoftGraphApiException('graph_auth_busy', 503, null, $debugContext);
        }
    }

    public function forgetToken(string $token): void
    {
        $activeTarget = $this->runtimeTarget->activeTarget();

        if ($this->runtimeTarget->requiresManagedTarget() && $activeTarget === null) {
            return;
        }

        $cacheKey = $this->cacheKey(
            $this->tokenContext($activeTarget?->tenant_id),
            $activeTarget?->client_id,
        );
        $cachedToken = $this->cachedToken($cacheKey);

        if ($cachedToken !== null && hash_equals($cachedToken, $token)) {
            Cache::forget($cacheKey);
        }
    }

    public function forgetOnboardingToken(MicrosoftGraphOnboardingTarget $target, string $token): void
    {
        $tokenContext = $this->tokenContext($target->tenant_id, allowOnboardingRead: true);
        $cacheKey = $this->cacheKey($tokenContext, $target->client_id);
        $cachedToken = $this->cachedToken($cacheKey);

        if ($cachedToken !== null && hash_equals($cachedToken, $token)) {
            Cache::forget($cacheKey);
        }
    }

    /** @return array{tenant_id: string, connection: BitwardenSecretManagerConnection} */
    private function tokenContext(?string $tenantOverride = null, bool $allowOnboardingRead = false): array
    {
        if (
            ! $allowOnboardingRead
            &&
            ! config('services.microsoft_graph.writes_enabled')
            && ! config('services.microsoft_graph.calendar_writes_enabled')
            && ! config('services.microsoft_graph.contacts_sync_enabled')
            && ! config('services.microsoft_graph.sharepoint_enabled')
        ) {
            throw new MicrosoftGraphApiException('graph_operations_disabled', 503);
        }

        if (! $allowOnboardingRead && $tenantOverride === null && $this->runtimeTarget->requiresManagedTarget()) {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        $tenantId = $tenantOverride ?? config('services.microsoft_graph.tenant_id');
        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        if (! is_string($tenantId) || trim($tenantId) === ''
            || ! $connection instanceof BitwardenSecretManagerConnection
            || ! $connection->isVerifiedForGraph()) {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        return [
            'tenant_id' => trim($tenantId),
            'connection' => $connection,
        ];
    }

    /** @param array{tenant_id: string, connection: BitwardenSecretManagerConnection} $tokenContext
     * @return array{tenant_id: string, client_id: string, client_secret: string}
     */
    private function credentials(array $tokenContext, ?string $expectedClientId): array
    {
        try {
            $appCredentials = $this->bwsSecretReader->read($tokenContext['connection'], [
                'MICROSOFT_GRAPH_CLIENT_ID',
                'MICROSOFT_GRAPH_CLIENT_SECRET',
            ]);
        } catch (\RuntimeException) {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        $clientId = $appCredentials['MICROSOFT_GRAPH_CLIENT_ID'] ?? null;
        $clientSecret = $appCredentials['MICROSOFT_GRAPH_CLIENT_SECRET'] ?? null;

        if (! is_string($clientId) || trim($clientId) === ''
            || ! is_string($clientSecret) || trim($clientSecret) === '') {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        if ($expectedClientId !== null
            && ! hash_equals(strtolower($expectedClientId), strtolower($clientId))) {
            throw new MicrosoftGraphApiException('graph_onboarding_client_id_mismatch', 422);
        }

        return [
            'tenant_id' => $tokenContext['tenant_id'],
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    /** @param array{tenant_id: string, connection: BitwardenSecretManagerConnection} $tokenContext */
    private function cacheKey(array $tokenContext, ?string $expectedClientId): string
    {
        return 'microsoft-graph-token-'.hash('sha256', implode("\0", [
            self::AUTHORITY,
            $tokenContext['tenant_id'],
            $tokenContext['connection']->environment,
            (string) $tokenContext['connection']->getKey(),
            (string) $tokenContext['connection']->getRawOriginal('updated_at'),
            $tokenContext['connection']->organization_id,
            $tokenContext['connection']->project_id,
            self::SCOPE,
            $expectedClientId ?? 'runtime-configured-client',
        ]));
    }

    private function logSuccessfulTokenAcquisition(string $token, string $tenantId, string $source, ?int $expiresIn = null): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $claims = $this->tokenClaims($token);
        $diagnosticClaims = $this->safeTokenClaims($claims);
        $expiration = is_numeric($claims['exp'] ?? null)
            ? gmdate('Y-m-d\TH:i:s\Z', (int) $claims['exp'])
            : ($expiresIn !== null ? now()->utc()->addSeconds($expiresIn)->toIso8601String() : null);

        Log::debug('Microsoft Graph token acquired', [
            'token_acquired' => true,
            'token_source' => $source,
            'token_expiration' => $expiration,
            'audience_claim' => $diagnosticClaims['aud'],
            'tenant_id' => $tenantId,
            'token_tenant_id' => $diagnosticClaims['tid'],
            ...$diagnosticClaims,
        ]);
    }

    /** @return array{aud: string|list<string>|null, tid: string|null, appid: string|null, roles: list<string>, scp: string|null}|null */
    public function localDiagnosticClaims(string $token): ?array
    {
        if (! app()->environment('local')) {
            return null;
        }

        return $this->safeTokenClaims($this->tokenClaims($token));
    }

    /** @param array<string, mixed> $claims
     * @return array{aud: string|list<string>|null, tid: string|null, appid: string|null, roles: list<string>, scp: string|null}
     */
    private function safeTokenClaims(array $claims): array
    {
        $audience = $claims['aud'] ?? null;

        return [
            'aud' => is_string($audience)
                ? $this->sanitizeDebugValue($audience, 512)
                : (is_array($audience) ? $this->stringClaimList($audience, 512) : null),
            'tid' => is_string($claims['tid'] ?? null) ? $this->sanitizeDebugValue($claims['tid'], 256) : null,
            'appid' => is_string($claims['appid'] ?? null) ? $this->sanitizeDebugValue($claims['appid'], 256) : null,
            'roles' => is_array($claims['roles'] ?? null) ? $this->stringClaimList($claims['roles'], 256) : [],
            'scp' => is_string($claims['scp'] ?? null) ? $this->sanitizeDebugValue($claims['scp'], 2048) : null,
        ];
    }

    /** @param array<mixed> $claims
     * @return list<string>
     */
    private function stringClaimList(array $claims, int $maximumLength): array
    {
        $values = [];

        foreach (array_slice($claims, 0, 50) as $claim) {
            if (is_string($claim)) {
                $values[] = $this->sanitizeDebugValue($claim, $maximumLength);
            }
        }

        return $values;
    }

    /**
     * Log and return only safe AAD failure fields. Token request fields and response tokens are never copied.
     *
     * @return array{failure_category: string, token_acquired: false, token_source: string, token_expiration: null, audience_claim: null, aud: null, tid: null, appid: null, roles: list<never>, scp: null, tenant_id: string, token_tenant_id: null, http_status: int|null, aad_error_code: string|null, aad_error_description: string|null, failure_reason: string}|null
     */
    private function tokenFailureDebugContext(
        string $tenantId,
        ?Response $response,
        string $clientSecret,
        string $failureReason,
    ): ?array {
        if (! app()->environment('local')) {
            return null;
        }

        $payload = $response?->json();
        $errorCode = is_array($payload) && is_string($payload['error'] ?? null)
            ? $this->sanitizeDebugValue($payload['error'], 256)
            : null;
        $errorDescription = is_array($payload) && is_string($payload['error_description'] ?? null)
            ? $payload['error_description']
            : null;

        if ($errorDescription !== null) {
            if ($clientSecret !== '') {
                $errorDescription = str_replace($clientSecret, '[redacted]', $errorDescription);
            }

            $errorDescription = $this->sanitizeDebugValue($errorDescription, 2048);
        }

        $context = [
            'failure_category' => 'authentication_failure',
            'token_acquired' => false,
            'token_source' => 'aad',
            'token_expiration' => null,
            'audience_claim' => null,
            'aud' => null,
            'tid' => null,
            'appid' => null,
            'roles' => [],
            'scp' => null,
            'tenant_id' => $tenantId,
            'token_tenant_id' => null,
            'http_status' => $response?->status(),
            'aad_error_code' => $errorCode,
            'aad_error_description' => $errorDescription,
            'failure_reason' => $failureReason,
        ];

        Log::debug('Microsoft Graph token acquisition failed', $context);

        return $context;
    }

    /** @return array<string, mixed> */
    private function tokenClaims(string $token): array
    {
        // Decode only for local diagnostics; never use unverified claims for authorization.
        $segments = explode('.', $token);
        $encodedClaims = $segments[1] ?? null;

        if (! is_string($encodedClaims) || $encodedClaims === '') {
            return [];
        }

        $base64 = strtr($encodedClaims, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        $claims = is_string($json) ? json_decode($json, true) : null;

        return is_array($claims) ? $claims : [];
    }

    private function sanitizeDebugValue(string $value, int $maximumLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';

        return substr(trim($value), 0, $maximumLength);
    }

    private function cachedToken(string $cacheKey): ?string
    {
        $encryptedToken = Cache::get($cacheKey);

        if (! is_string($encryptedToken)) {
            return null;
        }

        try {
            return Crypt::decryptString($encryptedToken);
        } catch (DecryptException) {
            Cache::forget($cacheKey);

            return null;
        }
    }
}
