<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
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
