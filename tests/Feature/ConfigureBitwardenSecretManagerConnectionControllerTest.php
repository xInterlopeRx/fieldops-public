<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\User;
use App\Services\BwsSecretHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class ConfigureBitwardenSecretManagerConnectionControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_bws_with_a_current_password_after_totp_unlocks_onboarding(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
            'quickbooks_sync_verification_required' => true,
        ]);
        $admin = $this->adminWithTwoFactor();
        Process::fake(function (PendingProcess $process) {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('verify', $payload['operation']);
            $this->assertSame(BitwardenSecretManagerConnection::ALL_REQUIRED_SECRET_NAMES, $payload['secret_names']);
            $this->assertSame('temporary-runtime-token', $payload['access_token']);
            $this->assertArrayNotHasKey('password', $payload);
            $this->assertArrayNotHasKey('totp_code', $payload);

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });

        $response = $this->actingAs($admin)->post(route('admin.integrations.bitwarden.store'), $this->validPayload());

        $response->assertRedirect();
        $response->assertSessionHas('status');
        $connection = BitwardenSecretManagerConnection::query()->sole();
        $this->assertSame('testing', $connection->environment);
        $this->assertSame('temporary-runtime-token', $connection->runtime_access_token);
        $this->assertSame(BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED, $connection->verification_status);
        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_verification_required'));
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event' => 'bws_connection_configured',
        ]);
        $this->assertArrayNotHasKey('runtime_access_token', $connection->toArray());

        Process::assertRan([BwsSecretHelper::HELPER_COMMAND]);
    }

    public function test_admin_status_page_exposes_connection_metadata_but_never_the_runtime_token(): void
    {
        $admin = $this->adminWithTwoFactor();
        $connection = new BitwardenSecretManagerConnection([
            'environment' => 'testing',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection->runtime_access_token = 'secret-runtime-reader-token';
        $connection->configured_by_user_id = $admin->id;
        $connection->configured_at = now();
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $connection->last_verified_at = now();
        $connection->save();

        $this->actingAs($admin)
            ->get(route('admin.integrations.bitwarden.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/BitwardenSecretManagerConnection')
                ->where('environment', 'testing')
                ->where('twoFactorEnabled', true)
                ->where('verificationProfile', BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY)
                ->where('requiredSecretNames', BitwardenSecretManagerConnection::GRAPH_SECRET_NAMES)
                ->where('connection.projectId', $connection->project_id)
                ->where('connection.verificationStatus', BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED)
                ->missing('connection.runtime_access_token'))
            ->assertDontSee('secret-runtime-reader-token');
    }

    public function test_wrong_password_does_not_start_bws_verification_or_flash_secrets(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake();
        $payload = $this->validPayload();
        $payload['password'] = 'wrong-password';

        $response = $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $payload);

        $response->assertSessionHasErrors('password');
        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        Process::assertNothingRan();
    }

    public function test_legacy_totp_input_is_ignored_and_scrubbed_when_password_validation_fails(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake();
        $payload = $this->validPayload();
        $payload['password'] = 'wrong-password';
        $payload['totp_code'] = '000000';

        $response = $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $payload);

        $response->assertSessionHasErrors('password');
        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        Process::assertNothingRan();
    }

    public function test_admin_without_confirmed_totp_cannot_configure_bws(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        Process::fake();

        $this->actingAs($admin)
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        Process::assertNothingRan();
    }

    public function test_basic_user_cannot_configure_bws(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Process::fake();

        $this->actingAs($user)
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        Process::assertNothingRan();
    }

    public function test_bws_verification_failure_does_not_save_or_flash_the_runtime_token(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"project_access_failed","results":[]}',
            errorOutput: 'sensitive provider detail',
            exitCode: 1,
        ));

        $response = $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload());

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionHas('error');
        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_failed_project_access_shows_only_a_sanitized_message(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"project_access_failed","results":[]}',
            errorOutput: 'provider diagnostic includes token data',
            exitCode: 1,
        ));

        $response = $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload());

        $response->assertRedirect(route('admin.home'));
        $response->assertSessionHas('error', 'BWS read access could not be verified. No new token was saved; check the environment project and Can Read machine-account access.');
        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
    }

    public function test_missing_required_bws_secrets_shows_provisioning_guidance_without_saving_the_token(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake(fn () => Process::result(
            '{"ok":false,"error":"missing_secret","results":[]}',
            exitCode: 1,
        ));

        $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload())
            ->assertRedirect(route('admin.home'))
            ->assertSessionHas('error', 'The BWS project is readable, but one or more secrets required for the selected import mode is missing, empty, or invalid. Add the names shown on this page, then retry; no new token was saved.');

        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
    }

    public function test_host_mode_with_no_bws_helper_shows_build_guidance_without_running_a_process(): void
    {
        config([
            'app.env' => 'local',
            'services.bitwarden.helper_command' => '/nonexistent/fieldops-bws-helper',
        ]);
        $admin = $this->adminWithTwoFactor();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $this->validPayload())
            ->assertRedirect(route('admin.home'))
            ->assertSessionHas('error', 'The local BWS helper is not built. Run docker/bws-helper/build-host.sh, then retry; no BWS request was made and no token was saved.');

        $this->assertNoSensitiveOldInput();
        $this->assertDatabaseCount('bitwarden_secret_manager_connections', 0);
        Process::assertNothingRan();
    }

    public function test_request_cannot_choose_bws_service_endpoints(): void
    {
        $admin = $this->adminWithTwoFactor();
        Process::fake(function (PendingProcess $process) {
            $payload = json_decode($process->input, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://api.bitwarden.com', $payload['api_url']);
            $this->assertSame('https://identity.bitwarden.com', $payload['identity_url']);

            return Process::result(json_encode([
                'ok' => true,
                'results' => [],
                'project_access_verified' => true,
                'required_secrets_verified' => true,
            ], JSON_THROW_ON_ERROR));
        });
        $payload = $this->validPayload();
        $payload['api_url'] = 'https://user@example.test';
        $payload['identity_url'] = 'https://attacker.example';

        $response = $this->actingAs($admin)
            ->from(route('admin.home'))
            ->post(route('admin.integrations.bitwarden.store'), $payload);

        $response->assertRedirect(route('admin.home'));
        $this->assertDatabaseHas('bitwarden_secret_manager_connections', [
            'environment' => 'testing',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
    }

    private function adminWithTwoFactor(): User
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $totpSecret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($totpSecret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt('[]'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->unlockAdminOnboardingAreaForTest($admin);

        return $admin;
    }

    /** @return array<string, string> */
    private function validPayload(): array
    {
        return [
            'runtime_access_token' => 'temporary-runtime-token',
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'password' => 'password',
        ];
    }

    private function assertNoSensitiveOldInput(): void
    {
        $oldInput = session()->get('_old_input', []);

        $this->assertArrayNotHasKey('runtime_access_token', $oldInput);
        $this->assertArrayNotHasKey('password', $oldInput);
        $this->assertArrayNotHasKey('totp_code', $oldInput);
    }
}
