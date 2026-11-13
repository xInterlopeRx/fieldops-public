<?php

namespace Tests\Feature;

use App\Jobs\ProcessFieldPulseDocumentImport;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\FieldPulseImportRun;
use App\Models\InstallationClaim;
use App\Models\Project;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApplicationSetupStepService;
use App\Services\FieldPulse\FieldPulseDocument;
use App\Services\FieldPulse\FieldPulseDocumentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Fortify\Fortify;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class FieldPulseDocumentImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_import_creates_customers_sites_jobs_and_notes_idempotently(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();

        $file = $this->documentFile();

        $preview = app(FieldPulseDocumentImportService::class)->preview($admin, $file);
        $this->assertSame(1, $preview['customers']);
        $this->assertSame(1, $preview['locations']);
        $this->assertSame(1, $preview['projects']);
        $this->assertSame(1, $preview['jobs']);
        $this->assertSame(1, $preview['jobs_with_project_reference']);
        $this->assertSame(0, $preview['jobs_with_unmatched_project_reference']);
        $this->assertSame(0, $preview['jobs_with_project_customer_mismatch']);
        $this->assertSame(3, $preview['notes']);
        $this->assertDatabaseCount('customers', 0);

        $run = app(FieldPulseDocumentImportService::class)->start($admin, $file);
        Queue::assertPushed(ProcessFieldPulseDocumentImport::class);
        Storage::disk('local')->assertExists(FieldPulseDocumentImportService::documentDiskPath($run->getKey()));

        (new ProcessFieldPulseDocumentImport($run->getKey()))->handle(app(FieldPulseDocumentImportService::class));

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(1, $run->customers_imported);
        $this->assertSame(1, $run->sites_imported);
        $this->assertSame(1, $run->projects_imported);
        $this->assertSame(1, $run->jobs_imported);
        $this->assertSame(3, $run->notes_imported);
        Storage::disk('local')->assertExists(FieldPulseDocumentImportService::documentDiskPath($run->getKey()));
        $this->assertSame(FieldPulseDocumentImportService::documentDiskPath($run->getKey()), $run->document_path);
        $this->assertSame(64, strlen((string) $run->document_sha256), 'The retained document SHA-256 must be recorded.');
        $this->assertGreaterThan(0, (int) $run->document_bytes, 'The retained document size must be recorded.');
        $this->assertNotNull($run->document_retained_at, 'A successful import must retain the uploaded document.');

        $customer = Customer::query()->where('fieldpulse_id', '100')->sole();
        $this->assertSame('Acme', $customer->name);
        $this->assertSame('company', $customer->metadata['fieldpulse']['account_type']);

        $site = Site::query()->where('fieldpulse_id', '501')->sole();
        $this->assertSame($customer->getKey(), $site->customer_id);

        $project = Project::query()->where('fieldpulse_id', '300')->sole();
        $this->assertSame($customer->getKey(), $project->customer_id);
        $this->assertSame('300', $project->project_number);
        $this->assertSame('planning', $project->status);
        $this->assertSame('Electrical repair', $project->metadata['fieldpulse']['description']);

        $job = WorkOrder::query()->where('fieldpulse_id', '700')->sole();
        $this->assertSame('Repair', $job->title);
        $this->assertSame('completed', $job->status);
        $this->assertSame($site->getKey(), $job->site_id);
        $this->assertSame($project->getKey(), $job->project_id);
        $this->assertSame('Replaced board', $job->job_notes);

        $this->assertSame(3, TimelineEvent::query()->count());

        // Re-importing must not duplicate records.
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_IN_PROGRESS, 'stage' => 'customers'])->save();
        app(FieldPulseDocumentImportService::class)->import($run->getKey());

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Site::query()->count());
        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertSame(3, TimelineEvent::query()->count());
    }

    public function test_document_import_leaves_job_unassociated_when_its_project_reference_is_missing(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();
        $file = $this->documentFile();
        $workbook = IOFactory::load($file->getRealPath());
        $jobs = $workbook->getSheetByName('Jobs');
        $jobs->setCellValue('I2', json_encode(['job_id' => '700', 'project_no' => '999']));
        $legacyPath = tempnam(sys_get_temp_dir(), 'fp-unmatched').'.xlsx';
        (new Xlsx($workbook))->save($legacyPath);
        $workbook->disconnectWorksheets();
        $file = new UploadedFile($legacyPath, 'fieldpulse-document.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $run = app(FieldPulseDocumentImportService::class)->start($admin, $file);
        (new ProcessFieldPulseDocumentImport($run->getKey()))->handle(app(FieldPulseDocumentImportService::class));

        $job = WorkOrder::query()->where('fieldpulse_id', '700')->sole();
        $this->assertNull($job->project_id);
        $this->assertSame('999', $job->metadata['fieldpulse']['project_no']);
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->fresh()->status);
    }

    public function test_document_import_refuses_project_association_when_project_and_job_customers_differ(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();
        $file = $this->documentFile(projectCustomerNumber: '200', includeOtherCustomer: true);

        $run = app(FieldPulseDocumentImportService::class)->start($admin, $file);
        (new ProcessFieldPulseDocumentImport($run->getKey()))->handle(app(FieldPulseDocumentImportService::class));

        $this->assertDatabaseMissing('work_orders', ['fieldpulse_id' => '700']);
        $this->assertDatabaseHas('fieldpulse_import_failures', [
            'import_id' => $run->getKey(),
            'resource_type' => 'job',
            'source_record_id' => '700',
            'failure_code' => 'fieldpulse_job_project_customer_mismatch',
        ]);
        $this->assertSame('200', Project::query()->where('fieldpulse_id', '300')->sole()->customer->fieldpulse_id);
        $this->assertSame(FieldPulseImportRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_document_import_does_not_claim_an_existing_project_number_without_a_fieldpulse_id_match(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();
        $customer = Customer::factory()->create(['fieldpulse_id' => '100']);
        $existingProject = Project::factory()->for($customer)->create([
            'project_number' => '300',
            'name' => 'Locally managed project',
        ]);
        $file = $this->documentFile();

        $run = app(FieldPulseDocumentImportService::class)->start($admin, $file);
        (new ProcessFieldPulseDocumentImport($run->getKey()))->handle(app(FieldPulseDocumentImportService::class));

        $existingProject->refresh();
        $this->assertNull($existingProject->fieldpulse_id);
        $this->assertSame('Locally managed project', $existingProject->name);
        $this->assertDatabaseHas('fieldpulse_import_failures', [
            'import_id' => $run->getKey(),
            'resource_type' => 'project',
            'source_record_id' => '300',
            'failure_code' => 'fieldpulse_project_number_conflict',
        ]);
        $this->assertNull(WorkOrder::query()->where('fieldpulse_id', '700')->sole()->project_id);
        $this->assertSame(FieldPulseImportRun::STATUS_FAILED, $run->fresh()->status);
    }

    public function test_document_reader_accepts_legacy_workbook_without_projects_sheet(): void
    {
        $file = $this->documentFile();
        $workbook = IOFactory::load($file->getRealPath());
        $workbook->removeSheetByIndex($workbook->getIndex($workbook->getSheetByName('Projects')));
        $legacyPath = tempnam(sys_get_temp_dir(), 'fp-legacy').'.xlsx';
        (new Xlsx($workbook))->save($legacyPath);
        $workbook->disconnectWorksheets();

        $data = app(FieldPulseDocument::class)->read($legacyPath);

        $this->assertSame([], $data['Projects']);
        $this->assertCount(1, $data['Jobs']);
    }

    private function documentFile(string $projectCustomerNumber = '100', bool $includeOtherCustomer = false): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fp-project-doc').'.xlsx';

        $customers = [[
            'fieldpulse_id' => '100',
            'display_name' => 'Acme',
            'company_name' => 'Acme Inc',
            'email' => 'a@example.test',
            'notes' => 'Account note',
            'account_type' => 'company',
            'status' => 'current customer',
        ]];

        if ($includeOtherCustomer) {
            $customers[] = [
                'fieldpulse_id' => '200',
                'display_name' => 'Other Customer',
                'company_name' => 'Other Customer Inc',
                'email' => 'other@example.test',
                'account_type' => 'company',
            ];
        }

        app(FieldPulseDocument::class)->write($path, [
            'Customers' => $customers,
            'Locations' => [[
                'fieldpulse_id' => '501',
                'customer_fieldpulse_id' => '100',
                'title' => 'Main Office',
                'address' => '1 Main St',
            ]],
            'Projects' => [[
                'fieldpulse_id' => '300',
                'customer_fieldpulse_id' => $projectCustomerNumber,
                'project_number' => '300',
                'name' => 'Main repair project',
                'notes' => 'Project note',
                'status' => 'Opportunity',
                'metadata_json' => json_encode(['description' => 'Electrical repair']),
            ]],
            'Jobs' => [[
                'fieldpulse_id' => '700',
                'customer_fieldpulse_id' => '100',
                'location_fieldpulse_id' => '501',
                'title' => 'Repair',
                'status' => 'completed',
                'job_notes' => 'Replaced board',
                'metadata_json' => json_encode(['job_id' => '700', 'project_no' => '300']),
            ]],
            'Notes' => [
                ['source_record_id' => 'customer:100:notes', 'customer_fieldpulse_id' => '100', 'site_fieldpulse_id' => '', 'kind' => 'customer_note', 'body' => 'Account note', 'occurred_at' => ''],
                ['source_record_id' => 'job:700:notes', 'customer_fieldpulse_id' => '100', 'site_fieldpulse_id' => '501', 'kind' => 'job_note', 'body' => 'Replaced board', 'occurred_at' => '2026-02-01 10:00:00'],
                ['source_record_id' => 'comment:900', 'customer_fieldpulse_id' => '100', 'site_fieldpulse_id' => '501', 'kind' => 'comment', 'body' => 'Warranty call', 'occurred_at' => '2026-02-02 09:00:00'],
            ],
        ]);

        return new UploadedFile($path, 'fieldpulse-document.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_document_import_imports_contacts_site_notes_job_times_and_metadata(): void
    {
        Storage::fake('local');
        Queue::fake();
        $admin = $this->firstAdmin();

        $file = $this->extendedDocumentFile();

        $preview = app(FieldPulseDocumentImportService::class)->preview($admin, $file);
        $this->assertSame(2, $preview['contacts']);

        $run = app(FieldPulseDocumentImportService::class)->start($admin, $file);
        (new ProcessFieldPulseDocumentImport($run->getKey()))->handle(app(FieldPulseDocumentImportService::class));

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        // 1 primary contact from the customer row + the 2 Contacts-sheet rows.
        $this->assertSame(3, $run->contacts_imported);

        // Contacts: distinct types satisfy the per-customer uniqueness rule,
        // archive notes ride in metadata, and each carries its source id.
        $contact = Contact::query()->where('fieldpulse_id', 'CSV-CON-100-01')->first();
        $this->assertNotNull($contact);
        $this->assertSame('secondary', $contact->contact_type);
        $this->assertSame('Riley', $contact->first_name);
        $this->assertSame('Project Manager', $contact->metadata['fieldpulse']['note'] ?? null);
        $additional = Contact::query()->where('fieldpulse_id', 'CSV-CON-100-02')->first();
        $this->assertNotNull($additional);
        $this->assertSame('additional_2', $additional->contact_type);
        $this->assertSame('Supervisor', $additional->metadata['fieldpulse']['note'] ?? null);

        // Site notes ride in site metadata.
        $site = Site::query()->where('fieldpulse_id', '501')->first();
        $this->assertSame('Gate code 9876', $site->metadata['fieldpulse']['notes'] ?? null);

        // Job archive times: metadata keeps everything, completed_at promotes.
        $workOrder = WorkOrder::query()->where('fieldpulse_id', '700')->first();
        $this->assertSame('2026-02-05 10:00:00', $workOrder->completed_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-04 08:00:00', $workOrder->metadata['fieldpulse']['start_at'] ?? null);

        // Notes: customer and comment kinds keyed by their archive sources.
        $this->assertDatabaseHas('timeline_events', ['source_record_id' => 'comment:901', 'kind' => 'comment']);
        $this->assertDatabaseHas('timeline_events', ['source_record_id' => 'customer_note:100', 'kind' => 'customer_note']);
    }

    private function extendedDocumentFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fp-docx').'.xlsx';

        app(FieldPulseDocument::class)->write($path, [
            'Customers' => [[
                'fieldpulse_id' => '100',
                'display_name' => 'Acme',
                'email' => 'a@example.test',
                'metadata_json' => json_encode(['billing_address' => '9 Billing Rd']),
            ]],
            'Locations' => [[
                'fieldpulse_id' => '501',
                'customer_fieldpulse_id' => '100',
                'title' => 'Main Office',
                'address' => '1 Main St',
                'metadata_json' => json_encode(['notes' => 'Gate code 9876']),
            ]],
            'Jobs' => [[
                'fieldpulse_id' => '700',
                'customer_fieldpulse_id' => '100',
                'location_fieldpulse_id' => '501',
                'title' => 'Repair',
                'status' => 'completed',
                'metadata_json' => json_encode([
                    'start_at' => '2026-02-04 08:00:00',
                    'end_at' => '2026-02-04 17:00:00',
                    'completed_at' => '2026-02-05 10:00:00',
                ]),
            ]],
            'Notes' => [
                ['source_record_id' => 'customer_note:100', 'customer_fieldpulse_id' => '100', 'site_fieldpulse_id' => '', 'kind' => 'customer_note', 'body' => 'Account note', 'occurred_at' => '2026-02-01 08:00:00'],
                ['source_record_id' => 'comment:901', 'customer_fieldpulse_id' => '100', 'site_fieldpulse_id' => '501', 'kind' => 'comment', 'body' => 'Nice work', 'occurred_at' => '2026-02-02 09:00:00'],
            ],
            'Contacts' => [
                [
                    'fieldpulse_contact_id' => 'CSV-CON-100-01',
                    'customer_fieldpulse_id' => '100',
                    'contact_type' => 'secondary',
                    'first_name' => 'Riley',
                    'last_name' => 'McCabe',
                    'email' => 'riley@example.test',
                    'phone' => '555-0001',
                    'notes' => 'Project Manager',
                    'created_at' => '2025-01-09 17:56',
                ],
                [
                    'fieldpulse_contact_id' => 'CSV-CON-100-02',
                    'customer_fieldpulse_id' => '100',
                    'contact_type' => 'additional_2',
                    'first_name' => 'Sam',
                    'last_name' => 'Smith',
                    'email' => 'sam@example.test',
                    'phone' => '555-0002',
                    'notes' => 'Supervisor',
                    'created_at' => '2025-01-10 10:00',
                ],
            ],
        ]);

        return new UploadedFile($path, 'fieldpulse-document.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
