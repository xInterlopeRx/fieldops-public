<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FieldPulse\FieldPulseDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FieldPulseProjectBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_backfill_preview_is_read_only_and_reports_existing_job_link_candidates(): void
    {
        [$customer, $site, $job] = $this->legacyJobFixture();
        $document = $this->projectDocument();

        $this->artisan('fieldpulse:backfill-project-links', ['document' => $document])
            ->expectsOutputToContain('Jobs to link')
            ->expectsOutputToContain('read-only dry run')
            ->assertExitCode(0);

        $this->assertSame(0, Project::query()->count());
        $this->assertNull($job->fresh()->project_id);
        $this->assertDatabaseMissing('audit_events', ['event' => 'fieldpulse_job_project_link_backfilled']);
    }

    public function test_execute_creates_project_links_existing_job_and_is_idempotent(): void
    {
        [, , $job] = $this->legacyJobFixture();
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $document = $this->projectDocument();

        $this->artisan('fieldpulse:backfill-project-links', [
            'document' => $document,
            '--execute' => true,
            '--actor-email' => $admin->email,
        ])->expectsOutputToContain('Projects created')->expectsOutputToContain('Jobs linked')->assertExitCode(0);

        $project = Project::query()->where('fieldpulse_id', '300')->sole();
        $this->assertSame('100', $project->customer->fieldpulse_id);
        $this->assertSame($project->getKey(), $job->fresh()->project_id);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => $job->getMorphClass(),
            'subject_id' => $job->getKey(),
            'event' => 'fieldpulse_job_project_link_backfilled',
        ]);

        $this->artisan('fieldpulse:backfill-project-links', [
            'document' => $document,
            '--execute' => true,
            '--actor-email' => $admin->email,
        ])->expectsOutputToContain('Jobs already linked correctly')->assertExitCode(0);

        $this->assertSame(1, Project::query()->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'fieldpulse_job_project_link_backfilled')->count());
        $this->assertSame(1, AuditEvent::query()->where('event', 'fieldpulse_project_backfilled')->count());
    }

    public function test_unmatched_project_reference_is_reported_without_creating_a_link(): void
    {
        [, , $job] = $this->legacyJobFixture();
        $document = $this->projectDocument(jobProjectNumber: '999');

        $this->artisan('fieldpulse:backfill-project-links', ['document' => $document])
            ->expectsOutputToContain('Jobs with unmatched Project reference')
            ->assertExitCode(0);

        $this->assertSame(0, Project::query()->count());
        $this->assertNull($job->fresh()->project_id);
    }

    public function test_execute_requires_an_active_admin_and_does_not_write_for_a_basic_user(): void
    {
        [, , $job] = $this->legacyJobFixture();
        $basicUser = User::factory()->create(['status' => 'active']);
        $document = $this->projectDocument();

        $this->artisan('fieldpulse:backfill-project-links', [
            'document' => $document,
            '--execute' => true,
            '--actor-email' => $basicUser->email,
        ])->expectsOutputToContain('An active Admin with integrations.manage is required')
            ->assertExitCode(1);

        $this->assertSame(0, Project::query()->count());
        $this->assertNull($job->fresh()->project_id);
        $this->assertDatabaseMissing('audit_events', ['event' => 'fieldpulse_project_backfilled']);
    }

    public function test_customer_conflict_blocks_the_entire_backfill_before_any_write(): void
    {
        [, , $job] = $this->legacyJobFixture();
        Customer::factory()->create(['fieldpulse_id' => '200']);
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $document = $this->projectDocument(projectCustomerNumber: '200');

        $this->artisan('fieldpulse:backfill-project-links', [
            'document' => $document,
            '--execute' => true,
            '--actor-email' => $admin->email,
        ])->expectsOutputToContain('fieldpulse_job_project_customer_mismatch')
            ->expectsOutputToContain('No backfill changes were applied')
            ->assertExitCode(1);

        $this->assertSame(0, Project::query()->count());
        $this->assertNull($job->fresh()->project_id);
        $this->assertDatabaseMissing('audit_events', ['event' => 'fieldpulse_project_backfilled']);
        $this->assertDatabaseMissing('audit_events', ['event' => 'fieldpulse_job_project_link_backfilled']);
    }

    public function test_execute_skips_ownerless_project_and_missing_job_while_applying_other_safe_links(): void
    {
        [, , $job] = $this->legacyJobFixture();
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $document = $this->projectDocument(includeMissingOwnerProject: true);

        $this->artisan('fieldpulse:backfill-project-links', [
            'document' => $document,
            '--execute' => true,
            '--actor-email' => $admin->email,
        ])->expectsOutputToContain('Projects skipped; source Customer missing locally')
            ->expectsOutputToContain('Missing Jobs skipped with orphan Projects')
            ->assertExitCode(0);

        $project = Project::query()->where('fieldpulse_id', '300')->sole();
        $this->assertSame($project->getKey(), $job->fresh()->project_id);
        $this->assertDatabaseMissing('projects', ['fieldpulse_id' => '301']);
        $this->assertDatabaseMissing('work_orders', ['fieldpulse_id' => '999']);
        $this->assertSame(1, AuditEvent::query()->where('event', 'fieldpulse_job_project_link_backfilled')->count());
    }

    /** @return array{Customer, Site, WorkOrder} */
    private function legacyJobFixture(): array
    {
        $customer = Customer::factory()->create(['fieldpulse_id' => '100']);
        $site = Site::factory()->for($customer)->create(['fieldpulse_id' => '501']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'title' => 'Legacy FieldPulse Job',
            'status' => 'created',
            'fieldpulse_id' => '700',
            'metadata' => ['fieldpulse' => ['project_no' => '300']],
        ]);

        return [$customer, $site, $job];
    }

    private function projectDocument(
        string $projectCustomerNumber = '100',
        string $jobProjectNumber = '300',
        bool $includeMissingOwnerProject = false,
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'fieldpulse-project-backfill').'.xlsx';

        $customers = [[
            'fieldpulse_id' => '100',
            'display_name' => 'Customer One',
        ]];
        $projects = [[
            'fieldpulse_id' => '300',
            'customer_fieldpulse_id' => $projectCustomerNumber,
            'project_number' => '300',
            'name' => 'Source Project',
            'notes' => 'Source notes',
            'status' => 'Opportunity',
            'metadata_json' => json_encode(['description' => 'Backfill fixture']),
        ]];
        $jobs = [[
            'fieldpulse_id' => '700',
            'customer_fieldpulse_id' => '100',
            'location_fieldpulse_id' => '501',
            'title' => 'Existing Job',
            'status' => 'created',
            'metadata_json' => json_encode(['project_no' => $jobProjectNumber]),
        ]];

        if ($includeMissingOwnerProject) {
            $customers[] = ['fieldpulse_id' => '200', 'display_name' => 'Customer Two'];
            $projects[] = [
                'fieldpulse_id' => '301',
                'customer_fieldpulse_id' => '200',
                'project_number' => '301',
                'name' => 'Ownerless source Project',
                'status' => 'Opportunity',
            ];
            $jobs[] = [
                'fieldpulse_id' => '999',
                'customer_fieldpulse_id' => '200',
                'location_fieldpulse_id' => '',
                'title' => 'Missing local Job',
                'status' => 'created',
                'metadata_json' => json_encode(['project_no' => '301']),
            ];
        }

        app(FieldPulseDocument::class)->write($path, [
            'Customers' => $customers,
            'Locations' => [[
                'fieldpulse_id' => '501',
                'customer_fieldpulse_id' => '100',
                'title' => 'Primary Site',
            ]],
            'Projects' => $projects,
            'Jobs' => $jobs,
            'Notes' => [],
        ]);

        return $path;
    }
}
