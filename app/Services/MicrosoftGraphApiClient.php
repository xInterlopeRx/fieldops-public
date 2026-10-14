<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\MicrosoftGraphOnboardingTarget;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MicrosoftGraphApiClient
{
    private const BASE_URL = 'https://graph.microsoft.com/v1.0';

    public const MAX_BINARY_CONTENT_BYTES = 20 * 1024 * 1024;

    private ?string $lastCorrelationId = null;

    public function __construct(private readonly MicrosoftGraphTokenProvider $tokens) {}

    /** @param array<string, string|int> $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function getJson(string $path, array $query = [], array $headers = []): array
    {
        return $this->sendGet($this->resourceUrl($path), $query, $headers);
    }

    /**
     * Read a resource only under the saved, environment-matched onboarding target.
     *
     * @param  array<string, string|int>  $query
     * @return array<string, mixed>
     */
    public function getJsonForOnboardingTarget(
        MicrosoftGraphOnboardingTarget $target,
        string $path,
        array $query = [],
    ): array {
        if (! $target->exists || $target->environment !== app()->environment()) {
            throw new MicrosoftGraphApiException('graph_onboarding_target_unavailable', 503);
        }

        return $this->sendGet($this->resourceUrl($path), $query, [], $target);
    }

    /**
     * Follow a Graph-generated OData continuation link without accepting arbitrary URLs.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function getJsonNextPage(string $nextLink, array $headers = []): array
    {
        $parts = parse_url($nextLink);
        $path = is_array($parts) ? ($parts['path'] ?? '') : '';
        $decodedSegments = explode('/', rawurldecode($path));

        if (
            strlen($nextLink) > 8192 ||
            preg_match('/[\r\n]/', $nextLink) === 1 ||
            ($parts['scheme'] ?? null) !== 'https' ||
            strtolower((string) ($parts['host'] ?? '')) !== 'graph.microsoft.com' ||
            (isset($parts['port']) && $parts['port'] !== 443) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment']) ||
            ! str_starts_with($path, '/v1.0/') ||
            in_array('..', $decodedSegments, true)
        ) {
            throw new MicrosoftGraphApiException('graph_invalid_next_link', 422);
        }

        return $this->sendGet($nextLink, [], $headers);
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postJson(string $path, array $payload, array $headers = []): array
    {
        return $this->sendJson('POST', $this->resourceUrl($path), $payload, $headers);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postJsonForOnboardingTarget(
        MicrosoftGraphOnboardingTarget $target,
        string $path,
        array $payload,
    ): array {
        if (! $target->exists || $target->environment !== app()->environment()) {
            throw new MicrosoftGraphApiException('graph_onboarding_target_unavailable', 503);
        }

        return $this->sendJson('POST', $this->resourceUrl($path), $payload, [], onboardingTarget: $target);
    }

    /** @param array<string, string> $headers */
    public function deleteForOnboardingTarget(
        MicrosoftGraphOnboardingTarget $target,
        string $path,
        array $headers = [],
    ): void {
        if (! $target->exists || $target->environment !== app()->environment()) {
            throw new MicrosoftGraphApiException('graph_onboarding_target_unavailable', 503);
        }

        $this->sendJson('DELETE', $this->resourceUrl($path), [], $headers, onboardingTarget: $target);
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function patchJson(string $path, array $payload, array $headers = []): array
    {
        return $this->sendJson('PATCH', $this->resourceUrl($path), $payload, $headers);
    }

    /** @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function putContent(
        string $path,
        string $content,
        array $headers = [],
        string $contentType = 'application/octet-stream',
    ): array {
        return $this->sendJson(
            'PUT',
            $this->resourceUrl($path),
            $content,
            $headers,
            $contentType,
        );
    }

    /** @param array<string, string> $headers */
    public function delete(string $path, array $headers = []): void
    {
        $this->sendJson('DELETE', $this->resourceUrl($path), [], $headers);
    }

    /**
     * Download bounded file content. Graph returns a short-lived preauthenticated
     * redirect; the bearer token is never forwarded to that URL or logged.
     */
    public function getContent(string $path, int $maximumBytes = self::MAX_BINARY_CONTENT_BYTES): string
    {
        if ($maximumBytes < 0 || $maximumBytes > self::MAX_BINARY_CONTENT_BYTES) {
            throw new MicrosoftGraphApiException('graph_content_size_limit_invalid', 422);
        }

        $url = $this->resourceUrl($path);
        $token = $this->tokens->accessToken();
        $response = $this->request($url, $token, [], [], 60);

        if ($response->status() === 401) {
            $this->tokens->forgetToken($token);
            $token = $this->tokens->accessToken();
            $response = $this->request($url, $token, [], [], 60);
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new MicrosoftGraphApiException(
                'graph_rate_limited',
                429,
                is_numeric($retryAfter) ? min(3600, max(1, (int) $retryAfter)) : null,
                $this->debugContext($response, $url, [], 'graph_service_failure', $token, 'GET'),
            );
        }

        if ($response->status() === 403) {
            throw new MicrosoftGraphApiException('graph_permission_denied', 502, null, $this->debugContext($response, $url, [], 'authorization_failure', $token, 'GET'));
        }

        if ($response->status() === 404) {
            throw new MicrosoftGraphApiException('graph_record_not_found', 404, null, $this->debugContext($response, $url, [], 'resource_not_found', $token, 'GET'));
        }

        if ($response->status() === 302) {
            $location = $this->preauthenticatedDownloadLocation($response);

            return $this->downloadPreauthenticatedContent($location, $maximumBytes);
        }

        if (! $response->successful()) {
            throw new MicrosoftGraphApiException(
                'graph_content_download_failed',
                502,
                null,
                $this->debugContext($response, $url, [], $this->failureCategory($response->status()), $token, 'GET'),
            );
        }

        return $this->boundedResponseContent($response, $maximumBytes);
    }

    /**
     * Fetch bounded content from a Graph-provided temporary URL without sending a Graph token.
     */
    public function getPreauthenticatedContent(string $url, int $maximumBytes): string
    {
        if ($maximumBytes < 0 || $maximumBytes > self::MAX_BINARY_CONTENT_BYTES) {
            throw new MicrosoftGraphApiException('graph_content_size_limit_invalid', 422);
        }

        return $this->downloadPreauthenticatedContent(
            $this->validatedPreauthenticatedUrl($url),
            $maximumBytes,
        );
    }

    /** @param array<string, string|int> $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function sendGet(
        string $url,
        array $query,
        array $headers,
        ?MicrosoftGraphOnboardingTarget $onboardingTarget = null,
    ): array {
        $token = $onboardingTarget === null
            ? $this->tokens->accessToken()
            : $this->tokens->accessTokenForOnboardingTarget($onboardingTarget);
        $response = $this->request($url, $token, $query, $headers);

        if ($response->status() === 401) {
            if ($onboardingTarget === null) {
                $this->tokens->forgetToken($token);
                $token = $this->tokens->accessToken();
            } else {
                $this->tokens->forgetOnboardingToken($onboardingTarget, $token);
                $token = $this->tokens->accessTokenForOnboardingTarget($onboardingTarget);
            }

            $response = $this->request($url, $token, $query, $headers);
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new MicrosoftGraphApiException(
                'graph_rate_limited',
                429,
                is_numeric($retryAfter) ? min(3600, max(1, (int) $retryAfter)) : null,
                $this->debugContext($response, $url, $query, 'graph_service_failure', $token, 'GET'),
            );
        }

        if ($response->status() === 403) {
            throw new MicrosoftGraphApiException('graph_permission_denied', 502, null, $this->debugContext($response, $url, $query, 'authorization_failure', $token, 'GET'));
        }

        if ($response->status() === 404) {
            throw new MicrosoftGraphApiException('graph_record_not_found', 404, null, $this->debugContext($response, $url, $query, 'resource_not_found', $token, 'GET'));
        }

        if (! $response->successful()) {
            throw new MicrosoftGraphApiException(
                'graph_request_failed',
                502,
                null,
                $this->debugContext($response, $url, $query, $this->failureCategory($response->status()), $token, 'GET'),
            );
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new MicrosoftGraphApiException('graph_invalid_response', 502, null, $this->debugContext($response, $url, $query, 'graph_service_failure', $token, 'GET'));
        }

        return $payload;
    }

    /** @param array<string, mixed>|string $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function sendJson(
        string $method,
        string $url,
        array|string $payload,
        array $headers,
        ?string $contentType = null,
        ?MicrosoftGraphOnboardingTarget $onboardingTarget = null,
    ): array {
        if ($onboardingTarget !== null && ! config('services.microsoft_graph.onboarding_writes_enabled')) {
            throw new MicrosoftGraphApiException('graph_onboarding_writes_disabled', 409);
        }

        $token = $onboardingTarget === null
            ? $this->tokens->accessToken()
            : $this->tokens->accessTokenForOnboardingTarget($onboardingTarget);
        $response = $this->requestBody($method, $url, $token, $payload, $headers, $contentType);

        if ($response->status() === 401) {
            if ($onboardingTarget === null) {
                $this->tokens->forgetToken($token);
                $token = $this->tokens->accessToken();
            } else {
                $this->tokens->forgetOnboardingToken($onboardingTarget, $token);
                $token = $this->tokens->accessTokenForOnboardingTarget($onboardingTarget);
            }

            $response = $this->requestBody($method, $url, $token, $payload, $headers, $contentType);
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');

            throw new MicrosoftGraphApiException(
                'graph_rate_limited',
                429,
                is_numeric($retryAfter) ? min(3600, max(1, (int) $retryAfter)) : null,
                $this->debugContext($response, $url, [], 'graph_service_failure', $token, $method),
            );
        }

        if ($response->status() === 403) {
            throw new MicrosoftGraphApiException('graph_permission_denied', 502, null, $this->debugContext($response, $url, [], 'authorization_failure', $token, $method));
        }

        if ($method === 'PATCH' && $response->status() === 404) {
            throw new MicrosoftGraphApiException(
                'graph_record_not_found',
                404,
                null,
                $this->debugContext($response, $url, [], 'resource_not_found', $token, $method),
            );
        }

        if ($method === 'DELETE' && $response->status() === 404) {
            return [];
        }

        if ($method === 'DELETE' && $response->status() === 412) {
            throw new MicrosoftGraphApiException(
                'graph_resource_changed',
                409,
                null,
                $this->debugContext($response, $url, [], 'graph_request_failure', $token, $method),
            );
        }

        if (! $response->successful()) {
            throw new MicrosoftGraphApiException(
                'graph_request_failed',
                502,
                null,
                $this->debugContext($response, $url, [], $this->failureCategory($response->status()), $token, $method),
            );
        }

        $result = $response->json();

        if (is_array($result)) {
            return $result;
        }

        if (in_array($response->status(), [202, 204], true) && ($result === null || $result === '')) {
            return [];
        }

        throw new MicrosoftGraphApiException('graph_invalid_response', 502, null, $this->debugContext($response, $url, [], 'graph_service_failure', $token, $method));
    }

    /** @param array<string, mixed>|string $payload
     * @param  array<string, string>  $headers
     */
    private function requestBody(
        string $method,
        string $url,
        string $token,
        array|string $payload,
        array $headers,
        ?string $contentType = null,
    ): Response {
        return $this->sendHttpRequest($method, $url, $token, $headers, 30, payload: $payload, contentType: $contentType);
    }

    /**
     * Keep upstream error details available only to local Graph diagnostics.
     * Authorization headers and tokens are deliberately never copied here.
     *
     * @param  array<string, string|int>  $query
     * @return array{failure_category: string|null, method: string, request_url: string, http_status: int|null, graph_error_code: string|null, graph_error_message: string|null, correlation_id: string|null, request_id: string|null}|null
     */
    private function debugContext(
        ?Response $response,
        string $url,
        array $query,
        ?string $failureCategory,
        ?string $accessToken,
        string $method,
        ?string $correlationId = null,
    ): ?array {
        if (! app()->environment('local')) {
            return null;
        }

        $correlationId ??= $this->lastCorrelationId;

        $payload = $response?->json();
        $graphError = $response !== null && ! $response->successful() && is_array($payload)
            ? ($payload['error'] ?? null)
            : null;
        $code = is_array($graphError) && is_string($graphError['code'] ?? null)
            ? $graphError['code']
            : null;
        $message = is_array($graphError) && is_string($graphError['message'] ?? null)
            ? $graphError['message']
            : null;

        if (is_string($accessToken) && $accessToken !== '') {
            $code = $code === null ? null : str_replace($accessToken, '[redacted]', $code);
            $message = $message === null ? null : str_replace($accessToken, '[redacted]', $message);
        }

        $code = $code === null ? null : $this->sanitizeDebugValue($code, 256);
        $message = $message === null ? null : $this->sanitizeDebugValue($message, 2048);
        $effectiveUri = $response?->effectiveUri();
        $requestUrl = $effectiveUri === null
            ? $this->requestUrl($url, $query)
            : (string) $effectiveUri;
        $responseCorrelationId = $this->responseHeader($response, ['client-request-id', 'x-ms-correlation-id', 'correlation-id']);
        $requestId = $this->responseHeader($response, ['request-id', 'x-ms-request-id']);

        $context = [
            'failure_category' => $failureCategory,
            'method' => $method,
            'request_url' => $this->redactSensitiveUrl($requestUrl),
            'http_status' => $response?->status(),
            'graph_error_code' => $code,
            'graph_error_message' => $message,
            'correlation_id' => $responseCorrelationId ?? $correlationId,
            'request_id' => $requestId,
        ];

        if ($failureCategory !== null) {
            $jwtClaims = $this->tokens->localDiagnosticClaims($accessToken ?? '');

            if ($jwtClaims !== null) {
                $context['jwt_claims'] = $jwtClaims;
            }
        }

        return $context;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string|int>  $query
     * @param  array<string, mixed>|string|null  $payload
     */
    private function sendHttpRequest(
        string $method,
        string $url,
        string $token,
        array $headers,
        int $timeoutSeconds,
        array $query = [],
        array|string|null $payload = null,
        ?string $contentType = null,
    ): Response {
        $correlationId = app()->environment('local')
            ? ($this->requestHeader($headers, 'client-request-id') ?? (string) Str::uuid())
            : null;
        $this->lastCorrelationId = $correlationId;

        try {
            $request = Http::withToken($token)
                ->acceptJson()
                ->withHeaders($headers)
                ->connectTimeout(5)
                ->timeout($timeoutSeconds)
                ->withoutRedirecting();

            if ($correlationId !== null && $this->requestHeader($headers, 'client-request-id') === null) {
                $request = $request->withHeaders(['client-request-id' => $correlationId]);
            }

            $response = match ($method) {
                'GET' => $query === [] ? $request->get($url) : $request->get($url, $query),
                'POST' => $request->post($url, is_array($payload) ? $payload : []),
                'PATCH' => $request->patch($url, is_array($payload) ? $payload : []),
                'PUT' => is_string($payload)
                    ? $request->withBody($payload, $contentType ?? 'application/octet-stream')->put($url, $payload)
                    : throw new MicrosoftGraphApiException('graph_invalid_upload_body', 422),
                'DELETE' => $request->delete($url),
                default => throw new MicrosoftGraphApiException('graph_unsupported_method', 422),
            };

            $context = $this->debugContext(
                $response,
                $url,
                $query,
                $response->successful() ? null : $this->failureCategory($response->status()),
                $token,
                $method,
                $correlationId,
            );

            if ($context !== null) {
                Log::debug('Microsoft Graph HTTP request', $context);
            }

            return $response;
        } catch (MicrosoftGraphApiException $exception) {
            throw $exception;
        } catch (ConnectionException) {
            $context = $this->debugContext(null, $url, $query, 'graph_service_failure', $token, $method, $correlationId);

            if ($context !== null) {
                Log::debug('Microsoft Graph HTTP request', $context);
            }

            throw new MicrosoftGraphApiException('graph_unavailable', 503, null, $context);
        } catch (Throwable) {
            $context = $this->debugContext(null, $url, $query, 'graph_service_failure', $token, $method, $correlationId);

            if ($context !== null) {
                Log::debug('Microsoft Graph HTTP request', $context);
            }

            throw new MicrosoftGraphApiException('graph_request_failed', 502, null, $context);
        }
    }

    private function failureCategory(int $status): ?string
    {
        return match (true) {
            $status === 401 => 'authentication_failure',
            $status === 403 => 'authorization_failure',
            $status === 404 => 'resource_not_found',
            $status === 408 || $status === 429 || $status >= 500 => 'graph_service_failure',
            $status >= 400 => 'graph_request_failure',
            default => null,
        };
    }

    private function preauthenticatedDownloadLocation(Response $response): string
    {
        $location = $response->header('Location');

        if (! is_string($location) || $location === '') {
            throw new MicrosoftGraphApiException('graph_download_redirect_invalid', 502);
        }

        return $this->validatedPreauthenticatedUrl($location);
    }

    private function validatedPreauthenticatedUrl(string $url): string
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $trustedHost = str_ends_with($host, '.sharepoint.com')
            || str_ends_with($host, '.sharepointonline.com')
            || str_ends_with($host, '.onedrive.com')
            || str_ends_with($host, '.1drv.com');

        if (
            $url === '' ||
            strlen($url) > 8192 ||
            preg_match('/[\r\n]/', $url) === 1 ||
            ! is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false ||
            ! $trustedHost ||
            (isset($parts['port']) && $parts['port'] !== 443) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment'])
        ) {
            throw new MicrosoftGraphApiException('graph_download_redirect_invalid', 502);
        }

        return $url;
    }

    private function downloadPreauthenticatedContent(string $url, int $maximumBytes): string
    {
        try {
            $response = Http::withHeaders(['Accept' => '*/*'])
                ->connectTimeout(5)
                ->timeout(60)
                ->withoutRedirecting()
                ->get($url);
        } catch (ConnectionException) {
            throw new MicrosoftGraphApiException('graph_unavailable', 503);
        } catch (Throwable) {
            throw new MicrosoftGraphApiException('graph_content_download_failed', 502);
        }

        if (! $response->successful()) {
            throw new MicrosoftGraphApiException('graph_content_download_failed', 502);
        }

        return $this->boundedResponseContent($response, $maximumBytes);
    }

    private function boundedResponseContent(Response $response, int $maximumBytes): string
    {
        $contentLength = $response->header('Content-Length');

        if (is_numeric($contentLength) && (int) $contentLength > $maximumBytes) {
            throw new MicrosoftGraphApiException('graph_content_too_large', 413);
        }

        $content = $response->body();

        if (strlen($content) > $maximumBytes) {
            throw new MicrosoftGraphApiException('graph_content_too_large', 413);
        }

        return $content;
    }

    /** @param array<string, string|int> $query */
    private function requestUrl(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        if ($queryString === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$queryString;
    }

    /** @param list<string> $headers */
    private function responseHeader(?Response $response, array $headers): ?string
    {
        if ($response === null) {
            return null;
        }

        foreach ($headers as $header) {
            $value = $response->header($header);

            if (is_string($value) && trim($value) !== '') {
                return $this->sanitizeDebugValue($value, 256);
            }
        }

        return null;
    }

    /** @param array<string, string> $headers */
    private function requestHeader(array $headers, string $header): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $header) === 0 && trim($value) !== '') {
                return $this->sanitizeDebugValue($value, 256);
            }
        }

        return null;
    }

    private function redactSensitiveUrl(string $url): string
    {
        return preg_replace(
            '/([?&](?:access_token|refresh_token|id_token|client_secret|client_assertion|authorization|token)=)[^&#]*/i',
            '$1[redacted]',
            $url,
        ) ?? $url;
    }

    private function sanitizeDebugValue(string $value, int $maximumLength): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';

        return substr(trim($value), 0, $maximumLength);
    }

    /** @param array<string, string|int> $query
     * @param  array<string, string>  $headers
     */
    private function request(string $url, string $token, array $query, array $headers, int $timeoutSeconds = 20): Response
    {
        return $this->sendHttpRequest('GET', $url, $token, $headers, $timeoutSeconds, $query);
    }

    private function resourceUrl(string $path): string
    {
        if (
            $path === '' ||
            str_starts_with($path, '/') ||
            str_contains($path, '?') ||
            str_contains($path, '#') ||
            preg_match('/[\r\n]/', $path) === 1 ||
            in_array('..', explode('/', $path), true)
        ) {
            throw new MicrosoftGraphApiException('graph_invalid_resource_path', 422);
        }

        return self::BASE_URL.'/'.$path;
    }
}
