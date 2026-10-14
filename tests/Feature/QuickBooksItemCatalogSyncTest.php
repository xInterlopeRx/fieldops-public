<?php

namespace Tests\Feature;

use App\Jobs\ProcessQuickBooksItemCatalogSyncPage;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\Item;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\User;
use App\Services\ApplicationSetupStepService;
use App\Services\OnboardingReadinessService;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksInitialCustomerImportService;
use App\Services\QuickBooksItemCatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class QuickBooksItemCatalogSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_catalog_sync_sanitizes_full_qbo_entities_and_passes_the_item_readiness_phase(): void
    {
        $admin = $this->readyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection($admin);
        $this->customerSiteImport($admin);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->qboItemResponse($request));

        $service = app(QuickBooksItemCatalogSyncService::class);
        $safePage = app(QuickBooksApiClient::class)->itemPage(1);
        $this->assertSame(['id', 'name', 'description', 'type', 'active'], array_keys($safePage['records'][0]));
        $this->assertSame('Synthetic line item description', $safePage['records'][0]['description']);
        $this->assertSame(['items' => 4], $service->preview($admin));

        $sync = $service->start($admin);

        $this->assertSame(QuickBooksItemCatalogSync::STATUS_IN_PROGRESS, $sync->status);
        Queue::assertPushed(
            ProcessQuickBooksItemCatalogSyncPage::class,
            fn (ProcessQuickBooksItemCatalogSyncPage $job): bool => $job->syncId === $sync->getKey()
                && $job->queue === ProcessQuickBooksItemCatalogSyncPage::QUEUE,
        );

        (new ProcessQuickBooksItemCatalogSyncPage($sync->getKey()))->handle($service);

        $sync->refresh();
        $this->assertSame(QuickBooksItemCatalogSync::STATUS_COMPLETE, $sync->status);
        $this->assertSame(4, $sync->imported_items);
        $this->assertDatabaseHas('items', [
            'quickbooks_realm_id' => 'sandbox-realm-items',
            'quickbooks_id' => '200',
            'name' => 'Service visit',
            'description' => 'Synthetic line item description',
            'type' => Item::TYPE_SERVICE,
            'active' => true,
        ]);
        $this->assertDatabaseHas('items', [
            'quickbooks_id' => '203',
            'type' => Item::TYPE_CATEGORY,
        ]);
        $this->assertDatabaseMissing('items', ['quickbooks_id' => '200', 'unit_price' => 125.50]);
        $this->assertFalse(Schema::hasColumn('items', 'unit_price'));
        $this->assertFalse(Schema::hasColumn('items', 'purchase_cost'));
        $this->assertFalse(Schema::hasColumn('items', 'income_account_ref'));
        $this->assertFalse(Schema::hasColumn('items', 'qty_on_hand'));
        $this->assertTrue(Schema::hasColumn('items', 'description'));
        $this->assertTrue(Schema::hasColumn('job_items', 'item_description_snapshot'));
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)
            ->value('status'));

        try {
            app(QuickBooksInitialCustomerImportService::class)->start($admin);
            $this->fail('A completed Customer/Site/Item import must not restart the Customer/Site phase.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quickbooks_import', $exception->errors());
        }

        $snapshot = $service->snapshot();
        $this->assertSame(3, $snapshot['activeItems']);
        $this->assertSame(2, $snapshot['selectableItems']);
        $this->assertArrayNotHasKey('unitPrice', $snapshot);
        $this->assertTrue(app(QuickBooksInitialCustomerImportService::class)->snapshot()['status'] === QuickBooksInitialCustomerImport::STATUS_COMPLETE);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked(), 'Graph readiness remains a separate hard gate.');
        Http::assertSentCount(5);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    public function test_item_catalog_sync_fails_closed_when_financial_response_fields_are_malformed_or_item_identity_is_missing(): void
    {
        $admin = $this->readyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection($admin);
        $this->customerSiteImport($admin);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($query['query'] ?? '', 'count(*)')) {
                return Http::response(['QueryResponse' => ['totalCount' => 1]]);
            }

            return Http::response(['QueryResponse' => [
                'Item' => [[
                    'UnitPrice' => 9_999_999,
                    'PurchaseCost' => 8_888_888,
                    'IncomeAccountRef' => ['value' => 'sensitive-account-id'],
                    'QtyOnHand' => 12345,
                    'Type' => 'Service',
                    'Active' => true,
                ]],
                'totalCount' => 1,
            ]]);
        });

        $service = app(QuickBooksItemCatalogSyncService::class);
        $run = $service->start($admin);

        (new ProcessQuickBooksItemCatalogSyncPage($run->getKey()))->handle($service);

        $this->assertSame(QuickBooksItemCatalogSync::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('invalid_items_response', $run->fresh()->failure_code);
        $this->assertDatabaseCount('items', 0);
        $this->assertSame(0, $service->snapshot()['importedItems']);
    }

    public function test_csv_only_mode_cannot_preview_or_sync_quickbooks_items(): void
    {
        $admin = $this->readyAdmin();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ]);
        Http::preventStrayRequests();

        try {
            app(QuickBooksItemCatalogSyncService::class)->preview($admin);
            $this->fail('CSV-only mode must not use the QuickBooks Item API.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('item_import', $exception->errors());
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('items', 0);
    }

    public function test_admin_can_refresh_the_read_only_item_catalog_from_the_quickbooks_page(): void
    {
        $this->configureQuickBooks();
        ApplicationSetupState::query()->whereKey(1)->update([
            'onboarding_required' => false,
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
        ]);
        $this->fakeIntuitBwsCredentials();
        $admin = $this->unclaimedAdminWithTotp();
        $this->quickBooksConnection($admin);
        Queue::fake([ProcessQuickBooksItemCatalogSyncPage::class]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_contains($query['query'] ?? '', 'count(*)')) {
                return Http::response(['QueryResponse' => ['totalCount' => 1]]);
            }

            return Http::response(['QueryResponse' => [
                'Item' => [['Id' => '299', 'Name' => 'Manual sync Item', 'Type' => 'Service', 'Active' => true]],
                'totalCount' => 1,
            ]]);
        });

        $this->actingAs($admin)
            ->get(route('admin.integrations.quickbooks.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('itemCatalog.status', QuickBooksItemCatalogSync::STATUS_PENDING)
                ->where('itemCatalog.canSync', true));

        $this->post(route('admin.integrations.quickbooks.items.sync'), [
            'confirm_sync' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.quickbooks.show'))
            ->assertSessionHas('status');

        $this->assertDatabaseHas('quickbooks_item_catalog_syncs', [
            'environment' => app()->environment(),
            'status' => QuickBooksItemCatalogSync::STATUS_IN_PROGRESS,
        ]);
        Queue::assertPushed(ProcessQuickBooksItemCatalogSyncPage::class);
        Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
    }

    private function readyAdmin(): User
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'onboarding_required' => true,
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
        ]);
        $admin = User::factory()->admin()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['item-import-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $admin->getKey(),
        ]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());

        $this->unlockAdminOnboardingAreaForTest($admin);

        return $admin->fresh();
    }

    private function unclaimedAdminWithTotp(): User
    {
        $admin = User::factory()->admin()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['item-sync-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->unlockAdminOnboardingAreaForTest($admin);

        return $admin->fresh();
    }

    private function configureQuickBooks(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
            'quickbooks_sync_enabled' => true,
        ]);
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
    }

    private function quickBooksConnection(User $admin): IntegrationConnection
    {
        return IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-realm-items',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_by_user_id' => $admin->getKey(),
            'connected_at' => now(),
        ]);
    }

    private function customerSiteImport(User $admin): QuickBooksInitialCustomerImport
    {
        return QuickBooksInitialCustomerImport::query()->create([
            'environment' => app()->environment(),
            'source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API,
            'realm_id' => 'sandbox-realm-items',
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'next_start_position' => 2,
            'total_customers' => 1,
            'processed_customers' => 1,
            'imported_customers' => 1,
            'imported_sites' => 0,
            'started_by_user_id' => $admin->getKey(),
            'started_at' => now(),
            'customer_sites_completed_at' => now(),
        ]);
    }

    private function qboItemResponse(Request $request)
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (str_contains($query['query'] ?? '', 'count(*)')) {
            return Http::response(['QueryResponse' => ['totalCount' => 4]]);
        }

        return Http::response(['QueryResponse' => [
            'Item' => [
                [
                    'Id' => '200',
                    'Name' => 'Service visit',
                    'Description' => 'Synthetic line item description',
                    'Type' => 'Service',
                    'Active' => true,
                    'UnitPrice' => 125.50,
                    'PurchaseCost' => 42.25,
                    'IncomeAccountRef' => ['value' => 'income-account-1'],
                ],
                [
                    'Id' => '201',
                    'Name' => 'Inventory supply',
                    'Type' => 'Inventory',
                    'Active' => false,
                    'UnitPrice' => 300,
                    'PurchaseCost' => 120,
                    'QtyOnHand' => 16,
                ],
                [
                    'Id' => '202',
                    'Name' => 'Grouped labor',
                    'Type' => 'Group',
                    'Active' => true,
                    'UnitPrice' => 200,
                ],
                [
                    'Id' => '203',
                    'Name' => 'Organizing category',
                    'Type' => 'Category',
                    'Active' => true,
                ],
            ],
            'totalCount' => 4,
        ]]);
    }
}
