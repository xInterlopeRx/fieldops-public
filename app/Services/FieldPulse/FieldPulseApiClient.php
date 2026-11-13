<?php

namespace App\Services\FieldPulse;

use App\Exceptions\FieldPulseApiException;
use App\Models\BitwardenSecretManagerConnection;
use App\Services\BwsSecretReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for the FieldPulse REST API (see ADR-0039).
 *
 * This client performs GET requests only. No create, update, or delete call
 * exists, so the gateway cannot mutate FieldPulse. The API key resolves from
 * BWS as FIELDPULSE_API_KEY and is never logged, serialized, or returned.
 */
class FieldPulseApiClient
{
    private const SECRET_NAME = 'FIELDPULSE_API_KEY';

    public function __construct(private readonly BwsSecretReader $secrets) {}

    /**
     * Perform a bounded, read-only GET request.
     *
     * @param  array<string, mixed>  $query
     * @return array{data: array<mixed>, total_count: int|null}
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl().'/'.ltrim($path, '/');

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey(),
                'Accept' => 'application/json',
            ])
                ->timeout($this->timeout())
                ->get($url, $query);
        } catch (ConnectionException) {
            throw new FieldPulseApiException('fieldpulse_transport_failed', 503);
        }

        return $this->decode($response);
    }

    /** @return array{data: array<mixed>, total_count: int|null} */
    private function decode(Response $response): array
    {
        match (true) {
            $response->status() === 429 => throw new FieldPulseApiException(
                'fieldpulse_rate_limited',
                429,
                $this->retryAfterSeconds($response),
            ),
            $response->status() === 401 => throw new FieldPulseApiException('fieldpulse_unauthorized', 401),
            ! $response->successful() => throw new FieldPulseApiException(
                'fieldpulse_request_failed',
                $response->status() >= 400 ? $response->status() : 502,
            ),
            default => null,
        };

        $payload = $response->json();

        if (! is_array($payload) || ($payload['error'] ?? null) !== false) {
            throw new FieldPulseApiException('fieldpulse_response_invalid', 502);
        }

        $data = $payload['response'] ?? null;

        if (! is_array($data)) {
            throw new FieldPulseApiException('fieldpulse_response_invalid', 502);
        }

        return [
            'data' => $data,
            'total_count' => is_int($payload['total_count'] ?? null) ? $payload['total_count'] : null,
        ];
    }

    private function apiKey(): string
    {
        $connection = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        if (! $connection instanceof BitwardenSecretManagerConnection
            || ! $connection->isVerifiedForSecretNames([self::SECRET_NAME])) {
            throw new FieldPulseApiException('fieldpulse_configuration_incomplete', 503);
        }

        try {
            $resolved = $this->secrets->read($connection, [self::SECRET_NAME]);
        } catch (RuntimeException) {
            throw new FieldPulseApiException('fieldpulse_configuration_incomplete', 503);
        }

        $apiKey = $resolved[self::SECRET_NAME] ?? null;

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new FieldPulseApiException('fieldpulse_configuration_incomplete', 503);
        }

        return $apiKey;
    }

    private function baseUrl(): string
    {
        $baseUrl = config('services.fieldpulse.base_url');

        if (! is_string($baseUrl)) {
            throw new FieldPulseApiException('fieldpulse_configuration_incomplete', 503);
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new FieldPulseApiException('fieldpulse_configuration_incomplete', 503);
        }

        return rtrim($baseUrl, '/');
    }

    private function timeout(): int
    {
        $timeout = config('services.fieldpulse.timeout');

        return is_int($timeout) && $timeout > 0 && $timeout <= 120 ? $timeout : 30;
    }

    private function retryAfterSeconds(Response $response): ?int
    {
        $header = $response->header('RateLimit-Reset');

        if (! is_string($header) || ! is_numeric($header)) {
            return null;
        }

        $resetAt = (int) $header;

        return $resetAt > time() ? $resetAt - time() : 0;
    }
}
