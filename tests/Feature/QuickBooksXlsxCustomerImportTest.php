<?php

namespace Tests\Feature;

use App\Jobs\ProcessQuickBooksXlsxImport;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\User;
use App\Services\ApplicationSetupStepService;
use App\Services\QuickBooks\QuickBooksXlsxCustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class QuickBooksXlsxCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_quickbooks_file_import_loads_customers_and_addresses_with_a_synthetic_key(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();
        $file = $this->workbookFile();

        $preview = app(QuickBooksXlsxCustomerImportService::class)->preview($admin, $file);
        $this->assertSame(1, $preview['customers']);
        $this->assertSame(1, $preview['billingAddresses']);
        $this->assertSame(1, $preview['shippingAddresses']);
        $this->assertTrue($preview['canStart']);
        $this->assertDatabaseCount('customers', 0);

        $run = app(QuickBooksXlsxCustomerImportService::class)->start($admin, $file);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX, $run->source);
        Queue::assertPushed(ProcessQuickBooksXlsxImport::class);

        (new ProcessQuickBooksXlsxImport($run->getKey()))->handle(app(QuickBooksXlsxCustomerImportService::class));

        $run->refresh();
        $this->assertSame(QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(1, $run->imported_customers);
        Storage::disk('local')->assertExists(QuickBooksXlsxCustomerImportService::documentDiskPath($run->getKey()));
        $this->assertSame(64, strlen((string) $run->source_sha256), 'The retained report SHA-256 must be recorded.');

        $customer = Customer::query()->sole();
        $this->assertSame('Acme Industrial', $customer->name);
        $this->assertSame('Chad', $customer->first_name);
        $this->assertSame('Nester', $customer->last_name);
        $this->assertSame('cnester@example.test', $customer->email);
        $this->assertSame('(540) 430-1192', $customer->phone);
        $this->assertSame(QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX, $customer->import_source);
        $this->assertNull($customer->quickbooks_id);
        $this->assertStringStartsWith('QBX-', (string) $customer->source_customer_id);

        $billing = CustomerAddress::query()->where('address_type', CustomerAddress::TYPE_BILLING)->sole();
        $this->assertSame('1030 Andrews Highway', $billing->line1);
        $this->assertSame('Midland', $billing->city);
        $this->assertSame('Texas', $billing->country_subdivision_code);
        $this->assertSame('79701', $billing->postal_code);

        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::INITIAL_QBO_IMPORT)->sole()->status);

        // Re-running must not duplicate records.
        $run->forceFill(['status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS])->save();
        Storage::disk('local')->put(QuickBooksXlsxCustomerImportService::documentDiskPath($run->getKey()), file_get_contents($file->getRealPath()));
        app(QuickBooksXlsxCustomerImportService::class)->import($run->getKey());

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(2, CustomerAddress::query()->count());
    }

    private function workbookFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qb-xlsx').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Customer Contact List');
        $sheet->setCellValue('A1', 'Byteharder Industrial Networks');
        $sheet->setCellValue('A2', 'Customer Contact List');
        $sheet->fromArray(['Customer', 'Phone Numbers', 'Email', 'Full Name', 'Billing Address', 'Shipping Address'], null, 'B5');
        $sheet->fromArray([
            'Acme Industrial',
            'Phone: (540) 430-1192',
            'cnester@example.test',
            'Chad Nester',
            "1030 Andrews Highway\nSuite 109 E\nMidland Texas 79701",
            "1030 Andrews Highway\nSuite 109 E\nMidland Texas 79701",
        ], null, 'B6');
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'customers.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function firstAdmin(): User
    {
        $admin = User::factory()->admin()->create(['status' => 'active', 'email_verified_at' => now()]);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        InstallationClaim::query()->whereKey(1)->update(['claimed_at' => now(), 'claimed_by_user_id' => $admin->getKey()]);
        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => true]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());

        return $admin->fresh();
    }
}
