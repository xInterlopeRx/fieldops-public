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

class AdminPayrollReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_overtime_uses_full_weeks_and_splits_entry_hours_across_days(): void
    {
        $this->travelTo('2026-06-01 00:00:00');
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => 'Mira Payroll', 'status' => 'active']);
        $otherTechnician = User::factory()->create(['name' => 'Noah Payroll', 'status' => 'active']);
        $longShift = $this->approvedEntry($technician, [
            'started_at' => '2026-06-01 00:00:00',
            'ended_at' => '2026-06-02 15:00:00',
            'duration_minutes' => 2340,
        ]);
        $thresholdCrossingShift = $this->approvedEntry($technician, [
            'started_at' => '2026-06-02 15:00:00',
            'ended_at' => '2026-06-02 18:00:00',
            'duration_minutes' => 180,
        ]);
        $followingWeekShift = $this->approvedEntry($otherTechnician, [
            'started_at' => '2026-06-08 08:00:00',
            'ended_at' => '2026-06-08 18:00:00',
            'duration_minutes' => 600,
        ]);
        $nextWeekForMira = $this->approvedEntry($technician, [
            'started_at' => '2026-06-08 08:00:00',
            'ended_at' => '2026-06-08 18:00:00',
            'duration_minutes' => 600,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.payroll', [
                'date_from' => '2026-06-02',
                'date_to' => '2026-06-02',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/TimeTracking/Payroll')
                ->where('report.overtimeThresholdHours', 40)
                ->where('report.workweekStartsOn', 'Monday')
                ->where('report.workweekEndsOn', 'Sunday')
                ->where('report.technicians.0.name', 'Mira Payroll')
                ->where('report.technicians.0.regularHours', 16)
                ->where('report.technicians.0.overtimeHours', 2)
                ->where('report.technicians.0.totalHours', 18)
                ->where('report.technicians.0.days.0.date', '2026-06-02')
                ->where('report.technicians.0.days.0.regularHours', 16)
                ->where('report.technicians.0.days.0.overtimeHours', 2)
                ->where('report.totals.totalHours', 18)
                ->where('report.technicians.0.days.0.entries.0.id', $longShift->id)
                ->where('report.technicians.0.days.0.entries.1.id', $thresholdCrossingShift->id));

        $this->get(route('admin.time-tracking.payroll', [
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-14',
        ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.technicians.0.name', 'Mira Payroll')
                ->where('report.technicians.0.regularHours', 50)
                ->where('report.technicians.0.overtimeHours', 2)
                ->where('report.technicians.0.totalHours', 52)
                ->where('report.technicians.1.name', 'Noah Payroll')
                ->where('report.technicians.1.totalHours', 10)
                ->where('report.totals.totalHours', 62));

        $this->assertModelExists($followingWeekShift);
        $this->assertModelExists($nextWeekForMira);
    }

    public function test_payroll_includes_approved_entries_only_and_reports_excluded_completed_entries(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => 'Approved Worker', 'status' => 'active']);
        $approved = $this->approvedEntry($technician, [
            'started_at' => '2026-06-10 09:00:00',
            'ended_at' => '2026-06-10 11:00:00',
            'duration_minutes' => 120,
        ]);
        $draft = $this->approvedEntry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-10 11:00:00',
            'ended_at' => '2026-06-10 12:00:00',
        ]);
        $invoiced = $this->approvedEntry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Invoiced,
            'started_at' => '2026-06-10 12:00:00',
            'ended_at' => '2026-06-10 13:00:00',
        ]);
        $paid = $this->approvedEntry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Paid,
            'started_at' => '2026-06-10 13:00:00',
            'ended_at' => '2026-06-10 14:00:00',
        ]);
        $running = $this->approvedEntry($technician, [
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'started_at' => '2026-06-10 14:00:00',
            'ended_at' => null,
            'duration_minutes' => null,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.time-tracking.payroll', [
                'date_from' => '2026-06-10',
                'date_to' => '2026-06-10',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('report.technicians.0.id', $technician->id)
                ->where('report.technicians.0.totalHours', 2)
                ->where('report.excludedEntriesCount', 3)
                ->where('report.technicians.0.days.0.entries.0.id', $approved->id));

        $this->assertModelExists($draft);
        $this->assertModelExists($invoiced);
        $this->assertModelExists($paid);
        $this->assertModelExists($running);
    }

    public function test_csv_and_excel_exports_include_payroll_totals_and_excel_detail_sheets(): void
    {
        $admin = $this->admin();
        $technician = User::factory()->create(['name' => '=1+1', 'status' => 'active']);
        $this->approvedEntry($technician, [
            'started_at' => '2026-06-10 09:00:00',
            'ended_at' => '2026-06-10 11:00:00',
            'duration_minutes' => 120,
        ]);
        $parameters = ['date_from' => '2026-06-10', 'date_to' => '2026-06-10'];

        $csvResponse = $this->actingAs($admin)
            ->get(route('admin.time-tracking.payroll.export.csv', $parameters))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="payroll-summary-2026-06-10-2026-06-10.csv"');

        $csv = $csvResponse->getContent();
        $this->assertStringContainsString("'=1+1", $csv);

        $excelResponse = $this->get(route('admin.time-tracking.payroll.export.excel', $parameters))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="payroll-report-2026-06-10-2026-06-10.xls"');
        $excel = $excelResponse->getContent();
        $this->assertStringContainsString('<?mso-application progid="Excel.Sheet"?>', $excel);
        $this->assertStringContainsString('<Worksheet ss:Name="Summary">', $excel);
        $this->assertStringContainsString('<Worksheet ss:Name="Daily Totals">', $excel);
        $this->assertStringContainsString('<Worksheet ss:Name="Time Entries">', $excel);
        $this->assertStringContainsString('<Data ss:Type="String">=1+1</Data>', $excel);
        $this->assertStringContainsString('<Data ss:Type="Number">2</Data>', $excel);

        $workbook = new \DOMDocument;
        $this->assertTrue($workbook->loadXML($excel));
    }

    public function test_payroll_page_and_exports_are_admin_only_and_date_ranges_are_bounded(): void
    {
        $this->get(route('admin.time-tracking.payroll'))
            ->assertRedirect(route('login'));

        $basicUser = User::factory()->create(['status' => 'active']);
        $this->actingAs($basicUser)
            ->get(route('admin.time-tracking.payroll'))
            ->assertForbidden();

        $this->get(route('admin.time-tracking.payroll.export.csv', [
            'date_from' => '2026-01-01',
            'date_to' => '2027-01-01',
        ]))->assertForbidden();

        $this->actingAs($this->admin())
            ->from(route('admin.time-tracking.payroll'))
            ->get(route('admin.time-tracking.payroll', [
                'date_from' => '2025-01-01',
                'date_to' => '2026-01-02',
            ]))
            ->assertSessionHasErrors('date_to');
    }

    /** @param array<string, mixed> $overrides */
    private function approvedEntry(User $technician, array $overrides = []): TimeEntry
    {
        $customer = Customer::factory()->create();

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
