<?php

namespace Tests\Feature;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Models\Customer;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTimeTrackingDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_metrics_separate_workflow_hours_and_count_both_exception_types(): void
    {
        $this->travelTo('2026-06-20 12:00:00');
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => 'Dashboard Technician', 'status' => 'active']);
        $activeTimerTechnician = User::factory()->create(['name' => 'Active Timer Technician', 'status' => 'active']);
        $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-12 10:00:00',
            'ended_at' => '2026-06-12 12:00:00',
            'duration_minutes' => 120,
        ]);
        $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-19 10:00:00',
            'ended_at' => '2026-06-19 10:30:00',
            'duration_minutes' => 30,
        ]);
        $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Approved,
            'billable' => true,
            'started_at' => '2026-06-18 09:00:00',
            'ended_at' => '2026-06-18 10:00:00',
            'duration_minutes' => 60,
        ]);
        $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
            'billable' => true,
            'started_at' => '2026-06-17 09:00:00',
            'ended_at' => '2026-06-17 09:30:00',
            'duration_minutes' => 30,
        ]);
        $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Paid,
            'billable' => false,
            'started_at' => '2026-06-16 09:00:00',
            'ended_at' => '2026-06-16 10:00:00',
            'duration_minutes' => 60,
        ]);
        $staleTimer = $this->entry($technician, [
            'started_at' => '2026-06-19 23:00:00',
            'ended_at' => null,
            'duration_minutes' => null,
        ]);
        $this->entry($activeTimerTechnician, [
            'started_at' => '2026-06-20 10:00:00',
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Dashboard')
                ->where('metrics.unapprovedHours', 2.5)
                ->where('metrics.approvedHours', 2.5)
                ->where('metrics.billableHours', 1.5)
                ->where('metrics.unbilledHours', 1)
                ->where('metrics.activeTimers', 2)
                ->where('metrics.staleTimers', 1)
                ->where('metrics.agedUnapprovedEntries', 1)
                ->where('metrics.exceptions', 2));

        $this->get(route('admin.time-tracking.exceptions'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Exceptions')
                ->where('entries.total', 2)
                ->where('entries.data.0.id', $staleTimer->id)
                ->where('entries.data.0.type', 'stale_timer')
                ->where('entries.data.1.type', 'aged_unapproved')
                ->where('metrics.exceptions', 2));
    }

    public function test_exception_thresholds_are_configurable(): void
    {
        $this->travelTo('2026-06-20 12:00:00');
        config([
            'time_tracking.dashboard.stale_timer_hours' => 4,
            'time_tracking.dashboard.unapproved_entry_age_days' => 2,
        ]);
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $timer = $this->entry($technician, [
            'started_at' => '2026-06-20 06:00:00',
            'ended_at' => null,
            'duration_minutes' => null,
        ]);
        $oldDraft = $this->entry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-17 08:00:00',
            'ended_at' => '2026-06-17 09:00:00',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('metrics.staleTimerHours', 4)
                ->where('metrics.unapprovedEntryAgeDays', 2)
                ->where('metrics.exceptions', 2));

        $this->assertModelExists($timer);
        $this->assertModelExists($oldDraft);
    }

    public function test_dashboard_and_exceptions_are_admin_only(): void
    {
        $this->get(route('admin.time-tracking.dashboard'))
            ->assertRedirect(route('login'));

        $basic = User::factory()->create(['status' => 'active']);

        $this->actingAs($basic)
            ->get(route('admin.time-tracking.dashboard'))
            ->assertForbidden();

        $this->get(route('admin.time-tracking.exceptions'))
            ->assertForbidden();
    }

    /** @param array<string, mixed> $overrides */
    private function entry(User $technician, array $overrides = []): TimeEntry
    {
        $customer = Customer::factory()->create();

        return TimeEntry::factory()->create(array_replace([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'customer_id' => $customer->id,
            'activity_type' => TimeEntryActivityType::Labor,
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'duration_minutes' => 60,
            'billable' => false,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }
}
