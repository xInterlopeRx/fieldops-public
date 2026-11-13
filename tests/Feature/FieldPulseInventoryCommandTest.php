<?php

namespace Tests\Feature;

use App\Models\BitwardenSecretManagerConnection;
use App\Services\BwsSecretHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class FieldPulseInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_probe_refuses_outside_local_and_makes_no_request(): void
    {
        Http::fake();
        Process::fake();

        $this->artisan('fieldpulse:inventory')
            ->expectsOutputToContain('only when APP_ENV=local')
            ->assertExitCode(1);

        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_inventory_probe_resolves_the_bws_key_and_prints_schema_without_values(): void
    {
        $this->useLocalEnvironment();
        $this->verifiedConnection();

        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => ['FIELDPULSE_API_KEY' => 'fieldpulse-reader-key'],
        ], JSON_THROW_ON_ERROR)));

        Http::fake([
            '*/customers*' => Http::response([
                'error' => false,
                'total_count' => 112,
                'response' => [[
                    'id' => 17787375,
                    'display_name' => 'World Finance',
                    'contacts' => [['first_name' => 'Ryan', 'email' => 'ryan@example.test']],
                ]],
            ]),
        ]);

        $this->artisan('fieldpulse:inventory', ['--endpoint' => 'customers'])
            ->expectsOutputToContain('total_count=112')
            ->expectsOutputToContain('id : integer')
            ->expectsOutputToContain('display_name : string')
            ->expectsOutputToContain('contacts[].first_name : string')
            ->doesntExpectOutputToContain('World Finance')
            ->doesntExpectOutputToContain('ryan@example.test')
            ->assertSuccessful();

        Process::assertRan([BwsSecretHelper::command()]);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && $request->hasHeader('x-api-key', 'fieldpulse-reader-key')
                && str_contains($request->url(), '/customers');
        });
    }

    public function test_inventory_probe_requires_a_verified_bws_connection(): void
    {
        $this->useLocalEnvironment();
        Process::fake();
        Http::fake();

        $this->artisan('fieldpulse:inventory', ['--endpoint' => 'customers'])
            ->expectsOutputToContain('fieldpulse_configuration_incomplete')
            ->assertExitCode(1);

        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_inventory_probe_reports_a_sanitized_failure_without_leaking_the_reason(): void
    {
        $this->useLocalEnvironment();
        $this->verifiedConnection();

        Process::fake(fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => ['FIELDPULSE_API_KEY' => 'fieldpulse-reader-key'],
        ], JSON_THROW_ON_ERROR)));

        Http::fake([
            '*/customers*' => Http::response(['message' => 'secret-provider-detail'], 500),
        ]);

        $this->artisan('fieldpulse:inventory', ['--endpoint' => 'customers'])
            ->expectsOutputToContain('fieldpulse_request_failed')
            ->doesntExpectOutputToContain('secret-provider-detail')
            ->assertExitCode(1);
    }

    private function useLocalEnvironment(): void
    {
        $this->app['env'] = 'local';
    }

    private function verifiedConnection(): BitwardenSecretManagerConnection
    {
        $connection = new BitwardenSecretManagerConnection([
            'environment' => 'local',
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
