<?php

namespace Tests;

use App\Models\BitwardenSecretManagerConnection;
use App\Models\User;
use App\Services\AdminOnboardingAreaAccess;
use App\Services\BwsSecretHelper;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    protected function unlockAdminOnboardingAreaForTest(User $user): void
    {
        $this->withSession([
            AdminOnboardingAreaAccess::SESSION_KEY => [
                'user_id' => (string) $user->getKey(),
                'last_activity_at' => now()->timestamp,
                'password_hash' => hash('sha256', (string) $user->getAuthPassword()),
                'two_factor_secret_hash' => hash('sha256', (string) $user->getRawOriginal('two_factor_secret')),
            ],
        ]);
    }

    protected function fakeIntuitBwsCredentials(): void
    {
        $this->fakeBwsRuntimeCredentials([
            'INTUIT_CLIENT_ID' => 'test-client-id',
            'INTUIT_CLIENT_SECRET' => 'test-client-secret',
        ], app()->environment());
    }

    protected function fakeMicrosoftGraphBwsCredentials(
        ?string $environment = null,
        string $clientId = 'test-graph-client-id',
        string $clientSecret = 'test-graph-client-secret',
    ): void {
        $environment ??= app()->environment();
        $this->fakeBwsRuntimeCredentials([
            'MICROSOFT_GRAPH_CLIENT_ID' => $clientId,
            'MICROSOFT_GRAPH_CLIENT_SECRET' => $clientSecret,
        ], $environment);
    }

    /** @param array<string, string> $secrets */
    private function fakeBwsRuntimeCredentials(array $secrets, string $environment): void
    {
        $helperCommand = BwsSecretHelper::command();
        $connection = BitwardenSecretManagerConnection::query()->firstOrNew([
            'environment' => $environment,
        ]);
        $connection->fill([
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => config('services.bitwarden.api_url'),
            'identity_url' => config('services.bitwarden.identity_url'),
        ]);
        $connection->runtime_access_token = 'test-runtime-reader-token';
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $connection->configured_at = now();
        $connection->last_verified_at = now();
        $connection->save();

        Process::fake(function (PendingProcess $process) use ($helperCommand, $secrets) {
            $input = json_decode((string) $process->input, true);

            if ($process->command !== [$helperCommand]
                || ! is_array($input)
                || ($input['operation'] ?? null) !== 'read'
                || ($input['secret_names'] ?? null) !== array_keys($secrets)) {
                return Process::result(
                    '{"ok":false,"error":"invalid_request","results":[]}',
                    'Unexpected BWS helper process payload.',
                    1,
                );
            }

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'resolved_secrets' => $secrets,
            ], JSON_THROW_ON_ERROR));
        });
    }
}
