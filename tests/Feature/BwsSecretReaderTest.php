<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use App\Services\BwsSecretHelper;
use App\Services\BwsSecretReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class BwsSecretReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_reader_resolves_only_requested_secret_values_from_stdin(): void
    {
        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => [
                'INTUIT_CLIENT_SECRET' => 'resolved-client-secret',
            ],
        ], JSON_THROW_ON_ERROR)));

        $result = app(BwsSecretReader::class)->read(
            $this->verifiedConnection(),
            ['INTUIT_CLIENT_SECRET'],
        );

        $this->assertSame(['INTUIT_CLIENT_SECRET' => 'resolved-client-secret'], $result);
        Process::assertRan(function (PendingProcess $process): bool {
            $this->assertSame([BwsSecretHelper::command()], $process->command);
            $this->assertStringContainsString('read-only-runtime-token', $process->input);
            $this->assertStringContainsString('"operation":"read"', $process->input);
            $this->assertStringNotContainsString('read-only-runtime-token', implode(' ', $process->command));

            return true;
        });
    }

    public function test_runtime_reader_fails_with_a_sanitized_error(): void
    {
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"authorization_failed","results":[]}',
            errorOutput: 'sensitive provider diagnostic',
            exitCode: 1,
        ));

        try {
            app(BwsSecretReader::class)->read($this->verifiedConnection(), ['INTUIT_CLIENT_SECRET']);
            $this->fail('The reader should reject a BWS authorization failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive provider diagnostic', $exception->getMessage());
        }
    }

    public function test_runtime_reader_rejects_arbitrary_names_without_starting_the_helper(): void
    {
        Process::fake();

        try {
            app(BwsSecretReader::class)->read($this->verifiedConnection(), ['UNLISTED_SECRET']);
            $this->fail('The reader must reject arbitrary secret names.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_runtime_reader_requires_a_persisted_verified_connection_for_this_environment(): void
    {
        Process::fake();
        $connection = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection->runtime_access_token = 'read-only-runtime-token';
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;

        try {
            app(BwsSecretReader::class)->read($connection, ['INTUIT_CLIENT_SECRET']);
            $this->fail('The reader must require a persisted connection.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_runtime_reader_rejects_a_connection_verified_against_different_deployment_endpoints(): void
    {
        $connection = $this->verifiedConnection();
        Process::fake();
        config(['services.bitwarden.api_url' => 'https://region-api.example.test']);

        try {
            app(BwsSecretReader::class)->read($connection, ['INTUIT_CLIENT_SECRET']);
            $this->fail('An endpoint change must require connection re-verification.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_runtime_reader_keeps_graph_credentials_available_while_qbo_profile_reverification_is_pending(): void
    {
        $connection = $this->verifiedConnection();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
            'quickbooks_sync_verification_required' => true,
        ]);
        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => [
                'MICROSOFT_GRAPH_CLIENT_ID' => 'graph-client-id',
                'MICROSOFT_GRAPH_CLIENT_SECRET' => 'graph-client-secret',
            ],
        ], JSON_THROW_ON_ERROR)));

        $secrets = app(BwsSecretReader::class)->read(
            $connection,
            BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES,
        );

        $this->assertSame([
            'MICROSOFT_GRAPH_CLIENT_ID' => 'graph-client-id',
            'MICROSOFT_GRAPH_CLIENT_SECRET' => 'graph-client-secret',
        ], $secrets);
        Process::assertRan([BwsSecretHelper::command()]);
    }

    public function test_runtime_reader_blocks_intuit_credentials_until_the_opted_in_profile_is_reverified(): void
    {
        $connection = $this->verifiedConnection();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
            'quickbooks_sync_verification_required' => true,
        ]);
        Process::fake();

        try {
            app(BwsSecretReader::class)->read($connection, ['INTUIT_CLIENT_SECRET']);
            $this->fail('The Intuit profile must be reverified after QuickBooks opt-in.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
        }

        Process::assertNothingRan();
    }

    public function test_runtime_reader_does_not_return_an_unrequested_secret_from_helper_output(): void
    {
        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => [
                'INTUIT_CLIENT_SECRET' => 'requested-value',
                'MICROSOFT_GRAPH_CLIENT_SECRET' => 'unrequested-value',
            ],
        ], JSON_THROW_ON_ERROR)));

        try {
            app(BwsSecretReader::class)->read($this->verifiedConnection(), ['INTUIT_CLIENT_SECRET']);
            $this->fail('The reader must reject unrequested values from the helper.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('BWS credentials could not be resolved.', $exception->getMessage());
            $this->assertStringNotContainsString('unrequested-value', $exception->getMessage());
        }
    }

    private function verifiedConnection(): BitwardenSecretManagerConnection
    {
        $connection = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection->runtime_access_token = 'read-only-runtime-token';
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $connection->last_verified_at = now();
        $connection->save();

        return $connection;
    }
}
