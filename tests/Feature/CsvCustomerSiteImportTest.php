<?php

namespace Tests\Feature;

use App\Jobs\ProcessQuickBooksInitialCustomerImport;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\PushQuickBooksSite;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\User;
use App\Services\ApplicationSetupStepService;
use App\Services\CsvCustomerSiteImportService;
use App\Services\OnboardingReadinessService;
use App\Services\QuickBooksApiClient;
use App\Services\QuickBooksCustomerWriteService;
use App\Services\QuickBooksInitialCustomerImportService;
use App\Services\QuickBooksOAuthService;
use App\Services\SetCustomerImportMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class CsvCustomerSiteImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_can_select_csv_mode_before_any_customer_records_exist(): void
    {
        $admin = $this->firstAdmin();

        app(SetCustomerImportMode::class)->change(
            $admin,
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        );

        $this->assertSame(
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
            ApplicationSetupState::query()->whereKey(1)->value('quickbooks_import_mode'),
        );
        $this->assertFalse(app(QuickBooksApiClient::class)->isConnectedForCurrentEnvironment());
        $this->assertFalse(app(QuickBooksOAuthService::class)->isConfigured());

        $readiness = app(OnboardingReadinessService::class)->snapshot();
        $qboStep = collect($readiness['steps'])->firstWhere('key', ApplicationSetupStep::QUICKBOOKS_OAUTH);

        $this->assertSame(ApplicationSetupStep::STATUS_NOT_REQUIRED, $qboStep['status']);
        $this->assertFalse($readiness['invitationsUnlocked']);
        $this->assertContains('Initial Customer/Site CSV import', $readiness['blockers']);
    }

    public function test_csv_preview_and_resumable_import_create_local_only_customers_contacts_sites_and_addresses(): void
    {
        $admin = $this->firstAdmin();
        app(SetCustomerImportMode::class)->change(
            $admin,
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
        );
        Http::preventStrayRequests();
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent('customer-export.csv', $this->csv());

        $preview = app(CsvCustomerSiteImportService::class)->preview($admin, $file);

        $this->assertSame(1, $preview['rows']);
        $this->assertSame(1, $preview['customers']);
        $this->assertSame(1, $preview['sites']);
        $this->assertSame(2, $preview['contacts']);
        $this->assertSame(1, $preview['assignedToUnmappedRows']);
        $this->assertTrue($preview['financialColumnsIgnored']);
        $this->assertContains('Account Status', $preview['unmappedSourceFieldsIgnored']);
        $this->assertSame(0, $preview['invalidRowCount']);
        $this->assertTrue($preview['canStart']);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('quickbooks_initial_customer_imports', 0);

        $run = app(CsvCustomerSiteImportService::class)->start($admin, $file, $preview['sha256']);

        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD, $run->source);
        $this->assertNull($run->realm_id);
        $this->assertSame(64, strlen($run->source_sha256));
        Queue::assertPushed(
            ProcessQuickBooksInitialCustomerImport::class,
            fn (ProcessQuickBooksInitialCustomerImport $job): bool => $job->importId === $run->getKey()
                && $job->queue === ProcessQuickBooksInitialCustomerImport::QUEUE,
        );

        (new ProcessQuickBooksInitialCustomerImport($run->getKey()))->handle(
            app(QuickBooksInitialCustomerImportService::class),
            app(CsvCustomerSiteImportService::class),
        );

        $run->refresh();
        $this->assertSame(0, $run->failures()->count(), implode(',', $run->failures()->pluck('failure_code')->all()));
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(1, $run->imported_customers);
        $customer = Customer::query()->sole();
        $site = $customer->sites()->sole();
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $run->status);
        $this->assertSame(1, $run->imported_customers);
        $this->assertSame(1, $run->imported_sites);
        $this->assertNull($run->source_payload, 'The temporary encrypted CSV payload should be cleared after success.');
        $this->assertSame('CSV-1001', $customer->source_customer_id);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD, $customer->import_source);
        $this->assertNull($customer->quickbooks_id, 'CSV source IDs must not be represented as verified QBO IDs.');
        $this->assertSame('Example Systems', $customer->name);
        $this->assertSame('1 Service Street, Suite 2, Austin, TX, 78701', $site->address);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD, $site->import_source);
        $this->assertNull($site->quickbooks_id);
        $this->assertDatabaseCount('customer_addresses', 2);
        $this->assertSame(
            CustomerAddress::TYPE_BILLING,
            $customer->addresses()->where('address_type', CustomerAddress::TYPE_BILLING)->sole()->address_type,
        );
        $this->assertSame(
            $site->getKey(),
            $customer->addresses()->where('address_type', CustomerAddress::TYPE_SHIPPING)->sole()->source_site_id,
        );
        $this->assertSame(2, Contact::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame(6, DB::table('contact_methods')->count());
        $this->assertArrayNotHasKey('source_metadata', $customer->metadata ?? []);
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)->value('status'));
        $readiness = app(OnboardingReadinessService::class)->snapshot();
        $csvStep = collect($readiness['steps'])->firstWhere('key', ApplicationSetupStep::INITIAL_QBO_IMPORT);
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, $csvStep['status']);
        $this->assertNotContains('Initial Customer/Site CSV import', $readiness['blockers']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());

        $writes = app(QuickBooksCustomerWriteService::class);
        $this->assertSame('csv_import_local_only', $writes->queueCustomer($customer, createIfUnlinked: true));
        $this->assertSame('csv_import_local_only', $writes->queueSite($site));
        Queue::assertNotPushed(PushQuickBooksCustomer::class);
        Queue::assertNotPushed(PushQuickBooksSite::class);
        Http::assertNothingSent();
    }

    public function test_csv_preview_rejects_duplicate_source_ids_without_persisting_customer_data(): void
    {
        $admin = $this->firstAdmin();
        app(SetCustomerImportMode::class)->change($admin, ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV);
        $file = UploadedFile::fake()->createWithContent(
            'customer-export.csv',
            $this->csv()."\n".$this->csvRow('CSV-1001'),
        );

        $preview = app(CsvCustomerSiteImportService::class)->preview($admin, $file);

        $this->assertSame(1, $preview['invalidRowCount']);
        $this->assertSame('csv_customer_id_duplicate', $preview['errors'][0]['code']);
        $this->assertFalse($preview['canStart']);
        $this->assertDatabaseCount('customers', 0);

        try {
            app(CsvCustomerSiteImportService::class)->start($admin, $file, $preview['sha256']);
            $this->fail('CSV rows with duplicate source IDs should not start an import run.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('csv_file', $exception->errors());
        }

        $this->assertDatabaseCount('quickbooks_initial_customer_imports', 0);
    }

    public function test_csv_source_id_conflicts_with_another_import_source_are_not_merged(): void
    {
        $admin = $this->firstAdmin();
        app(SetCustomerImportMode::class)->change($admin, ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV);
        Customer::query()->create([
            'client_id' => 'C01001',
            'source_customer_id' => 'CSV-1001',
            'name' => 'Existing source record',
            'metadata' => ['source' => 'legacy_seed'],
        ]);
        $file = UploadedFile::fake()->createWithContent('customer-export.csv', $this->csv());

        $preview = app(CsvCustomerSiteImportService::class)->preview($admin, $file);

        $this->assertSame(1, $preview['conflictCount']);
        $this->assertFalse($preview['canStart']);
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('quickbooks_initial_customer_imports', 0);
    }

    public function test_admin_can_preview_and_confirm_csv_without_quickbooks_api_credentials(): void
    {
        $admin = $this->firstAdmin();
        app(SetCustomerImportMode::class)->change($admin, ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV);
        Queue::fake([ProcessQuickBooksInitialCustomerImport::class]);
        Http::preventStrayRequests();
        $this->loginWithTotp($admin);
        $csv = $this->csv();

        $previewResponse = $this->post(route('admin.onboarding.initial-import.customer-sites.csv.preview'), [
            'file' => UploadedFile::fake()->createWithContent('customer-export.csv', $csv),
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'))
            ->assertSessionHas('csvImportPreview.customers', 1)
            ->assertSessionHas('csvImportPreview.financialColumnsIgnored', true);

        $preview = $previewResponse->getSession()->get('csvImportPreview');

        $this->post(route('admin.onboarding.initial-import.customer-sites.csv.start'), [
            'file' => UploadedFile::fake()->createWithContent('customer-export.csv', $csv),
            'preview_sha256' => $preview['sha256'],
            'confirm_import' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.onboarding'))
            ->assertSessionHas('status');

        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseHas('quickbooks_initial_customer_imports', [
            'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
        ]);
        Queue::assertPushed(ProcessQuickBooksInitialCustomerImport::class, fn (ProcessQuickBooksInitialCustomerImport $job): bool => $job->queue === ProcessQuickBooksInitialCustomerImport::QUEUE);
        Http::assertNothingSent();
    }

    public function test_import_mode_cannot_be_changed_after_customer_records_exist(): void
    {
        $admin = $this->firstAdmin();
        Customer::query()->create(['name' => 'Existing local record']);

        try {
            app(SetCustomerImportMode::class)->change(
                $admin,
                ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV,
            );
            $this->fail('Import source changes must stop once local Customers exist.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('import_mode', $exception->errors());
        }

        $this->assertSame(
            ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API,
            ApplicationSetupState::query()->whereKey(1)->value('quickbooks_import_mode'),
        );
        $this->assertDatabaseCount('customers', 1);
    }

    private function firstAdmin(): User
    {
        $admin = User::factory()->admin()->create([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $admin->getKey(),
        ]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());

        return $admin->fresh();
    }

    private function loginWithTotp(User $user): void
    {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->unlockAdminOnboardingAreaForTest($user);
    }

    private function csv(): string
    {
        return implode(',', [
            'Customer Id', 'Account Type', 'Account Status', 'First Name', 'Last Name', 'Company Name',
            'Display Name', 'Created', 'Email', 'Alternate Email', 'Phone Number', 'Alternate Phone Number',
            'Notes', 'Address Line 1', 'Address Line 2', 'City', 'State', 'Zip Code',
            'Use Separate Billing Address?', 'Billing Address Line 1', 'Billing Address Line 2',
            'Billing City', 'Billing State', 'Billing Zip Code', 'Secondary First Name', 'Secondary Last Name',
            'Secondary Email', 'Secondary Phone', 'Total Paid', 'Total Unpaid', 'Tags', 'Lead Source',
            'Assigned To [First Name]', 'Assigned To [Last Name]',
        ])."\n".$this->csvRow('CSV-1001');
    }

    private function csvRow(string $customerId): string
    {
        return implode(',', [
            $customerId, 'business', 'active', 'Ada', 'Example', 'Example Systems', 'Example Systems',
            '2026-01-01', 'ada@example.test', 'billing@example.test', '555-0100', '555-0101', 'Synthetic test note',
            '1 Service Street', 'Suite 2', 'Austin', 'TX', '78701', 'yes', '99 Billing Avenue', '', 'Austin', 'TX', '78702',
            'Grace', 'Example', 'grace@example.test', '555-0102', '1000.00', '500.00', 'Priority', 'Referral', 'Demo', 'User',
        ]);
    }
}
