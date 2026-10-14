<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use UnexpectedValueException;

class QuickBooksOAuthService
{
    public function __construct(private readonly BwsSecretReader $bwsSecretReader) {}

    /** @return array<int, string> */
    public function missingConfiguration(): array
    {
        if (! $this->apiModeEnabled()) {
            return ['QuickBooks API is disabled in CSV import mode'];
        }

        return $this->missingConfigurationFor($this->hasVerifiedBwsConnection());
    }

    public function apiModeEnabled(): bool
    {
        return ApplicationSetupState::query()->whereKey(1)->value('quickbooks_import_mode')
            !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV;
    }

    /**
     * @return array<int, string>
     */
    private function missingConfigurationFor(bool $hasVerifiedBwsConnection): array
    {
        $missing = [];

        if (! $hasVerifiedBwsConnection) {
            $missing[] = 'verified BWS runtime reader connection';
        }

        foreach (['QUICKBOOKS_REDIRECT_URI' => config('services.quickbooks.redirect_uri')] as $key => $value) {
            if (! is_string($value) || trim($value) === '') {
                $missing[] = $key;
            }
        }

        if (! in_array($this->environment(), ['sandbox', 'production'], true)) {
            $missing[] = 'QUICKBOOKS_ENVIRONMENT (sandbox or production)';
        }

        return $missing;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfiguration() === [];
    }

    public function environment(): string
    {
        return (string) config('services.quickbooks.environment', 'sandbox');
    }

    public function redirectUri(): string
    {
        return (string) config('services.quickbooks.redirect_uri', '');
    }

    public function authorizationUrl(string $state): string
    {
        if ($this->missingConfiguration() !== []) {
            throw new UnexpectedValueException('QuickBooks OAuth configuration is incomplete.');
        }

        $credentials = $this->resolveApplicationCredentials();

        if ($credentials === null) {
            throw new UnexpectedValueException('QuickBooks BWS app credentials could not be resolved.');
        }

        return config('services.quickbooks.authorization_url').'?'.http_build_query([
            'client_id' => $credentials['client_id'],
            'response_type' => 'code',
            'scope' => config('services.quickbooks.accounting_scope'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at: Carbon,
     *     refresh_token_expires_at: Carbon|null
     * }
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function exchangeAuthorizationCode(string $code): array
    {
        $credentials = $this->resolveApplicationCredentials();

        if ($credentials === null) {
            throw new UnexpectedValueException('QuickBooks OAuth application credentials are unavailable.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                $credentials['client_id'],
                $credentials['client_secret'],
            )
            ->connectTimeout(5)
            ->timeout(15)
            ->post(config('services.quickbooks.token_url'), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ])
            ->throw();

        return $this->parseTokenPayload($response->json());
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at: Carbon,
     *     refresh_token_expires_at: Carbon|null
     * }
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $credentials = $this->resolveApplicationCredentials();

        if ($credentials === null) {
            throw new QuickBooksApiException('bws_credentials_unavailable', 503);
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth(
                $credentials['client_id'],
                $credentials['client_secret'],
            )
            ->connectTimeout(5)
            ->timeout(15)
            ->post(config('services.quickbooks.token_url'), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken,
            ])
            ->throw();

        return $this->parseTokenPayload($response->json());
    }

    /** @return array{client_id: string, client_secret: string}|null */
    private function resolveApplicationCredentials(): ?array
    {
        if (! $this->apiModeEnabled()) {
            return null;
        }

        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        if (! $connection instanceof BitwardenSecretManagerConnection
            || ! $connection->isVerifiedForCurrentEnvironment()) {
            return null;
        }

        try {
            $secrets = $this->bwsSecretReader->read($connection, [
                'INTUIT_CLIENT_ID',
                'INTUIT_CLIENT_SECRET',
            ]);
        } catch (RuntimeException) {
            return null;
        }

        $clientId = $secrets['INTUIT_CLIENT_ID'] ?? null;
        $clientSecret = $secrets['INTUIT_CLIENT_SECRET'] ?? null;

        if (! is_string($clientId) || trim($clientId) === ''
            || ! is_string($clientSecret) || trim($clientSecret) === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    private function hasVerifiedBwsConnection(): bool
    {
        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        return $connection instanceof BitwardenSecretManagerConnection
            && $connection->isVerifiedForCurrentEnvironment();
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at: Carbon,
     *     refresh_token_expires_at: Carbon|null
     * }
     */
    private function parseTokenPayload(mixed $payload): array
    {
        $accessToken = is_array($payload) ? ($payload['access_token'] ?? null) : null;
        $refreshToken = is_array($payload) ? ($payload['refresh_token'] ?? null) : null;
        $accessTokenLifetime = is_array($payload) ? ($payload['expires_in'] ?? null) : null;
        $refreshTokenLifetime = is_array($payload) ? ($payload['x_refresh_token_expires_in'] ?? null) : null;

        if (
            ! is_string($accessToken) || $accessToken === '' ||
            ! is_string($refreshToken) || $refreshToken === '' ||
            ! is_numeric($accessTokenLifetime) || (int) $accessTokenLifetime < 1 ||
            ($refreshTokenLifetime !== null && (! is_numeric($refreshTokenLifetime) || (int) $refreshTokenLifetime < 1))
        ) {
            throw new UnexpectedValueException('QuickBooks returned an invalid OAuth token response.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_token_expires_at' => now()->addSeconds((int) $accessTokenLifetime),
            'refresh_token_expires_at' => $refreshTokenLifetime === null
                ? null
                : now()->addSeconds((int) $refreshTokenLifetime),
        ];
    }
}
