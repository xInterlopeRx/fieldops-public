<?php

namespace App\Services;

use App\Models\BitwardenSecretManagerConnection;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

class BwsSecretReader
{
    /** @var list<string> */
    private const ALLOWED_SECRET_NAMES = [
        'INTUIT_CLIENT_ID',
        'INTUIT_CLIENT_SECRET',
        'MICROSOFT_GRAPH_CLIENT_ID',
        'MICROSOFT_GRAPH_CLIENT_SECRET',
    ];

    /**
     * Resolve only named app credentials through the runtime's read-only BWS token.
     *
     * Callers must keep the returned values in process memory and pass them directly to the
     * integration client. They must not log, serialize, cache, or queue this return value.
     *
     * @param  list<string>  $secretNames
     * @return array<string, string>
     *
     * @throws RuntimeException With a sanitized message only.
     */
    public function read(
        BitwardenSecretManagerConnection $connection,
        array $secretNames,
    ): array {
        $apiUrl = config('services.bitwarden.api_url');
        $identityUrl = config('services.bitwarden.identity_url');

        if (! $connection->isVerifiedForSecretNames($secretNames)
            || ! is_string($apiUrl)
            || ! is_string($identityUrl)
            || ! $this->validRequest($apiUrl, $identityUrl, $connection->organization_id, $connection->project_id, $secretNames)) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        try {
            $accessToken = $connection->runtime_access_token;
        } catch (DecryptException) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        if (! is_string($accessToken) || trim($accessToken) === '' || strlen($accessToken) > 8192) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        try {
            $input = json_encode([
                'access_token' => $accessToken,
                'api_url' => $apiUrl,
                'identity_url' => $identityUrl,
                'organization_id' => $connection->organization_id,
                'project_id' => $connection->project_id,
                'operation' => 'read',
                'secret_names' => array_values($secretNames),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        if (strlen($input) > 32 * 1024) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        try {
            $process = Process::timeout(90)
                ->idleTimeout(45)
                ->input($input)
                ->run([BwsSecretHelper::command()]);
        } catch (ProcessTimedOutException|RuntimeException) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        return $this->normaliseResponse($process->output(), $process->successful(), $secretNames);
    }

    /**
     * @param  list<string>  $secretNames
     */
    private function validRequest(
        string $apiUrl,
        string $identityUrl,
        string $organizationId,
        string $projectId,
        array $secretNames,
    ): bool {
        if (! Str::isUuid($organizationId)
            || ! Str::isUuid($projectId)
            || ! $this->validServiceUrl($apiUrl)
            || ! $this->validServiceUrl($identityUrl)
            || $secretNames === []
            || count($secretNames) > count(self::ALLOWED_SECRET_NAMES)) {
            return false;
        }

        return count(array_unique($secretNames)) === count($secretNames)
            && array_diff($secretNames, self::ALLOWED_SECRET_NAMES) === [];
    }

    private function validServiceUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($value);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    /**
     * @param  list<string>  $requestedNames
     * @return array<string, string>
     */
    private function normaliseResponse(string $output, bool $processSucceeded, array $requestedNames): array
    {
        try {
            $response = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        if (! is_array($response)
            || ($response['ok'] ?? null) !== true
            || ! $processSucceeded
            || ($response['results'] ?? null) !== []
            || array_key_exists('error', $response)
            || ! is_array($response['resolved_secrets'] ?? null)
            || array_diff(array_keys($response), ['ok', 'results', 'resolved_secrets']) !== []
            || array_diff(array_keys($response['resolved_secrets']), $requestedNames) !== []) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        $secrets = [];
        foreach ($response['resolved_secrets'] as $name => $value) {
            if (! in_array($name, self::ALLOWED_SECRET_NAMES, true)
                || ! is_string($value)
                || trim($value) === ''
                || strlen($value) > 16 * 1024) {
                throw new RuntimeException('BWS credentials could not be resolved.');
            }

            $secrets[$name] = $value;
        }

        if (array_diff($requestedNames, array_keys($secrets)) !== []) {
            throw new RuntimeException('BWS credentials could not be resolved.');
        }

        return $secrets;
    }
}
