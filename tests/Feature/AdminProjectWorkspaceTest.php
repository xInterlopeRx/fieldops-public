<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\AttachmentLifecycleEmailNotification;
use App\Notifications\AttachmentLifecycleNotification;
use App\Notifications\BusinessAssignmentChanged;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminProjectWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_a_project_workspace_with_sites_jobs_and_activity(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $customer = Customer::create(['name' => 'Project customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Project site', 'address' => '1 Main Street']);
        $project = Project::create([
            'customer_id' => $customer->id,
            'project_number' => 'P-301',
            'name' => 'Lighting project',
            'status' => 'planning',
        ]);
        $project->sites()->attach($site);
        WorkOrder::create([
            'site_id' => $site->id,
            'project_id' => $project->id,
            'job_number' => 'J-301',
            'title' => 'Inspect lighting',
            'scope' => 'Inspect all fixtures.',
            'status' => 'scheduled',
        ]);

        $this->getJson(route('admin.projects.workspace.show', $project))
            ->assertOk()
            ->assertJsonPath('data.project.number', 'P-301')
            ->assertJsonPath('data.project.customerName', 'Project customer')
            ->assertJsonPath('data.availableSites.0.name', 'Project site')
            ->assertJsonPath('data.jobs.0.number', 'J-301')
            ->assertJsonFragment(['summary' => 'Admin Project Tester created project P-301']);
    }

    public function test_admin_can_update_project_sites_and_staff_assignments(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->create(['name' => 'Project technician', 'status' => 'active']);
        $customer = Customer::create(['name' => 'Project customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Assigned site']);
        $project = Project::create([
            'customer_id' => $customer->id,
            'project_number' => 'P-302',
            'name' => 'Old project name',
            'status' => 'planning',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.projects.workspace.update', $project), [
                'name' => 'Updated project name',
                'notes' => 'Plan the work in phases.',
                'status' => 'active',
                'site_ids' => [$site->id],
                'assigned_user_ids' => [$staff->id],
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Project saved.');

        $this->assertSame('Updated project name', $project->fresh()->name);
        $this->assertDatabaseHas('project_site', ['project_id' => $project->id, 'site_id' => $site->id]);
        $this->assertDatabaseHas('project_user', [
            'project_id' => $project->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Project::class,
            'subject_id' => $project->id,
            'event' => 'edited',
        ]);
        Notification::assertSentTo($staff, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
    }

    public function test_project_workspace_rejects_a_site_outside_the_project_customer(): void
    {
        $admin = $this->admin();
        $customer = Customer::create(['name' => 'Customer one']);
        $otherCustomer = Customer::create(['name' => 'Customer two']);
        $foreignSite = Site::create(['customer_id' => $otherCustomer->id, 'name' => 'Foreign site']);
        $project = Project::create(['customer_id' => $customer->id, 'name' => 'Scoped project']);

        $this->actingAs($admin)
            ->putJson(route('admin.projects.workspace.update', $project), [
                'name' => 'Changed project',
                'notes' => null,
                'status' => 'active',
                'site_ids' => [$foreignSite->id],
                'assigned_user_ids' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_ids');

        $this->assertSame('Scoped project', $project->fresh()->name);
    }

    public function test_admin_can_upload_and_download_a_private_project_attachment(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $customer = Customer::query()->create(['name' => 'Attachment customer']);
        $site = Site::query()->create([
            'customer_id' => $customer->getKey(),
            'name' => 'Attachment site',
        ]);
        $project = Project::create([
            'customer_id' => $customer->getKey(),
            'name' => 'Attachment project',
        ]);
        $project->sites()->attach($site->getKey());
        $projectParticipant = User::factory()->create(['status' => 'active']);
        $jobParticipant = User::factory()->create(['status' => 'active']);
        $visitParticipant = User::factory()->create(['status' => 'active']);
        $project->assignedUsers()->attach($projectParticipant->getKey(), ['assigned_by_user_id' => $admin->getKey()]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'project_id' => $project->getKey(),
            'job_number' => 'J-PROJECT-ATTACHMENT',
            'title' => 'Project attachment job',
            'scope' => 'Review the project work.',
            'status' => 'scheduled',
        ]);
        $job->assignedUsers()->attach($jobParticipant->getKey(), ['assigned_by_user_id' => $admin->getKey()]);
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => $job->scope,
        ]);
        $visit->assignedUsers()->attach($visitParticipant->getKey());
        $legacyPath = 'projects/'.$project->id.'/attachments/legacy.pdf';
        Storage::disk('local')->put($legacyPath, 'legacy project attachment');
        $legacyAttachment = $project->attachments()->create([
            'uploaded_by' => $admin->id,
            'kind' => 'documents',
            'original_name' => 'legacy.pdf',
            'disk' => 'local',
            'path' => $legacyPath,
            'mime_type' => 'application/pdf',
            'size' => 25,
        ]);
        $this->mockSharePointUpload();
        Notification::fake();

        $response = $this->actingAs($admin)
            ->post(route('admin.projects.attachments.store', $project), [
                'file' => UploadedFile::fake()->createWithContent('work-order.pdf', "%PDF-1.4\nfixture"),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'work-order.pdf')
            ->assertJsonPath('data.uploadedBy', $admin->name);
        $this->assertSame(1, ProjectAttachment::query()->count());
        $sharePointAttachment = SharePointAttachment::query()->sole();
        $this->assertSame($project->getKey(), $sharePointAttachment->project_id);
        $this->assertSame($admin->getKey(), $sharePointAttachment->uploaded_by);
        $this->assertSame('work-order.pdf', $sharePointAttachment->filename);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => Project::class,
            'subject_id' => $project->getKey(),
            'event' => 'attachment_uploaded',
        ]);
        foreach ([$projectParticipant, $jobParticipant, $visitParticipant] as $participant) {
            Notification::assertSentTo($participant, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_uploaded');
            Notification::assertSentTo($participant, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_uploaded');
        }
        $this->assertSame(route('attachments.preview', $sharePointAttachment), $response->json('data.previewUrl'));
        $this->assertNull($response->json('data.thumbnailUrl'));

        $workspace = $this->getJson(route('admin.projects.workspace.show', $project))
            ->assertOk()
            ->assertJsonCount(2, 'data.attachments');
        $items = collect($workspace->json('data.attachments'));
        $this->assertSame([
            route('admin.projects.attachments.show', $legacyAttachment),
            route('attachments.open', $sharePointAttachment),
        ], $items->pluck('url')->sort()->values()->all());

        $this->get(route('admin.projects.attachments.show', $legacyAttachment))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->get(route('attachments.open', $sharePointAttachment))
            ->assertRedirect('https://example.sharepoint.com/sites/work-schedules/project-item-1');
    }

    public function test_admin_can_upload_powerpoint_and_receive_the_microsoft_365_open_capability(): void
    {
        $admin = $this->admin();
        $project = Project::query()->create([
            'customer_id' => Customer::query()->create(['name' => 'Presentation customer'])->getKey(),
            'name' => 'Presentation project',
        ]);
        $this->mockSharePointUpload();

        $response = $this->actingAs($admin)
            ->post(route('admin.projects.attachments.store', $project), [
                'file' => $this->powerPointFile(),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'presentation.pptx')
            ->assertJsonPath('data.officeApp', 'powerpoint')
            ->assertJsonPath('data.previewUrl', null);

        $attachment = SharePointAttachment::query()->sole();
        $this->assertSame(route('attachments.open', $attachment), $response->json('data.onlineUrl'));
        $this->assertDatabaseHas('sharepoint_attachments', [
            'id' => $attachment->getKey(),
            'project_id' => $project->getKey(),
            'filename' => 'presentation.pptx',
        ]);
    }

    public function test_basic_user_cannot_open_or_edit_a_project_workspace(): void
    {
        $project = Project::create([
            'customer_id' => Customer::create(['name' => 'Private customer'])->id,
            'name' => 'Private project',
        ]);
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('admin.projects.workspace.show', $project))
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('admin.projects.workspace.update', $project), [
                'name' => 'Unauthorized change',
                'notes' => null,
                'status' => 'active',
                'site_ids' => [],
                'assigned_user_ids' => [],
            ])
            ->assertForbidden();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Admin Project Tester',
            'status' => 'active',
        ]);
    }

    private function mockSharePointUpload(): void
    {
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-test',
        ]);

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('upload')
            ->once()
            ->andReturnUsing(fn (string $path, string $contents, string $contentType): array => [
                'id' => 'project-item-1',
                'name' => basename($path),
                'size' => strlen($contents),
                'mime_type' => $contentType,
                'e_tag' => 'etag-project-item-1',
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/project-item-1',
            ]);
    }

    private function powerPointFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fieldops-pptx-');
        $archive = new \ZipArchive;

        $archive->open($path, \ZipArchive::OVERWRITE);
        $archive->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/></Types>',
        );
        $archive->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"/>',
        );
        $archive->close();

        $contents = file_get_contents($path);
        unlink($path);

        return UploadedFile::fake()->createWithContent('presentation.pptx', is_string($contents) ? $contents : '');
    }
}
