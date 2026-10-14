<?php

namespace Tests\Feature;

use App\Exceptions\BwsProjectReadAccessException;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\BwsSecretWriteAttempt;
use App\Models\User;
use App\Services\BwsSecretHelper;
use App\Services\ConfigureBitwardenSecretManagerConnection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ConfigureBitwardenSecretManagerConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_connection_is_saved_only_after_the_helper_confirms_project_read_access(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
            'quickbooks_sync_verification_required' => true,
        ]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        Process::fake(function (PendingProcess $process) {
            $this->assertSame([BwsSecretHelper::HELPER_COMMAND], $process->command);

            $request = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('verify', $request['operation']);
            $this->assertSame('runtime-reader-token', $request['access_token']);
            $this->assertSame('https://api.bitwarden.com', $request['api_url']);
            $this->assertSame('https://identity.bitwarden.com', $request['identity_url']);
            $this->assertArrayNotHasKey('secrets', $request);
            $this->assertSame(BitwardenSecretManagerConnection::ALL_REQUIRED_SECRET_NAMES, $request['secret_names']);
            $this->assertStringNotContainsString('runtime-reader-token', implode(' ', $process->command));

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });

        $connection = app(ConfigureBitwardenSecretManagerConnection::class)->configure(
            $admin,
            'runtime-reader-token',
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        );

        $this->assertSame('testing', $connection->environment);
        $this->assertSame(BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED, $connection->verification_status);
        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_verification_required'));
        $this->assertSame($admin->id, $connection->configured_by_user_id);
        $this->assertSame('runtime-reader-token', $connection->runtime_access_token);
        $this->assertNotSame(
            'runtime-reader-token',
            $connection->getRawOriginal('runtime_access_token'),
        );
        $this->assertArrayNotHasKey('runtime_access_token', $connection->toArray());
        $this->assertNotNull($connection->last_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => $connection->getMorphClass(),
            'subject_id' => $connection->id,
            'event' => 'bws_connection_configured',
        ]);
        $audit = AuditEvent::query()->where('subject_id', $connection->id)->firstOrFail();
        $this->assertArrayNotHasKey('runtime_access_token', $audit->metadata);

        Process::assertRan([BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_default_quickbooks_opt_out_verifies_only_graph_credentials(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        Process::fake(function (PendingProcess $process) {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES, $payload['secret_names']);

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });

        $connection = app(ConfigureBitwardenSecretManagerConnection::class)->configure(
            $admin,
            'runtime-reader-token',
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        );

        $this->assertSame(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY, $connection->verification_profile);
        $this->assertTrue($connection->isVerifiedForCurrentEnvironment());
        Process::assertRan([BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_csv_import_mode_verifies_only_graph_secrets_in_bws(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        Process::fake(function (PendingProcess $process) {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('verify', $payload['operation']);
            $this->assertSame(BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES, $payload['secret_names']);

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });

        $connection = app(ConfigureBitwardenSecretManagerConnection::class)->configure(
            $admin,
            'runtime-reader-token',
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        );

        $this->assertSame(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY, $connection->verification_profile);
        $this->assertTrue($connection->isVerifiedForCurrentEnvironment());
        Process::assertRan([BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_csv_import_mode_verifies_only_graph_secrets_and_persists_the_profile(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        Process::fake(function (PendingProcess $process) {
            $request = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('verify', $request['operation']);
            $this->assertSame(BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES, $request['secret_names']);

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });

        $connection = app(ConfigureBitwardenSecretManagerConnection::class)->configure(
            $admin,
            'runtime-reader-token',
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000002',
        );

        $this->assertSame(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY, $connection->verification_profile);
        $this->assertTrue($connection->isVerifiedForCurrentEnvironment());
    }

    public function test_failed_read_access_verification_does_not_replace_an_existing_connection(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $existing = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $existing->runtime_access_token = 'existing-reader-token';
        $existing->configured_by_user_id = $admin->id;
        $existing->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $existing->last_verified_at = now();
        $existing->save();

        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"project_access_failed","results":[]}',
            errorOutput: 'sensitive BWS response details',
            exitCode: 1,
        ));

        try {
            app(ConfigureBitwardenSecretManagerConnection::class)->configure(
                $admin,
                'replacement-reader-token',
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000099',
            );
            $this->fail('A connection without project read access must not be saved.');
        } catch (BwsProjectReadAccessException $exception) {
            $this->assertSame('BWS project read access could not be verified.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive BWS response details', $exception->getMessage());
        }

        $this->assertSame('existing-reader-token', $existing->fresh()->runtime_access_token);
        $this->assertSame('00000000-0000-0000-0000-000000000002', $existing->fresh()->project_id);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_missing_required_secret_does_not_replace_an_existing_runtime_connection(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $existing = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $existing->runtime_access_token = 'existing-reader-token';
        $existing->configured_by_user_id = $admin->id;
        $existing->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $existing->last_verified_at = now();
        $existing->save();
        BwsSecretWriteAttempt::query()->create([
            'id' => (string) Str::uuid(),
            'connection_id' => $existing->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => 'testing',
            'organization_id' => $existing->organization_id,
            'project_id' => $existing->project_id,
            'status' => BwsSecretWriteAttempt::STATUS_RECONCILIATION_REQUIRED,
            'fingerprint_key_id' => str_repeat('a', 24),
            'started_at' => now()->subMinute(),
        ]);
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"missing_secret","results":[]}',
            exitCode: 1,
        ));

        try {
            app(ConfigureBitwardenSecretManagerConnection::class)->configure(
                $admin,
                'replacement-reader-token',
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000099',
            );
            $this->fail('The reader token must not be saved when required app credentials are absent.');
        } catch (BwsProjectReadAccessException $exception) {
            $this->assertSame(BwsProjectReadAccessException::REQUIRED_SECRETS_INVALID, $exception->reason);
        }

        $this->assertSame('existing-reader-token', $existing->fresh()->runtime_access_token);
        $this->assertSame('00000000-0000-0000-0000-000000000002', $existing->fresh()->project_id);
        $this->assertSame(BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED, $existing->fresh()->verification_status);
    }

    public function test_legacy_write_attempt_history_does_not_block_runtime_reader_reverification(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $existing = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $existing->runtime_access_token = 'existing-reader-token';
        $existing->configured_by_user_id = $admin->getKey();
        $existing->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $existing->last_verified_at = now();
        $existing->save();
        $attempt = BwsSecretWriteAttempt::query()->create([
            'id' => (string) Str::uuid(),
            'connection_id' => $existing->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => 'testing',
            'organization_id' => $existing->organization_id,
            'project_id' => $existing->project_id,
            'status' => BwsSecretWriteAttempt::STATUS_RECONCILIATION_REQUIRED,
            'fingerprint_key_id' => str_repeat('a', 24),
            'started_at' => now()->subMinute(),
        ]);
        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'project_access_verified' => true,
            'required_secrets_verified' => true,
        ], JSON_THROW_ON_ERROR)));

        $connection = app(ConfigureBitwardenSecretManagerConnection::class)->configure(
            $admin,
            'replacement-reader-token',
            '00000000-0000-0000-0000-000000000001',
            '00000000-0000-0000-0000-000000000099',
        );

        $this->assertSame('replacement-reader-token', $connection->runtime_access_token);
        $this->assertSame('00000000-0000-0000-0000-000000000099', $connection->project_id);
        $this->assertModelExists($attempt->fresh());
        Process::assertRan([BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_user_without_integration_permission_cannot_configure_bws(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Process::fake();

        try {
            app(ConfigureBitwardenSecretManagerConnection::class)->configure(
                $user,
                'runtime-reader-token',
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000002',
            );
            $this->fail('A user without integration permission must be denied.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
            Process::assertNothingRan();
        }
    }

    public function test_invalid_deployment_bws_endpoint_is_rejected_before_the_helper_runs(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        config(['services.bitwarden.api_url' => 'https://user@example.test']);
        Process::fake();

        try {
            app(ConfigureBitwardenSecretManagerConnection::class)->configure(
                $admin,
                'runtime-reader-token',
                '00000000-0000-0000-0000-000000000001',
                '00000000-0000-0000-0000-000000000002',
            );
            $this->fail('Credential-bearing deployment BWS URLs must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('BWS connection settings are invalid.', $exception->getMessage());
            Process::assertNothingRan();
        }
    }
}
