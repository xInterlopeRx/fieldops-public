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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminTimeEntryReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_time_entries_by_all_review_filters(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => 'Mira Technician', 'status' => 'active']);
        $otherTechnician = User::factory()->create(['name' => 'Other Technician', 'status' => 'active']);
        $customer = Customer::factory()->create(['name' => 'Harbor Works']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Harbor Pier']);
        $project = Project::factory()->create([
            'customer_id' => $customer->id,
            'project_number' => 'HARBOR-P1',
            'name' => 'Harbor Retrofit',
        ]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'HARBOR-J1',
            'title' => 'Replace dock lights',
            'status' => config('work_orders.default_status'),
        ]);
        $matchingEntry = $this->completedEntry($technician, [
            'customer_id' => $customer->id,
            'project_id' => $project->id,
            'job_id' => $job->id,
            'activity_type' => TimeEntryActivityType::Labor,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-10 09:00:00',
            'ended_at' => '2026-06-10 10:00:00',
        ]);
        $otherCustomer = Customer::factory()->create(['name' => 'Other Company']);
        $otherProject = Project::factory()->create(['customer_id' => $otherCustomer->id, 'name' => 'Other Project']);
        $otherEntry = $this->completedEntry($otherTechnician, [
            'customer_id' => $otherCustomer->id,
            'project_id' => $otherProject->id,
            'activity_type' => TimeEntryActivityType::Documentation,
            'billable' => false,
            'approval_status' => TimeEntryApprovalStatus::Approved,
            'started_at' => '2026-06-11 09:00:00',
            'ended_at' => '2026-06-11 10:00:00',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.review', [
                'date_from' => '2026-06-10',
                'date_to' => '2026-06-10',
                'technician' => 'mira',
                'customer' => 'harbor',
                'project' => 'retrofit',
                'activity_type' => 'labor',
                'billable' => '1',
                'status' => 'draft',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Review')
                ->where('entries.total', 1)
                ->where('entries.data.0.id', $matchingEntry->id)
                ->where('entries.data.0.technician', 'Mira Technician')
                ->where('entries.data.0.customer', 'Harbor Works')
                ->where('entries.data.0.project', 'HARBOR-P1 · Harbor Retrofit')
                ->where('entries.data.0.job', 'HARBOR-J1 · Replace dock lights')
                ->where('entries.data.0.activityLabel', 'Labor')
                ->where('entries.data.0.billable', true)
                ->where('entries.data.0.status', 'draft')
                ->where('filters.date_from', '2026-06-10')
                ->where('filters.status', 'draft'));

        $this->assertModelExists($otherEntry);
    }

    public function test_admin_can_approve_unapprove_edit_and_audit_a_permanent_delete(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $entry = $this->completedEntry($technician, ['notes' => 'Initial field note.']);

        $this->actingAs($admin)
            ->from(route('admin.time-tracking.review'))
            ->get(route('admin.time-tracking.entries.edit', $entry))
            ->assertOk()
            ->assertJsonPath('data.id', $entry->id)
            ->assertJsonPath('data.notes', 'Initial field note.');

        $this->post(route('admin.time-tracking.entries.approve', $entry))
            ->assertRedirect(route('admin.time-tracking.review'))
            ->assertSessionHas('status', 'Time entry approved.');

        $this->assertSame(TimeEntryApprovalStatus::Approved, $entry->fresh()->approval_status);
        $this->assertSame($admin->id, $entry->fresh()->updated_by);

        $this->post(route('admin.time-tracking.entries.unapprove', $entry))
            ->assertRedirect(route('admin.time-tracking.review'))
            ->assertSessionHas('status', 'Time entry returned to Draft.');

        $this->post(route('admin.time-tracking.entries.approve', $entry));
        $this->patch(route('admin.time-tracking.entries.update', $entry), [
            'started_at' => '2026-06-12 09:00:00',
            'ended_at' => '2026-06-12 11:30:00',
            'duration_minutes' => 150,
            'activity_type' => TimeEntryActivityType::Research->value,
            'billable' => false,
            'notes' => 'Corrected after administrator review.',
        ])
            ->assertRedirect(route('admin.time-tracking.review'))
            ->assertSessionHas('status', 'Time entry updated. Changes to an approved entry returned it to Draft.');

        $entry->refresh();
        $this->assertSame(TimeEntryApprovalStatus::Draft, $entry->approval_status);
        $this->assertSame(150, $entry->duration_minutes);
        $this->assertSame(TimeEntryActivityType::Research, $entry->activity_type);
        $this->assertSame('Corrected after administrator review.', $entry->notes);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => TimeEntry::class,
            'subject_id' => $entry->id,
            'actor_user_id' => $admin->id,
            'event' => 'edited',
        ]);

        $this->delete(route('admin.time-tracking.entries.destroy', $entry))
            ->assertRedirect(route('admin.time-tracking.review'))
            ->assertSessionHas('status', 'Time entry permanently deleted.');

        $this->assertDatabaseMissing('time_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => TimeEntry::class,
            'subject_id' => $entry->id,
            'actor_user_id' => $admin->id,
            'event' => 'deleted',
        ]);
    }

    public function test_edit_rejects_an_end_time_before_the_start_without_changing_the_entry(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $entry = $this->completedEntry($technician, [
            'started_at' => '2026-06-10 09:00:00',
            'ended_at' => '2026-06-10 10:00:00',
            'duration_minutes' => 60,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.time-tracking.review'))
            ->patch(route('admin.time-tracking.entries.update', $entry), [
                'started_at' => '2026-06-10 12:00:00',
                'ended_at' => '2026-06-10 11:00:00',
                'duration_minutes' => 60,
                'activity_type' => TimeEntryActivityType::Labor->value,
                'billable' => false,
                'notes' => 'Invalid interval.',
            ])
            ->assertSessionHasErrors('ended_at');

        $this->assertSame('2026-06-10 09:00:00', $entry->fresh()->started_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-10 10:00:00', $entry->fresh()->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(60, $entry->fresh()->duration_minutes);
    }

    public function test_bulk_approval_is_atomic_and_accepts_only_completed_draft_entries(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $first = $this->completedEntry($technician);
        $second = $this->completedEntry($technician);
        $running = $this->completedEntry($technician, [
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.time-tracking.review'))
            ->postJson(route('admin.time-tracking.bulk-approve'), ['ids' => [$first->id, $running->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $this->assertSame(TimeEntryApprovalStatus::Draft, $first->fresh()->approval_status);
        $this->assertSame(TimeEntryApprovalStatus::Draft, $running->fresh()->approval_status);

        $this->from(route('admin.time-tracking.review'))
            ->postJson(route('admin.time-tracking.bulk-approve'), ['ids' => [$first->id, $second->id]])
            ->assertRedirect(route('admin.time-tracking.review'));

        $this->assertSame(TimeEntryApprovalStatus::Approved, $first->fresh()->approval_status);
        $this->assertSame(TimeEntryApprovalStatus::Approved, $second->fresh()->approval_status);
        $this->assertSame($admin->id, $first->fresh()->updated_by);
        $this->assertSame($admin->id, $second->fresh()->updated_by);
    }

    public function test_review_and_mutation_routes_reject_guests_and_basic_users(): void
    {
        $technician = User::factory()->create(['status' => 'active']);
        $entry = $this->completedEntry($technician);

        $this->get(route('admin.time-tracking.review'))
            ->assertRedirect(route('login'));

        $basicUser = User::factory()->create(['status' => 'active']);

        $this->actingAs($basicUser)
            ->get(route('admin.time-tracking.review'))
            ->assertForbidden();

        $this->delete(route('admin.time-tracking.entries.destroy', $entry))
            ->assertForbidden();

        $this->post(route('admin.time-tracking.bulk-approve'), ['ids' => [$entry->id]])
            ->assertForbidden();

        $this->assertModelExists($entry);
    }

    public function test_filter_date_range_must_be_ordered(): void
    {
        $this->actingAs($this->admin())
            ->from(route('admin.time-tracking.review'))
            ->get(route('admin.time-tracking.review', [
                'date_from' => '2026-06-11',
                'date_to' => '2026-06-10',
            ]))
            ->assertSessionHasErrors('date_to');
    }

    /** @param array<string, mixed> $overrides */
    private function completedEntry(User $technician, array $overrides = []): TimeEntry
    {
        $customer = Customer::factory()->create();

        return TimeEntry::factory()->create(array_replace([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'customer_id' => $customer->id,
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'duration_minutes' => 60,
            'activity_type' => TimeEntryActivityType::Labor,
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'billable' => false,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }
}
