<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\PushQuickBooksSite;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\IntegrationConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\QuickBooksCustomerWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuickBooksCustomerWriteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
    }

    public function test_new_customer_write_creates_and_links_the_quickbooks_customer(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer*' => Http::response([
                'Customer' => [
                    'Id' => '901',
                    'SyncToken' => '0',
                    'DisplayName' => 'Writeback Verification Customer',
                    'CompanyName' => 'FieldOps Sandbox Demo',
                    'GivenName' => 'Writeback',
                    'FamilyName' => 'Customer',
                    'PrimaryEmailAddr' => ['Address' => 'writeback@example.test'],
                    'PrimaryPhone' => ['FreeFormNumber' => '555-0181'],
                    'Notes' => 'Created through FieldOps in sandbox.',
                    'BillAddr' => ['Id' => '400', 'Line1' => '100 Demo Road', 'City' => 'Austin'],
                    'ShipAddr' => ['Id' => '401', 'Line1' => '200 Demo Road', 'City' => 'Austin'],
                    'Job' => false,
                    'Active' => true,
                    'FullyQualifiedName' => 'Writeback Verification Customer',
                ],
            ]),
        ]);
        $customer = Customer::create([
            'client_id' => 'C01027',
            'name' => 'Writeback Verification Customer',
            'company_name' => 'FieldOps Sandbox Demo',
            'first_name' => 'Writeback',
            'last_name' => 'Customer',
            'email' => 'writeback@example.test',
            'phone' => '555-0181',
            'notes' => 'Created through FieldOps in sandbox.',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'writeback-create-901',
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => '100 Demo Road',
            'city' => 'Austin',
            'is_default' => true,
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => '200 Demo Road',
            'city' => 'Austin',
            'is_default' => true,
        ]);

        (new PushQuickBooksCustomer($customer->id, 'writeback-create-901'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $customer->refresh();
        $this->assertSame('901', $customer->quickbooks_id);
        $this->assertSame('0', $customer->quickbooks_sync_token);
        $this->assertSame('synced', $customer->quickbooks_sync_status);
        $this->assertNull($customer->quickbooks_write_request_id);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/customer?requestid=writeback-create-901')
            && ! isset($request['Id'])
            && $request['DisplayName'] === 'Writeback Verification Customer'
            && $request['Notes'] === 'Created through FieldOps in sandbox.'
            && $request['BillAddr']['Line1'] === '100 Demo Road'
            && $request['ShipAddr']['Line1'] === '200 Demo Road');
    }

    public function test_creating_a_customer_queues_an_idempotent_sandbox_write_with_default_addresses(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Queue::fake([PushQuickBooksCustomer::class]);
        $user = $this->user();

        $this->actingAs($user)
            ->post(route('customers.store'), [
                'name' => 'FieldOps Sandbox Writeback Demo 20260929',
                'first_name' => 'FieldOps',
                'last_name' => 'Demo',
                'email' => 'fieldops-writeback@example.test',
                'phone' => '555-0198',
                'notes' => 'Synthetic sandbox writeback test.',
                'billing_address' => [
                    'line1' => '100 Demo Road',
                    'city' => 'Austin',
                    'country_subdivision_code' => 'TX',
                    'postal_code' => '78701',
                    'country' => 'USA',
                ],
                'shipping_address' => [
                    'line1' => '200 Demo Road',
                    'city' => 'Austin',
                    'country_subdivision_code' => 'TX',
                    'postal_code' => '78702',
                    'country' => 'USA',
                ],
            ])
            ->assertRedirect(route('calendar'));

        $customer = Customer::query()->where('name', 'FieldOps Sandbox Writeback Demo 20260929')->firstOrFail();
        $this->assertSame('pending', $customer->quickbooks_sync_status);
        $this->assertNull($customer->quickbooks_id);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address_type' => CustomerAddress::TYPE_BILLING,
            'line1' => '100 Demo Road',
            'source' => CustomerAddress::SOURCE_MANUAL,
            'is_default' => true,
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'line1' => '200 Demo Road',
            'source' => CustomerAddress::SOURCE_MANUAL,
            'is_default' => true,
        ]);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->id
            && $job->requestId === $customer->quickbooks_write_request_id);
    }

    public function test_work_order_site_created_for_a_linked_customer_queues_a_qbo_child_write(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        $user = $this->user();
        $customer = Customer::create([
            'name' => 'Parent Customer',
            'quickbooks_id' => '100',
        ]);
        Queue::fake([PushQuickBooksSite::class]);

        $this->actingAs($user)
            ->post(route('work-orders.store'), [
                'title' => 'Demo site writeback work',
                'scope' => 'Replace the exterior lighting and verify operation.',
                'status' => 'scheduled',
                'scheduled_at' => now()->addDays(1)->toDateTimeString(),
                'customer_id' => $customer->id,
                'site_name' => 'FieldOps Demo Site Writeback',
                'site_address' => '500 Demo Lane',
            ])
            ->assertRedirect(route('calendar'));

        $site = Site::query()->where('name', 'FieldOps Demo Site Writeback')->firstOrFail();
        $this->assertSame($customer->id, $site->customer_id);
        $this->assertNull($site->quickbooks_id);
        $this->assertNotNull($site->quickbooks_write_request_id);
        Queue::assertPushed(PushQuickBooksSite::class, fn (PushQuickBooksSite $job): bool => $job->siteId === $site->id
            && $job->requestId === $site->quickbooks_write_request_id);
    }

    public function test_customer_update_route_queues_qbo_write_for_a_linked_customer(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        $customer = Customer::create([
            'client_id' => 'C01027',
            'name' => 'Existing Sandbox Customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '2',
            'quickbooks_sync_status' => 'synced',
        ]);
        Queue::fake([PushQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->put(route('customers.update', $customer), [
                'name' => 'Updated Sandbox Customer',
                'first_name' => 'Updated',
                'last_name' => 'Customer',
                'email' => 'updated@example.test',
                'phone' => '555-0110',
                'notes' => 'Updated from FieldOps.',
                'billing_address' => ['line1' => 'New billing location'],
                'shipping_address' => ['line1' => 'New shipping location'],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame('Updated Sandbox Customer', $customer->name);
        $this->assertSame('pending', $customer->quickbooks_sync_status);
        $this->assertNotNull($customer->quickbooks_write_request_id);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->id
            && $job->requestId === $customer->quickbooks_write_request_id);
    }

    public function test_customer_edit_queues_only_the_fields_changed_in_fieldops(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        $customer = Customer::create([
            'name' => 'Existing Sandbox Customer',
            'email' => 'preserve@example.test',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '2',
            'quickbooks_sync_status' => 'synced',
            'quickbooks_last_synced_at' => now(),
        ]);
        Queue::fake([PushQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->put(route('customers.update', $customer), [
                'name' => 'Existing Sandbox Customer',
                'email' => 'preserve@example.test',
                'notes' => 'Only the notes changed.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $customer->refresh();
        $this->assertSame(['notes'], $customer->quickbooks_write_fields);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->id);
    }

    public function test_customer_cannot_be_edited_while_a_qbo_write_is_unresolved(): void
    {
        $this->configureSandbox();
        $customer = Customer::create([
            'name' => 'Pending Customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'pending-write-100',
            'quickbooks_write_fields' => ['notes'],
        ]);
        Queue::fake();

        $this->actingAs($this->user())
            ->put(route('customers.update', $customer), [
                'name' => 'Changed While Pending',
                'notes' => 'Must wait for the existing request.',
            ])
            ->assertSessionHasErrors('quickbooks');

        $this->assertSame('Pending Customer', $customer->fresh()->name);
        Queue::assertNothingPushed();
    }

    public function test_customer_cannot_remove_a_qbo_linked_default_address_from_fieldops(): void
    {
        $this->configureSandbox();
        $customer = Customer::create([
            'name' => 'Address Customer',
            'quickbooks_id' => '100',
        ]);
        $address = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'quickbooks_address_id' => 'billing-address-1',
            'line1' => '100 Billing Road',
            'is_default' => true,
        ]);
        Queue::fake();

        $this->actingAs($this->user())
            ->put(route('customers.update', $customer), [
                'name' => 'Address Customer',
                'billing_address' => ['line1' => ''],
            ])
            ->assertSessionHasErrors('billing_address.line1');

        $this->assertSame('100 Billing Road', $address->fresh()->line1);
        Queue::assertNothingPushed();
    }

    public function test_customer_response_reports_pending_write_without_exposing_request_details(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        $customer = Customer::create([
            'name' => 'Pending Customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'pending-write-100',
            'quickbooks_write_fields' => ['notes'],
            'quickbooks_write_payload' => ['Notes' => 'Private write snapshot'],
        ]);
        Queue::fake();

        $response = $this->actingAs($this->user())
            ->getJson(route('customers.show', $customer));

        $response
            ->assertOk()
            ->assertJsonPath('quickbooks_sync.write_pending', true)
            ->assertJsonPath('quickbooks_sync.status', 'pending')
            ->assertJsonMissingPath('data.quickbooks_write_request_id')
            ->assertJsonMissingPath('data.quickbooks_write_fields')
            ->assertJsonMissingPath('data.quickbooks_write_payload');
        Queue::assertNothingPushed();
    }

    public function test_retry_reuses_the_persisted_payload_and_request_id(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        $postCount = 0;
        Http::fake(function (HttpRequest $request) use (&$postCount) {
            if ($request->method() === 'GET') {
                return Http::response(['Customer' => [
                    'Id' => '100',
                    'SyncToken' => '7',
                    'Job' => false,
                ]]);
            }

            $postCount++;

            return $postCount === 1
                ? Http::response(['Fault' => ['type' => 'SERVICE']], 503)
                : Http::response(['Customer' => [
                    'Id' => '100',
                    'SyncToken' => '8',
                    'DisplayName' => 'Snapshot Customer',
                    'Notes' => 'Original note',
                    'Job' => false,
                ]]);
        });
        $customer = Customer::create([
            'name' => 'Snapshot Customer',
            'notes' => 'Original note',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '7',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'snapshot-write-100',
            'quickbooks_write_fields' => ['notes'],
        ]);
        $writes = app(QuickBooksCustomerWriteService::class);

        try {
            $writes->pushCustomer($customer->id, 'snapshot-write-100');
            $this->fail('The first QuickBooks write should be retryable.');
        } catch (QuickBooksApiException $exception) {
            $this->assertSame(503, $exception->statusCode);
        }

        $this->assertSame('Original note', $customer->fresh()->quickbooks_write_payload['Notes']);
        $customer->forceFill(['notes' => 'A later local value'])->save();

        $writes->pushCustomer($customer->id, 'snapshot-write-100');

        $customer->refresh();
        $this->assertSame('8', $customer->quickbooks_sync_token);
        $this->assertNull($customer->quickbooks_write_payload);
        $posts = collect(Http::recorded())
            ->filter(fn (array $record): bool => $record[0]->method() === 'POST')
            ->values();
        $this->assertCount(2, $posts);
        $this->assertSame('Original note', $posts[0][0]['Notes']);
        $this->assertSame('Original note', $posts[1][0]['Notes']);
        $this->assertSame($posts[0][0]->url(), $posts[1][0]->url());
    }

    public function test_customer_write_pushes_a_sparse_update_and_stores_qbo_version(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'GET') {
                return Http::response(['Customer' => [
                    'Id' => '100',
                    'SyncToken' => '2',
                    'DisplayName' => 'Existing Sandbox Customer',
                    'Job' => false,
                ]]);
            }

            return Http::response(['Customer' => [
                'Id' => '100',
                'SyncToken' => '3',
                'DisplayName' => 'Updated Sandbox Customer',
                'GivenName' => 'Updated',
                'FamilyName' => 'Customer',
                'PrimaryEmailAddr' => ['Address' => 'updated@example.test'],
                'PrimaryPhone' => ['FreeFormNumber' => '555-0110'],
                'Notes' => 'Updated sandbox note',
                'Job' => false,
                'Active' => true,
                'MetaData' => ['LastUpdatedTime' => '2026-09-29T12:00:00-07:00'],
            ]]);
        });
        $customer = Customer::create([
            'name' => 'Updated Sandbox Customer',
            'first_name' => 'Updated',
            'last_name' => 'Customer',
            'email' => 'updated@example.test',
            'phone' => '555-0110',
            'notes' => 'Updated sandbox note',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '2',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'write-request-100',
            'quickbooks_write_fields' => ['notes'],
        ]);

        (new PushQuickBooksCustomer($customer->id, 'write-request-100'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $customer->refresh();
        $this->assertSame('3', $customer->quickbooks_sync_token);
        $this->assertSame('synced', $customer->quickbooks_sync_status);
        $this->assertNull($customer->quickbooks_write_request_id);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/customer?requestid=write-request-100')
            && $request['Id'] === '100'
            && $request['SyncToken'] === '2'
            && $request['sparse'] === true
            && $request['Notes'] === 'Updated sandbox note');
    }

    public function test_customer_write_stops_without_posting_when_quickbooks_sync_token_is_newer(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => [
                    'Id' => '100',
                    'SyncToken' => '3',
                    'DisplayName' => 'Changed directly in QuickBooks',
                    'Job' => false,
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'FieldOps edit awaiting push',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '2',
            'quickbooks_sync_status' => 'pending',
            'quickbooks_write_request_id' => 'conflict-write-100',
            'quickbooks_write_fields' => ['name'],
        ]);

        (new PushQuickBooksCustomer($customer->id, 'conflict-write-100'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $customer->refresh();
        $this->assertSame('failed', $customer->quickbooks_sync_status);
        $this->assertSame('quickbooks_conflict', $customer->quickbooks_sync_error);
        $this->assertNull($customer->quickbooks_write_request_id);
        $this->assertSame('FieldOps edit awaiting push', $customer->name);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/customer/100'));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
    }

    public function test_site_write_stops_without_posting_when_quickbooks_sync_token_is_newer(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/101' => Http::response([
                'Customer' => [
                    'Id' => '101',
                    'SyncToken' => '5',
                    'DisplayName' => 'Current QuickBooks Site',
                    'ParentRef' => ['value' => '100'],
                    'Job' => true,
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Parent customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $site = $customer->sites()->create([
            'name' => 'FieldOps Site edit awaiting push',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '4',
            'quickbooks_parent_id' => '100',
            'quickbooks_write_request_id' => 'conflict-site-101',
        ]);

        (new PushQuickBooksSite($site->id, 'conflict-site-101'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $site->refresh();
        $this->assertSame('4', $site->quickbooks_sync_token);
        $this->assertSame('FieldOps Site edit awaiting push', $site->name);
        $this->assertNull($site->quickbooks_write_request_id);
        $this->assertSame('failed', $customer->fresh()->quickbooks_sync_status);
        $this->assertSame('quickbooks_conflict', $customer->fresh()->quickbooks_sync_error);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
    }

    public function test_linked_site_write_includes_and_reconciles_qbo_primary_contact_fields(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/101' => Http::response([
                'Customer' => [
                    'Id' => '101',
                    'SyncToken' => '2',
                    'ParentRef' => ['value' => '100'],
                    'Job' => true,
                ],
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer*' => Http::response([
                'Customer' => [
                    'Id' => '101',
                    'SyncToken' => '3',
                    'DisplayName' => 'Ocean View Site',
                    'ParentRef' => ['value' => '100'],
                    'Job' => true,
                    'Active' => true,
                    'GivenName' => 'Site',
                    'MiddleName' => 'Primary',
                    'FamilyName' => 'Contact',
                    'Title' => 'Facilities lead',
                    'Suffix' => 'Jr.',
                    'PrimaryEmailAddr' => ['Address' => 'site-contact@example.test'],
                    'PrimaryPhone' => ['FreeFormNumber' => '555-0140'],
                    'AlternatePhone' => ['FreeFormNumber' => '555-0141'],
                    'Mobile' => ['FreeFormNumber' => '555-0142'],
                    'Fax' => ['FreeFormNumber' => '555-0143'],
                    'WebAddr' => ['URI' => 'https://example.test'],
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Parent customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $site = $customer->sites()->create([
            'name' => 'Ocean View Site',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '2',
            'quickbooks_parent_id' => '100',
            'quickbooks_write_request_id' => 'site-contact-write-101',
        ]);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Site',
            'email' => 'before@example.test',
            'phone' => '555-0100',
        ]);
        $contact->fill([
            'middle_name' => 'Primary',
            'last_name' => 'Contact',
            'title' => 'Facilities lead',
            'suffix' => 'Jr.',
            'email' => 'site-contact@example.test',
            'phone' => '555-0140',
            'alternate_phone' => '555-0141',
            'mobile_phone' => '555-0142',
            'fax' => '555-0143',
            'website' => 'https://example.test',
        ])->save();

        (new PushQuickBooksSite($site->id, 'site-contact-write-101'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $site->refresh();
        $contact->refresh();
        $this->assertSame('3', $site->quickbooks_sync_token);
        $this->assertNull($site->quickbooks_write_request_id);
        $this->assertSame('quickbooks', $contact->source);
        $this->assertSame('site-contact@example.test', $contact->email);
        $this->assertSame('555-0142', $contact->mobile_phone);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/customer?requestid=site-contact-write-101')
            && $request['GivenName'] === 'Site'
            && $request['MiddleName'] === 'Primary'
            && $request['FamilyName'] === 'Contact'
            && $request['PrimaryEmailAddr']['Address'] === 'site-contact@example.test'
            && $request['Mobile']['FreeFormNumber'] === '555-0142'
            && $request['Fax']['FreeFormNumber'] === '555-0143');
    }

    public function test_new_fieldops_site_is_created_as_a_qbo_child_customer_job(): void
    {
        $this->configureSandbox();
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer*' => Http::response([
                'Customer' => [
                    'Id' => '101',
                    'SyncToken' => '0',
                    'DisplayName' => 'Demo Service Location',
                    'FullyQualifiedName' => 'Parent Customer:Demo Service Location',
                    'ParentRef' => ['value' => '100', 'name' => 'Parent Customer'],
                    'Job' => true,
                    'BillWithParent' => false,
                    'Active' => true,
                    'Level' => 1,
                    'ShipAddr' => ['Line1' => '300 Demo Lane'],
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Parent Customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $site = $customer->sites()->create([
            'name' => 'Demo Service Location',
            'company_name' => 'Local Site Company',
            'preferred_delivery_method' => 'Print',
            'address' => '300 Demo Lane',
            'metadata' => ['created_from' => 'work_order_form'],
            'quickbooks_print_on_check_name' => 'Local check label',
            'quickbooks_write_request_id' => 'site-write-101',
        ]);

        (new PushQuickBooksSite($site->id, 'site-write-101'))
            ->handle(app(QuickBooksCustomerWriteService::class));

        $site->refresh();
        $this->assertSame('101', $site->quickbooks_id);
        $this->assertSame('0', $site->quickbooks_sync_token);
        $this->assertNull($site->quickbooks_write_request_id);
        $this->assertSame('synced', $customer->fresh()->quickbooks_sync_status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/customer?requestid=site-write-101')
            && $request['DisplayName'] === 'Demo Service Location'
            && $request['Job'] === true
            && $request['ParentRef']['value'] === '100'
            && $request['ShipAddr']['Line1'] === '300 Demo Lane'
            && ! isset($request['CompanyName'])
            && ! isset($request['PreferredDeliveryMethod'])
            && ! isset($request['PrintOnCheckName'])
            && ! isset($request['BillAddr']));
    }

    private function configureSandbox(): void
    {
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
        $this->fakeIntuitBwsCredentials();
    }

    private function connectQuickBooks(): void
    {
        IntegrationConnection::query()->create([
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

    private function user(): User
    {
        return User::factory()->create([
            'status' => 'active',
        ]);
    }
}
