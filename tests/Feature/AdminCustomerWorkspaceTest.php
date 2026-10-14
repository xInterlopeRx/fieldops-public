<?php

namespace Tests\Feature;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\PushQuickBooksSite;
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
        $this->assertSame('Updated customer', $customer->name);
        $this->assertSame('Dispatch person', $customer->first_name);
        $this->assertSame('1 Dispatch Way, Oakland CA 94612, US', $customer->address);
        $this->assertDatabaseHas('sites', [
            'customer_id' => $customer->id,
            'name' => 'New warehouse',
            'address' => '2 Bay Road',
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

    public function test_customer_workspace_creates_customer_wide_and_site_specific_role_contacts(): void
    {
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Scoped role contacts']);
        $site = $customer->sites()->create(['name' => 'North service site']);
        $payload = $this->workspacePayload();
        $payload['sites'] = [[
            'id' => $site->getKey(),
            'name' => $site->name,
            'address' => null,
        ]];
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
            'address' => '2 QBO Road',
            'quickbooks_id' => '101',
            'quickbooks_sync_token' => '2',
            'quickbooks_parent_id' => '100',
        ]);
        $contact = $customer->contacts()->create([
            'site_id' => $site->getKey(),
            'contact_type' => 'primary',
            'source' => 'quickbooks',
            'first_name' => 'Source',
            'email' => 'source@example.test',
            'phone' => '555-0101',
        ]);
        $payload = $this->workspacePayload();
        $payload['name'] = $customer->name;
        $payload['company_name'] = $customer->company_name;
        $payload['first_name'] = $customer->first_name;
        $payload['email'] = $customer->email;
        $payload['phone'] = $customer->phone;
        $payload['billing_address'] = [
            'line1' => '1 Dispatch Way',
            'city' => 'Oakland',
            'country_subdivision_code' => 'CA',
            'postal_code' => '94612',
            'country' => 'US',
        ];
        $payload['sites'] = [['id' => $site->getKey(), 'name' => $site->name, 'address' => $site->address]];
        $payload['contacts'] = [$this->contactPayload($contact, [[
            'method_type' => 'email',
            'label' => 'operations',
            'value' => 'ops@example.test',
            'is_primary' => true,
        ]])];
        $payload['email_addresses'] = [];

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), $payload)
            ->assertOk();

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

        $this->actingAs($admin)
            ->putJson(route('admin.customers.workspace.update', $customer), [
                ...$this->workspacePayload(),
                'sites' => [['id' => $site->id, 'name' => $site->name, 'address' => $site->address]],
                'contacts' => [[
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
                ]],
            ])
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
            ->assertRedirect('https://example.sharepoint.com/sites/work-schedules/customer-item-1');
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
            'name' => 'Updated customer',
            'company_name' => 'Updated company',
            'first_name' => 'Dispatch person',
            'email' => 'dispatch@example.test',
            'phone' => '555-0100',
            'billing_address' => [
                'line1' => '1 Dispatch Way',
                'city' => 'Oakland',
                'country_subdivision_code' => 'CA',
                'postal_code' => '94612',
                'country' => 'US',
            ],
            'sites' => [['name' => 'New warehouse', 'address' => '2 Bay Road']],
            'contacts' => [[
                'contact_type' => 'other',
                'first_name' => 'Jordan',
                'last_name' => 'Jones',
                'email' => 'jordan@example.test',
            ]],
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
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-test',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('upload')
            ->once()
            ->withArgs(function (string $path, string $contents, string $contentType) use ($customerId): bool {
                $this->assertMatchesRegularExpression(
                    '/\AFieldOps\/customer-'.$customerId.'\/[0-9a-f-]{36}\.[a-z0-9]{1,12}\z/',
                    $path,
                );

                return true;
            })
            ->andReturnUsing(fn (string $path, string $contents, string $contentType): array => [
                'id' => $itemId,
                'name' => basename($path),
                'size' => strlen($contents),
                'mime_type' => $contentType,
                'e_tag' => 'etag-'.$itemId,
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/'.$itemId,
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
