<?php

namespace Tests\Feature;

use App\Jobs\ProcessQuickBooksInitialCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\Customer;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\Project;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksInitialCustomerImportFailure;
use App\Models\User;
use App\Services\ApplicationSetupStepService;
use App\Services\CsvCustomerSiteImportService;
use App\Services\OnboardingReadinessService;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksInitialCustomerImportService;
use App\Services\SetCustomerImportMode;
use App\Services\SetQuickBooksSyncOptIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class QuickBooksInitialCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_site_import_is_queued_bounded_and_preserves_existing_fieldops_records(): void
    {
        $admin = $this->setupReadyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $nestedChild = false;
        Http::fake($this->customerApiResponses($nestedChild));

        $existing = Customer::query()->create([
            'client_id' => 'C01001',
            'name' => 'Unrelated FieldOps Customer',
        ]);
        $project = Project::query()->create([
            'customer_id' => $existing->getKey(),
            'name' => 'Keep operational project',
            'status' => 'active',
        ]);

        $preview = app(QuickBooksInitialCustomerImportService::class)->preview($admin);

        $this->assertSame(['customers' => 1, 'qboRecords' => 2, 'potentialSites' => 1], $preview);
        $this->assertDatabaseCount('customers', 1);

        $run = app(QuickBooksInitialCustomerImportService::class)->start($admin);

        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS, $run->status);
        $this->assertSame(1, $run->next_start_position);
        Queue::assertPushed(
            ProcessQuickBooksInitialCustomerImport::class,
            fn (ProcessQuickBooksInitialCustomerImport $job): bool => $job->importId === $run->getKey()
                && $job->queue === ProcessQuickBooksInitialCustomerImport::QUEUE,
        );

        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $run->refresh();
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $run->status);
        $this->assertSame(1, $run->imported_customers);
        $this->assertSame(1, $run->imported_sites);
        $this->assertDatabaseHas('customers', [
            'client_id' => 'C01002',
            'quickbooks_id' => '100',
            'name' => 'Sandbox Parent',
        ]);
        $this->assertDatabaseHas('sites', [
            'quickbooks_id' => '101',
            'quickbooks_parent_id' => '100',
            'name' => 'Sandbox Child',
        ]);
        $this->assertModelExists($existing->fresh());
        $this->assertModelExists($project->fresh());
        $this->assertDatabaseCount('quickbooks_initial_customer_import_failures', 0);

        $step = ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)->sole();
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, $step->status);
        $this->assertSame(1, $step->progress_current);
        $this->assertFalse(app(OnboardingReadinessService::class)->snapshot()['ready']);
        $this->assertContains('Initial QuickBooks Customer/Site/Item import', app(OnboardingReadinessService::class)->snapshot()['blockers']);
    }

    public function test_invalid_hierarchy_is_recorded_per_customer_and_resume_retries_idempotently(): void
    {
        $admin = $this->setupReadyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $nestedChild = true;
        Http::fake($this->customerApiResponses($nestedChild));

        $run = app(QuickBooksInitialCustomerImportService::class)->start($admin);
        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $run->refresh();
        $failure = QuickBooksInitialCustomerImportFailure::query()->sole();
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_FAILED, $run->status);
        $this->assertSame('100', $failure->quickbooks_customer_id);
        $this->assertSame('nested_or_orphaned_customer_hierarchy', $failure->failure_code);
        $this->assertSame('qbo_customer_import_records_failed', $run->failure_code);
        $this->assertDatabaseMissing('customers', ['quickbooks_id' => '100']);
        $this->assertSame([
            ['recordId' => '100', 'row' => null, 'code' => 'nested_or_orphaned_customer_hierarchy'],
        ], app(QuickBooksInitialCustomerImportService::class)->snapshot()['failures']);

        $step = ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)->sole();
        $this->assertSame(ApplicationSetupStep::STATUS_FAILED, $step->status);
        $this->assertSame('qbo_customer_import_records_failed', $step->failure_code);

        $nestedChild = false;
        $resumed = app(QuickBooksInitialCustomerImportService::class)->resume($admin);

        $this->assertSame($run->getKey(), $resumed->getKey());
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS, $resumed->status);
        $this->assertSame(1, $resumed->next_start_position);
        $this->assertSame(0, $resumed->processed_customers);
        Queue::assertPushed(ProcessQuickBooksInitialCustomerImport::class, 2);

        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $run->fresh()->status);
        $this->assertDatabaseCount('quickbooks_initial_customer_import_failures', 0);
        $this->assertDatabaseHas('customers', ['quickbooks_id' => '100']);
    }

    public function test_import_stops_if_the_authorized_qbo_company_changes(): void
    {
        $admin = $this->setupReadyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $connection = $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $nestedChild = false;
        Http::fake($this->customerApiResponses($nestedChild));

        $run = app(QuickBooksInitialCustomerImportService::class)->start($admin);
        $connection->forceFill(['realm_id' => 'different-sandbox-company'])->save();

        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_FAILED, $run->fresh()->status);
        $this->assertSame('quickbooks_import_company_changed', $run->fresh()->failure_code);
        $this->assertDatabaseCount('customers', 0);
        Http::assertSentCount(2);
    }

    public function test_retryable_provider_failure_pauses_without_skipping_the_current_customer(): void
    {
        $admin = $this->setupReadyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParameters);
            $query = $queryParameters['query'] ?? '';

            if (str_contains($query, 'FullyQualifiedName LIKE')) {
                return Http::response([], 429, ['Retry-After' => '120']);
            }

            $records = str_contains($query, 'Job = false') ? [$this->parentCustomer()] : [$this->parentCustomer()];

            return Http::response(['QueryResponse' => ['Customer' => $records, 'totalCount' => str_contains($query, 'Job = false') ? 1 : 2]]);
        });

        $run = app(QuickBooksInitialCustomerImportService::class)->start($admin);
        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $run->refresh();
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_FAILED, $run->status);
        $this->assertSame('rate_limited', $run->failure_code);
        $this->assertSame(1, $run->next_start_position);
        $this->assertSame(0, $run->processed_customers);
        $this->assertDatabaseCount('quickbooks_initial_customer_import_failures', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_changed_parent_customer_count_pauses_before_importing_the_mismatched_page(): void
    {
        $admin = $this->setupReadyAdmin();
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $parentCount = 1;
        Http::fake(function (Request $request) use (&$parentCount) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParameters);
            $query = $queryParameters['query'] ?? '';

            if (str_contains($query, 'Job = false')) {
                return Http::response(['QueryResponse' => ['Customer' => [$this->parentCustomer()], 'totalCount' => $parentCount]]);
            }

            return Http::response(['QueryResponse' => ['Customer' => [$this->parentCustomer()], 'totalCount' => 2]]);
        });

        $run = app(QuickBooksInitialCustomerImportService::class)->start($admin);
        $parentCount = 2;

        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))
            ->handle(app(QuickBooksInitialCustomerImportService::class), app(CsvCustomerSiteImportService::class));

        $this->assertSame('qbo_customer_set_changed', $run->fresh()->failure_code);
        $this->assertSame(1, $run->fresh()->next_start_position);
        $this->assertSame(0, $run->fresh()->processed_customers);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_completed_csv_import_can_be_preserved_while_starting_a_separate_quickbooks_import_later(): void
    {
        $admin = $this->setupReadyAdmin();
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_import_mode' => ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        ]);
        $csvRun = QuickBooksInitialCustomerImport::query()->create([
            'environment' => app()->environment(),
            'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'realm_id' => null,
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'next_start_position' => 2,
            'total_customers' => 1,
            'processed_customers' => 1,
            'imported_customers' => 1,
            'imported_sites' => 1,
            'started_by_user_id' => $admin->getKey(),
            'started_at' => now()->subMinute(),
            'customer_sites_completed_at' => now(),
        ]);
        $csvCustomer = Customer::query()->create([
            'client_id' => 'C01001',
            'source_customer_id' => 'CSV-1001',
            'import_source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'name' => 'CSV Local Customer',
        ]);

        try {
            app(SetCustomerImportMode::class)->change(
                $admin,
                ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
            );
            $this->fail('Switching a completed CSV import to API mode must require explicit duplicate-risk confirmation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_api_transition', $exception->errors());
        }

        app(SetCustomerImportMode::class)->change(
            $admin,
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
            confirmCsvApiTransition: true,
        );
        app(SetQuickBooksSyncOptIn::class)->update($admin, true);
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
        $this->fakeIntuitBwsCredentials();
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_verification_required' => false]);
        $this->quickBooksConnection();
        Queue::fake();
        Http::preventStrayRequests();
        $nestedChild = false;
        Http::fake($this->customerApiResponses($nestedChild));

        $apiRun = app(QuickBooksInitialCustomerImportService::class)->start($admin);

        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API, $apiRun->source);
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $csvRun->fresh()->status);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD, $csvRun->fresh()->source);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD, $csvCustomer->fresh()->import_source);
        $this->assertNull($csvCustomer->fresh()->quickbooks_id);
        $this->assertDatabaseCount('quickbooks_initial_customer_imports', 2);
        Queue::assertPushed(ProcessQuickBooksInitialCustomerImport::class);
        Http::assertSentCount(2);
    }

    public function test_preview_counts_bounded_pages_when_qbo_omits_total_count(): void
    {
        $this->configureQuickBooks();
        $this->fakeIntuitBwsCredentials();
        $this->quickBooksConnection();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParameters);
            $query = $queryParameters['query'] ?? '';
            preg_match('/startposition (\d+)/', $query, $positionMatch);
            $startPosition = (int) ($positionMatch[1] ?? 1);
            $pageSize = 50;
            $limit = str_contains($query, 'Job = false') ? 51 : 55;
            $records = [];

            for ($id = $startPosition; $id < min($startPosition + $pageSize, $limit + 1); $id++) {
                $records[] = ['Id' => (string) $id];
            }

            return Http::response(['QueryResponse' => [
                'Customer' => $records,
                'startPosition' => $startPosition,
                'maxResults' => $pageSize,
            ]]);
        });

        $counts = app(QuickBooksApiClient::class)->customerImportCounts();

        $this->assertSame(['customers' => 51, 'qbo_records' => 55], $counts);
        Http::assertSentCount(4);
    }

    private function setupReadyAdmin(): User
    {
        $admin = User::factory()->admin()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['first-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $admin->getKey(),
        ]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());

        return $admin->fresh();
    }

    private function configureQuickBooks(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_opted_in' => true,
            'quickbooks_sync_opted_in_at' => now(),
        ]);
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
    }

    private function quickBooksConnection(): IntegrationConnection
    {
        ApplicationSetupState::query()->whereKey(1)->update([
            'quickbooks_sync_enabled' => true,
        ]);

        return IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-realm-123',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
    }

    /** @return \Closure(Request): Response */
    private function customerApiResponses(bool &$nestedChild): \Closure
    {
        return function (Request $request) use (&$nestedChild) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $queryParameters);
            $query = $queryParameters['query'] ?? '';

            if (str_contains($query, 'Job = false')) {
                return Http::response([
                    'QueryResponse' => [
                        'Customer' => [$this->parentCustomer()],
                        'totalCount' => 1,
                    ],
                ]);
            }

            if (str_contains($query, 'FullyQualifiedName LIKE')) {
                return Http::response([
                    'QueryResponse' => [
                        'Customer' => [$nestedChild ? $this->nestedChild() : $this->childCustomer()],
                        'totalCount' => 1,
                    ],
                ]);
            }

            return Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->parentCustomer()],
                    'totalCount' => 2,
                ],
            ]);
        };
    }

    /** @return array<string, mixed> */
    private function parentCustomer(): array
    {
        return [
            'Id' => '100',
            'SyncToken' => '0',
            'DisplayName' => 'Sandbox Parent',
            'FullyQualifiedName' => 'Sandbox Parent',
            'Job' => false,
            'Active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function childCustomer(): array
    {
        return [
            'Id' => '101',
            'SyncToken' => '0',
            'DisplayName' => 'Sandbox Child',
            'FullyQualifiedName' => 'Sandbox Parent:Sandbox Child',
            'ParentRef' => ['value' => '100', 'name' => 'Sandbox Parent'],
            'Job' => true,
            'Level' => 1,
            'Active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function nestedChild(): array
    {
        return [
            'Id' => '102',
            'SyncToken' => '0',
            'DisplayName' => 'Nested Child',
            'FullyQualifiedName' => 'Sandbox Parent:Sandbox Child:Nested Child',
            'ParentRef' => ['value' => '101', 'name' => 'Sandbox Child'],
            'Job' => true,
            'Level' => 2,
            'Active' => true,
        ];
    }
}
