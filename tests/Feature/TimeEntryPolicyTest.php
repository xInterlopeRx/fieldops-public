<?php

namespace Tests\Feature;

use App\Enums\TimeEntryApprovalStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Policies\TimeEntryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class TimeEntryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_staff_can_access_timer_abilities_only_for_their_own_entries(): void
    {
        $performer = User::factory()->create(['status' => 'active']);
        $otherUser = User::factory()->create(['status' => 'active']);
        $timeEntry = TimeEntry::factory()->create([
            'user_id' => $performer->id,
            'created_by' => $performer->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->assertInstanceOf(TimeEntryPolicy::class, Gate::getPolicyFor(TimeEntry::class));
        $this->assertTrue($performer->can('viewAny', TimeEntry::class));
        $this->assertTrue($performer->can('view', $timeEntry));
        $this->assertTrue($performer->can('create', TimeEntry::class));
        $this->assertTrue($performer->can('stop', $timeEntry));
        $this->assertFalse($otherUser->can('view', $timeEntry));
        $this->assertFalse($otherUser->can('stop', $timeEntry));
        $this->assertFalse($performer->can('update', $timeEntry));
        $this->assertFalse($performer->can('delete', $timeEntry));
        $this->assertFalse($performer->can('approve', $timeEntry));
        $this->assertFalse($performer->can('reviewAny', TimeEntry::class));
    }

    public function test_admin_review_abilities_are_limited_by_entry_state(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $technician = User::factory()->create(['status' => 'active']);
        $draft = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
        ]);
        $approved = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'approval_status' => TimeEntryApprovalStatus::Approved,
        ]);
        $invoiced = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
        ]);
        $running = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->assertTrue($admin->can('reviewAny', TimeEntry::class));
        $this->assertTrue($admin->can('update', $draft));
        $this->assertTrue($admin->can('approve', $draft));
        $this->assertTrue($admin->can('update', $approved));
        $this->assertTrue($admin->can('unapprove', $approved));
        $this->assertFalse($admin->can('approve', $approved));
        $this->assertFalse($admin->can('update', $invoiced));
        $this->assertFalse($admin->can('approve', $invoiced));
        $this->assertTrue($admin->can('delete', $invoiced));
        $this->assertFalse($admin->can('update', $running));
        $this->assertFalse($admin->can('approve', $running));
        $this->assertTrue($admin->can('delete', $running));
        $this->assertFalse($technician->can('reviewAny', TimeEntry::class));
        $this->assertFalse($technician->can('delete', $draft));
    }

    public function test_only_admin_can_mark_a_completed_billable_approved_entry_invoiced(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $technician = User::factory()->create(['status' => 'active']);
        $eligible = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Approved,
        ]);
        $nonBillable = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'billable' => false,
            'approval_status' => TimeEntryApprovalStatus::Approved,
        ]);
        $alreadyInvoiced = TimeEntry::factory()->create([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
        ]);

        $this->assertTrue($admin->can('markInvoiced', $eligible));
        $this->assertFalse($admin->can('markInvoiced', $nonBillable));
        $this->assertFalse($admin->can('markInvoiced', $alreadyInvoiced));
        $this->assertFalse($technician->can('markInvoiced', $eligible));
    }

    public function test_stopped_time_entry_is_not_authorized_for_another_timer_stop(): void
    {
        $performer = User::factory()->create(['status' => 'active']);
        $timeEntry = TimeEntry::factory()->create([
            'user_id' => $performer->id,
            'created_by' => $performer->id,
        ]);

        $this->assertFalse($performer->can('stop', $timeEntry));
    }
}
