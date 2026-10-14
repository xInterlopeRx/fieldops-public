<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AttachmentListService;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharePointAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_preserves_sharepoint_fields_and_all_owner_relationships(): void
    {
        $company = Customer::query()->create([
            'name' => 'Example customer',
            'company_name' => 'Example company',
        ]);
        $site = $company->sites()->create(['name' => 'Main site']);
        $project = Project::query()->create([
            'customer_id' => $company->getKey(),
            'name' => 'Project one',
        ]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'project_id' => $project->getKey(),
            'job_number' => 'J90001',
            'title' => 'Job one',
            'status' => 'pending',
        ]);

        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'project_id' => $project->getKey(),
            'job_id' => $job->getKey(),
        ]));

        $this->assertSame($company->getKey(), $attachment->company->getKey());
        $this->assertSame($site->getKey(), $attachment->site->getKey());
        $this->assertSame($project->getKey(), $attachment->project->getKey());
        $this->assertSame($job->getKey(), $attachment->job->getKey());
        $this->assertDatabaseHas('sharepoint_attachments', [
            'id' => $attachment->getKey(),
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'project_id' => $project->getKey(),
            'job_id' => $job->getKey(),
            'filename' => 'site-photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'sharepoint_drive_id' => 'drive-library-1',
            'sharepoint_item_id' => 'drive-item-1',
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work/drive-item-1',
        ]);
        $this->assertSame(2048, $attachment->file_size);
        $this->assertTrue($company->sharePointAttachments()->whereKey($attachment->getKey())->exists());
        $this->assertTrue($site->sharePointAttachments()->whereKey($attachment->getKey())->exists());
        $this->assertTrue($project->sharePointAttachments()->whereKey($attachment->getKey())->exists());
        $this->assertTrue($job->sharePointAttachments()->whereKey($attachment->getKey())->exists());
    }

    public function test_sharepoint_metadata_coexists_with_existing_local_customer_attachment_rows(): void
    {
        $company = Customer::query()->create(['name' => 'Existing customer']);
        $localAttachment = CustomerAttachment::query()->create([
            'customer_id' => $company->getKey(),
            'kind' => 'documents',
            'original_name' => 'existing.pdf',
            'disk' => 'local',
            'path' => 'customers/1/attachments/existing.pdf',
            'mime_type' => 'application/pdf',
            'size' => 12,
        ]);
        $sharePointAttachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
        ]));

        $this->assertSame(
            [$localAttachment->getKey()],
            $company->attachments()->pluck('id')->all(),
        );
        $this->assertSame(
            [$sharePointAttachment->getKey()],
            $company->sharePointAttachments()->pluck('id')->all(),
        );
        $this->assertModelExists($localAttachment);
        $this->assertModelExists($sharePointAttachment);
    }

    public function test_sharepoint_drive_item_pair_is_unique(): void
    {
        $company = Customer::query()->create(['name' => 'Unique key customer']);
        SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
        ]));

        $this->expectException(QueryException::class);

        SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
        ]));
    }

    public function test_company_customer_relationship_is_required(): void
    {
        $attributes = $this->metadataAttributes();
        unset($attributes['company_id']);

        $this->expectException(QueryException::class);

        SharePointAttachment::query()->create($attributes);
    }

    public function test_remote_open_rejects_a_non_sharepoint_destination(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $company = Customer::query()->create(['name' => 'Open URL customer']);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'sharepoint_url' => 'https://attacker.example/file.pdf',
        ]));

        $this->actingAs($admin)
            ->get(route('attachments.open', $attachment))
            ->assertNotFound();
    }

    public function test_explicit_sharepoint_availability_check_removes_metadata_when_the_remote_item_is_gone(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $company = Customer::query()->create(['name' => 'Missing remote file customer']);
        $site = Site::query()->create(['customer_id' => $company->getKey(), 'name' => 'Missing remote file site']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'job_number' => 'J-MISSING-1',
            'title' => 'Missing remote file job',
            'status' => 'pending',
        ]);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
        ]));

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('metadata')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andThrow(new MicrosoftGraphApiException('graph_record_not_found', 404));

        $this->actingAs($admin)
            ->getJson(route('attachments.availability', $attachment))
            ->assertStatus(410)
            ->assertJsonPath('message', 'SharePoint no longer has this file. The stale FieldOps attachment link was removed.');

        $this->assertDatabaseMissing('sharepoint_attachments', ['id' => $attachment->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_remote_missing',
        ]);
    }

    public function test_sharepoint_availability_errors_leave_attachment_metadata_unchanged(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $company = Customer::query()->create(['name' => 'Unavailable service customer']);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
        ]));

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('metadata')
            ->once()
            ->andThrow(new MicrosoftGraphApiException('graph_service_unavailable', 503));

        $this->actingAs($admin)
            ->getJson(route('attachments.availability', $attachment))
            ->assertStatus(503)
            ->assertJsonPath('message', 'SharePoint could not verify this file. Its attachment record was left unchanged.');

        $this->assertModelExists($attachment);
        $this->assertDatabaseMissing('audit_events', ['event' => 'attachment_remote_missing']);
    }

    public function test_unassigned_basic_user_cannot_open_a_job_attachment(): void
    {
        $company = Customer::query()->create(['name' => 'Assigned access customer']);
        $site = Site::query()->create([
            'customer_id' => $company->getKey(),
            'name' => 'Assigned access site',
        ]);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'job_number' => 'J-OPEN-1',
            'title' => 'Assigned access job',
            'status' => 'pending',
        ]);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
        ]));
        $unassigned = User::factory()->create(['status' => 'active']);

        $this->actingAs($unassigned)
            ->get(route('attachments.open', $attachment))
            ->assertForbidden();

        $this->getJson(route('attachments.availability', $attachment))
            ->assertForbidden();
    }

    public function test_successful_sharepoint_availability_check_preserves_attachment_metadata(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $company = Customer::query()->create(['name' => 'Available remote file customer']);
        $attachment = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
        ]));

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('metadata')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andReturn([
                'id' => $attachment->sharepoint_item_id,
                'name' => $attachment->filename,
                'size' => $attachment->file_size,
                'mime_type' => $attachment->mime_type,
                'e_tag' => 'etag-current',
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => $attachment->sharepoint_url,
            ]);

        $this->actingAs($admin)
            ->getJson(route('attachments.availability', $attachment))
            ->assertOk()
            ->assertJsonPath('available', true);

        $this->assertModelExists($attachment);
        $this->assertDatabaseMissing('audit_events', ['event' => 'attachment_remote_missing']);
    }

    public function test_workspace_attachment_resources_expose_generic_office_open_actions_by_document_type(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $company = Customer::query()->create(['name' => 'Office action customer']);
        $word = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'service-plan.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'sharepoint_drive_id' => 'drive-office-word',
            'sharepoint_item_id' => 'item-office-word',
        ]));
        $excel = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'readings.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'sharepoint_drive_id' => 'drive-office-excel',
            'sharepoint_item_id' => 'item-office-excel',
        ]));
        $powerPoint = SharePointAttachment::query()->create($this->metadataAttributes([
            'company_id' => $company->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'service-briefing.pptx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'sharepoint_drive_id' => 'drive-office-powerpoint',
            'sharepoint_item_id' => 'item-office-powerpoint',
        ]));
        $company->attachments()->create([
            'uploaded_by' => $admin->getKey(),
            'kind' => 'documents',
            'original_name' => 'legacy.docx',
            'disk' => 'local',
            'path' => 'customers/1/attachments/legacy.docx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size' => 12,
        ]);

        $resources = collect(app(AttachmentListService::class)->forCustomerWorkspace($company))
            ->keyBy('name');

        $this->assertSame('word', $resources->get($word->filename)['officeApp']);
        $this->assertSame('excel', $resources->get($excel->filename)['officeApp']);
        $this->assertSame('powerpoint', $resources->get($powerPoint->filename)['officeApp']);

        foreach ([$word, $excel, $powerPoint] as $attachment) {
            $resource = $resources->get($attachment->filename);
            $this->assertSame(route('attachments.open', $attachment), $resource['onlineUrl']);
            $this->assertSame(route('attachments.availability', $attachment), $resource['availabilityUrl']);
            $this->assertArrayNotHasKey('sharepoint_url', $resource);
            $this->assertArrayNotHasKey('sharepoint_drive_id', $resource);
        }

        $this->assertNull($resources->get('legacy.docx')['onlineUrl']);
        $this->assertNull($resources->get('legacy.docx')['officeApp']);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function metadataAttributes(array $overrides = []): array
    {
        return array_replace([
            'company_id' => null,
            'site_id' => null,
            'project_id' => null,
            'job_id' => null,
            'filename' => 'site-photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 2048,
            'sharepoint_drive_id' => 'drive-library-1',
            'sharepoint_item_id' => 'drive-item-1',
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work/drive-item-1',
        ], $overrides);
    }
}
