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
}
