<?php

namespace Tests\Feature;

use App\Jobs\ProcessFieldPulseCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\FieldPulseImportRun;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApplicationSetupStepService;
use App\Services\FieldPulse\FieldPulseCustomerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class FieldPulseCustomerImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_is_blocked_for_a_non_admin_actor(): void
    {
        Http::fake();
        Process::fake();
        $actor = User::factory()->create(['status' => 'active', 'email_verified_at' => now()]);

        $this->expectException(HttpException::class);

        app(FieldPulseCustomerImportService::class)->start($actor);
    }

    public function test_preview_returns_read_only_counts_and_starts_no_import(): void
    {
        $admin = $this->firstAdmin();
        $this->fakeProvider(['customers' => 112, 'locations' => 538], []);
        Process::fake($this->bwsHelper());

        $preview = app(FieldPulseCustomerImportService::class)->preview($admin);

        $this->assertSame('fieldpulse', $preview['source']);
        $this->assertSame(112, $preview['customers']);
        $this->assertSame(538, $preview['locations']);
        $this->assertNull($preview['existingRun']);
        $this->assertDatabaseCount('fieldpulse_import_runs', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_import_creates_customers_sites_and_contacts_and_is_idempotent(): void
    {
        $admin = $this->firstAdmin();
        Queue::fake();
        $this->fakeProvider(
            ['customers' => 2, 'locations' => 3],
            [
                'customers' => [
                    [
                        'id' => 17787375,
                        'display_name' => 'World Finance',
                        'company_name' => 'World Acceptance Corporation',
                        'first_name' => 'Ryan',
                        'last_name' => 'Hall',
                        'email' => 'ryan.hall@example.test',
                        'phone' => '8645479205',
                        'alt_phone' => '8640000000',
                        'address_1' => '104 South Main Street',
                        'city' => 'Greenville',
                        'state' => 'South Carolina',
                        'zip_code' => '29601',
                        'notes' => 'Primary account',
                        'account_type' => 'individual',
                        'status' => 'current customer',
                        'lead_source' => 'Word of Mouth',
                        'assigned_to' => 42,
                        'created_at' => '2026-08-08 01:09:52',
                        'secondary_first_name' => 'Dana',
                        'secondary_last_name' => 'Hall',
                        'secondary_email' => 'dana@example.test',
                    ],
                    [
                        'id' => 1672,
                        'display_name' => 'T3 Net',
                        'email' => 'ops@t3net.test',
                    ],
                ],
                'locations' => [
                    ['id' => 501, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 17787375, 'title' => 'Main Office', 'address_1' => '104 South Main Street', 'city' => 'Greenville', 'state' => 'South Carolina', 'zip_code' => '29601', 'is_main_location' => true],
                    ['id' => 502, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 17787375, 'title' => 'Warehouse', 'address_1' => '9 Depot Road', 'city' => 'Greenville', 'state' => 'South Carolina', 'zip_code' => '29602'],
                    ['id' => 503, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 1672, 'title' => 'T3 HQ', 'address_1' => '601 North Marienfeld Street', 'city' => 'Midland', 'state' => 'Texas', 'zip_code' => '79701'],
                ],
            ],
        );
        Process::fake($this->bwsHelper());

        $run = app(FieldPulseCustomerImportService::class)->start($admin);
        Queue::assertPushed(ProcessFieldPulseCustomerImport::class);

        $this->drain($run);

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(2, $run->customers_imported);
        $this->assertSame(3, $run->sites_imported);
        $this->assertSame(0, $run->failures()->count());

        $customer = Customer::query()->where('fieldpulse_id', '17787375')->sole();
        $this->assertSame('fieldpulse', $customer->import_source);
        $this->assertSame('World Finance', $customer->name);
        $this->assertSame('current customer', $customer->metadata['fieldpulse']['status']);
        $this->assertSame('Word of Mouth', $customer->metadata['fieldpulse']['lead_source']);
        $this->assertNull($customer->quickbooks_id);
        $this->assertSame(2, $customer->sites()->count(), 'Multi-site: both FieldPulse locations become Sites.');
        $this->assertSame(2, Contact::query()->where('customer_id', $customer->getKey())->count());
        $this->assertSame('fieldpulse', Contact::query()->where('customer_id', $customer->getKey())->first()->source);

        // Re-running the same provider data must not duplicate records.
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_IN_PROGRESS, 'stage' => 'customers', 'customers_page' => 1])->save();
        $this->drain($run);

        $this->assertSame(2, Customer::query()->count());
        $this->assertSame(3, Site::query()->count());
        $this->assertSame(3, Contact::query()->count());
    }

    public function test_onboarding_page_exposes_the_fieldpulse_import_panel(): void
    {
        $admin = $this->firstAdmin();
        $this->loginWithTotp($admin);

        $this->get(route('admin.onboarding'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Onboarding')
                ->where('fieldpulseImport', null));

        Queue::fake();
        $this->fakeProvider(['customers' => 5, 'locations' => 9], []);
        Process::fake($this->bwsHelper());
        app(FieldPulseCustomerImportService::class)->start($admin);

        $this->get(route('admin.onboarding'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Onboarding')
                ->where('fieldpulseImport.status', 'in_progress')
                ->where('fieldpulseImport.stage', 'customers'));
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

    public function test_customer_and_job_notes_import_as_idempotent_timeline_events(): void
    {
        $admin = $this->firstAdmin();
        Queue::fake();
        $this->fakeProvider(
            ['customers' => 1, 'locations' => 1, 'jobs' => 1],
            [
                'customers' => [
                    ['id' => 100, 'display_name' => 'Acme', 'notes' => 'Account-level note', 'created_at' => '2026-01-01 09:00:00'],
                ],
                'locations' => [
                    ['id' => 501, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 100, 'title' => 'Main Office'],
                ],
                'jobs' => [
                    [
                        'id' => 700,
                        'customer_id' => 100,
                        'location_id' => 501,
                        'job_type' => 'service',
                        'notes' => 'Replaced the controller board',
                        'field_notes' => 'Gate code 1234',
                        'start_time' => '2026-01-02 08:00:00',
                    ],
                ],
            ],
        );
        Process::fake($this->bwsHelper());

        $run = app(FieldPulseCustomerImportService::class)->start($admin);
        $this->drain($run);

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(3, $run->notes_imported);
        $this->assertSame(3, TimelineEvent::query()->count());

        $jobNote = TimelineEvent::query()->where('kind', TimelineEvent::KIND_JOB_NOTE)->sole();
        $this->assertSame('Replaced the controller board', $jobNote->body);
        $this->assertSame('700', $jobNote->metadata['fieldpulse_job_id']);
        $this->assertSame('2026-01-02 08:00:00', $jobNote->occurred_at->toDateTimeString());
        $this->assertSame(TimelineEvent::SOURCE_FIELDPULSE, $jobNote->source);

        $customerNote = TimelineEvent::query()->where('kind', TimelineEvent::KIND_CUSTOMER_NOTE)->sole();
        $this->assertSame('Account-level note', $customerNote->body);

        // Re-running must not duplicate timeline events.
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_IN_PROGRESS, 'stage' => 'customers', 'customers_page' => 1])->save();
        $this->drain($run);

        $this->assertSame(3, TimelineEvent::query()->count());
    }

    public function test_jobs_import_as_work_orders_with_status_mapping_and_site_fallback(): void
    {
        $admin = $this->firstAdmin();
        Queue::fake();
        $this->fakeProvider(
            ['customers' => 1, 'locations' => 1, 'jobs' => 2],
            [
                'customers' => [['id' => 100, 'display_name' => 'Acme']],
                'locations' => [['id' => 501, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 100, 'title' => 'Main Office']],
                'jobs' => [
                    [
                        'id' => 700,
                        'customer_id' => 100,
                        'location_id' => 501,
                        'job_type' => 'Repair',
                        'status_id' => 948708,
                        'notes' => 'Replaced the board',
                        'field_notes' => 'Gate code 1234',
                        'start_time' => '2026-02-01 09:00:00',
                        'completed_at' => '2026-02-01T10:00:00.000000Z',
                    ],
                    [
                        'id' => 701,
                        'customer_id' => 100,
                        'location_id' => null,
                        'job_type' => 'Inspect',
                        'status_id' => 948706,
                    ],
                ],
            ],
        );
        Process::fake($this->bwsHelper());

        $run = app(FieldPulseCustomerImportService::class)->start($admin);
        $this->drain($run);

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE, $run->status, $run->failure_code ?? '');
        $this->assertSame(2, $run->jobs_imported);
        $this->assertSame(2, WorkOrder::query()->count());

        $site = Site::query()->where('fieldpulse_id', '501')->sole();

        $completed = WorkOrder::query()->where('fieldpulse_id', '700')->sole();
        $this->assertSame('Repair', $completed->title);
        $this->assertSame('completed', $completed->status);
        $this->assertSame($site->getKey(), $completed->site_id);
        $this->assertSame('Replaced the board', $completed->job_notes);
        $this->assertSame('Gate code 1234', $completed->field_notes);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('700', $completed->metadata['fieldpulse']['job_id']);

        // Job without a location falls back to the Customer's Site, and in_progress maps through.
        $inProgress = WorkOrder::query()->where('fieldpulse_id', '701')->sole();
        $this->assertSame('in_progress', $inProgress->status);
        $this->assertSame($site->getKey(), $inProgress->site_id);

        // Re-running must not duplicate work orders.
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_IN_PROGRESS, 'stage' => 'customers', 'customers_page' => 1])->save();
        $this->drain($run);

        $this->assertSame(2, WorkOrder::query()->count());
    }

    public function test_job_comments_import_as_timeline_events(): void
    {
        $admin = $this->firstAdmin();
        Queue::fake();
        $this->fakeProvider(
            ['customers' => 1, 'locations' => 1, 'jobs' => 1, 'comments' => 2],
            [
                'customers' => [['id' => 100, 'display_name' => 'Acme']],
                'locations' => [['id' => 501, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 100, 'title' => 'Main']],
                'jobs' => [['id' => 700, 'customer_id' => 100, 'location_id' => 501, 'job_type' => 'Repair', 'status_id' => 948708]],
                'comments' => [
                    [
                        'id' => 900,
                        'commentable_type' => 'App\\Http\\Api\\Core\\Models\\BaseJob',
                        'commentable_id' => 700,
                        'text' => 'Warranty call. No invoice.',
                        'created_at' => '2026-09-01 20:14:11',
                        'is_visible_in_customer_portal' => false,
                    ],
                    [
                        'id' => 901,
                        'commentable_type' => 'App\\Http\\Api\\Core\\Models\\BaseJob',
                        'commentable_id' => 999999,
                        'text' => 'Orphan comment',
                        'created_at' => '2026-09-02 10:00:00',
                    ],
                ],
            ],
        );
        Process::fake($this->bwsHelper());

        $run = app(FieldPulseCustomerImportService::class)->start($admin);
        $this->drain($run);

        $run->refresh();
        $this->assertSame(1, $run->comments_imported);
        $this->assertSame(1, $run->failures()->where('resource_type', 'comment')->count());

        $comment = TimelineEvent::query()->where('kind', TimelineEvent::KIND_COMMENT)->sole();
        $this->assertSame('Warranty call. No invoice.', $comment->body);
        $this->assertSame('700', $comment->metadata['fieldpulse_job_id']);
        $customer = Customer::query()->where('fieldpulse_id', '100')->sole();
        $this->assertSame($customer->getKey(), $comment->customer_id);
    }

    public function test_export_writes_a_csv_the_upload_parser_accepts(): void
    {
        $this->firstAdmin();
        Process::fake($this->bwsHelper());
        $this->fakeProvider(['customers' => 2, 'locations' => 0], [
            'customers' => [
                ['id' => 100, 'display_name' => 'Acme', 'email' => 'a@example.test', 'address_1' => '1 Main', 'city' => 'Midland', 'state' => 'Texas', 'zip_code' => '79701', 'account_type' => 'company', 'status' => 'current customer'],
                ['id' => 101, 'display_name' => 'Beta', 'email' => 'not-a-valid-email'],
            ],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'fp-export').'.csv';

        $this->artisan('fieldpulse:export-customers-csv', ['path' => $path])
            ->expectsOutputToContain('Wrote 2 Customer row(s)')
            ->assertSuccessful();

        $this->assertFileExists($path);

        // Assert the exported CSV structure directly: the CSV first-run upload
        // path (and its parser) was removed in ADR-0042, but the bridge export
        // must still emit the same header schema and drop malformed emails.
        $rows = array_map('str_getcsv', array_values(array_filter(file($path), 'trim')));
        $header = array_shift($rows);
        $idColumn = array_search('Customer Id', $header, true);
        $emailColumn = array_search('Email', $header, true);

        $this->assertContains('Customer Id', $header, 'The bridge writes the upload header schema.');
        $this->assertContains('Email', $header);
        $this->assertCount(2, $rows);
        $this->assertSame('100', $rows[0][$idColumn]);
        $this->assertSame('a@example.test', $rows[0][$emailColumn]);
        $this->assertSame('', $rows[1][$emailColumn], 'Malformed source emails are dropped so the file is importable.');

        @unlink($path);
    }

    public function test_location_without_a_matching_customer_is_recorded_and_fails_the_run(): void
    {
        $admin = $this->firstAdmin();
        Queue::fake();
        $this->fakeProvider(
            ['customers' => 0, 'locations' => 1],
            [
                'customers' => [],
                'locations' => [
                    ['id' => 900, 'object_type' => 'App\\Http\\Api\\Core\\Models\\BaseCustomer', 'object_id' => 999999, 'title' => 'Orphan'],
                ],
            ],
        );
        Process::fake($this->bwsHelper());

        $run = app(FieldPulseCustomerImportService::class)->start($admin);
        $this->drain($run);

        $run->refresh();
        $this->assertSame(FieldPulseImportRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->failures()->count());
        $this->assertSame('fieldpulse_location_owner_missing', $run->failures()->sole()->failure_code);
        $this->assertSame('location', $run->failures()->sole()->resource_type);
    }

    public function test_conflicting_quickbooks_import_blocks_a_fieldpulse_run(): void
    {
        $admin = $this->firstAdmin();
        QuickBooksInitialCustomerImport::query()->create([
            'environment' => app()->environment(),
            'source' => 'csv_upload',
            'status' => 'customer_site_complete',
        ]);
        Process::fake($this->bwsHelper());
        Http::preventStrayRequests();

        $this->expectException(ValidationException::class);

        app(FieldPulseCustomerImportService::class)->start($admin);
    }

    private function drain(FieldPulseImportRun $run): void
    {
        $service = app(FieldPulseCustomerImportService::class);
        $guard = 0;

        while ($service->processNextPage($run->getKey())) {
            if (++$guard > 20) {
                $this->fail('The FieldPulse import did not reach a terminal state.');
            }
        }
    }

    /**
     * @param  array{customers: int, locations: int}  $counts
     * @param  array{customers?: list<array<string, mixed>>, locations?: list<array<string, mixed>>}  $records
     */
    private function fakeProvider(array $counts, array $records): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($counts, $records) {
            if (str_contains($request->url(), 'status-workflow-statuses')) {
                return Http::response([
                    'error' => false,
                    'response' => $records['statuses'] ?? [
                        ['id' => 948704, 'name' => 'New', 'type' => 'new'],
                        ['id' => 948705, 'name' => 'On The Way', 'type' => 'travel_time'],
                        ['id' => 948706, 'name' => 'In Progress', 'type' => 'in_progress'],
                        ['id' => 948707, 'name' => 'Pending', 'type' => 'pending'],
                        ['id' => 948708, 'name' => 'Completed', 'type' => 'completed'],
                        ['id' => 948709, 'name' => 'Canceled', 'type' => 'canceled'],
                    ],
                ]);
            }

            if (str_contains($request->url(), '/locations')) {
                return Http::response([
                    'error' => false,
                    'total_count' => $counts['locations'],
                    'response' => $records['locations'] ?? [],
                ]);
            }

            if (str_contains($request->url(), '/jobs')) {
                return Http::response([
                    'error' => false,
                    'total_count' => $counts['jobs'] ?? 0,
                    'response' => $records['jobs'] ?? [],
                ]);
            }

            if (str_contains($request->url(), '/comments')) {
                return Http::response([
                    'error' => false,
                    'total_count' => $counts['comments'] ?? 0,
                    'response' => $records['comments'] ?? [],
                ]);
            }

            if (str_contains($request->url(), '/customers')) {
                return Http::response([
                    'error' => false,
                    'total_count' => $counts['customers'],
                    'response' => $records['customers'] ?? [],
                ]);
            }

            return Http::response(['error' => true], 404);
        });
    }

    private function bwsHelper(): callable
    {
        return fn () => Process::result(json_encode([
            'ok' => true,
            'results' => [],
            'resolved_secrets' => ['FIELDPULSE_API_KEY' => 'fieldpulse-reader-key'],
        ], JSON_THROW_ON_ERROR));
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
        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => true]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());

        $connection = new BitwardenSecretManagerConnection([
            'environment' => app()->environment(),
            'organization_id' => '00000000-0000-0000-0000-000000000001',
            'project_id' => '00000000-0000-0000-0000-000000000002',
            'api_url' => 'https://api.bitwarden.com',
            'identity_url' => 'https://identity.bitwarden.com',
        ]);
        $connection->runtime_access_token = 'read-only-runtime-token';
        $connection->verification_status = BitwardenSecretManagerConnection::STATUS_SECRETS_VERIFIED;
        $connection->last_verified_at = now();
        $connection->save();

        return $admin->fresh();
    }
}
