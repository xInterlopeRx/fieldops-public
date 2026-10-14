<?php

namespace Tests\Feature;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\User;
use App\Models\UserInvitation;
use App\Services\ApplicationSetupStepService;
use App\Services\OnboardingReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OnboardingReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_install_shows_progress_and_blocks_staff_invitations_until_receipt(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->actingAs($admin)
            ->get(route('admin.onboarding'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Onboarding')
                ->where('readiness.required', true)
                ->where('readiness.invitationsUnlocked', false)
                ->where('readiness.steps.4.key', ApplicationSetupStep::GRAPH_VALIDATION)
                ->where('readiness.steps.4.status', ApplicationSetupStep::STATUS_PENDING));

        $this->actingAs($admin)
            ->post(route('admin.users.invitations.store'), [
                'name' => 'New teammate',
                'email' => 'new-teammate@example.test',
                'roles' => ['basic_user'],
            ])
            ->assertRedirect(route('admin.onboarding'));

        $this->assertDatabaseMissing('user_invitations', ['email' => 'new-teammate@example.test']);
    }

    public function test_existing_invitation_links_are_not_usable_before_readiness(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $token = Str::random(64);
        UserInvitation::query()->create([
            'invited_by' => $admin->getKey(),
            'name' => 'Old invitation',
            'email' => 'old-invite@example.test',
            'roles' => ['basic_user'],
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDay(),
        ]);

        $this->get(route('invitation.accept', $token))->assertNotFound();
        $this->post(route('invitation.accept', $token), [
            'password' => 'Valid-Password-2026!',
            'password_confirmation' => 'Valid-Password-2026!',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'old-invite@example.test']);
    }

    public function test_initial_import_cannot_be_deferred_under_the_customer_and_item_readiness_gate(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);
        $this->connectBwsReader();
        $this->connectQuickBooks();
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::QUICKBOOKS_OAUTH, $admin);
        $this->loginWithTotp($admin);

        $this->post('/admin/onboarding/initial-import/defer', [
            'confirm_defer' => true,
            'password' => 'password',
        ])->assertNotFound();

        $step = ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)->sole();
        $this->assertSame(ApplicationSetupStep::STATUS_PENDING, $step->status);
        $snapshot = app(OnboardingReadinessService::class)->snapshot();
        $this->assertFalse($snapshot['ready']);
        $this->assertContains('Initial QuickBooks Customer/Site/Item import', $snapshot['blockers']);
    }

    public function test_admin_can_defer_and_resume_bws_setup_without_unlocking_invitations(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        $this->loginWithTotp($admin);

        $this->get(route('admin.onboarding'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('readiness.canDeferBwsRuntimeReader', true)
                ->where('readiness.invitationsUnlocked', false));

        $this->post(route('admin.onboarding.bws.defer'), [
            'confirm_defer' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'))
            ->assertSessionHas('status', 'BWS setup is paused. Invitations and BWS-dependent integrations remain locked until the required secrets are verified.');

        $step = ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::BWS_RUNTIME_READER)->sole();
        $this->assertSame(ApplicationSetupStep::STATUS_DEFERRED, $step->status);
        $this->assertFalse(app(OnboardingReadinessService::class)->snapshot()['ready']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        $this->assertContains('Verify BWS read access and required app credentials', app(OnboardingReadinessService::class)->snapshot()['blockers']);

        $this->post(route('admin.onboarding.bws.resume'), [
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'));

        $this->assertSame(ApplicationSetupStep::STATUS_PENDING, $step->fresh()->status);
        $this->assertTrue(app(OnboardingReadinessService::class)->snapshot()['canDeferBwsRuntimeReader']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_quickbooks_steps_are_not_required_by_default_and_graph_only_bws_is_sufficient(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'readiness_completed_by_user_id' => $admin->getKey(),
            'readiness_receipt_type' => 'verified',
        ]);

        $snapshot = app(OnboardingReadinessService::class)->snapshot();
        $steps = collect($snapshot['steps'])->keyBy('key');

        $this->assertFalse($snapshot['quickBooksOptedIn']);
        $this->assertSame(ApplicationSetupStep::STATUS_NOT_REQUIRED, $steps[ApplicationSetupStep::QUICKBOOKS_OAUTH]['status']);
        $this->assertSame(ApplicationSetupStep::STATUS_NOT_REQUIRED, $steps[ApplicationSetupStep::INITIAL_QBO_IMPORT]['status']);
        $this->assertNotContains('Connect the QuickBooks company', $snapshot['blockers']);
        $this->assertNotContains('Initial QuickBooks Customer/Site/Item import', $snapshot['blockers']);
        $this->assertTrue($snapshot['invitationsUnlocked']);
        $this->assertTrue(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_opt_in_makes_intuit_bws_credentials_and_quickbooks_steps_required(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        $this->loginWithTotp($admin);

        $this->put(route('admin.onboarding.quickbooks-opt-in.update'), [
            'opted_in' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'));

        $snapshot = app(OnboardingReadinessService::class)->snapshot();
        $steps = collect($snapshot['steps'])->keyBy('key');

        $this->assertTrue($snapshot['quickBooksOptedIn']);
        $this->assertFalse($snapshot['invitationsUnlocked']);
        $this->assertContains('Verify BWS read access and required app credentials', $snapshot['blockers']);
        $this->assertSame(ApplicationSetupStep::STATUS_PENDING, $steps[ApplicationSetupStep::QUICKBOOKS_OAUTH]['status']);
        $this->assertSame(ApplicationSetupStep::STATUS_PENDING, $steps[ApplicationSetupStep::INITIAL_QBO_IMPORT]['status']);
        $this->assertFalse(BitwardenSecretManagerConnection::query()->sole()->isVerifiedForCurrentEnvironment());
        $this->assertTrue(BitwardenSecretManagerConnection::query()->sole()->isVerifiedForGraph());
        $this->assertDatabaseHas('audit_events', [
            'event' => 'quickbooks_onboarding_opted_in',
            'actor_user_id' => $admin->getKey(),
        ]);
    }

    public function test_opt_in_requires_a_new_bws_check_even_when_an_all_secrets_profile_was_previously_verified(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader(BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED);
        BitwardenSecretManagerConnection::query()->sole()->forceFill([
            'last_verified_at' => now()->subMinute(),
        ])->save();
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        $this->loginWithTotp($admin);

        $this->put(route('admin.onboarding.quickbooks-opt-in.update'), [
            'opted_in' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'));

        $snapshot = app(OnboardingReadinessService::class)->snapshot();

        $this->assertFalse(BitwardenSecretManagerConnection::query()->sole()->isVerifiedForCurrentEnvironment());
        $this->assertContains('Verify BWS read access and required app credentials', $snapshot['blockers']);
    }

    public function test_quickbooks_opt_in_after_readiness_does_not_relock_staff_invitations(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now()->subMinute(),
            'readiness_completed_by_user_id' => $admin->getKey(),
            'readiness_receipt_type' => 'verified',
        ]);
        $this->loginWithTotp($admin);

        $this->put(route('admin.onboarding.quickbooks-opt-in.update'), [
            'opted_in' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'));

        $snapshot = app(OnboardingReadinessService::class)->snapshot();

        $this->assertContains('Verify BWS read access and required app credentials', $snapshot['blockers']);
        $this->assertFalse($snapshot['ready']);
        $this->assertTrue($snapshot['invitationsUnlocked']);
        $this->assertTrue(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_quickbooks_opt_in_is_rejected_for_csv_only_source_mode(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ]);
        $this->loginWithTotp($admin);

        $this->put(route('admin.onboarding.quickbooks-opt-in.update'), [
            'opted_in' => true,
            'confirm_change' => true,
            'password' => 'password',
        ])->assertSessionHasErrors('quickbooks_opt_in');

        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_opted_in'));
    }

    public function test_admin_cannot_complete_readiness_while_graph_validation_is_pending(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader();
        $this->connectQuickBooks();
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::QUICKBOOKS_OAUTH, $admin);
        $this->loginWithTotp($admin);
        $this->postJson(route('password.confirm.store'), ['password' => 'password'])->assertCreated();

        $this->post(route('admin.onboarding.complete'), [
            'confirm_complete' => true,
            'password' => 'password',
        ])->assertSessionHasErrors('readiness');

        $this->assertNull(ApplicationSetupState::query()->whereKey(1)->value('readiness_completed_at'));
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_customer_site_phase_alone_cannot_satisfy_the_initial_items_readiness_gate(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);
        $this->connectBwsReader();
        $this->connectQuickBooks();
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::QUICKBOOKS_OAUTH, $admin);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin);
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'readiness_completed_by_user_id' => $admin->getKey(),
            'readiness_receipt_type' => 'verified',
        ]);
        QuickBooksInitialCustomerImport::query()->create([
            'environment' => app()->environment(),
            'realm_id' => 'sandbox-realm-123',
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'next_start_position' => 3,
            'total_customers' => 2,
            'processed_customers' => 2,
            'imported_customers' => 2,
            'imported_sites' => 4,
            'started_by_user_id' => $admin->getKey(),
            'started_at' => now()->subMinute(),
            'customer_sites_completed_at' => now(),
        ]);

        $snapshot = app(OnboardingReadinessService::class)->snapshot();

        $this->assertFalse($snapshot['ready']);
        $this->assertFalse($snapshot['canComplete']);
        $this->assertFalse($snapshot['invitationsUnlocked']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        $this->assertSame('customer_site_complete', $snapshot['customerSiteImport']['status']);
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, collect($snapshot['steps'])->firstWhere('key', ApplicationSetupStep::INITIAL_QBO_IMPORT)['status']);
        $this->assertContains('Initial QuickBooks Customer/Site/Item import', $snapshot['blockers']);
        $this->assertStringContainsString('Preview and confirm the read-only QuickBooks Item catalog sync', collect($snapshot['steps'])->firstWhere('key', ApplicationSetupStep::INITIAL_QBO_IMPORT)['message']);
    }

    public function test_completed_csv_import_allows_invitations_with_quickbooks_sync_off_and_no_item_catalog(): void
    {
        $admin = $this->verifiedBootstrapAdmin();
        $this->connectBwsReader(BitwardenSecretManagerConnection::PROFILE_GRAPH_ONLY);
        $steps = app(ApplicationSetupStepService::class);
        $steps->markFirstAdminReadyIfEligible($admin);
        $steps->pass(ApplicationSetupStep::BWS_RUNTIME_READER, $admin);
        $steps->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin);
        $steps->pass(ApplicationSetupStep::GRAPH_VALIDATION, $admin);
        $steps->pass(ApplicationSetupStep::FAILURE_RECONCILIATION, $admin);
        QuickBooksInitialCustomerImport::query()->create([
            'environment' => app()->environment(),
            'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'realm_id' => null,
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'next_start_position' => 2,
            'total_customers' => 1,
            'processed_customers' => 1,
            'imported_customers' => 1,
            'imported_sites' => 0,
            'started_by_user_id' => $admin->getKey(),
            'started_at' => now()->subMinute(),
            'customer_sites_completed_at' => now(),
        ]);
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
            'quickbooks_sync_opted_in' => false,
            'quickbooks_sync_opted_in_at' => null,
            'quickbooks_sync_enabled' => false,
            'readiness_completed_at' => now(),
            'readiness_completed_by_user_id' => $admin->getKey(),
            'readiness_receipt_type' => 'verified',
        ]);

        $this->assertSame(0, QuickBooksItemCatalogSync::query()->count());
        $snapshot = app(OnboardingReadinessService::class)->snapshot();
        $csvImportStep = collect($snapshot['steps'])->firstWhere('key', ApplicationSetupStep::INITIAL_QBO_IMPORT);
        $this->assertFalse($snapshot['quickBooksOptedIn']);
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, $csvImportStep['status']);
        $this->assertNull($csvImportStep['message']);
        $this->assertSame(QuickBooksItemCatalogSync::STATUS_PENDING, $snapshot['itemCatalog']['status']);
        $this->assertTrue(app(OnboardingReadinessService::class)->invitationsUnlocked());

        $this->loginWithTotp($admin);
        $this->post(route('admin.users.invitations.store'), [
            'name' => 'CSV-mode teammate',
            'email' => 'csv-mode-teammate@example.test',
            'roles' => ['basic_user'],
        ])->assertRedirect();

        $this->assertDatabaseHas('user_invitations', ['email' => 'csv-mode-teammate@example.test']);
        $this->assertSame(0, QuickBooksItemCatalogSync::query()->count());
        $this->assertFalse((bool) ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled'));
    }

    public function test_legacy_installations_keep_their_invitation_access(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'onboarding_required' => false,
            'readiness_completed_at' => now(),
            'readiness_receipt_type' => 'legacy',
        ]);
        $admin = User::factory()->admin()->create(['status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.users.invitations.store'), [
                'name' => 'Legacy teammate',
                'email' => 'legacy-teammate@example.test',
                'roles' => ['basic_user'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('user_invitations', ['email' => 'legacy-teammate@example.test']);
    }

    public function test_fresh_readiness_receipt_also_requires_the_complete_initial_item_import(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);
        $connection = new BitwardenSecretManagerConnection([
            'environment' => app()->environment(),
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => config('services.bitwarden.api_url'),
            'identity_url' => config('services.bitwarden.identity_url'),
            'configured_by_user_id' => $admin->getKey(),
            'verification_status' => 'read_verified',
            'configured_at' => now(),
            'last_verified_at' => now(),
        ]);
        $connection->runtime_access_token = 'legacy-project-only-reader-token';
        $connection->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'readiness_completed_by_user_id' => $admin->getKey(),
            'readiness_receipt_type' => 'verified',
        ]);
        app(ApplicationSetupStepService::class)->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin);

        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());

        $connection->forceFill([
            'verification_status' => BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED,
        ])->save();

        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_step_progress_is_bounded_and_failure_codes_are_sanitized(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $steps = app(ApplicationSetupStepService::class);
        $steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin);
        $steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin, 4, 10);
        $steps->fail(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin, 'provider response included private data');

        $step = ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)
            ->sole();

        $this->assertSame(ApplicationSetupStep::STATUS_FAILED, $step->status);
        $this->assertSame('setup_step_failed', $step->failure_code);
        $this->assertSame(4, $step->progress_current);
        $this->assertSame(10, $step->progress_total);
        $this->assertSame(1, $step->attempt_count);

        try {
            $steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $admin, 11, 10);
            $this->fail('Out-of-range progress should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Setup progress is outside the allowed bounds.', $exception->getMessage());
        }
    }

    private function verifiedBootstrapAdmin(): User
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode([
                'first-code',
                'second-code',
            ], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $admin->getKey(),
        ]);

        return $admin->fresh();
    }

    private function connectBwsReader(string $profile = BitwardenSecretManagerConnection::PROFILE_ALL_REQUIRED): void
    {
        $connection = new BitwardenSecretManagerConnection;
        $connection->fill([
            'environment' => app()->environment(),
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => config('services.bitwarden.api_url'),
            'identity_url' => config('services.bitwarden.identity_url'),
        ]);
        $connection->runtime_access_token = 'test-runtime-reader-token';
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $connection->verification_profile = $profile;
        $connection->configured_at = now();
        $connection->last_verified_at = now();
        $connection->save();
    }

    private function connectQuickBooks(): void
    {
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

    private function loginWithTotp(User $user): void
    {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $code = app(Google2FA::class)->getCurrentOtp(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        );

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->unlockAdminOnboardingAreaForTest($user);
    }
}
