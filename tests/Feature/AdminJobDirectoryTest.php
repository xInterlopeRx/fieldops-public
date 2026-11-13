<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminJobDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_jobs_by_project_and_search_related_records(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Harbor Works']);
        $site = $customer->sites()->create(['name' => 'East Pier']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'HARBOR-P1',
            'name' => 'Harbor retrofit',
            'status' => 'active',
        ]);
        $project->sites()->attach($site);
        $job = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'HARBOR-J1',
            'title' => 'Replace dock lighting',
            'status' => 'pending',
        ]);
        WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'HARBOR-J2',
            'title' => 'Unrelated work item',
            'status' => 'completed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.jobs', [
                'project_id' => $project->id,
                'q' => 'East Pier',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Jobs')
                ->where('jobs.total', 1)
                ->where('jobs.data.0.id', $job->id)
                ->where('jobs.data.0.number', 'HARBOR-J1')
                ->where('jobs.data.0.siteName', 'East Pier')
                ->where('jobs.data.0.customerName', 'Harbor Works')
                ->where('jobs.data.0.projectName', 'Harbor retrofit')
                ->where('jobs.data.0.visitsCount', 0)
                ->where('projectFilter.id', $project->id)
                ->where('filters.project_id', $project->id));
    }

    public function test_admin_can_filter_jobs_by_status_company_and_project_name_and_sort_by_job_number(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Zeta Facilities', 'company_name' => 'Zeta Company']);
        $site = $customer->sites()->create(['name' => 'Main Site']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'ZETA-P1',
            'name' => 'North Campus',
            'status' => 'active',
        ]);
        $southProject = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'ZETA-P2',
            'name' => 'South Campus',
            'status' => 'active',
        ]);
        $firstJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-010',
            'title' => 'North maintenance',
            'status' => 'pending',
        ]);
        $secondJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-020',
            'title' => 'North upgrade',
            'status' => 'scheduled',
        ]);
        WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-030',
            'title' => 'North completed job',
            'status' => 'completed',
        ]);
        WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $southProject->id,
            'job_number' => 'J-040',
            'title' => 'South maintenance',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.jobs', [
                'project_id' => $project->id,
                'company' => 'Zeta',
                'project_name' => 'North',
                'exclude_statuses' => ['completed'],
                'sort' => 'job_number_desc',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Jobs')
                ->where('jobs.total', 2)
                ->where('jobs.data.0.id', $secondJob->id)
                ->where('jobs.data.1.id', $firstJob->id)
                ->where('filters.company', 'Zeta')
                ->where('filters.projectName', 'North')
                ->where('filters.project_id', $project->id)
                ->where('filters.sort', 'job_number_desc')
                ->where('statusOptions.0.value', 'created'));
    }

    public function test_admin_can_sort_jobs_by_company_name(): void
    {
        $admin = $this->admin();
        ['zetaJob' => $zetaJob, 'alphaJob' => $alphaJob] = $this->jobsForRelatedSort();

        $this->actingAs($admin)
            ->get(route('admin.jobs', ['sort' => 'company_asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $alphaJob->id)
                ->where('jobs.data.1.id', $zetaJob->id));
    }

    public function test_admin_can_sort_jobs_by_project_name(): void
    {
        $admin = $this->admin();
        ['zetaJob' => $zetaJob, 'alphaJob' => $alphaJob] = $this->jobsForRelatedSort();

        $this->actingAs($admin)
            ->get(route('admin.jobs', ['sort' => 'project_asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $zetaJob->id)
                ->where('jobs.data.1.id', $alphaJob->id));
    }

    public function test_admin_can_sort_jobs_by_title(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Title Sorting Company']);
        $site = $customer->sites()->create(['name' => 'Main Site']);
        $zuluJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'J-001',
            'title' => 'Zulu Job',
            'status' => 'scheduled',
        ]);
        $alphaJob = WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'J-002',
            'title' => 'Alpha Job',
            'status' => 'scheduled',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.jobs', ['sort' => 'title_desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('jobs.data.0.id', $zuluJob->id)
                ->where('jobs.data.1.id', $alphaJob->id));
    }

    public function test_job_directory_rejects_unknown_status_filters_and_sort_values(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.jobs', [
                'exclude_statuses' => ['not-a-job-status'],
                'sort' => 'untrusted-column',
            ]))
            ->assertSessionHasErrors(['exclude_statuses.0', 'sort']);
    }

    public function test_unchecking_every_job_status_returns_no_jobs(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Status Filter Company']);
        $site = $customer->sites()->create(['name' => 'Main Site']);
        WorkOrder::query()->create([
            'site_id' => $site->id,
            'job_number' => 'J-001',
            'title' => 'Only Job',
            'status' => 'created',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.jobs', [
                'exclude_statuses' => ['created', 'scheduled', 'pending', 'on_my_way', 'in_progress', 'completed', 'cancelled'],
            ]))
            ->assertInertia(fn (Assert $page) => $page->where('jobs.total', 0));
    }

    public function test_basic_user_cannot_browse_jobs_and_guest_is_redirected(): void
    {
        $this->get(route('admin.jobs'))
            ->assertRedirect(route('login'));

        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->get(route('admin.jobs'))
            ->assertForbidden();
    }

    public function test_job_status_color_registry_is_shared_with_the_authenticated_ui(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.jobs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('work_order_statuses', fn ($statuses): bool => collect($statuses)
                    ->mapWithKeys(fn (array $status): array => [$status['value'] => $status['status_color']])
                    ->all() === [
                        'created' => 'gray',
                        'scheduled' => 'blue',
                        'pending' => 'orange',
                        'on_my_way' => 'purple',
                        'in_progress' => 'magenta',
                        'completed' => 'green',
                        'cancelled' => 'red',
                    ]));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }

    /** @return array{zetaJob: WorkOrder, alphaJob: WorkOrder} */
    private function jobsForRelatedSort(): array
    {
        $zeta = Customer::create(['name' => 'Zeta Facilities', 'company_name' => 'Zeta Company']);
        $alpha = Customer::create(['name' => 'Alpha Works', 'company_name' => 'Alpha Company']);
        $zetaSite = $zeta->sites()->create(['name' => 'Zeta Site']);
        $alphaSite = $alpha->sites()->create(['name' => 'Alpha Site']);
        $zetaProject = Project::query()->create([
            'customer_id' => $zeta->id,
            'project_number' => 'ZETA-P1',
            'name' => 'Alpha Project',
            'status' => 'active',
        ]);
        $alphaProject = Project::query()->create([
            'customer_id' => $alpha->id,
            'project_number' => 'ALPHA-P1',
            'name' => 'Zulu Project',
            'status' => 'active',
        ]);
        $zetaJob = WorkOrder::query()->create([
            'site_id' => $zetaSite->id,
            'project_id' => $zetaProject->id,
            'job_number' => 'J-ZETA',
            'title' => 'Zeta Job',
            'status' => 'scheduled',
        ]);
        $alphaJob = WorkOrder::query()->create([
            'site_id' => $alphaSite->id,
            'project_id' => $alphaProject->id,
            'job_number' => 'J-ALPHA',
            'title' => 'Alpha Job',
            'status' => 'scheduled',
        ]);

        return ['zetaJob' => $zetaJob, 'alphaJob' => $alphaJob];
    }
}
