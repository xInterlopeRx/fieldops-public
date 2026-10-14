<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimeEntryTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_timer_creates_a_draft_entry_and_active_timer_query_returns_it(): void
    {
        $user = $this->staffUser();
        $customer = Customer::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('time-entries.timer.start'), [
                'customer_id' => $customer->id,
                'activity_type' => 'labor',
                'notes' => 'Replace a damaged fixture.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.userId', $user->id)
            ->assertJsonPath('data.customerId', $customer->id)
            ->assertJsonPath('data.activityType', 'labor')
            ->assertJsonPath('data.endedAt', null)
            ->assertJsonPath('data.durationMinutes', null)
            ->assertJsonPath('data.billable', false)
            ->assertJsonPath('data.approvalStatus', 'draft');

        $timeEntryId = $response->json('data.id');

        $this->assertDatabaseHas('time_entries', [
            'id' => $timeEntryId,
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'ended_at' => null,
            'duration_minutes' => null,
            'approval_status' => 'draft',
        ]);

        $this->getJson(route('time-entries.active'))
            ->assertOk()
            ->assertJsonPath('data.id', $timeEntryId);
    }

    public function test_user_cannot_start_a_second_active_timer(): void
    {
        $user = $this->staffUser();
        $customer = Customer::factory()->create();
        $payload = [
            'customer_id' => $customer->id,
            'activity_type' => 'travel',
        ];

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.start'), $payload)
            ->assertCreated();

        $this->postJson(route('time-entries.timer.start'), $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'You already have an active timer.');

        $this->assertSame(1, TimeEntry::query()->where('user_id', $user->id)->whereNull('ended_at')->count());
    }

    public function test_start_timer_accepts_project_only_context_and_validates_customer_scope(): void
    {
        $user = $this->staffUser();
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.start'), [
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'activity_type' => 'project_work',
            ])
            ->assertCreated()
            ->assertJsonPath('data.projectId', $project->id)
            ->assertJsonPath('data.jobId', null);

        $otherUser = $this->staffUser();
        $otherCustomer = Customer::factory()->create();
        $foreignProject = Project::factory()->create(['customer_id' => $otherCustomer->id]);

        $this->actingAs($otherUser)
            ->postJson(route('time-entries.timer.start'), [
                'customer_id' => $customer->id,
                'project_id' => $foreignProject->id,
                'activity_type' => 'project_work',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Choose a Project belonging to the selected Customer.');

        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_start_timer_associates_a_job_visit_and_its_project(): void
    {
        $user = $this->staffUser();
        [$customer, $project, $job] = $this->jobContext();
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Inspect the work.',
        ]);

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.start'), [
                'customer_id' => $customer->id,
                'job_id' => $job->id,
                'visit_id' => $visit->id,
                'activity_type' => 'labor',
            ])
            ->assertCreated()
            ->assertJsonPath('data.projectId', $project->id)
            ->assertJsonPath('data.jobId', $job->id)
            ->assertJsonPath('data.visitId', $visit->id);

        $this->assertDatabaseHas('time_entries', [
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'job_id' => $job->id,
            'visit_id' => $visit->id,
        ]);
    }

    public function test_stop_timer_saves_end_time_and_elapsed_whole_minutes(): void
    {
        $user = $this->staffUser();
        $customer = Customer::factory()->create();
        $this->travelTo('2026-10-02 14:00:00');

        $started = $this->actingAs($user)
            ->postJson(route('time-entries.timer.start'), [
                'customer_id' => $customer->id,
                'activity_type' => 'remote_support',
            ])
            ->assertCreated();
        $timeEntryId = $started->json('data.id');
        $this->travelTo('2026-10-02 14:07:45');

        $this->postJson(route('time-entries.timer.stop', $timeEntryId))
            ->assertOk()
            ->assertJsonPath('data.endedAt', '2026-10-02T14:07:45+00:00')
            ->assertJsonPath('data.durationMinutes', 7)
            ->assertJsonPath('data.approvalStatus', 'draft');

        $this->assertDatabaseHas('time_entries', [
            'id' => $timeEntryId,
            'updated_by' => $user->id,
            'duration_minutes' => 7,
            'approval_status' => 'draft',
        ]);
        $this->getJson(route('time-entries.active'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_stop_timer_returns_not_found_for_unknown_or_another_users_entry(): void
    {
        $user = $this->staffUser();
        $otherUser = $this->staffUser();
        $otherEntry = TimeEntry::factory()->create([
            'user_id' => $otherUser->id,
            'created_by' => $otherUser->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.stop', 999999))
            ->assertNotFound();

        $this->postJson(route('time-entries.timer.stop', $otherEntry))
            ->assertNotFound();
    }

    public function test_stop_timer_returns_conflict_for_an_already_stopped_entry(): void
    {
        $user = $this->staffUser();
        $entry = TimeEntry::factory()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.stop', $entry))
            ->assertConflict()
            ->assertJsonPath('message', 'This timer has already been stopped.');
    }

    public function test_stop_timer_rejects_client_supplied_end_time_and_duration(): void
    {
        $user = $this->staffUser();
        $entry = TimeEntry::factory()->create([
            'user_id' => $user->id,
            'created_by' => $user->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($user)
            ->postJson(route('time-entries.timer.stop', $entry), [
                'ended_at' => now()->toIso8601String(),
                'duration_minutes' => 10,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ended_at', 'duration_minutes']);

        $this->assertNull($entry->fresh()->ended_at);
        $this->assertNull($entry->fresh()->duration_minutes);
    }

    public function test_active_timer_query_is_limited_to_the_signed_in_user(): void
    {
        $user = $this->staffUser();
        $otherUser = $this->staffUser();
        $entry = TimeEntry::factory()->create([
            'user_id' => $otherUser->id,
            'created_by' => $otherUser->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($user)
            ->getJson(route('time-entries.active'))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertDatabaseHas('time_entries', ['id' => $entry->id, 'user_id' => $otherUser->id]);
    }

    public function test_timer_endpoints_forbid_staff_without_work_order_permissions(): void
    {
        $user = $this->staffUser();
        $user->syncRoles(['management']);
        $customer = Customer::factory()->create();

        $this->actingAs($user)
            ->getJson(route('time-entries.active'))
            ->assertForbidden();

        $this->postJson(route('time-entries.timer.start'), [
            'customer_id' => $customer->id,
            'activity_type' => 'labor',
        ])->assertForbidden();

        $this->assertDatabaseCount('time_entries', 0);
    }

    private function staffUser(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    /** @return array{Customer, Project, WorkOrder} */
    private function jobContext(): array
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['customer_id' => $customer->id]);
        $site = Site::create([
            'customer_id' => $customer->id,
            'name' => 'Timer test site',
        ]);
        $job = WorkOrder::create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-TIMER-1',
            'title' => 'Timer test job',
            'scope' => 'Record time for the timer test.',
            'status' => 'scheduled',
        ]);

        return [$customer, $project, $job];
    }
}
