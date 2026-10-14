<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\BwsSecretHelper;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class QuickBooksOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_quickbooks_setup_without_exposing_credentials(): void
    {
        $this->configureQuickBooks();

        $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('configured', true)
                ->where('environment', 'sandbox')
                ->where('oauthRequiresLocalhost', false)
                ->where('redirectUri', 'http://localhost:8000/admin/integrations/quickbooks/callback')
                ->missing('clientId')
                ->missing('clientSecret')
                ->missing('connection.access_token')
                ->missing('connection.refresh_token'));
    }

    public function test_legacy_environment_client_credentials_do_not_fall_back_when_bws_is_unavailable(): void
    {
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.client_id' => 'legacy-environment-client-id',
            'services.quickbooks.client_secret' => 'legacy-environment-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8000/admin/integrations/quickbooks/callback',
        ]);
        Process::fake();

        $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('configured', false)
                ->where('missingConfiguration.0', 'verified BWS runtime reader connection')
                ->missing('clientId')
                ->missing('clientSecret'));

        Process::assertNothingRan();
    }

    public function test_intuit_oauth_fails_closed_when_bws_credential_read_fails(): void
    {
        $this->configureQuickBooks();
        config([
            'services.quickbooks.client_id' => 'legacy-environment-client-id',
            'services.quickbooks.client_secret' => 'legacy-environment-client-secret',
        ]);
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"project_access_failed","results":[]}',
            errorOutput: 'private BWS details',
            exitCode: 1,
        ));
        Http::fake([]);
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.redirect'))
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_basic_user_cannot_manage_quickbooks_connection(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('admin.integrations.quickbooks.show'))
            ->assertForbidden();
    }

    public function test_admin_cannot_start_oauth_until_credentials_are_configured(): void
    {
        $this->configureQuickBooks(redirectUri: '');

        $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.redirect'))
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('error');
    }

    public function test_admin_starts_oauth_with_accounting_scope_and_session_state(): void
    {
        $this->configureQuickBooks();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.redirect'));

        $response->assertRedirectContains('https://appcenter.intuit.com/connect/oauth2');
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('test-client-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('com.intuit.quickbooks.accounting', $query['scope']);
        $this->assertSame('http://localhost:8000/admin/integrations/quickbooks/callback', $query['redirect_uri']);
        $this->assertSame($query['state'], session('quickbooks.oauth_state.value'));
    }

    public function test_host_ip_oauth_start_is_blocked_for_a_localhost_callback(): void
    {
        $this->configureQuickBooks(redirectUri: 'http://localhost:8000/admin/integrations/quickbooks/callback');

        $response = $this->actingAs($this->admin())
            ->get('http://192.0.2.1:8000/admin/integrations/quickbooks/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('/admin/integrations/quickbooks', (string) $response->headers->get('Location'));
        $response->assertSessionHas('error', 'This QuickBooks callback is localhost-only. Start or reconnect QuickBooks from the host computer at localhost; other devices need a registered HTTPS callback.');
        $this->assertNull(session('quickbooks.oauth_state.value'));
    }

    public function test_host_ip_quickbooks_page_warns_that_oauth_requires_localhost(): void
    {
        $this->configureQuickBooks(redirectUri: 'http://localhost:8000/admin/integrations/quickbooks/callback');

        $this->actingAs($this->admin())
            ->get('http://192.0.2.1:8000/admin/integrations/quickbooks')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('oauthRequiresLocalhost', true));
    }

    public function test_callback_rejects_missing_or_invalid_state_without_calling_intuit(): void
    {
        $this->configureQuickBooks();
        Http::preventStrayRequests();

        $this->actingAs($this->admin())
            ->get(route('admin.integrations.quickbooks.callback', [
                'code' => 'authorization-code',
                'state' => 'forged-state',
                'realmId' => 'sandbox-realm',
            ]))
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseMissing('integration_connections', ['provider' => 'quickbooks']);
    }

    public function test_callback_exchanges_code_and_stores_encrypted_tokens(): void
    {
        $this->configureQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 7776000,
                'token_type' => 'Bearer',
            ]),
        ]);
        $user = $this->admin();

        $startResponse = $this->actingAs($user)
            ->get(route('admin.integrations.quickbooks.redirect'));
        parse_str((string) parse_url((string) $startResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('admin.integrations.quickbooks.callback', [
            'code' => 'one-time-authorization-code',
            'state' => $query['state'],
            'realmId' => 'sandbox-company-123',
        ]))
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status');

        Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' &&
            $request['grant_type'] === 'authorization_code' &&
            $request['code'] === 'one-time-authorization-code' &&
            $request['redirect_uri'] === 'http://localhost:8000/admin/integrations/quickbooks/callback' &&
            $request->hasHeader('Authorization', 'Basic '.base64_encode('test-client-id:test-client-secret'))
        );

        $connection = IntegrationConnection::query()->firstOrFail();

        $this->assertSame('quickbooks', $connection->provider);
        $this->assertSame('sandbox', $connection->environment);
        $this->assertSame('sandbox-company-123', $connection->realm_id);
        $this->assertSame('test-access-token', $connection->access_token);
        $this->assertSame('test-refresh-token', $connection->refresh_token);
        $this->assertSame($user->id, $connection->connected_by_user_id);
        $this->assertArrayNotHasKey('access_token', $connection->toArray());
        $this->assertArrayNotHasKey('refresh_token', $connection->toArray());
    }

    public function test_callback_replaces_tokens_that_cannot_be_decrypted_with_the_current_key(): void
    {
        $this->configureQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'replacement-access-token',
                'refresh_token' => 'replacement-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 7776000,
                'token_type' => 'Bearer',
            ]),
        ]);
        $user = $this->admin();
        $existing = IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'old-sandbox-company',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now()->subDay(),
        ]);
        $foreignEncrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        DB::table('integration_connections')->where('id', $existing->getKey())->update([
            'access_token' => $foreignEncrypter->encryptString('unreadable-access-token'),
            'refresh_token' => $foreignEncrypter->encryptString('unreadable-refresh-token'),
        ]);

        $startResponse = $this->actingAs($user)
            ->get(route('admin.integrations.quickbooks.redirect'));
        parse_str((string) parse_url((string) $startResponse->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->get(route('admin.integrations.quickbooks.callback', [
            'code' => 'replacement-authorization-code',
            'state' => $query['state'],
            'realmId' => 'sandbox-company-456',
        ]))
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status');

        $updated = $existing->fresh();
        $this->assertSame($existing->getKey(), $updated->getKey());
        $this->assertSame('sandbox-company-456', $updated->realm_id);
        $this->assertSame('replacement-access-token', $updated->access_token);
        $this->assertSame('replacement-refresh-token', $updated->refresh_token);
        $this->assertDatabaseCount('integration_connections', 1);
        $this->assertArrayNotHasKey('access_token', $updated->toArray());
        $this->assertArrayNotHasKey('refresh_token', $updated->toArray());
    }

    private function configureQuickBooks(
        string $redirectUri = 'http://localhost:8000/admin/integrations/quickbooks/callback',
    ): void {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.redirect_uri' => $redirectUri,
            'services.quickbooks.authorization_url' => 'https://appcenter.intuit.com/connect/oauth2',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
            'services.quickbooks.accounting_scope' => 'com.intuit.quickbooks.accounting',
        ]);
        $this->fakeIntuitBwsCredentials();
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create([
            'status' => 'active',
        ]);

        $this->unlockAdminOnboardingAreaForTest($admin);

        return $admin;
    }
}
