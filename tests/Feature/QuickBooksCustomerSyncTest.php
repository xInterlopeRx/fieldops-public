<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksCustomer;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerEmailAddress;
use App\Models\IntegrationConnection;
use App\Models\Site;
use App\Models\User;
use App\Services\ArchiveService;
use App\Services\QuickBooksCustomerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QuickBooksCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
    }

    public function test_opening_a_customer_at_the_six_hour_boundary_queues_a_single_parent_and_children_refresh(): void
    {
        $this->freezeTime();
        config(['services.quickbooks.customer_sync_freshness_minutes' => 360]);
        $this->connectQuickBooks();
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'synced',
            'quickbooks_last_synced_at' => now()->subHours(6),
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->getJson(route('customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('quickbooks_sync.linked', true)
            ->assertJsonPath('quickbooks_sync.status', 'pending');

        $this->getJson(route('customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('quickbooks_sync.status', 'pending');

        Queue::assertPushedTimes(SyncQuickBooksCustomer::class, 1);
        Queue::assertPushed(SyncQuickBooksCustomer::class, fn (SyncQuickBooksCustomer $job): bool => $job->customerId === $customer->id);
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'quickbooks_sync_status' => 'pending',
        ]);
    }

    public function test_opening_a_customer_within_the_six_hour_freshness_window_does_not_dispatch_a_quickbooks_request(): void
    {
        $this->freezeTime();
        config(['services.quickbooks.customer_sync_freshness_minutes' => 360]);
        $this->connectQuickBooks();
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'synced',
            'quickbooks_last_synced_at' => now()->subHours(6)->addSecond(),
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->getJson(route('customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('quickbooks_sync.status', 'synced');

        Queue::assertNothingPushed();
    }

    public function test_user_can_force_a_refresh_even_when_the_customer_is_fresh(): void
    {
        $this->connectQuickBooks();
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'synced',
            'quickbooks_last_synced_at' => now(),
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->postJson(route('customers.quickbooks.refresh', $customer))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        Queue::assertPushed(SyncQuickBooksCustomer::class, fn (SyncQuickBooksCustomer $job): bool => $job->customerId === $customer->id);
    }

    public function test_unlinked_customer_cannot_be_force_refreshed(): void
    {
        $customer = Customer::create(['name' => 'Local Only Customer']);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->postJson(route('customers.quickbooks.refresh', $customer))
            ->assertUnprocessable()
            ->assertJsonPath('status', 'unlinked');

        Queue::assertNothingPushed();
    }

    public function test_refresh_does_not_contact_quickbooks_for_an_archived_customer(): void
    {
        Http::preventStrayRequests();
        $customer = Customer::create([
            'name' => 'Archived QBO Customer',
            'quickbooks_id' => '100',
        ]);
        app(ArchiveService::class)->archive('customers', $customer->id, null);

        app(QuickBooksCustomerSyncService::class)->sync($customer->id);

        Http::assertNothingSent();
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'is_archived' => true,
        ]);
    }

    public function test_refresh_does_not_recreate_or_unarchive_a_quickbooks_site_that_was_archived(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => $this->quickBooksParent(),
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->quickBooksChild()],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 1,
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Refresh Parent',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $site = $customer->sites()->create([
            'name' => 'Previously archived site',
            'quickbooks_id' => '101',
            'quickbooks_parent_id' => '100',
        ]);
        app(ArchiveService::class)->archive('sites', $site->id, null);

        app(QuickBooksCustomerSyncService::class)->sync($customer->id);

        $this->assertDatabaseCount('sites', 1);
        $this->assertDatabaseHas('sites', [
            'id' => $site->id,
            'name' => 'Previously archived site',
            'is_archived' => true,
        ]);
        $this->assertDatabaseMissing('contacts', ['site_id' => $site->id]);
        $this->assertDatabaseMissing('customer_addresses', ['source_site_id' => $site->id]);
    }

    public function test_linked_customer_reports_disconnected_quickbooks_without_queueing_a_refresh(): void
    {
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->actingAs($this->user())
            ->getJson(route('customers.show', $customer))
            ->assertOk()
            ->assertJsonPath('quickbooks_sync.status', 'not_connected');

        Queue::assertNothingPushed();
    }

    public function test_guest_cannot_force_a_quickbooks_customer_refresh(): void
    {
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);

        $this->post(route('customers.quickbooks.refresh', $customer))
            ->assertRedirect(route('login'));

        Queue::assertNothingPushed();
    }

    public function test_rate_limited_refresh_job_waits_for_the_retry_after_interval(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response(
                ['Fault' => ['Error' => [['code' => '3001']]]],
                429,
                ['Retry-After' => '90'],
            ),
        ]);
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $job = (new SyncQuickBooksCustomer($customer->id))->withFakeQueueInteractions();

        $job->handle(app(QuickBooksCustomerSyncService::class));

        $job->assertReleased(90);
        $this->assertSame('pending', $customer->fresh()->quickbooks_sync_status);
    }

    public function test_refresh_preserves_manual_contacts_and_clears_qbo_contact_when_qbo_contract_has_no_contact_fields(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        $child = $this->quickBooksChild();
        unset(
            $child['GivenName'],
            $child['MiddleName'],
            $child['FamilyName'],
            $child['Title'],
            $child['Suffix'],
            $child['PrimaryEmailAddr'],
            $child['PrimaryPhone'],
            $child['AlternatePhone'],
            $child['Mobile'],
            $child['Fax'],
            $child['WebAddr'],
        );
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => $this->quickBooksParent(),
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$child],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 1,
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Local Business Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $site = $customer->sites()->create([
            'name' => 'Existing site',
            'quickbooks_id' => '101',
            'quickbooks_parent_id' => '100',
        ]);
        $manualContact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Technician',
            'phone' => '555-0199',
        ]);
        $quickBooksContact = $customer->contacts()->create([
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Previously synced',
            'email' => 'previous@example.test',
        ]);

        (new SyncQuickBooksCustomer($customer->id))
            ->handle(app(QuickBooksCustomerSyncService::class));

        $manualContact->refresh();
        $this->assertSame('manual', $manualContact->source);
        $this->assertSame('Technician', $manualContact->first_name);
        $this->assertSame('555-0199', $manualContact->phone);
        $this->assertSame('quickbooks', $quickBooksContact->fresh()->source);
        $this->assertNull($quickBooksContact->fresh()->first_name);
        $this->assertNull($quickBooksContact->fresh()->email);
        $this->assertDatabaseCount('contacts', 2);
    }

    public function test_refresh_imports_quickbooks_profile_addresses_and_child_site_contact(): void
    {
        config([
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        ]);
        $this->connectQuickBooks(accessTokenExpiresAt: now()->subMinute());
        Http::preventStrayRequests();
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'rotated-access-token',
                'refresh_token' => 'rotated-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 7776000,
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => $this->quickBooksParent(),
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->quickBooksChild(), $this->quickBooksGrandchild()],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 2,
                ],
            ]),
        ]);
        $customer = Customer::create([
            'name' => 'Old Customer Name',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'pending',
        ]);
        $manualShippingAddress = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'Technician preferred address',
            'is_default' => true,
        ]);

        (new SyncQuickBooksCustomer($customer->id))
            ->handle(app(QuickBooksCustomerSyncService::class));

        $customer->refresh();
        $site = Site::query()->where('quickbooks_id', '101')->firstOrFail();
        $contact = Contact::query()
            ->where('customer_id', $customer->id)
            ->where('site_id', $site->id)
            ->where('contact_type', 'primary')
            ->firstOrFail();
        $billingAddress = CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('address_type', CustomerAddress::TYPE_BILLING)
            ->firstOrFail();
        $shippingAddress = CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('address_type', CustomerAddress::TYPE_SHIPPING)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->firstOrFail();
        $siteBillingAddress = CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('source_site_id', $site->id)
            ->where('address_type', CustomerAddress::TYPE_BILLING)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->firstOrFail();
        $siteShippingAddress = CustomerAddress::query()
            ->where('customer_id', $customer->id)
            ->where('source_site_id', $site->id)
            ->where('address_type', CustomerAddress::TYPE_SHIPPING)
            ->where('source', CustomerAddress::SOURCE_QUICKBOOKS)
            ->firstOrFail();

        $this->assertSame('Freeman Sporting Goods', $customer->name);
        $this->assertSame('Freeman Sporting Goods LLC', $customer->company_name);
        $this->assertSame('office@example.test', $customer->email);
        $this->assertSame('Commercial', $customer->quickbooks_customer_type_name);
        $this->assertSame('synced', $customer->quickbooks_sync_status);
        $this->assertNotNull($customer->quickbooks_last_synced_at);
        $this->assertSame('0969 Ocean View', $site->name);
        $this->assertSame('Ocean View Service Group', $site->company_name);
        $this->assertSame('Email', $site->preferred_delivery_method);
        $this->assertSame('Ocean View Check Name', $site->quickbooks_print_on_check_name);
        $this->assertSame('29 Ocean View, Austin TX 78701, USA', $site->address);
        $this->assertDatabaseMissing('sites', ['quickbooks_id' => '102']);
        $this->assertSame('Site', $contact->first_name);
        $this->assertSame('Contact', $contact->last_name);
        $this->assertSame('site@example.test', $contact->email);
        $this->assertSame('quickbooks', $contact->source);
        $this->assertSame('QuickBooks site billing location', $siteBillingAddress->line1);
        $this->assertSame('203', $siteBillingAddress->quickbooks_address_id);
        $this->assertFalse($siteBillingAddress->is_default);
        $this->assertSame('29 Ocean View', $siteShippingAddress->line1);
        $this->assertSame('202', $siteShippingAddress->quickbooks_address_id);
        $this->assertFalse($siteShippingAddress->is_default);
        $this->assertSame('quickbooks', $billingAddress->source);
        $this->assertSame('100 Main Street, Austin TX 78701, USA', $customer->address);
        $this->assertTrue($billingAddress->is_default);
        $this->assertFalse($manualShippingAddress->fresh()->is_default);
        $this->assertTrue($shippingAddress->is_default);
        $this->assertSame('rotated-refresh-token', IntegrationConnection::query()->firstOrFail()->refresh_token);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/query')
            && str_contains((string) $request['query'], "FullyQualifiedName LIKE 'Freeman Sporting Goods:%'"));
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/oauth2/v1/tokens/bearer')
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'test-refresh-token');
    }

    public function test_admin_can_resolve_a_failed_write_by_reloading_quickbooks_contract_values(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => $this->quickBooksParent(),
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->quickBooksChild()],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 1,
                ],
            ]),
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $customer = Customer::create([
            'name' => 'FieldOps customer conflict',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '3',
            'quickbooks_sync_status' => 'failed',
            'quickbooks_sync_error' => 'quickbooks_conflict',
            'quickbooks_write_request_id' => 'stale-customer-write',
            'quickbooks_write_fields' => ['name'],
            'quickbooks_write_payload' => ['DisplayName' => 'FieldOps customer conflict'],
        ]);
        $site = $customer->sites()->create([
            'name' => 'FieldOps site conflict',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '1',
            'quickbooks_parent_id' => '100',
            'quickbooks_write_request_id' => 'stale-site-write',
        ]);
        $manualBillingAddress = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'Conflicting local billing address',
            'is_default' => true,
        ]);
        $manualShippingAddress = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'Conflicting local shipping address',
            'is_default' => true,
        ]);
        $fieldOpsOnlyContact = $customer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'FieldOps only',
        ]);
        $emailAddress = CustomerEmailAddress::create([
            'customer_id' => $customer->id,
            'email' => 'local@example.test',
            'label' => 'Local-only',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customers.quickbooks.resolve', $customer))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertNull($customer->fresh()->quickbooks_write_request_id);
        $this->assertNull($site->fresh()->quickbooks_write_request_id);
        Queue::assertPushed(SyncQuickBooksCustomer::class, fn (SyncQuickBooksCustomer $job): bool => $job->customerId === $customer->id);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'event' => 'quickbooks_refreshed',
        ]);

        (new SyncQuickBooksCustomer($customer->id))->handle(app(QuickBooksCustomerSyncService::class));

        $customer->refresh();
        $site->refresh();
        $this->assertSame('Freeman Sporting Goods', $customer->name);
        $this->assertSame('100 Main Street, Austin TX 78701, USA', $customer->address);
        $this->assertSame('0969 Ocean View', $site->name);
        $this->assertSame('29 Ocean View, Austin TX 78701, USA', $site->address);
        $this->assertFalse($manualBillingAddress->fresh()->is_default);
        $this->assertFalse($manualShippingAddress->fresh()->is_default);
        $this->assertSame('quickbooks', $customer->defaultBillingAddress()->firstOrFail()->source);
        $this->assertSame('quickbooks', $customer->defaultShippingAddress()->firstOrFail()->source);
        $this->assertSame('FieldOps only', $fieldOpsOnlyContact->fresh()->first_name);
        $this->assertModelExists($emailAddress);
        $this->assertSame('synced', $customer->quickbooks_sync_status);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
    }

    public function test_admin_resolves_a_failed_qbo_write_by_refreshing_from_qbo_and_qbo_values_win(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Http::preventStrayRequests();
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/customer/100' => Http::response([
                'Customer' => $this->quickBooksParent(),
            ]),
            'sandbox-quickbooks.api.intuit.com/v3/company/sandbox-realm-123/query*' => Http::response([
                'QueryResponse' => [
                    'Customer' => [$this->quickBooksChild()],
                    'startPosition' => 1,
                    'maxResults' => 1000,
                    'totalCount' => 1,
                ],
            ]),
        ]);
        Queue::fake([SyncQuickBooksCustomer::class]);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $customer = Customer::create([
            'name' => 'FieldOps conflicting name',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '3',
            'quickbooks_sync_status' => 'failed',
            'quickbooks_sync_error' => 'quickbooks_conflict',
            'quickbooks_write_request_id' => 'failed-customer-write',
            'quickbooks_write_fields' => ['name'],
            'quickbooks_write_payload' => ['DisplayName' => 'FieldOps conflicting name'],
        ]);
        $site = $customer->sites()->create([
            'name' => 'FieldOps conflicting Site',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '1',
            'quickbooks_parent_id' => '100',
            'quickbooks_write_request_id' => 'failed-site-write',
        ]);
        $manualBilling = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'FieldOps billing override',
            'is_default' => true,
        ]);
        $manualShipping = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'FieldOps shipping override',
            'is_default' => true,
        ]);
        $manualContact = $customer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'FieldOps-only',
            'email' => 'local@example.test',
        ]);
        $extraEmail = CustomerEmailAddress::create([
            'customer_id' => $customer->id,
            'email' => 'extra@example.test',
            'label' => 'Local billing',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customers.quickbooks.resolve', $customer))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertNull($customer->fresh()->quickbooks_write_request_id);
        $this->assertNull($site->fresh()->quickbooks_write_request_id);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'event' => 'quickbooks_refreshed',
        ]);
        Queue::assertPushed(SyncQuickBooksCustomer::class, fn (SyncQuickBooksCustomer $job): bool => $job->customerId === $customer->id);

        (new SyncQuickBooksCustomer($customer->id))->handle(app(QuickBooksCustomerSyncService::class));

        $customer->refresh();
        $site->refresh();
        $this->assertSame('Freeman Sporting Goods', $customer->name);
        $this->assertSame('100 Main Street, Austin TX 78701, USA', $customer->address);
        $this->assertSame('0969 Ocean View', $site->name);
        $this->assertSame('29 Ocean View, Austin TX 78701, USA', $site->address);
        $this->assertFalse($manualBilling->fresh()->is_default);
        $this->assertFalse($manualShipping->fresh()->is_default);
        $this->assertSame('quickbooks', $customer->defaultBillingAddress()->firstOrFail()->source);
        $this->assertSame('quickbooks', $customer->defaultShippingAddress()->firstOrFail()->source);
        $this->assertSame('FieldOps-only', $manualContact->fresh()->first_name);
        $this->assertModelExists($extraEmail);
        $this->assertSame('synced', $customer->quickbooks_sync_status);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/customer/100'));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
    }

    private function connectQuickBooks(?Carbon $accessTokenExpiresAt = null): void
    {
        $this->fakeIntuitBwsCredentials();

        IntegrationConnection::create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-realm-123',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => $accessTokenExpiresAt ?? now()->addHour(),
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

    /** @return array<string, mixed> */
    private function quickBooksParent(): array
    {
        return [
            'Id' => '100',
            'SyncToken' => '4',
            'DisplayName' => 'Freeman Sporting Goods',
            'FullyQualifiedName' => 'Freeman Sporting Goods',
            'CompanyName' => 'Freeman Sporting Goods LLC',
            'GivenName' => 'Martha',
            'FamilyName' => 'Freeman',
            'PrimaryEmailAddr' => ['Address' => 'office@example.test'],
            'PrimaryPhone' => ['FreeFormNumber' => '555-0100'],
            'BillAddr' => [
                'Id' => '200',
                'Line1' => '100 Main Street',
                'City' => 'Austin',
                'CountrySubDivisionCode' => 'TX',
                'PostalCode' => '78701',
                'Country' => 'USA',
            ],
            'ShipAddr' => [
                'Id' => '201',
                'Line1' => '100 Shipping Street',
                'City' => 'Austin',
                'CountrySubDivisionCode' => 'TX',
                'PostalCode' => '78702',
                'Country' => 'USA',
            ],
            'CustomerTypeRef' => ['value' => '3', 'name' => 'Commercial'],
            'Active' => true,
            'Job' => false,
            'Level' => 0,
            'PreferredDeliveryMethod' => 'Email',
            'MetaData' => [
                'CreateTime' => '2026-09-01T12:00:00-07:00',
                'LastUpdatedTime' => '2026-09-29T12:00:00-07:00',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function quickBooksChild(): array
    {
        return [
            'Id' => '101',
            'SyncToken' => '2',
            'DisplayName' => '0969 Ocean View',
            'GivenName' => 'Site',
            'FamilyName' => 'Contact',
            'CompanyName' => 'Ocean View Service Group',
            'PrintOnCheckName' => 'Ocean View Check Name',
            'PreferredDeliveryMethod' => 'Email',
            'PrimaryEmailAddr' => ['Address' => 'site@example.test'],
            'PrimaryPhone' => ['FreeFormNumber' => '555-0101'],
            'ParentRef' => ['value' => '100', 'name' => 'Freeman Sporting Goods'],
            'BillWithParent' => false,
            'Active' => true,
            'Job' => true,
            'Level' => 1,
            'BillAddr' => [
                'Id' => '203',
                'Line1' => 'QuickBooks site billing location',
                'City' => 'Austin',
                'CountrySubDivisionCode' => 'TX',
                'PostalCode' => '78702',
                'Country' => 'USA',
            ],
            'ShipAddr' => [
                'Id' => '202',
                'Line1' => '29 Ocean View',
                'City' => 'Austin',
                'CountrySubDivisionCode' => 'TX',
                'PostalCode' => '78701',
                'Country' => 'USA',
            ],
            'MetaData' => [
                'CreateTime' => '2026-09-01T12:00:00-07:00',
                'LastUpdatedTime' => '2026-09-29T12:00:00-07:00',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function quickBooksGrandchild(): array
    {
        return [
            'Id' => '102',
            'SyncToken' => '0',
            'DisplayName' => 'Child of Ocean View',
            'ParentRef' => ['value' => '101', 'name' => '0969 Ocean View'],
            'Active' => true,
            'Job' => true,
            'Level' => 2,
            'FullyQualifiedName' => 'Freeman Sporting Goods:0969 Ocean View:Child of Ocean View',
        ];
    }
}
