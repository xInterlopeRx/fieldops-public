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

    public function test_admin_can_filter_projects_by_status_company_and_project_name_and_sort_by_number(): void
    {
        $admin = $this->admin();
        $zeta = Customer::create(['name' => 'Zeta Facilities', 'company_name' => 'Zeta Company']);
        $alpha = Customer::create(['name' => 'Alpha Works', 'company_name' => 'Alpha Company']);

        Project::query()->create([
            'customer_id' => $zeta->id,
            'project_number' => 'P-010',
            'name' => 'North Bridge Phase One',
            'status' => 'planning',
        ]);
        Project::query()->create([
            'customer_id' => $zeta->id,
            'project_number' => 'P-020',
            'name' => 'North Bridge Phase Two',
            'status' => 'active',
        ]);
        Project::query()->create([
            'customer_id' => $zeta->id,
            'project_number' => 'P-030',
            'name' => 'North Bridge Complete',
            'status' => 'completed',
        ]);
        Project::query()->create([
            'customer_id' => $alpha->id,
            'project_number' => 'P-040',
            'name' => 'North Bridge Other Company',
            'status' => 'planning',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.projects', [
                'company' => 'Zeta',
                'project_name' => 'North Bridge',
                'exclude_statuses' => ['completed'],
                'sort' => 'number_desc',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Projects')
                ->where('projects.total', 2)
                ->where('projects.data.0.number', 'P-020')
                ->where('projects.data.1.number', 'P-010')
                ->where('filters.company', 'Zeta')
                ->where('filters.projectName', 'North Bridge')
                ->where('filters.statuses', ['created', 'scheduled', 'planning', 'active', 'on_hold', 'cancelled'])
                ->where('filters.sort', 'number_desc')
                ->where('statusOptions', [
                    ['value' => 'created', 'label' => 'Created', 'color' => 'gray'],
                    ['value' => 'scheduled', 'label' => 'Scheduled', 'color' => 'blue'],
                    ['value' => 'planning', 'label' => 'Planning', 'color' => 'purple'],
                    ['value' => 'active', 'label' => 'Active', 'color' => 'green'],
                    ['value' => 'on_hold', 'label' => 'On Hold', 'color' => 'orange'],
                    ['value' => 'completed', 'label' => 'Completed', 'color' => 'green'],
                    ['value' => 'cancelled', 'label' => 'Cancelled', 'color' => 'red'],
                ]));
    }

    public function test_admin_can_sort_projects_by_company_name(): void
    {
        $admin = $this->admin();
        $zeta = Customer::create(['name' => 'Zeta Facilities', 'company_name' => 'Zeta Company']);
        $alpha = Customer::create(['name' => 'Alpha Works', 'company_name' => 'Alpha Company']);
        $zetaProject = Project::query()->create([
            'customer_id' => $zeta->id,
            'project_number' => 'P-010',
            'name' => 'Alpha Project',
            'status' => 'active',
        ]);
        $alphaProject = Project::query()->create([
            'customer_id' => $alpha->id,
            'project_number' => 'P-020',
            'name' => 'Zulu Project',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.projects', ['sort' => 'company_asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.data.0.id', $alphaProject->id)
                ->where('projects.data.1.id', $zetaProject->id));
    }

    public function test_project_directory_rejects_unknown_status_filters_and_sort_values(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.projects', [
                'exclude_statuses' => ['not-a-project-status'],
                'sort' => 'untrusted-column',
            ]))
            ->assertSessionHasErrors(['exclude_statuses.0', 'sort']);
    }

    public function test_unchecking_every_project_status_returns_no_projects(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Status Filter Company']);
        Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'P-001',
            'name' => 'Only Project',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.projects', [
                'exclude_statuses' => ['created', 'scheduled', 'planning', 'active', 'on_hold', 'completed', 'cancelled'],
            ]))
            ->assertInertia(fn (Assert $page) => $page->where('projects.total', 0));
    }

    public function test_admin_can_sort_projects_by_name(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Project Sorting Company']);
        $zuluProject = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'P-002',
            'name' => 'Zulu Project',
            'status' => 'active',
        ]);
        $alphaProject = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'P-001',
            'name' => 'Alpha Project',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.projects', ['sort' => 'name_desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('projects.data.0.id', $zuluProject->id)
                ->where('projects.data.1.id', $alphaProject->id));
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
