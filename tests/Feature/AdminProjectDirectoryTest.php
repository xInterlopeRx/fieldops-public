<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminProjectDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_browse_a_bounded_project_directory_and_find_linked_customers(): void
    {
        $admin = $this->admin();
        $customer = Customer::create([
            'name' => 'Northwind Facilities',
            'company_name' => 'Northwind Group',
        ]);

        foreach (range(1, 26) as $number) {
            Project::query()->create([
                'customer_id' => $customer->id,
                'project_number' => sprintf('P-%02d', $number),
                'name' => sprintf('Facility upgrade %02d', $number),
                'status' => 'active',
            ]);
        }

        $this->actingAs($admin)
            ->get(route('admin.projects', ['q' => 'Northwind Group']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Projects')
                ->where('projects.total', 26)
                ->where('projects.per_page', 25)
                ->where('projects.data.0.name', 'Facility upgrade 01')
                ->where('projects.data.0.customerName', 'Northwind Group')
                ->where('projects.data.0.sitesCount', 0)
                ->where('projects.data.0.jobsCount', 0)
                ->where('filters.q', 'Northwind Group'));
    }

    public function test_basic_user_cannot_browse_projects_and_guest_is_redirected(): void
    {
        $this->get(route('admin.projects'))
            ->assertRedirect(route('login'));

        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(route('admin.projects'))
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }
}
