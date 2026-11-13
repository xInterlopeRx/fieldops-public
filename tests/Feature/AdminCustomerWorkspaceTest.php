<?php

namespace Tests\Feature;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\PushQuickBooksSite;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerEmailAddress;
use App\Models\IntegrationConnection;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\AttachmentLifecycleEmailNotification;
use App\Notifications\AttachmentLifecycleNotification;
use App\Notifications\BusinessAssignmentChanged;
use App\Services\ArchiveService;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCustomerWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_customer_workspace_returns_bounded_customer_context_and_activity(): void
    {
        $this->travelTo('2026-10-02 12:00:00');
        $admin = $this->admin();
        $this->actingAs($admin);
        $customer = Customer::create(['name' => 'Workspace Customer', 'email' => 'contact@example.test']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Main shop']);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Casey',
            'email' => 'casey@example.test',
        ]);
        $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Office,
            'value' => 'casey.office@example.test',
            'is_primary' => true,
        ]);
        $project = Project::create([
            'customer_id' => $customer->id,
            'project_number' => 'P-200',
            'name' => 'Lighting upgrade',
        ]);
        $job = WorkOrder::create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-200',
            'title' => 'Inspect lights',
            'scope' => 'Inspect and repair all light fixtures.',
            'status' => 'scheduled',
        ]);
        $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Inspect fixtures.',
        ]);
        CustomerEmailAddress::create([
            'customer_id' => $customer->id,
            'email' => 'billing@example.test',
            'label' => 'Billing',
        ]);

        $this->getJson(route('admin.customers.workspace.show', $customer))
            ->assertOk()
            ->assertJsonPath('data.customer.name', 'Workspace Customer')
            ->assertJsonPath('data.sites.0.name', 'Main shop')
            ->assertJsonPath('data.sites.0.address.country', 'US')
            ->assertJsonPath('data.sites.0.primaryContactId', $contact->getKey())
            ->assertJsonPath('data.contacts.0.email', 'casey@example.test')
            ->assertJsonPath('data.contacts.0.siteId', $site->getKey())
            ->assertJsonPath('data.contacts.0.siteName', 'Main shop')
            ->assertJsonPath('data.contacts.0.methods.0.methodType', 'email')
            ->assertJsonPath('data.contacts.0.methods.0.isPrimary', true)
            ->assertJsonPath('data.projects.0.number', 'P-200')
            ->assertJsonPath('data.jobs.0.number', 'J-200')
            ->assertJsonPath('data.upcomingJobs.0.id', $job->id)
            ->assertJsonPath('data.emailAddresses.0.email', 'billing@example.test')
            ->assertJsonMissingPath('data.customer.quickbooks_id')
            ->assertJsonFragment(['summary' => 'Admin Person created customer Workspace Customer']);
    }

    public function test_admin_can_create_a_project_from_the_customer_projects_tab(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Project action customer']);

        $this->actingAs($admin)
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'New customer project',
                'project_number' => 'CP-301',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'New customer project')
            ->assertJsonPath('data.number', 'CP-301')
            ->assertJsonPath('data.status', 'created')
            ->assertJsonPath('data.jobsCount', 0);

        $project = Project::query()->where('project_number', 'CP-301')->sole();

        $this->assertSame($customer->getKey(), $project->customer_id);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => $project->getMorphClass(),
            'subject_id' => $project->getKey(),
            'event' => 'created',
        ]);
    }

    public function test_admin_project_creation_auto_numbers_the_project_and_attaches_selected_sites(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Auto-number Project Client']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Selected Project Site']);
        $otherCustomer = Customer::create(['name' => 'Other Project Client']);
        $otherSite = Site::create(['customer_id' => $otherCustomer->getKey(), 'name' => 'Foreign Project Site']);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'Automatically numbered project',
                'site_ids' => [$site->getKey()],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Automatically numbered project');

        $project = Project::query()->where('name', 'Automatically numbered project')->sole();
        $this->assertSame('P'.str_pad((string) $project->getKey(), 5, '0', STR_PAD_LEFT), $project->project_number);
        $this->assertSame($project->project_number, $response->json('data.number'));
        $this->assertDatabaseHas('project_site', [
            'project_id' => $project->getKey(),
            'site_id' => $site->getKey(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'Invalid site project',
                'site_ids' => [$otherSite->getKey()],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_ids');

        $this->assertDatabaseMissing('projects', ['name' => 'Invalid site project']);
    }

    public function test_admin_can_add_a_new_site_from_project_creation_and_it_is_attached_to_the_project(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Project Site Draft Client']);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'Project with a new Site',
                'site_ids' => [],
                'new_site' => [
                    'name' => 'Newly added Project Site',
                    'address' => '42 Field Street, Oakland CA 94612',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Project with a new Site');

        $site = Site::query()->where('name', 'Newly added Project Site')->sole();
        $project = Project::query()->where('name', 'Project with a new Site')->sole();

        $this->assertSame($customer->getKey(), $site->customer_id);
        $this->assertSame('42 Field Street, Oakland CA 94612', $site->address);
        $this->assertSame([$site->getKey()], $response->json('data.siteIds'));
        $this->assertDatabaseHas('project_site', [
            'project_id' => $project->getKey(),
            'site_id' => $site->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => $site->getMorphClass(),
            'subject_id' => $site->getKey(),
            'event' => 'created',
        ]);
    }

    public function test_customer_project_creation_rejects_an_existing_project_number(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Project number customer']);
        Project::create([
            'customer_id' => $customer->getKey(),
            'project_number' => 'CP-DUPLICATE',
            'name' => 'Existing project',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'Duplicate project',
                'project_number' => 'CP-DUPLICATE',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('project_number');

        $this->assertDatabaseMissing('projects', ['name' => 'Duplicate project']);
    }

    public function test_basic_user_cannot_create_a_customer_project(): void
    {
        $customer = Customer::create(['name' => 'Restricted project customer']);

        $this->actingAs(User::factory()->create(['status' => 'active']))
            ->postJson(route('admin.customers.projects.store', $customer), [
                'name' => 'Unauthorized project',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('projects', ['name' => 'Unauthorized project']);
    }

    public function test_admin_can_update_customer_profile_contacts_sites_assignments_and_emails(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->create(['status' => 'active', 'name' => 'Assigned Technician']);
        $customer = Customer::create(['name' => 'Original customer']);

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $this->workspacePayload($staff->id))
            ->assertOk()
            ->assertJsonPath('message', 'Customer profile saved.');

        $customer->refresh();
        $this->assertSame('Updated company', $customer->name);
        $this->assertSame('Updated company', $customer->company_name);
        $this->assertSame('commercial', $customer->customer_group);
        $this->assertSame('current_client', $customer->customer_status);
        $this->assertSame('Dispatch person', $customer->first_name);
        $this->assertSame('dispatch@example.test', $customer->email);
        $this->assertSame('555-0100', $customer->phone);
        $this->assertSame('1 Dispatch Way, Oakland CA 94612, US', $customer->address);
        $this->assertDatabaseHas('sites', [
            'customer_id' => $customer->id,
            'name' => 'New warehouse',
            'address' => '2 Bay Road, Oakland CA 94612, US',
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->id,
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            'line1' => '2 Bay Road',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
        ]);
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->id,
            'contact_type' => 'other',
            'first_name' => 'Jordan',
            'email' => 'jordan@example.test',
        ]);
        $this->assertDatabaseHas('customer_email_addresses', [
            'customer_id' => $customer->id,
            'email' => 'billing@example.test',
            'label' => 'Billing',
        ]);
        $this->assertDatabaseHas('customer_user', [
            'customer_id' => $customer->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'event' => 'edited',
        ]);

        Notification::assertSentTo($staff, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
        Notification::assertSentTo($admin, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
    }

    public function test_customer_workspace_rejects_unknown_group_and_status_values(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Unclassified customer']);
        $payload = $this->workspacePayload();
        $payload['customer_group'] = 'government';
        $payload['customer_status'] = 'active';

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_group', 'customer_status']);

        $this->assertNull($customer->fresh()->customer_group);
        $this->assertNull($customer->fresh()->customer_status);
    }

    public function test_customer_workspace_rejects_an_empty_site_name_without_creating_a_site(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Original customer']);
        $payload = $this->workspacePayload();
        $payload['sites'] = [[
            'id' => null,
            'name' => '',
            'address' => $this->siteAddressPayload(''),
            'primary_contact_id' => null,
            'primary_contact_key' => null,
        ]];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sites.0.name', 'sites.0.address.line1']);

        $this->assertDatabaseCount('sites', 0);
        $this->assertSame('Original customer', $customer->fresh()->name);
    }

    public function test_customer_workspace_rejects_a_site_without_required_address_parts(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Required Site Address']);
        $address = $this->siteAddressPayload();
        $address['city'] = '';
        $address['country_subdivision_code'] = '';
        $address['postal_code'] = '';
        $payload = $this->workspacePayload();
        $payload['sites'] = [[
            'id' => null,
            'name' => 'Incomplete address site',
            'address' => $address,
            'primary_contact_id' => null,
            'primary_contact_key' => null,
        ]];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'sites.0.address.city',
                'sites.0.address.country_subdivision_code',
                'sites.0.address.postal_code',
            ]);

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_customer_workspace_rejects_a_site_state_outside_the_state_dropdown(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Invalid Site State']);
        $address = $this->siteAddressPayload();
        $address['country_subdivision_code'] = 'XX';
        $payload = $this->workspacePayload();
        $payload['sites'] = [[
            'id' => null,
            'name' => 'Invalid state site',
            'address' => $address,
            'primary_contact_id' => null,
            'primary_contact_key' => null,
        ]];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sites.0.address.country_subdivision_code');

        $this->assertDatabaseCount('sites', 0);
    }

    public function test_customer_workspace_requires_billing_street_city_state_and_postal_code(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Required Billing Address']);
        $payload = $this->workspacePayload();
        $payload['billing_address']['line1'] = '';
        $payload['billing_address']['city'] = '';
        $payload['billing_address']['country_subdivision_code'] = 'XX';
        $payload['billing_address']['postal_code'] = '';
        $payload['billing_address']['country'] = null;

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'billing_address.line1',
                'billing_address.city',
                'billing_address.country_subdivision_code',
                'billing_address.postal_code',
            ]);

        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_site_changes_do_not_require_or_rewrite_an_unchanged_incomplete_billing_address(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create([
            'name' => 'Legacy billing customer',
            'address' => '1 Legacy Billing Way',
        ]);
        $site = $customer->sites()->create([
            'name' => 'Old Site Name',
            'address' => '10 Site Way, Oakland CA 94612, US',
        ]);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            ...$this->siteAddressPayload('10 Site Way'),
        ]);
        $payload = $this->workspacePayload();
        $payload['billing_address'] = [
            'line1' => '1 Legacy Billing Way',
            'line2' => '',
            'line3' => '',
            'line4' => '',
            'line5' => '',
            'city' => '',
            'country_subdivision_code' => '',
            'postal_code' => '',
            'country' => '',
        ];
        $payload['sites'] = [array_merge($this->sitePayload($site, $this->siteAddressPayload('10 Site Way')), [
            'name' => 'Updated Site Name',
        ])];
        $payload['contacts'] = [];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertSame('Updated Site Name', $site->fresh()->name);
        $this->assertSame('1 Legacy Billing Way', $customer->fresh()->address);
        $this->assertDatabaseMissing('customer_addresses', [
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_BILLING,
        ]);
    }

    public function test_customer_workspace_saves_a_default_shipping_address_copied_from_billing(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
        Queue::fake([PushQuickBooksCustomer::class]);
        $admin = $this->admin();
        $customer = Customer::query()->create([
            'name' => 'Billing and shipping customer',
            'company_name' => 'Billing and shipping customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '4',
            'quickbooks_sync_status' => 'synced',
        ]);
        $payload = $this->workspacePayload();
        $payload['shipping_address'] = $payload['billing_address'];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        foreach ([CustomerAddress::TYPE_BILLING, CustomerAddress::TYPE_SHIPPING] as $addressType) {
            $this->assertDatabaseHas('customer_addresses', [
                'customer_id' => $customer->getKey(),
                'address_type' => $addressType,
                'line1' => '1 Dispatch Way',
                'city' => 'Oakland',
                'country_subdivision_code' => 'CA',
                'postal_code' => '94612',
                'is_default' => true,
            ]);
        }

        $this->getJson(route('admin.customers.workspace.show', $customer))
            ->assertOk()
            ->assertJsonPath('data.shippingAddress.line1', '1 Dispatch Way')
            ->assertJsonPath('data.shippingAddress.city', 'Oakland');

        $this->assertContains('ShipAddr', $customer->fresh()->quickbooks_write_fields);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->getKey());
    }

    public function test_customer_and_site_addresses_do_not_require_a_country(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Optional country address']);
        $payload = $this->workspacePayload();
        $payload['billing_address']['country'] = null;
        $payload['sites'][0]['address']['country'] = null;

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'country' => null,
        ]);
        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            'country' => null,
        ]);
    }

    public function test_site_primary_contact_references_a_customer_wide_contact_without_moving_it(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Customer-wide site contact']);
        $site = $customer->sites()->create([
            'name' => 'Warehouse',
            'address' => '10 Warehouse Way, Oakland CA 94612, US',
        ]);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            'is_default' => true,
            ...$this->siteAddressPayload('10 Warehouse Way'),
        ]);
        $contact = $customer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Casey',
            'last_name' => 'Jones',
        ]);
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = null;
        $address = $this->siteAddressPayload('10 Warehouse Way');
        $address['line2'] = 'Suite 100';
        $payload['sites'] = [array_merge($this->sitePayload($site, $address), ['primary_contact_id' => $contact->getKey()])];
        $payload['contacts'] = [$this->contactPayload($contact, [])];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertSame($contact->getKey(), $site->fresh()->primary_contact_id);
        $this->assertNull($contact->fresh()->site_id);
        $this->assertSame('other', $contact->fresh()->contact_type);
        $this->assertDatabaseHas('customer_addresses', [
            'source_site_id' => $site->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'line2' => 'Suite 100',
            'is_default' => true,
        ]);
    }

    public function test_site_can_select_a_contact_scoped_to_another_site_without_moving_or_retyping_it(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Shared site contact']);
        $site = $customer->sites()->create([
            'name' => 'North office',
            'address' => '10 North Road, Oakland CA 94612, US',
        ]);
        $otherSite = $customer->sites()->create([
            'name' => 'South office',
            'address' => '20 South Road, Oakland CA 94612, US',
        ]);
        $secondTargetSite = $customer->sites()->create([
            'name' => 'East office',
            'address' => '30 East Road, Oakland CA 94612, US',
        ]);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            ...$this->siteAddressPayload('10 North Road'),
        ]);
        $contact = $customer->contacts()->create([
            'site_id' => $otherSite->getKey(),
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Jordan',
            'last_name' => 'Lee',
            'phone' => '555-0198',
        ]);
        $customerPrimaryContact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Alex',
            'last_name' => 'Morgan',
            'phone' => '555-0127',
        ]);
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = null;
        $payload['sites'] = [
            array_merge($this->sitePayload($site), ['primary_contact_id' => $contact->getKey()]),
            array_merge($this->sitePayload($secondTargetSite), ['primary_contact_id' => $customerPrimaryContact->getKey()]),
        ];
        $payload['contacts'] = [
            $this->contactPayload($contact, []),
            $this->contactPayload($customerPrimaryContact, []),
        ];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertSame($contact->getKey(), $site->fresh()->primary_contact_id);
        $this->assertSame($otherSite->getKey(), $contact->fresh()->site_id);
        $this->assertSame('primary', $contact->fresh()->contact_type);
        $this->assertSame('quickbooks', $contact->fresh()->source);
        $this->assertSame($customerPrimaryContact->getKey(), $secondTargetSite->fresh()->primary_contact_id);
        $this->assertNull($customerPrimaryContact->fresh()->site_id);
        $this->assertSame('primary', $customerPrimaryContact->fresh()->contact_type);
    }

    public function test_site_card_can_create_a_customer_wide_contact_as_its_primary_contact(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'New site contact']);
        $site = $customer->sites()->create([
            'name' => 'Main office',
            'address' => '20 Main Street, Oakland CA 94612, US',
        ]);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            ...$this->siteAddressPayload('20 Main Street'),
        ]);
        $clientKey = 'site-contact-draft-1';
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = null;
        $payload['sites'] = [array_merge($this->sitePayload($site), [
            'primary_contact_id' => null,
            'primary_contact_key' => $clientKey,
        ])];
        $payload['contacts'] = [[
            'client_key' => $clientKey,
            'site_id' => null,
            'contact_type' => 'other',
            'first_name' => 'Morgan',
            'last_name' => 'Reed',
            'email' => 'morgan@example.test',
            'methods' => [],
        ], [
            'client_key' => 'another-customer-contact',
            'site_id' => null,
            'contact_type' => 'other',
            'first_name' => 'Taylor',
            'last_name' => 'Reed',
            'methods' => [],
        ]];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $contact = Contact::query()->where('customer_id', $customer->getKey())->where('email', 'morgan@example.test')->firstOrFail();
        $this->assertSame($contact->getKey(), $site->fresh()->primary_contact_id);
        $this->assertNull($contact->site_id);
        $this->assertSame('other', $contact->contact_type);
        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->getKey(),
            'first_name' => 'Taylor',
            'contact_type' => 'other',
            'site_id' => null,
        ]);
    }

    public function test_site_card_rejects_a_primary_contact_from_another_customer(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Site contact owner']);
        $otherCustomer = Customer::query()->create(['name' => 'Other contact owner']);
        $site = $customer->sites()->create(['name' => 'Main site', 'address' => '30 Main Road, Oakland CA 94612, US']);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_SITE,
            ...$this->siteAddressPayload('30 Main Road'),
        ]);
        $foreignContact = $otherCustomer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Private',
            'last_name' => 'Contact',
        ]);
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = null;
        $payload['sites'] = [array_merge($this->sitePayload($site), ['primary_contact_id' => $foreignContact->getKey()])];
        $payload['contacts'] = [];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sites.0.primary_contact_id');

        $this->assertNull($site->fresh()->primary_contact_id);
        $this->assertSame('Site contact owner', $customer->fresh()->name);
    }

    public function test_assigning_a_customer_wide_primary_contact_updates_the_customer_quickbooks_fields(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
        Queue::fake([PushQuickBooksCustomer::class, PushQuickBooksSite::class]);
        $admin = $this->admin();
        $customer = Customer::query()->create([
            'name' => 'Dispatch Company',
            'company_name' => 'Dispatch Company',
            'first_name' => 'Old',
            'email' => 'old@example.test',
            'phone' => '555-0001',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '4',
            'quickbooks_sync_status' => 'synced',
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
            'quickbooks_address_id' => 'billing-100',
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
            'is_default' => true,
        ]);
        $oldPrimary = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Old',
            'email' => 'old@example.test',
        ]);
        $newPrimary = $customer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'New',
            'last_name' => 'Primary',
            'email' => 'new.primary@example.test',
            'phone' => '555-0100',
        ]);
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = $customer->company_name;
        $payload['billing_address'] = [
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
        ];
        $payload['sites'] = [];
        $payload['contacts'] = [array_merge($this->contactPayload($newPrimary, []), ['contact_type' => 'primary'])];
        $payload['email_addresses'] = [];
        $payload['assigned_user_ids'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertSame('other', $oldPrimary->fresh()->contact_type);
        $this->assertSame('primary', $newPrimary->fresh()->contact_type);
        $this->assertSame('New', $customer->fresh()->first_name);
        $this->assertSame('new.primary@example.test', $customer->fresh()->email);
        $this->assertSame('555-0100', $customer->fresh()->phone);
        $this->assertContains('first_name', $customer->fresh()->quickbooks_write_fields);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->getKey());
    }

    public function test_customer_workspace_creates_customer_wide_and_site_specific_role_contacts(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Scoped role contacts']);
        $site = $customer->sites()->create(['name' => 'North service site']);
        $payload = $this->workspacePayload();
        $payload['sites'] = [$this->sitePayload($site, $this->siteAddressPayload('1 North Service Road'))];
        $payload['contacts'] = [
            [
                'site_id' => null,
                'contact_type' => 'operations_contact',
                'first_name' => 'Customer Operations',
                'methods' => [],
            ],
            [
                'site_id' => $site->getKey(),
                'contact_type' => 'gate_contact',
                'first_name' => 'Gate Contact',
                'methods' => [],
            ],
            [
                'site_id' => $site->getKey(),
                'contact_type' => 'supervisor',
                'first_name' => 'Site Supervisor',
                'methods' => [],
            ],
            [
                'site_id' => $site->getKey(),
                'contact_type' => 'safety_contact',
                'first_name' => 'Safety Contact',
                'methods' => [],
            ],
        ];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertDatabaseHas('contacts', [
            'customer_id' => $customer->getKey(),
            'site_id' => null,
            'contact_type' => 'operations_contact',
            'first_name' => 'Customer Operations',
        ]);
        foreach (['gate_contact', 'supervisor', 'safety_contact'] as $type) {
            $this->assertDatabaseHas('contacts', [
                'customer_id' => $customer->getKey(),
                'site_id' => $site->getKey(),
                'contact_type' => $type,
            ]);
        }

        $workspace = $this->getJson(route('admin.customers.workspace.show', $customer))
            ->assertOk();
        $contacts = collect($workspace->json('data.contacts'))->keyBy('type');
        $this->assertNull($contacts['operations_contact']['siteId']);
        $this->assertSame($site->getKey(), $contacts['gate_contact']['siteId']);
        $this->assertSame('North service site', $contacts['gate_contact']['siteName']);
    }

    public function test_customer_workspace_can_add_email_and_phone_methods_and_set_one_primary_per_type(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Method management customer']);
        $contact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Mina',
            'last_name' => 'Technician',
        ]);
        $payload = $this->workspacePayload();
        $payload['contacts'] = [$this->contactPayload($contact, [
            ['method_type' => 'email', 'label' => 'office', 'value' => 'mina.office@example.test', 'is_primary' => true],
            ['method_type' => 'email', 'label' => 'personal', 'value' => 'mina.personal@example.test', 'is_primary' => false],
            ['method_type' => 'phone', 'label' => 'dispatch', 'value' => '+1-555-0200', 'is_primary' => true],
            ['method_type' => 'phone', 'label' => 'after_hours', 'value' => '+1-555-0299', 'is_primary' => false],
            ['method_type' => 'teams', 'label' => 'operations', 'value' => 'mina.operations', 'is_primary' => true],
        ])];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertDatabaseCount('contact_methods', 5);
        $this->assertDatabaseHas('contact_methods', [
            'contact_id' => $contact->getKey(),
            'method_type' => 'email',
            'value' => 'mina.office@example.test',
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('contact_methods', [
            'contact_id' => $contact->getKey(),
            'method_type' => 'phone',
            'value' => '+1-555-0299',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('contact_methods', [
            'contact_id' => $contact->getKey(),
            'method_type' => 'teams',
            'value' => 'mina.operations',
            'is_primary' => true,
        ]);
    }

    public function test_customer_workspace_removes_only_omitted_email_and_phone_methods(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Method removal customer']);
        $contact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Noah',
        ]);
        $keepEmail = $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Office,
            'value' => 'keep@example.test',
            'is_primary' => true,
        ]);
        $removeEmail = $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Personal,
            'value' => 'remove@example.test',
        ]);
        $keepPhone = $contact->methods()->create([
            'method_type' => ContactMethodType::Phone,
            'label' => ContactMethodLabel::Dispatch,
            'value' => '+1-555-0300',
            'is_primary' => true,
        ]);
        $removePhone = $contact->methods()->create([
            'method_type' => ContactMethodType::Phone,
            'label' => ContactMethodLabel::Mobile,
            'value' => '+1-555-0301',
        ]);
        $payload = $this->workspacePayload();
        $payload['contacts'] = [$this->contactPayload($contact, [
            ['id' => $keepEmail->getKey(), 'method_type' => 'email', 'label' => 'office', 'value' => 'keep@example.test', 'is_primary' => true],
            ['id' => $keepPhone->getKey(), 'method_type' => 'phone', 'label' => 'dispatch', 'value' => '+1-555-0300', 'is_primary' => true],
        ])];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertModelExists($contact->fresh());
        $this->assertModelExists($keepEmail->fresh());
        $this->assertModelExists($keepPhone->fresh());
        $this->assertDatabaseMissing('contact_methods', ['id' => $removeEmail->getKey()]);
        $this->assertDatabaseMissing('contact_methods', ['id' => $removePhone->getKey()]);
    }

    public function test_customer_workspace_can_move_primary_status_between_existing_methods(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Primary method customer']);
        $contact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Ada',
        ]);
        $firstEmail = $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Office,
            'value' => 'first@example.test',
            'is_primary' => true,
        ]);
        $secondEmail = $contact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Personal,
            'value' => 'second@example.test',
        ]);
        $payload = $this->workspacePayload();
        $payload['contacts'] = [$this->contactPayload($contact, [
            ['id' => $firstEmail->getKey(), 'method_type' => 'email', 'label' => 'office', 'value' => 'first@example.test', 'is_primary' => false],
            ['id' => $secondEmail->getKey(), 'method_type' => 'email', 'label' => 'personal', 'value' => 'second@example.test', 'is_primary' => true],
        ])];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertDatabaseHas('contact_methods', ['id' => $firstEmail->getKey(), 'is_primary' => false]);
        $this->assertDatabaseHas('contact_methods', ['id' => $secondEmail->getKey(), 'is_primary' => true]);
    }

    public function test_customer_workspace_rejects_multiple_primary_methods_of_the_same_type(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Duplicate primary customer']);
        $payload = $this->workspacePayload();
        $payload['contacts'][0]['methods'] = [
            ['method_type' => 'email', 'label' => 'office', 'value' => 'one@example.test', 'is_primary' => true],
            ['method_type' => 'email', 'label' => 'personal', 'value' => 'two@example.test', 'is_primary' => true],
        ];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contacts.0.methods.1.is_primary');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_methods', 0);
    }

    public function test_customer_workspace_rejects_invalid_contact_method_emails(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Invalid method email customer']);
        $payload = $this->workspacePayload();
        $payload['contacts'][0]['methods'] = [[
            'method_type' => 'email',
            'label' => 'office',
            'value' => 'not-an-email',
            'is_primary' => true,
        ]];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contacts.0.methods.0.value');

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_methods', 0);
    }

    public static function unsupportedContactMethodValues(): array
    {
        return [
            'method type' => ['method_type', 'fax', 'contacts.0.methods.0.method_type'],
            'label' => ['label', 'headquarters', 'contacts.0.methods.0.label'],
        ];
    }

    #[DataProvider('unsupportedContactMethodValues')]
    public function test_customer_workspace_rejects_contact_method_values_outside_allowed_enums(
        string $attribute,
        string $value,
        string $errorKey,
    ): void {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Invalid method enum customer']);
        $payload = $this->workspacePayload();
        $method = [
            'method_type' => 'email',
            'label' => 'office',
            'value' => 'valid@example.test',
            'is_primary' => true,
        ];
        $method[$attribute] = $value;
        $payload['contacts'][0]['methods'] = [$method];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('contact_methods', 0);
    }

    public function test_customer_workspace_rejects_a_method_owned_by_another_contact(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Scoped methods customer']);
        $firstContact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'First',
        ]);
        $secondContact = $customer->contacts()->create([
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Second',
        ]);
        $secondMethod = $secondContact->methods()->create([
            'method_type' => ContactMethodType::Email,
            'label' => ContactMethodLabel::Office,
            'value' => 'second@example.test',
        ]);
        $payload = $this->workspacePayload();
        $payload['contacts'] = [
            $this->contactPayload($firstContact, [[
                'id' => $secondMethod->getKey(),
                'method_type' => 'email',
                'label' => 'office',
                'value' => 'second@example.test',
                'is_primary' => false,
            ]]),
            $this->contactPayload($secondContact, [[
                'id' => $secondMethod->getKey(),
                'method_type' => 'email',
                'label' => 'office',
                'value' => 'second@example.test',
                'is_primary' => false,
            ]]),
        ];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contacts');

        $this->assertSame('First', $firstContact->fresh()->first_name);
        $this->assertSame($secondContact->getKey(), $secondMethod->fresh()->contact_id);
    }

    public function test_customer_workspace_keeps_new_contact_methods_out_of_quickbooks_writes(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Queue::fake([PushQuickBooksCustomer::class, PushQuickBooksSite::class]);
        $admin = $this->admin();
        $customer = Customer::query()->create([
            'name' => 'Updated customer',
            'company_name' => 'Updated company',
            'first_name' => 'Dispatch person',
            'email' => 'dispatch@example.test',
            'phone' => '555-0100',
            'address' => '1 Dispatch Way, Oakland CA 94612, US',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '4',
            'quickbooks_sync_status' => 'synced',
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
            'quickbooks_address_id' => 'billing-100',
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
            'is_default' => true,
        ]);
        $site = $customer->sites()->create([
            'name' => 'QBO site',
            'address' => '2 QBO Road, Oakland CA 94612, US',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '2',
            'quickbooks_parent_id' => '100',
        ]);
        $site->customerAddresses()->create([
            'customer_id' => $customer->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_QUICKBOOKS,
            'quickbooks_address_id' => 'shipping-101',
            ...$this->siteAddressPayload('2 QBO Road'),
        ]);
        $contact = $customer->contacts()->create([
            'site_id' => $site->getKey(),
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Source',
            'email' => 'source@example.test',
            'phone' => '555-0101',
        ]);
        $site->forceFill(['primary_contact_id' => $contact->getKey()])->save();
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = $customer->company_name;
        $payload['billing_address'] = [
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
        ];
        $payload['sites'] = [$this->sitePayload($site)];
        $payload['contacts'] = [$this->contactPayload($contact, [[
            'method_type' => 'email',
            'label' => 'operations',
            'value' => 'ops@example.test',
            'is_primary' => true,
        ]])];
        $payload['email_addresses'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk()
            ->assertJsonPath('quickBooksSiteStatuses', []);

        $this->assertDatabaseHas('contact_methods', [
            'contact_id' => $contact->getKey(),
            'value' => 'ops@example.test',
        ]);
        Queue::assertNotPushed(PushQuickBooksCustomer::class);
        Queue::assertNotPushed(PushQuickBooksSite::class);
    }

    public function test_removing_a_customer_assignment_notifies_the_staff_member_and_admins(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->create(['name' => 'Removed Technician', 'status' => 'active']);
        $customer = Customer::create(['name' => 'Unassignment customer']);
        $customer->assignedUsers()->attach($staff->id, ['assigned_by_user_id' => $admin->id]);

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $this->workspacePayload())
            ->assertOk();

        $this->assertDatabaseMissing('customer_user', [
            'customer_id' => $customer->id,
            'user_id' => $staff->id,
        ]);
        Notification::assertSentTo($staff, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'unassigned');
        Notification::assertSentTo($admin, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'unassigned');
    }

    public function test_customer_workspace_rejects_a_contact_site_from_another_customer(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Scoped customer']);
        $otherCustomer = Customer::create(['name' => 'Other customer']);
        $foreignSite = Site::create(['customer_id' => $otherCustomer->id, 'name' => 'Private site']);

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), [
                ...$this->workspacePayload(),
                'assigned_user_ids' => [],
                'contacts' => [[
                    'contact_type' => 'other',
                    'site_id' => $foreignSite->id,
                    'first_name' => 'Cross-scope',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contacts');

        $this->assertSame('Scoped customer', $customer->fresh()->name);
        $this->assertDatabaseMissing('contacts', ['first_name' => 'Cross-scope']);
    }

    public function test_editing_a_quickbooks_site_contact_queues_a_sandbox_write(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        $this->connectQuickBooks();
        Queue::fake([PushQuickBooksCustomer::class, PushQuickBooksSite::class]);
        $admin = $this->admin();
        $customer = Customer::create([
            'name' => 'Updated customer',
            'company_name' => 'Updated company',
            'first_name' => 'Dispatch person',
            'email' => 'dispatch@example.test',
            'phone' => '555-0100',
            'quickbooks_id' => '100',
            'quickbooks_sync_token' => '4',
            'quickbooks_sync_status' => 'synced',
        ]);
        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
            'is_default' => true,
        ]);
        $site = Site::create([
            'customer_id' => $customer->id,
            'name' => 'QBO site',
            'address' => '2 QBO Road',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '2',
            'quickbooks_parent_id' => '100',
        ]);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Source',
            'email' => 'source@example.test',
            'phone' => '555-0101',
        ]);

        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = $customer->company_name;
        $payload['sites'] = [$this->sitePayload($site)];
        $payload['contacts'] = [[
            'id' => $contact->id,
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'first_name' => 'Updated in FieldOps',
            'middle_name' => null,
            'last_name' => 'Primary',
            'title' => 'Facilities Lead',
            'suffix' => null,
            'email' => 'updated-site@example.test',
            'phone' => '555-0144',
            'alternate_phone' => null,
            'mobile_phone' => null,
            'fax' => null,
            'website' => null,
            'remove' => false,
        ]];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

        $this->assertSame('Updated in FieldOps', $contact->fresh()->first_name);
        $this->assertSame('updated-site@example.test', $contact->fresh()->email);
        $this->assertNotNull($site->fresh()->quickbooks_write_request_id);
        Queue::assertPushed(PushQuickBooksSite::class, fn (PushQuickBooksSite $job): bool => $job->siteId === $site->id
            && $job->requestId === $site->fresh()->quickbooks_write_request_id);
        Queue::assertNotPushed(PushQuickBooksCustomer::class);
    }

    public function test_basic_user_cannot_open_or_change_the_admin_customer_workspace(): void
    {
        $customer = Customer::create(['name' => 'Private workspace']);
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('admin.customers.workspace.show', $customer))
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('admin.customers.workspace.update', $customer), $this->workspacePayload())
            ->assertForbidden();
    }

    public function test_admin_can_upload_a_private_customer_attachment(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $participant = User::factory()->create(['name' => 'Customer technician', 'status' => 'active']);
        $customer = Customer::create(['name' => 'Attachment customer']);
        $customer->assignedUsers()->attach($participant->getKey(), ['assigned_by_user_id' => $admin->getKey()]);
        $legacyPath = 'customers/'.$customer->id.'/attachments/legacy.pdf';
        Storage::disk('local')->put($legacyPath, 'legacy attachment');
        $legacyAttachment = $customer->attachments()->create([
            'uploaded_by' => $admin->id,
            'kind' => 'documents',
            'original_name' => 'legacy.pdf',
            'disk' => 'local',
            'path' => $legacyPath,
            'mime_type' => 'application/pdf',
            'size' => 17,
        ]);
        $this->mockSharePointUpload('customer-item-1', (int) $customer->getKey());
        Notification::fake();

        $response = $this->actingAs($admin)
            ->post(route('admin.customers.attachments.store', $customer), [
                'file' => UploadedFile::fake()->createWithContent('service-sheet.pdf', "%PDF-1.4\nfixture"),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'service-sheet.pdf')
            ->assertJsonPath('data.uploadedBy', $admin->name);

        $sharePointAttachment = SharePointAttachment::query()->sole();
        $this->assertSame($customer->getKey(), $sharePointAttachment->company_id);
        $this->assertSame($admin->getKey(), $sharePointAttachment->uploaded_by);
        $this->assertSame('customer-item-1', $sharePointAttachment->sharepoint_item_id);
        $this->assertSame('service-sheet.pdf', $sharePointAttachment->filename);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => Customer::class,
            'subject_id' => $customer->getKey(),
            'event' => 'attachment_uploaded',
        ]);
        Notification::assertSentTo($participant, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_uploaded'
            && $notification->via($participant) === ['database', 'mail']);
        Notification::assertSentTo($participant, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_uploaded'
            && $notification->via($participant) === ['mail']);
        $this->assertSame(route('attachments.preview', $sharePointAttachment), $response->json('data.previewUrl'));
        $this->assertNull($response->json('data.thumbnailUrl'));
        Storage::disk('local')->assertMissing('customers/'.$customer->id.'/attachments/service-sheet.pdf');

        $workspace = $this->getJson(route('admin.customers.workspace.show', $customer))
            ->assertOk()
            ->assertJsonCount(2, 'data.attachments');
        $items = collect($workspace->json('data.attachments'));
        $this->assertSame([
            route('admin.customer-attachments.show', $legacyAttachment),
            route('attachments.open', $sharePointAttachment),
        ], $items->pluck('url')->sort()->values()->all());

        $this->get(route('admin.customer-attachments.show', $legacyAttachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('attachments.open', $sharePointAttachment))
            ->assertRedirect('https://tenant.sharepoint.com/sites/work-schedules/customer-item-1');
    }

    public function test_customer_upload_fails_safely_when_sharepoint_is_disabled_without_local_fallback(): void
    {
        Storage::fake('local');
        config(['services.microsoft_graph.sharepoint_enabled' => false]);
        $admin = $this->admin();
        $participant = User::factory()->create(['status' => 'active']);
        $customer = Customer::query()->create(['name' => 'Disabled SharePoint customer']);
        $customer->assignedUsers()->attach($participant->getKey(), ['assigned_by_user_id' => $admin->getKey()]);
        Notification::fake();

        $this->actingAs($admin)
            ->post(route('admin.customers.attachments.store', $customer), [
                'file' => UploadedFile::fake()->createWithContent('blocked.pdf', "%PDF-1.4\nfixture"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(503)
            ->assertJsonPath('message', 'The attachment could not be uploaded. Try again or contact an administrator.')
            ->assertDontSee('SharePoint');

        $this->assertDatabaseCount('customer_attachments', 0);
        $this->assertDatabaseCount('sharepoint_attachments', 0);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => Customer::class,
            'subject_id' => $customer->getKey(),
            'event' => 'attachment_failed',
        ]);
        Notification::assertSentTo($participant, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_failed');
        Notification::assertSentTo($participant, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_failed');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_customer_archive_includes_new_profile_email_and_attachment_records_and_restores_them(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Recoverable customer']);
        $email = CustomerEmailAddress::create([
            'customer_id' => $customer->id,
            'email' => 'dispatch@example.test',
        ]);
        $path = 'customers/'.$customer->id.'/attachments/file.pdf';
        Storage::disk('local')->put($path, 'private customer attachment');
        $attachment = $customer->attachments()->create([
            'uploaded_by' => $admin->id,
            'kind' => 'documents',
            'original_name' => 'Customer file.pdf',
            'disk' => 'local',
            'path' => $path,
            'mime_type' => 'application/pdf',
            'size' => 27,
        ]);

        $batch = app(ArchiveService::class)->archive('customers', $customer->id, $admin);

        $this->assertDatabaseHas('customer_email_addresses', ['id' => $email->id, 'is_archived' => true]);
        $this->assertDatabaseHas('customer_attachments', ['id' => $attachment->id, 'is_archived' => true]);
        Storage::disk('local')->assertExists($path);

        app(ArchiveService::class)->restore($batch->id, $admin);

        $this->assertDatabaseHas('customer_email_addresses', ['id' => $email->id, 'is_archived' => false]);
        $this->assertDatabaseHas('customer_attachments', ['id' => $attachment->id, 'is_archived' => false]);
    }

    /** @return array<string, mixed> */
    private function workspacePayload(?int $assignedUserId = null): array
    {
        return [
            'name' => 'Updated company',
            'company_name' => 'Updated company',
            'customer_group' => 'commercial',
            'customer_status' => 'current_client',
            'billing_address' => [
                'line1' => '1 Dispatch Way',
                'city' => 'Oakland',
                'country_subdivision_code' => 'CA',
                'postal_code' => '94612',
                'country' => 'US',
            ],
            'sites' => [[
                'name' => 'New warehouse',
                'address' => $this->siteAddressPayload('2 Bay Road'),
                'primary_contact_id' => null,
                'primary_contact_key' => null,
            ]],
            'contacts' => [
                [
                    'site_id' => null,
                    'contact_type' => 'primary',
                    'first_name' => 'Dispatch person',
                    'email' => 'dispatch@example.test',
                    'phone' => '555-0100',
                ],
                [
                    'site_id' => null,
                    'contact_type' => 'other',
                    'first_name' => 'Jordan',
                    'last_name' => 'Jones',
                    'email' => 'jordan@example.test',
                ],
            ],
            'email_addresses' => [['email' => 'billing@example.test', 'label' => 'Billing']],
            'assigned_user_ids' => $assignedUserId === null ? [] : [$assignedUserId],
        ];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Admin Person',
            'status' => 'active',
        ]);
    }

    /** @return array<string, string|null> */
    private function siteAddressPayload(string $line1 = '2 Bay Road'): array
    {
        return [
            'line1' => $line1,
            'line2' => null,
            'line3' => null,
            'line4' => null,
            'line5' => null,
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
        ];
    }

    /** @return array<string, mixed> */
    private function sitePayload(Site $site, ?array $address = null): array
    {
        $primaryContactId = $site->primary_contact_id
            ?? $site->contacts()->where('contact_type', 'primary')->value('id');
        $storedAddress = $site->customerAddresses()
            ->where('address_type', CustomerAddress::TYPE_SHIPPING)
            ->first();
        $address ??= $storedAddress?->only([
            'line1', 'line2', 'line3', 'line4', 'line5', 'city',
            'country_subdivision_code', 'postal_code', 'country',
        ]) ?? $this->siteAddressPayload($site->address ?: '2 Bay Road');

        return [
            'id' => $site->getKey(),
            'name' => $site->name,
            'address' => $address,
            'primary_contact_id' => $primaryContactId,
            'primary_contact_key' => null,
        ];
    }

    /** @param list<array<string, mixed>> $methods
     * @return array<string, mixed>
     */
    private function contactPayload(Contact $contact, array $methods): array
    {
        return [
            'id' => $contact->getKey(),
            'site_id' => $contact->site_id,
            'contact_type' => $contact->contact_type,
            'first_name' => $contact->first_name,
            'middle_name' => $contact->middle_name,
            'last_name' => $contact->last_name,
            'title' => $contact->title,
            'suffix' => $contact->suffix,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'alternate_phone' => $contact->alternate_phone,
            'mobile_phone' => $contact->mobile_phone,
            'fax' => $contact->fax,
            'website' => $contact->website,
            'methods' => $methods,
        ];
    }

    private function mockSharePointUpload(string $itemId, int $customerId): void
    {
        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.site_id' => 'site-test',
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-test',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('uploadToTarget')
            ->once()
            ->withArgs(function (string $siteId, string $driveId, string $path, string $contents, string $contentType) use ($customerId): bool {
                $this->assertMatchesRegularExpression(
                    '/\AFieldOps\/customer-'.$customerId.'\/[0-9a-f-]{36}\.[a-z0-9]{1,12}\z/',
                    $path,
                );

                $this->assertSame('site-test', $siteId);
                $this->assertSame('drive-test', $driveId);

                return true;
            })
            ->andReturnUsing(fn (string $siteId, string $driveId, string $path, string $contents, string $contentType): array => [
                'id' => $itemId,
                'name' => basename($path),
                'size' => strlen($contents),
                'mime_type' => $contentType,
                'e_tag' => 'etag-'.$itemId,
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/'.$itemId,
            ]);
    }

    private function connectQuickBooks(): void
    {
        IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-workspace-realm',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
    }
}
