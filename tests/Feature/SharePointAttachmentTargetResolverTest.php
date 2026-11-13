<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use App\Services\SharePointAttachmentTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SharePointAttachmentTargetResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_attachment_upload_stays_blocked_until_business_target_is_activated(): void
    {
        $target = $this->target();
        $this->mockRuntimeTarget($target);
        $this->cleanedCanary($target);

        try {
            app(SharePointAttachmentTargetResolver::class)->forNewAttachment();
            $this->fail('Expected the Business Attachments write gate to be closed.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_business_attachments_target_not_ready', $exception->reason);
            $this->assertSame(503, $exception->httpStatus);
        }
    }

    public function test_managed_attachment_upload_resolves_to_the_saved_business_attachments_drive_after_activation(): void
    {
        $target = $this->target([
            'business_attachments_writes_activated_at' => now(),
        ]);
        $this->mockRuntimeTarget($target);
        $this->cleanedCanary($target);

        $resolved = app(SharePointAttachmentTargetResolver::class)->forNewAttachment();

        $this->assertSame([
            'site_id' => 'business-site-id',
            'drive_id' => 'business-drive-id',
            'target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
        ], $resolved);
    }

    public function test_business_attachment_writes_auto_activate_only_after_all_existing_rows_are_migrated(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $target = $this->target();
        $this->mockRuntimeTarget($target);
        $this->cleanedCanary($target);
        $customer = Customer::query()->create(['name' => 'Target gate customer']);
        $site = Site::query()->create(['customer_id' => $customer->getKey(), 'name' => 'Target gate site']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'title' => 'Target gate job',
            'status' => 'created',
        ]);
        $attachment = SharePointAttachment::query()->create([
            'company_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'site-photo.jpg',
            'mime_type' => 'image/jpeg',
            'file_size' => 12,
            'sharepoint_drive_id' => 'work-schedules-drive-id',
            'sharepoint_item_id' => 'legacy-item-id',
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/work-schedules/legacy-item-id',
        ]);

        $resolver = app(SharePointAttachmentTargetResolver::class);

        $this->assertFalse($resolver->activateBusinessAttachmentWritesIfReady($admin, $target));
        $this->assertNull($target->fresh()->business_attachments_writes_activated_at);

        $attachment->forceFill([
            'sharepoint_site_id' => 'business-site-id',
            'sharepoint_target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
            'sharepoint_drive_id' => 'business-drive-id',
            'sharepoint_item_id' => 'business-item-id',
            'sharepoint_url' => 'https://tenant.sharepoint.com/sites/business-attachments/business-item-id',
        ])->save();

        $this->assertTrue($resolver->activateBusinessAttachmentWritesIfReady($admin, $target));
        $this->assertNotNull($target->fresh()->business_attachments_writes_activated_at);
        $this->assertSame($admin->getKey(), $target->fresh()->business_attachments_writes_activated_by_user_id);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_business_attachments_writes_activated']);
    }

    private function target(array $overrides = []): MicrosoftGraphOnboardingTarget
    {
        return MicrosoftGraphOnboardingTarget::query()->create(array_merge([
            'environment' => app()->environment(),
            'tenant_id' => '11111111-1111-4111-8111-111111111111',
            'client_id' => '22222222-2222-4222-8222-222222222222',
            'notification_mailbox_id' => '33333333-3333-4333-8333-333333333333',
            'notification_mailbox_address' => 'fieldops@example.test',
            'target_is_dedicated_shared_mailbox' => true,
            'notification_calendar_id' => null,
            'work_schedules_group_id' => '44444444-4444-4444-8444-444444444444',
            'work_schedules_site_id' => 'work-schedules-site-id',
            'work_schedules_drive_id' => 'work-schedules-drive-id',
            'business_attachments_site_id' => 'business-site-id',
            'business_attachments_drive_id' => 'business-drive-id',
            'non_target_mailbox_email' => 'control@example.test',
            'non_target_is_user_mailbox' => true,
            'non_target_site_id' => 'control-site-id',
            'non_target_drive_id' => 'control-drive-id',
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'target_contacts_status' => 'accessible',
            'non_target_contacts_status' => 'denied',
            'boundary_verified_at' => now(),
            'contacts_boundary_verified_at' => now(),
            'runtime_activated_at' => now(),
        ], $overrides));
    }

    private function mockRuntimeTarget(MicrosoftGraphOnboardingTarget $target): void
    {
        $runtime = $this->mock(MicrosoftGraphRuntimeTargetResolver::class);
        $runtime->shouldReceive('requiresManagedTarget')->andReturn(true);
        $runtime->shouldReceive('activeTarget')->andReturn($target);
    }

    private function cleanedCanary(MicrosoftGraphOnboardingTarget $target): void
    {
        MicrosoftGraphSharePointOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'environment' => $target->environment,
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'site_id' => $target->business_attachments_site_id,
            'drive_id' => $target->business_attachments_drive_id,
            'status' => MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED,
            'operation_id' => (string) Str::uuid(),
            'folder_name' => 'FieldOps setup canary',
            'remote_item_id' => 'canary-folder-item-id',
            'remote_etag' => 'canary-etag',
            'attempted_at' => now()->subMinute(),
            'created_remotely_at' => now()->subMinute(),
            'cleaned_at' => now(),
        ]);
    }
}
