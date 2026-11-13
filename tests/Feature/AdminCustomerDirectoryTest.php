<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminCustomerDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_browse_a_bounded_customer_directory_without_provider_ids(): void
    {
        $admin = $this->admin();

        foreach (range(1, 26) as $number) {
            Customer::create([
                'client_id' => 'C'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
                'quickbooks_id' => (string) (100 + $number),
                'name' => sprintf('Customer %02d', $number),
                'company_name' => 'Sandbox Company',
                'email' => "customer{$number}@example.test",
                'quickbooks_active' => true,
                'quickbooks_sync_status' => 'synced',
                'quickbooks_last_synced_at' => now(),
            ]);
        }
        $customer = Customer::query()->where('client_id', 'C00001')->firstOrFail();
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => '1 Demo Way',
            'city' => 'Testville',
            'country_subdivision_code' => 'CA',
            'postal_code' => '90000',
            'country' => 'US',
            'is_default' => true,
        ]);
        $site = $customer->sites()->create(['name' => 'Demo directory site']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'DEMO-ADMIN-P1',
            'name' => 'Demo directory project',
        ]);
        $project->sites()->attach($site);
        WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'DEMO-ADMIN-J1',
            'title' => 'Demo directory job',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers')
                ->where('customers.total', 26)
                ->where('customers.per_page', 25)
                ->where('customers.data.0.name', 'Customer 01')
                ->where('customers.data.0.sitesCount', 1)
                ->where('customers.data.0.projectsCount', 1)
                ->where('customers.data.0.jobsCount', 1)
                ->where('customers.data.0.primaryAddress', '1 Demo Way, Testville CA 90000, US')
                ->where('customers.data.0.syncStatus', 'synced')
                ->missing('customers.data.0.quickbooks_id')
                ->where('filters.groups', ['unassigned', 'residential', 'commercial', 'industrial', 'non_profit'])
                ->where('filters.statuses', ['unassigned', 'opportunity', 'current_client', 'transferred_client', 'lost_opportunity', 'archived'])
                ->where('statusOptions.2.label', 'Current Customer')
                ->where('statusOptions.3.label', 'Transferred Customer')
                ->where('filters.q', ''));
    }

    public function test_admin_can_find_a_customer_by_a_linked_site_name(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'name' => 'Freeman Sporting Goods',
            'quickbooks_id' => '7',
        ]);
        $customer->sites()->create([
            'name' => '0969 Ocean View',
            'quickbooks_id' => '8',
            'quickbooks_parent_id' => '7',
            'quickbooks_is_job' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers', ['q' => 'ocean view']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers')
                ->where('customers.total', 1)
                ->where('customers.data.0.name', 'Freeman Sporting Goods')
                ->where('customers.data.0.sitesCount', 1)
                ->where('filters.q', 'ocean view'));
    }

    public function test_admin_can_filter_customers_by_group_and_status_and_sort_results(): void
    {
        $admin = $this->admin();
        Customer::query()->create([
            'client_id' => 'C00010',
            'name' => 'Alpha Residence',
            'customer_group' => 'residential',
            'customer_status' => 'opportunity',
        ]);
        Customer::query()->create([
            'client_id' => 'C00002',
            'name' => 'Zeta Residence',
            'customer_group' => 'residential',
            'customer_status' => 'opportunity',
        ]);
        Customer::query()->create([
            'client_id' => 'C00001',
            'name' => 'Commercial Client',
            'customer_group' => 'commercial',
            'customer_status' => 'current_client',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers', [
                'exclude_groups' => ['unassigned', 'commercial', 'industrial', 'non_profit'],
                'exclude_statuses' => ['unassigned', 'current_client', 'transferred_client', 'lost_opportunity', 'archived'],
                'sort' => 'name_desc',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 2)
                ->where('customers.data.0.name', 'Zeta Residence')
                ->where('customers.data.1.name', 'Alpha Residence')
                ->where('customers.data.0.customerGroup', 'residential')
                ->where('customers.data.0.customerStatus', 'opportunity')
                ->where('filters.groups', ['residential'])
                ->where('filters.statuses', ['opportunity'])
                ->where('filters.sort', 'name_desc'));

        $this->get(route('admin.customers', [
            'exclude_groups' => ['unassigned', 'commercial', 'industrial', 'non_profit'],
            'exclude_statuses' => ['unassigned', 'current_client', 'transferred_client', 'lost_opportunity', 'archived'],
            'sort' => 'client_id_asc',
        ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.name', 'Zeta Residence')
                ->where('customers.data.1.name', 'Alpha Residence'));
    }

    public function test_admin_can_sort_customers_by_primary_contact_first_and_last_name(): void
    {
        $admin = $this->admin();
        Customer::query()->create([
            'name' => 'Zoe Example',
            'first_name' => 'Zoe',
            'last_name' => 'Alpha',
        ]);
        Customer::query()->create([
            'name' => 'Avery Example',
            'first_name' => 'Avery',
            'last_name' => 'Zephyr',
        ]);
        Customer::query()->create([
            'name' => 'Morgan Example',
            'first_name' => 'Morgan',
            'last_name' => 'Young',
        ]);

        $sorts = [
            'primary_first_asc' => ['Avery Example', 'Morgan Example', 'Zoe Example'],
            'primary_first_desc' => ['Zoe Example', 'Morgan Example', 'Avery Example'],
            'primary_last_asc' => ['Zoe Example', 'Morgan Example', 'Avery Example'],
            'primary_last_desc' => ['Avery Example', 'Morgan Example', 'Zoe Example'],
        ];

        foreach ($sorts as $sort => $expectedNames) {
            $response = $this->actingAs($admin)->get(route('admin.customers', ['sort' => $sort]));

            $response->assertInertia(fn (Assert $page) => $page
                ->where('customers.data.0.name', $expectedNames[0])
                ->where('customers.data.1.name', $expectedNames[1])
                ->where('customers.data.2.name', $expectedNames[2])
                ->where('filters.sort', $sort));
        }
    }

    public function test_archived_customers_are_excluded_from_directory_and_use_archive_restore(): void
    {
        $admin = $this->admin();
        $active = Customer::query()->create([
            'name' => 'Active Customer',
            'customer_group' => 'industrial',
            'customer_status' => 'current_client',
        ]);
        $archived = Customer::query()->create([
            'name' => 'Archived Customer',
            'customer_group' => 'non_profit',
            'customer_status' => 'transferred_client',
        ]);
        $batch = app(ArchiveService::class)->archive('customers', $archived->getKey(), $admin);

        $this->actingAs($admin)
            ->get(route('admin.customers', ['exclude_statuses' => [
                'unassigned', 'opportunity', 'current_client', 'transferred_client', 'lost_opportunity',
            ]]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 0)
                ->where('filters.statuses', ['archived'])
                ->where('filters.showArchivedShortcut', true));

        $this->get(route('admin.customers'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('customers.total', 1)
                ->where('customers.data.0.id', $active->getKey()));

        $this->get(route('admin.archive', ['type' => 'customers']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('batches.data.0.id', $batch->getKey())
                ->where('batches.data.0.record.id', $archived->getKey()));
    }

    public function test_basic_user_cannot_browse_the_customer_directory(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('admin.customers'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_the_customer_directory(): void
    {
        $this->get(route('admin.customers'))
            ->assertRedirect(route('login'));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'status' => 'active',
        ]);
    }
}
