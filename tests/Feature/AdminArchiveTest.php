<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\ArchiveService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\TestCase;

class AdminArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_a_customer_cascade_without_removing_attachment_files(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Archive Customer']);
        $site = $customer->sites()->create(['name' => 'Archive Site']);
        $contact = $customer->contacts()->create([
            'site_id' => $site->id,
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Alex',
        ]);
        $address = $customer->addresses()->create([
            'source_site_id' => $site->id,
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_SITE,
            'line1' => '100 Archive Way',
            'is_default' => true,
        ]);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'ARCHIVE-P1',
            'name' => 'Archive Project',
        ]);
        $project->sites()->attach($site);
        $workOrder = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'ARCHIVE-J1',
            'title' => 'Archive Job',
            'status' => 'pending',
        ]);
        $visit = $workOrder->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now(),
        ]);
        $revisit = $workOrder->visits()->create([
            'visit_type' => 'revisit',
            'initial_visit_id' => $visit->id,
            'scheduled_at' => now()->addDay(),
        ]);
        $workOrderAttachment = WorkOrderAttachment::query()->create([
            'work_order_id' => $workOrder->id,
            'uploaded_by' => $admin->id,
            'kind' => 'documents',
            'original_name' => 'job.pdf',
            'disk' => 'local',
            'path' => 'work-orders/job.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        $projectAttachment = ProjectAttachment::query()->create([
            'project_id' => $project->id,
            'uploaded_by' => $admin->id,
            'kind' => 'documents',
            'original_name' => 'project.pdf',
            'disk' => 'local',
            'path' => 'projects/project.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
        ]);
        Storage::disk('local')->put($workOrderAttachment->path, 'job!');
        Storage::disk('local')->put($projectAttachment->path, 'proj');

        $response = $this->actingAs($admin)
            ->postJson(route('admin.archives.archive', ['type' => 'customers', 'record' => $customer->id]), [
                'reason' => 'Duplicate test record',
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'customers')
            ->assertJsonPath('data.record.id', $customer->id)
            ->assertJsonPath('data.reason', 'Duplicate test record');

        $batchId = $response->json('data.id');

        foreach ([
            ['customers', $customer->id],
            ['sites', $site->id],
            ['contacts', $contact->id],
            ['customer_addresses', $address->id],
            ['projects', $project->id],
            ['work_orders', $workOrder->id],
            ['visits', $visit->id],
            ['visits', $revisit->id],
            ['work_order_attachments', $workOrderAttachment->id],
            ['project_attachments', $projectAttachment->id],
        ] as [$table, $id]) {
            $this->assertDatabaseHas($table, [
                'id' => $id,
                'is_archived' => true,
                'archive_batch_id' => $batchId,
            ]);
        }

        $this->assertNull(Customer::query()->find($customer->id));
        $this->assertNull(Site::query()->find($site->id));
        $this->assertTrue(Storage::disk('local')->exists($workOrderAttachment->path));
        $this->assertTrue(Storage::disk('local')->exists($projectAttachment->path));

        $this->actingAs($admin)
            ->getJson(route('admin.archives.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $batchId)
            ->assertJsonPath('data.0.record_count', 10)
            ->assertJsonPath('data.0.record_counts.visits', 2);
    }

    public function test_restoring_a_parent_batch_does_not_restore_a_descendant_archived_earlier(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Restore Customer']);
        $site = $customer->sites()->create(['name' => 'Restore Site']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'RESTORE-P1',
            'name' => 'Restore Project',
        ]);
        $project->sites()->attach($site);
        $workOrder = WorkOrder::query()->create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'RESTORE-J1',
            'title' => 'Restore Job',
            'status' => 'pending',
        ]);
        $visit = $workOrder->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now(),
        ]);

        $jobBatch = app(ArchiveService::class)->archive('work-orders', $workOrder->id, $admin, 'Earlier job archive');
        $customerResponse = $this->actingAs($admin)
            ->postJson(route('admin.archives.archive', ['type' => 'customers', 'record' => $customer->id]))
            ->assertCreated();
        $customerBatchId = $customerResponse->json('data.id');

        $this->postJson(route('admin.archives.restore', $customerBatchId))
            ->assertOk()
            ->assertJsonPath('restored', true);

        $this->assertModelExists(Customer::query()->findOrFail($customer->id));
        $this->assertModelExists(Site::query()->findOrFail($site->id));
        $this->assertModelExists(Project::query()->findOrFail($project->id));
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'is_archived' => true,
            'archive_batch_id' => $jobBatch->getKey(),
        ]);
        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'is_archived' => true,
            'archive_batch_id' => $jobBatch->getKey(),
        ]);

        $this->postJson(route('admin.archives.restore', $jobBatch->getKey()))
            ->assertOk();

        $this->assertModelExists(WorkOrder::query()->findOrFail($workOrder->id));
        $this->assertModelExists(Visit::query()->findOrFail($visit->id));
    }

    public function test_archiving_a_site_does_not_archive_a_project_shared_with_another_active_site(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Shared Project Customer']);
        $siteToArchive = $customer->sites()->create(['name' => 'First Site']);
        $otherSite = $customer->sites()->create(['name' => 'Second Site']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'SHARED-P1',
            'name' => 'Shared Project',
        ]);
        $project->sites()->attach([$siteToArchive->id, $otherSite->id]);
        $workOrder = WorkOrder::query()->create([
            'site_id' => $siteToArchive->id,
            'project_id' => $project->id,
            'job_number' => 'SHARED-J1',
            'title' => 'First-site job',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.archives.archive', ['type' => 'sites', 'record' => $siteToArchive->id]))
            ->assertCreated();

        $this->assertModelExists(Project::query()->findOrFail($project->id));
        $this->assertModelExists(Site::query()->findOrFail($otherSite->id));
        $this->assertNull(WorkOrder::query()->find($workOrder->id));
    }

    public function test_site_archive_keeps_a_project_active_when_another_active_site_has_one_of_its_jobs(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Shared Job Customer']);
        $siteToArchive = $customer->sites()->create(['name' => 'Project Site']);
        $otherSite = $customer->sites()->create(['name' => 'Other Job Site']);
        $project = Project::query()->create([
            'customer_id' => $customer->id,
            'project_number' => 'SHARED-JOB-P1',
            'name' => 'Shared Job Project',
        ]);
        $project->sites()->attach($siteToArchive);
        $siteJob = WorkOrder::query()->create([
            'site_id' => $siteToArchive->id,
            'project_id' => $project->id,
            'job_number' => 'SHARED-JOB-J1',
            'title' => 'Job at archived site',
            'status' => 'pending',
        ]);
        $otherJob = WorkOrder::query()->create([
            'site_id' => $otherSite->id,
            'project_id' => $project->id,
            'job_number' => 'SHARED-JOB-J2',
            'title' => 'Job at active site',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.archives.archive', ['type' => 'sites', 'record' => $siteToArchive->id]))
            ->assertCreated();

        $this->assertModelExists(Project::query()->findOrFail($project->id));
        $this->assertNull(WorkOrder::query()->find($siteJob->id));
        $this->assertModelExists(WorkOrder::query()->findOrFail($otherJob->id));
    }

    public function test_restore_conflict_keeps_a_previously_default_archived_address_archived(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Address Restore Customer']);
        $address = $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'Former default',
            'is_default' => true,
        ]);
        $batch = app(ArchiveService::class)->archive('customer-addresses', $address->id, $admin);
        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'is_archived' => true,
            'archive_batch_id' => $batch->getKey(),
        ]);
        $addressIndex = DB::selectOne(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?",
            ['customer_addresses_one_default_per_type_unique'],
        );
        $this->assertStringContainsString('is_archived = false', $addressIndex->sql);

        $customer->addresses()->create([
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_MANUAL,
            'line1' => 'Current default',
            'is_default' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.archives.restore', $batch->getKey()))
            ->assertStatus(409);

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'is_archived' => true,
            'archive_batch_id' => $batch->getKey(),
        ]);
    }

    public function test_restore_conflict_keeps_an_archived_contact_in_its_batch(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Contact Restore Customer']);
        $contact = $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Former contact',
        ]);
        $batch = app(ArchiveService::class)->archive('contacts', $contact->id, $admin);

        $customer->contacts()->create([
            'contact_type' => 'primary',
            'source' => 'manual',
            'first_name' => 'Current contact',
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.archives.restore', $batch->getKey()))
            ->assertStatus(409);

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'is_archived' => true,
            'archive_batch_id' => $batch->getKey(),
        ]);
    }

    public function test_archive_routes_are_admin_only_and_model_deletes_are_blocked(): void
    {
        $customer = Customer::create(['name' => 'Protected Customer']);
        $batch = app(ArchiveService::class)->archive('customers', $customer->id, null);
        $basicUser = User::factory()->create(['status' => 'active']);

        $this->post(route('admin.archives.archive', ['type' => 'customers', 'record' => $customer->id]))
            ->assertRedirect(route('login'));

        $this->actingAs($basicUser)
            ->getJson(route('admin.archives.index'))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->postJson(route('admin.archives.archive', ['type' => 'customers', 'record' => $customer->id]))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->postJson(route('admin.archives.restore', $batch->getKey()))
            ->assertForbidden();

        $this->expectException(LogicException::class);
        $customer->delete();
    }

    public function test_admin_can_filter_archive_batches_and_restore_from_the_archive_page(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Recoverable UI Customer']);
        $batch = app(ArchiveService::class)->archive('customers', $customer->id, $admin, 'UI archive test');

        $this->actingAs($admin)
            ->get(route('admin.archive', ['type' => 'customers']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Archive')
                ->where('batches.total', 1)
                ->where('batches.data.0.id', $batch->getKey())
                ->where('batches.data.0.record.label', 'Recoverable UI Customer')
                ->where('batches.data.0.reason', 'UI archive test')
                ->where('filters.type', 'customers')
                ->where('status', null));

        $this->post(route('admin.archive.restore', $batch->getKey()))
            ->assertRedirect(route('admin.archive'))
            ->assertSessionHas('status', 'Archive restored.');

        $this->assertModelExists(Customer::query()->findOrFail($customer->id));
        $this->assertDatabaseHas('archive_batches', [
            'id' => $batch->getKey(),
            'restored_by_user_id' => $admin->id,
        ]);
    }

    public function test_archive_page_is_admin_only(): void
    {
        $this->get(route('admin.archive'))
            ->assertRedirect(route('login'));

        $basicUser = User::factory()->create(['status' => 'active']);

        $this->actingAs($basicUser)
            ->get(route('admin.archive'))
            ->assertForbidden();
    }

    public function test_foreign_keys_prevent_hard_deleting_a_customer_with_archivable_descendants(): void
    {
        $customer = Customer::create(['name' => 'FK-protected Customer']);
        $customer->sites()->create(['name' => 'FK-protected Site']);

        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $customer->id)->delete();
    }

    public function test_eloquent_bulk_deletes_are_blocked_for_archiveable_models(): void
    {
        $customer = Customer::create(['name' => 'Bulk-delete protected Customer']);

        $this->expectException(LogicException::class);
        Customer::query()->whereKey($customer->id)->delete();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['status' => 'active']);
    }
}
