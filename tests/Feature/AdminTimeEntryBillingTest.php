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

class AdminTimeEntryBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_report_groups_entries_and_calculates_billable_and_unbilled_hours(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => 'Billing Technician', 'status' => 'active']);
        $customer = Customer::factory()->create(['name' => 'Harbor Customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'North Pier']);
        $project = Project::factory()->create([
            'customer_id' => $customer->id,
            'project_number' => 'HARBOR-P1',
            'name' => 'Harbor Retrofit',
        ]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'HARBOR-J1',
            'title' => 'Replace light fixtures',
            'status' => config('work_orders.default_status'),
        ]);
        $approvedBillable = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'job_id' => $job->id,
            'duration_minutes' => 90,
            'billable' => true,
        ]);
        $invoicedBillable = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'job_id' => $job->id,
            'duration_minutes' => 60,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
        ]);
        $paidBillable = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'job_id' => $job->id,
            'duration_minutes' => 30,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Paid,
        ]);
        $approvedNonBillable = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'job_id' => $job->id,
            'duration_minutes' => 120,
            'billable' => false,
        ]);
        $projectOnly = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'duration_minutes' => 30,
            'billable' => true,
        ]);
        $draft = $this->entry($technician, $customer, [
            'project_id' => $project->id,
            'job_id' => $job->id,
            'duration_minutes' => 240,
            'billable' => true,
            'approval_status' => TimeEntryApprovalStatus::Draft,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.billing', [
                'date_from' => '2026-06-10',
                'date_to' => '2026-06-10',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Billing')
                ->where('report.totalBillableHours', 3.5)
                ->where('report.totalUnbilledHours', 2)
                ->where('report.customers.0.name', 'Harbor Customer')
                ->where('report.customers.0.totalBillableHours', 3.5)
                ->where('report.customers.0.totalUnbilledHours', 2)
                ->where('report.customers.0.projects.0.name', 'HARBOR-P1 · Harbor Retrofit')
                ->where('report.customers.0.projects.0.jobs.0.name', 'HARBOR-J1 · Replace light fixtures')
                ->where('report.customers.0.projects.0.jobs.0.entries.0.id', $approvedBillable->id)
                ->where('report.customers.0.projects.0.jobs.0.entries.0.billableStatus', 'Billable · Unbilled')
                ->where('report.customers.0.projects.0.jobs.0.entries.0.canMarkInvoiced', true)
                ->where('report.customers.0.projects.0.jobs.0.entries.1.id', $invoicedBillable->id)
                ->where('report.customers.0.projects.0.jobs.0.entries.1.billableStatus', 'Billable · Invoiced')
                ->where('report.customers.0.projects.0.jobs.0.entries.3.id', $approvedNonBillable->id)
                ->where('report.customers.0.projects.0.jobs.0.entries.3.canMarkInvoiced', false)
                ->where('report.customers.0.projects.0.jobs.1.name', 'Project-level entries (no Job)')
                ->where('report.customers.0.projects.0.jobs.1.entries.0.id', $projectOnly->id)
                ->where('filters.date_from', '2026-06-10'));

        $this->assertModelExists($paidBillable);
        $this->assertModelExists($draft);
    }

    public function test_admin_can_mark_one_billable_approved_entry_invoiced_without_quickbooks_writes(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $eligible = $this->entry($technician, $customer, ['billable' => true]);
        $nonBillable = $this->entry($technician, $customer, ['billable' => false]);

        $this->actingAs($admin)
            ->from(route('admin.time-tracking.billing'))
            ->post(route('admin.time-tracking.billing.entries.mark-invoiced', $eligible))
            ->assertRedirect(route('admin.time-tracking.billing'))
            ->assertSessionHas('status', 'Time entry marked Invoiced locally. No QuickBooks record was changed.');

        $this->assertSame(TimeEntryApprovalStatus::Invoiced, $eligible->fresh()->approval_status);
        $this->assertSame($admin->id, $eligible->fresh()->updated_by);
        $this->assertDatabaseHas('audit_events', [
            'subject_type' => TimeEntry::class,
            'subject_id' => $eligible->id,
            'actor_user_id' => $admin->id,
            'event' => 'marked_invoiced',
        ]);

        $this->post(route('admin.time-tracking.billing.entries.mark-invoiced', $nonBillable))
            ->assertForbidden();

        $this->assertSame(TimeEntryApprovalStatus::Approved, $nonBillable->fresh()->approval_status);
    }

    public function test_bulk_mark_invoiced_is_atomic_and_accepts_only_billable_approved_entries(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $first = $this->entry($technician, $customer, ['billable' => true]);
        $second = $this->entry($technician, $customer, ['billable' => true]);
        $nonBillable = $this->entry($technician, $customer, ['billable' => false]);

        $this->actingAs($admin)
            ->postJson(route('admin.time-tracking.billing.bulk-mark-invoiced'), [
                'ids' => [$first->id, $nonBillable->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ids');

        $this->assertSame(TimeEntryApprovalStatus::Approved, $first->fresh()->approval_status);
        $this->assertSame(TimeEntryApprovalStatus::Approved, $nonBillable->fresh()->approval_status);

        $this->from(route('admin.time-tracking.billing'))
            ->postJson(route('admin.time-tracking.billing.bulk-mark-invoiced'), [
                'ids' => [$first->id, $second->id],
            ])
            ->assertRedirect(route('admin.time-tracking.billing'));

        $this->assertSame(TimeEntryApprovalStatus::Invoiced, $first->fresh()->approval_status);
        $this->assertSame(TimeEntryApprovalStatus::Invoiced, $second->fresh()->approval_status);
        $this->assertSame($admin->id, $first->fresh()->updated_by);
        $this->assertSame($admin->id, $second->fresh()->updated_by);
    }

    public function test_billing_page_and_invoice_actions_are_admin_only_and_date_range_is_bounded(): void
    {
        $technician = User::factory()->create(['status' => 'active']);
        $customer = Customer::factory()->create();
        $entry = $this->entry($technician, $customer, ['billable' => true]);

        $this->get(route('admin.time-tracking.billing'))
            ->assertRedirect(route('login'));

        $basic = User::factory()->create(['status' => 'active']);
        $this->actingAs($basic)
            ->get(route('admin.time-tracking.billing'))
            ->assertForbidden();

        $this->post(route('admin.time-tracking.billing.entries.mark-invoiced', $entry))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->from(route('admin.time-tracking.billing'))
            ->get(route('admin.time-tracking.billing', [
                'date_from' => '2025-01-01',
                'date_to' => '2026-01-02',
            ]))
            ->assertSessionHasErrors('date_to');
    }

    /** @param array<string, mixed> $overrides */
    private function entry(User $technician, Customer $customer, array $overrides = []): TimeEntry
    {
        return TimeEntry::factory()->create(array_replace([
            'user_id' => $technician->id,
            'created_by' => $technician->id,
            'customer_id' => $customer->id,
            'started_at' => '2026-06-10 09:00:00',
            'ended_at' => '2026-06-10 10:00:00',
            'duration_minutes' => 60,
            'activity_type' => TimeEntryActivityType::Labor,
            'approval_status' => TimeEntryApprovalStatus::Approved,
            'billable' => false,
        ], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }
}
