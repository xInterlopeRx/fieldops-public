<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BitwardenSecretManagerConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_reader_token_is_encrypted_in_storage_and_hidden_from_serialization(): void
    {
        $connection = $this->connection();
        $connection->runtime_access_token = 'runtime-reader-token';
        $connection->save();

        $storedToken = DB::table('bitwarden_secret_manager_connections')
            ->where('id', $connection->id)
            ->value('runtime_access_token');

        $this->assertNotSame('runtime-reader-token', $storedToken);
        $this->assertSame('runtime-reader-token', $connection->fresh()->runtime_access_token);
        $this->assertArrayNotHasKey('runtime_access_token', $connection->toArray());
        $this->assertStringNotContainsString('runtime-reader-token', $connection->toJson());
    }

    public function test_runtime_reader_token_cannot_be_set_through_mass_assignment(): void
    {
        $connection = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
            'runtime_access_token' => 'untrusted-token',
        ]);

        $this->assertNull($connection->runtime_access_token);
    }

    public function test_only_one_bws_connection_can_be_configured_for_an_environment(): void
    {
        $existing = $this->connection();
        $existing->runtime_access_token = 'runtime-reader-token';
        $existing->save();

        $duplicate = $this->connection();
        $duplicate->runtime_access_token = 'another-runtime-token';

        $this->expectException(QueryException::class);
        $duplicate->save();
    }

    public function test_legacy_project_only_read_verification_does_not_unlock_runtime_access(): void
    {
        $connection = $this->connection();
        $connection->runtime_access_token = 'runtime-reader-token';
        $connection->verification_status = 'read_verified';
        $connection->save();

        $this->assertFalse($connection->fresh()->isVerifiedForCurrentEnvironment());
    }

    public function test_graph_only_bws_profile_is_accepted_for_csv_import_but_not_api_import(): void
    {
        config([
            'services.bitwarden.api_url' => 'https://api.bitwarden.com',
            'services.bitwarden.identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection = $this->connection();
        $connection->forceFill([
            'verification_profile' => BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY,
            'verification_status' => BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED,
            'runtime_access_token' => 'runtime-reader-token',
        ])->save();

        $this->assertTrue($connection->fresh()->isVerifiedForImportMode(ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV));
        $this->assertFalse($connection->fresh()->isVerifiedForImportMode(ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API));
        $this->assertFalse($connection->fresh()->isVerifiedForImportMode('unsupported'));
    }

    public function test_all_required_bws_profile_is_accepted_for_api_import_mode(): void
    {
        config([
            'services.bitwarden.api_url' => 'https://api.bitwarden.com',
            'services.bitwarden.identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection = $this->connection();
        $connection->forceFill([
            'verification_profile' => BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED,
            'verification_status' => BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED,
            'runtime_access_token' => 'runtime-reader-token',
        ])->save();

        $this->assertTrue($connection->fresh()->isVerifiedForImportMode(ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API));
    }

    private function connection(): BitwardenSecretManagerConnection
    {
        return new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
            'verification_status' => BitwardenSecretManagerConnection::STATUS_UNVERIFIED,
        ]);
    }
}
