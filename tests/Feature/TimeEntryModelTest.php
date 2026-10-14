<?php

namespace Tests\Feature;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimeEntryModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_draft_entry_with_typed_activity_and_approval_enums(): void
    {
        $timeEntry = TimeEntry::factory()->create();

        $this->assertModelExists($timeEntry);
        $this->assertSame(TimeEntryActivityType::Administrative, $timeEntry->activity_type);
        $this->assertSame(TimeEntryApprovalStatus::Draft, $timeEntry->approval_status);
        $this->assertFalse($timeEntry->billable);
        $this->assertSame($timeEntry->user_id, $timeEntry->created_by);
        $this->assertSame(60, $timeEntry->duration_minutes);
        $this->assertGreaterThan($timeEntry->started_at, $timeEntry->ended_at);
    }

    public function test_time_entry_resolves_performer_customer_project_job_visit_and_audit_users(): void
    {
        $performer = User::factory()->create();
        $creator = User::factory()->create();
        $updater = User::factory()->create();
        $customer = Customer::factory()->create();
        $site = Site::create([
            'customer_id' => $customer->id,
            'name' => 'Time entry site',
        ]);
        $project = Project::factory()->create(['customer_id' => $customer->id]);
        $job = WorkOrder::create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-TIME-1',
            'title' => 'Time entry test job',
            'scope' => 'Record a time entry.',
            'status' => 'scheduled',
        ]);
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Record time against this Visit.',
        ]);
        $timeEntry = TimeEntry::factory()->create([
            'user_id' => $performer->id,
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'job_id' => $job->id,
            'visit_id' => $visit->id,
            'created_by' => $creator->id,
            'updated_by' => $updater->id,
        ]);

        $this->assertSame($performer->id, $timeEntry->user->id);
        $this->assertSame($customer->id, $timeEntry->customer->id);
        $this->assertSame($project->id, $timeEntry->project->id);
        $this->assertSame($job->id, $timeEntry->job->id);
        $this->assertSame($visit->id, $timeEntry->visit->id);
        $this->assertSame($creator->id, $timeEntry->createdBy->id);
        $this->assertSame($updater->id, $timeEntry->updatedBy->id);
        $this->assertTrue($customer->timeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($project->timeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($job->timeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($visit->timeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($performer->timeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($creator->createdTimeEntries()->whereKey($timeEntry->id)->exists());
        $this->assertTrue($updater->updatedTimeEntries()->whereKey($timeEntry->id)->exists());
    }

    public function test_project_only_entry_can_have_no_job_or_visit(): void
    {
        $customer = Customer::factory()->create();
        $project = Project::factory()->create(['customer_id' => $customer->id]);

        $timeEntry = TimeEntry::factory()->create([
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'job_id' => null,
            'visit_id' => null,
            'activity_type' => TimeEntryActivityType::ProjectWork,
        ]);

        $this->assertSame($project->id, $timeEntry->project->id);
        $this->assertNull($timeEntry->job_id);
        $this->assertNull($timeEntry->visit_id);
        $this->assertSame(TimeEntryActivityType::ProjectWork, $timeEntry->activity_type);
    }

    public function test_database_rejects_activity_types_outside_the_enum(): void
    {
        $timeEntry = TimeEntry::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('time_entries')
            ->where('id', $timeEntry->id)
            ->update(['activity_type' => 'not_a_time_entry_activity']);
    }

    public function test_database_rejects_approval_statuses_outside_the_enum(): void
    {
        $timeEntry = TimeEntry::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('time_entries')
            ->where('id', $timeEntry->id)
            ->update(['approval_status' => 'not_an_approval_status']);
    }

    public function test_database_rejects_negative_duration_and_nonpositive_intervals(): void
    {
        $timeEntry = TimeEntry::factory()->create();

        try {
            DB::table('time_entries')
                ->where('id', $timeEntry->id)
                ->update(['duration_minutes' => -1]);

            $this->fail('Expected negative duration to be rejected.');
        } catch (QueryException) {
            $this->assertModelExists($timeEntry);
        }

        $this->expectException(QueryException::class);

        DB::table('time_entries')
            ->where('id', $timeEntry->id)
            ->update(['ended_at' => $timeEntry->started_at]);
    }

    public function test_database_allows_only_one_active_timer_per_user(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstActiveTimer = TimeEntry::factory()->create([
            'user_id' => $firstUser->id,
            'created_by' => $firstUser->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);
        $secondUsersActiveTimer = TimeEntry::factory()->create([
            'user_id' => $secondUser->id,
            'created_by' => $secondUser->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->assertModelExists($firstActiveTimer);
        $this->assertModelExists($secondUsersActiveTimer);

        $this->expectException(QueryException::class);

        TimeEntry::factory()->create([
            'user_id' => $firstUser->id,
            'created_by' => $firstUser->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);
    }
}
