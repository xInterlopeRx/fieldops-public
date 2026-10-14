<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\PushQuickBooksCustomer;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksCustomerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class QuickBooksSyncControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_quickbooks_sync_is_paused_by_default_and_enable_requires_current_password(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->createAdminWithTotp();
        $this->configureQuickBooksForOptIn($admin);
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));

        $this->actingAs($admin)
            ->from(route('admin.integrations.quickbooks.show'))
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => true,
                'confirm_change' => true,
                'password' => 'incorrect-password',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));

        $this->put(route('admin.integrations.quickbooks.sync-control.update'), [
            'enabled' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status', 'QuickBooks sync enabled. Held QuickBooks jobs have resumed.');

        $this->assertTrue((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
        $this->assertDatabaseHas('audit_events', [
            'event' => 'quickbooks_sync_resumed',
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_admin_can_pause_and_resume_quickbooks_work_with_current_password_inside_unlocked_onboarding(): void
    {
        config(['queue.default' => 'database']);
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
        $admin = $this->createAdminWithTotp();
        $this->configureQuickBooksForOptIn($admin);
        $this->unlockAdminOnboardingAreaForTest($admin);

        PushQuickBooksCustomer::dispatch(123, 'request-123')->onQueue('quickbooks-sync');
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => false,
                'confirm_change' => true,
                'password' => 'password',
            ])
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status', 'QuickBooks sync paused. Queued QuickBooks jobs are held; in-flight requests may finish.')
            ->assertSessionMissing('_old_input.password');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
        $this->assertDatabaseHas('jobs', ['queue' => 'quickbooks-paused:quickbooks-sync']);
        $this->assertDatabaseHas('jobs', ['queue' => 'default']);
        $this->assertDatabaseHas('audit_events', ['event' => 'quickbooks_sync_paused', 'actor_user_id' => $admin->id]);

        $this->put(route('admin.integrations.quickbooks.sync-control.update'), [
            'enabled' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status', 'QuickBooks sync enabled. Held QuickBooks jobs have resumed.');

        $this->assertTrue((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
        $this->assertDatabaseHas('jobs', ['queue' => 'quickbooks-sync']);
        $this->assertDatabaseMissing('jobs', ['queue' => 'quickbooks-paused:quickbooks-sync']);
        $this->assertDatabaseHas('audit_events', ['event' => 'quickbooks_sync_resumed', 'actor_user_id' => $admin->id]);
    }

    public function test_quickbooks_switch_rejects_wrong_password_but_ignores_legacy_totp_input(): void
    {
        config(['queue.default' => 'database']);
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
        $admin = $this->createAdminWithTotp();
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->actingAs($admin)
            ->from(route('admin.integrations.quickbooks.show'))
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => false,
                'confirm_change' => true,
                'password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');

        $this->put(route('admin.integrations.quickbooks.sync-control.update'), [
            'enabled' => false,
            'confirm_change' => true,
            'password' => 'password',
            'totp_code' => '000000',
        ])
            ->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status', 'QuickBooks sync paused. Queued QuickBooks jobs are held; in-flight requests may finish.')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
        $this->assertSame(1, AuditEvent::query()->where('event', 'quickbooks_sync_paused')->count());
    }

    public function test_only_an_admin_can_change_quickbooks_sync_state(): void
    {
        config(['queue.default' => 'database']);
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => false,
                'confirm_change' => true,
                'password' => 'password',
            ])
            ->assertForbidden();

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
    }

    public function test_switch_is_rejected_when_the_configured_queue_cannot_hold_jobs(): void
    {
        config(['queue.default' => 'sync']);
        $admin = $this->createAdminWithTotp();
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->actingAs($admin)
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => false,
                'confirm_change' => true,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('quickbooks_sync');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
    }

    public function test_quickbooks_sync_cannot_be_enabled_until_opted_in_and_bws_and_company_are_ready(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->createAdminWithTotp();
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->actingAs($admin)
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => true,
                'confirm_change' => true,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('quickbooks_sync');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
    }

    public function test_quickbooks_sync_cannot_be_enabled_with_a_graph_only_bws_profile_after_opt_in(): void
    {
        config(['queue.default' => 'database']);
        $admin = $this->createAdminWithTotp();
        $this->configureQuickBooksForOptIn($admin, BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY);
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->actingAs($admin)
            ->put(route('admin.integrations.quickbooks.sync-control.update'), [
                'enabled' => true,
                'confirm_change' => true,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('quickbooks_sync');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
    }

    public function test_customer_refresh_requests_are_rejected_while_quickbooks_sync_is_paused(): void
    {
        config(['queue.default' => 'database']);
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => false]);
        $customer = Customer::factory()->create(['quickbooks_id' => 'qb-customer-123']);
        $originalStatus = DB::table('customers')->where('id', $customer->id)->value('quickbooks_sync_status');

        $status = app(QuickBooksCustomerSyncService::class)->requestRefresh($customer);

        $this->assertSame('sync_paused', $status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame($originalStatus, DB::table('customers')->where('id', $customer->id)->value('quickbooks_sync_status'));
    }

    public function test_worker_holds_legacy_default_queue_quickbooks_jobs_without_running_them(): void
    {
        config(['queue.default' => 'database']);
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => false]);
        $customer = Customer::factory()->create([
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'request-legacy-123',
        ]);

        PushQuickBooksCustomer::dispatch((int) $customer->id, 'request-legacy-123')->onQueue('default');

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--queue' => 'default',
            '--once' => true,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 10,
        ])->assertExitCode(0);

        $this->assertDatabaseHas('jobs', ['queue' => 'quickbooks-paused:default']);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('pending', $customer->fresh()->quickbooks_sync_status);
        $this->assertSame('request-legacy-123', $customer->fresh()->quickbooks_write_request_id);
    }

    public function test_quickbooks_api_reads_are_blocked_without_sending_requests_while_paused(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => false]);
        Http::preventStrayRequests();

        try {
            app(QuickBooksApiClient::class)->topLevelCustomerCount();
            $this->fail('QuickBooks API reads should be blocked while synchronization is paused.');
        } catch (QuickBooksApiException $exception) {
            $this->assertSame('quickbooks_sync_paused', $exception->reason);
            $this->assertSame(423, $exception->statusCode);
        }

        Http::assertNothingSent();
    }

    private function createAdminWithTotp(): User
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        return User::factory()->admin()->create([
            'status' => 'active',
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function configureQuickBooksForOptIn(
        User $admin,
        string $profile = BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED,
    ): void {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);

        $bws = new BitwardenSecretManagerConnection([
            'environment' => app()->environment(),
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => config('services.bitwarden.api_url'),
            'identity_url' => config('services.bitwarden.identity_url'),
        ]);
        $bws->runtime_access_token = 'test-runtime-reader-token';
        $bws->configured_by_user_id = $admin->getKey();
        $bws->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $bws->verification_profile = $profile;
        $bws->configured_at = now();
        $bws->last_verified_at = now();
        $bws->save();

        IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => config('services.quickbooks.environment'),
            'realm_id' => 'sandbox-realm-123',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
    }
}
