<?php

namespace Tests\Feature;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Models\Customer;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TimeEntryTeamTimesheetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_without_security_groups_sees_only_own_and_shared_work_timesheets_without_financial_fields(): void
    {
        $this->travelTo('2026-11-12 15:00:00');
        $viewer = User::factory()->adminWithoutSecurityGroups()->create(['status' => 'active']);
        $teammate = User::factory()->create(['name' => 'Team Technician', 'status' => 'active']);
        $unrelated = User::factory()->create(['name' => 'Unrelated Technician', 'status' => 'active']);
        $customer = Customer::create(['name' => 'Assigned Team Client']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Assigned Team Site']);
        $sharedJob = $this->job($site, 'J-TEAM-1');
        $unsharedJob = $this->job($site, 'J-TEAM-2');
        $sharedJob->assignedUsers()->attach($viewer->getKey(), ['assigned_by_user_id' => $viewer->getKey()]);
        $sharedJob->assignedUsers()->attach($teammate->getKey(), ['assigned_by_user_id' => $viewer->getKey()]);
        $unsharedJob->assignedUsers()->attach($unrelated->getKey(), ['assigned_by_user_id' => $unrelated->getKey()]);
        $visit = $sharedJob->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => '2026-11-12 14:00:00',
            'duration_minutes' => 60,
            'scope' => 'Shared team visit.',
        ]);
        $visit->assignedUsers()->attach($viewer->getKey());
        $visitOnlyJob = $this->job($site, 'J-TEAM-3');
        $visitOnlyJob->assignedUsers()->attach($teammate->getKey(), ['assigned_by_user_id' => $teammate->getKey()]);
        $visitOnly = $visitOnlyJob->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => '2026-11-12 13:00:00',
            'duration_minutes' => 60,
            'scope' => 'Visit-only team assignment.',
        ]);
        $visitOnly->assignedUsers()->attach($viewer->getKey());

        $sharedEntry = $this->entry($teammate, $customer, $sharedJob, $visit, [
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
            'notes' => 'Private correction note',
        ]);
        $unsharedEntry = $this->entry($unrelated, $customer, $unsharedJob);
        $ownEntry = $this->entry($viewer, $customer, $sharedJob);
        $visitSharedEntry = $this->entry($teammate, $customer, $visitOnlyJob, $visitOnly);

        $this->actingAs($viewer)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Team')
                ->where('entries.total', 3)
                ->where('entries.data.0.id', $sharedEntry->getKey())
                ->where('entries.data.0.technician', 'Team Technician')
                ->where('entries.data.0.statusLabel', 'Reviewed')
                ->missing('entries.data.0.billable')
                ->missing('entries.data.0.notes')
                ->where('entries.data.1.id', $ownEntry->getKey())
                ->where('entries.data.2.id', $visitSharedEntry->getKey()));

        $this->assertModelExists($unsharedEntry);
        $this->assertFalse($viewer->can('reviewAny', TimeEntry::class));
        $this->assertFalse($viewer->can('update', $sharedEntry));
        $this->assertFalse($viewer->can('delete', $sharedEntry));
    }

    public function test_finance_and_hr_roles_can_view_all_time_entries_but_only_hr_can_review_them(): void
    {
        $finance = User::factory()->create(['status' => 'active']);
        $finance->syncRoles(['basic_user', 'finance']);
        $hr = User::factory()->create(['status' => 'active']);
        $hr->syncRoles(['basic_user', 'hr']);
        $technician = User::factory()->create(['status' => 'active']);
        $customer = Customer::create(['name' => 'Private Timekeeping Client']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Private Timekeeping Site']);
        $job = $this->job($site, 'J-PRIVATE-1');
        $entry = $this->entry($technician, $customer, $job, null, [
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Approved,
        ]);

        $this->assertTrue($finance->can('viewFinancial', TimeEntry::class));
        $this->assertTrue($finance->can('view', $entry));
        $this->assertFalse($finance->can('reviewAny', TimeEntry::class));
        $this->assertFalse($finance->can('update', $entry));

        $this->assertTrue($hr->can('viewFinancial', TimeEntry::class));
        $this->assertTrue($hr->can('reviewAny', TimeEntry::class));
        $this->assertTrue($hr->can('update', $entry));
    }

    private function job(Site $site, string $jobNumber): WorkOrder
    {
        return WorkOrder::create([
            'site_id' => $site->getKey(),
            'job_number' => $jobNumber,
            'title' => 'Timekeeping visibility test job',
            'scope' => 'Review the assigned time entry.',
            'status' => 'created',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function entry(
        User $owner,
        Customer $customer,
        WorkOrder $job,
        ?Visit $visit = null,
        array $overrides = [],
    ): TimeEntry {
        static $minute = 0;
        $minute++;

        return TimeEntry::factory()->create(array_replace([
            'user_id' => $owner->getKey(),
            'created_by' => $owner->getKey(),
            'customer_id' => $customer->getKey(),
            'project_id' => null,
            'job_id' => $job->getKey(),
            'visit_id' => $visit?->getKey(),
            'activity_type' => TimeEntryActivityType::Labor,
            'started_at' => now()->subMinutes($minute * 2),
            'ended_at' => now()->subMinutes($minute),
            'duration_minutes' => 1,
            'billable' => false,
            'approval_status' => TimeEntryApprovalStatus::Draft,
        ], $overrides));
    }
}
