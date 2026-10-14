<?php

namespace App\Services;

use App\Exceptions\BwsProjectReadAccessException;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

class ConfigureBitwardenSecretManagerConnection
{
    public function __construct(private readonly ApplicationSetupStepService $setupSteps) {}

    /**
     * Verify access and required app-secret availability, then persist the runtime reader token.
     *
     * The caller must additionally require recent password confirmation and a fresh TOTP
     * challenge before allowing an Admin to rotate these settings. This method accepts only
     * a BWS Can Read token; machine-account permissions are provisioned outside FieldOps.
     *
     * @throws AuthorizationException
     * @throws RuntimeException With sanitized verification failures only.
     */
    public function configure(
        User $actor,
        string $runtimeAccessToken,
        string $organizationId,
        string $projectId,
    ): BitwardenSecretManagerConnection {
        if ($actor->status !== 'active') {
            throw new AuthorizationException;
        }

        Gate::forUser($actor)->authorize('integrations.manage');

        $environment = config('app.env');
        $setupState = ApplicationSetupState::query()->whereKey(1)->first();
        $quickBooksOptedIn = $setupState === null
            || ! $setupState->onboarding_required
            || $setupState->quickbooks_sync_opted_in;
        $apiUrl = config('services.bitwarden.api_url');
        $identityUrl = config('services.bitwarden.identity_url');

        if (! is_string($environment)
            || trim($environment) === ''
            || strlen($environment) > 20
            || trim($runtimeAccessToken) === ''
            || strlen($runtimeAccessToken) > 8192
            || ! Str::isUuid($organizationId)
            || ! Str::isUuid($projectId)
            || ! is_string($apiUrl)
            || ! is_string($identityUrl)
            || ! $this->validServiceUrl($apiUrl)
            || ! $this->validServiceUrl($identityUrl)) {
            throw new InvalidArgumentException('BWS connection settings are invalid.');
        }

        $this->setupSteps->begin(ApplicationSetupStep::BWS_RUNTIME_READER, $actor);
        $profile = $quickBooksOptedIn
            ? BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED
            : BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY;
        $secretNames = $profile === BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY
            ? BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES
            : BitwardenSecretManagerConnection::ALL_REQUIRED_SECRET_NAMES;

        try {
            $this->verifyReadAccess($runtimeAccessToken, $organizationId, $projectId, $apiUrl, $identityUrl, $secretNames);
        } catch (BwsProjectReadAccessException $exception) {
            $failureCode = $exception->reason === BwsProjectReadAccessException::REQUIRED_SECRETS_INVALID
                ? 'bws_required_secrets_missing'
                : 'bws_project_read_access_failed';
            $this->setupSteps->fail(ApplicationSetupStep::BWS_RUNTIME_READER, $actor, $failureCode);

            throw $exception;
        }

        $verifiedAt = now();

        try {
            return DB::transaction(function () use (
                $actor,
                $apiUrl,
                $environment,
                $identityUrl,
                $organizationId,
                $projectId,
                $runtimeAccessToken,
                $profile,
                $verifiedAt,
            ): BitwardenSecretManagerConnection {
                $setupState = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->first();
                $currentQuickBooksOptIn = $setupState === null
                    || ! $setupState->onboarding_required
                    || $setupState->quickbooks_sync_opted_in;
                $currentProfile = $currentQuickBooksOptIn
                    ? BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED
                    : BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY;

                if ($currentProfile !== $profile) {
                    throw ValidationException::withMessages([
                        'runtime_access_token' => 'The QuickBooks setup choice changed while BWS was being verified. Re-run the Can Read check for the current choice.',
                    ]);
                }

                $connection = BitwardenSecretManagerConnection::query()
                    ->where('environment', $environment)
                    ->lockForUpdate()
                    ->first();

                if (! $connection instanceof BitwardenSecretManagerConnection) {
                    $connection = new BitwardenSecretManagerConnection;
                }

                $connection->fill([
                    'environment' => $environment,
                    'organization_id' => $organizationId,
                    'project_id' => $projectId,
                    'api_url' => $apiUrl,
                    'identity_url' => $identityUrl,
                ]);
                $connection->runtime_access_token = $runtimeAccessToken;
                $connection->configured_by_user_id = $actor->getKey();
                $connection->configured_at = $verifiedAt;
                $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
                $connection->verification_profile = $profile;
                $connection->last_verified_at = $verifiedAt;
                $connection->last_error_code = null;
                $connection->save();

                if ($setupState?->onboarding_required && $setupState->quickbooks_sync_opted_in) {
                    $setupState->forceFill(['quickbooks_sync_verification_required' => false])->save();
                }

                AuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'subject_type' => $connection->getMorphClass(),
                    'subject_id' => $connection->getKey(),
                    'event' => 'bws_connection_configured',
                    'summary' => $actor->name.' configured BWS read access for the '.$environment.' environment.',
                    'metadata' => [
                        'environment' => $environment,
                        'organization_id' => $organizationId,
                        'project_id' => $projectId,
                        'verification_status' => BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED,
                        'verification_profile' => $profile,
                    ],
                    'created_at' => $verifiedAt,
                ]);

                $this->setupSteps->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $actor);

                return $connection;
            });
        } catch (ValidationException $exception) {
            $this->setupSteps->fail(ApplicationSetupStep::BWS_RUNTIME_READER, $actor, 'bws_profile_changed_during_verification');

            throw $exception;
        }
    }

    private function verifyReadAccess(
        string $runtimeAccessToken,
        string $organizationId,
        string $projectId,
        string $apiUrl,
        string $identityUrl,
        array $secretNames,
    ): void {
        try {
            $input = json_encode([
                'access_token' => $runtimeAccessToken,
                'api_url' => $apiUrl,
                'identity_url' => $identityUrl,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'operation' => 'verify',
                'secret_names' => array_values($secretNames),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new BwsProjectReadAccessException;
        }

        if (strlen($input) > 32 * 1024) {
            throw new BwsProjectReadAccessException;
        }

        $helperCommand = BwsSecretHelper::command();

        if (config('app.env') !== 'testing' && ! is_executable($helperCommand)) {
            throw new BwsProjectReadAccessException(BwsProjectReadAccessException::HELPER_UNAVAILABLE);
        }

        try {
            $result = Process::timeout(90)
                ->idleTimeout(45)
                ->input($input)
                ->run([$helperCommand]);
        } catch (ProcessTimedOutException|RuntimeException) {
            throw new BwsProjectReadAccessException;
        }

        try {
            $response = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BwsProjectReadAccessException;
        }

        if (is_array($response) && in_array(($response['error'] ?? null), ['missing_secret', 'secret_too_large'], true)) {
            throw new BwsProjectReadAccessException(BwsProjectReadAccessException::REQUIRED_SECRETS_INVALID);
        }

        if (! is_array($response)
            || ($response['ok'] ?? null) !== true
            || ! $result->successful()
            || ($response['results'] ?? null) !== []
            || ($response['project_access_verified'] ?? null) !== true
            || ($response['required_secrets_verified'] ?? null) !== true
            || array_diff(array_keys($response), ['ok', 'results', 'project_access_verified', 'required_secrets_verified']) !== []) {
            throw new BwsProjectReadAccessException;
        }
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
}
