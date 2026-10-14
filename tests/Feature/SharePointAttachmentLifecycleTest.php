<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\AttachmentLifecycleEmailNotification;
use App\Notifications\AttachmentLifecycleNotification;
use App\Services\AttachmentLifecycleService;
use App\Services\MicrosoftGraphSharePointStorageService;
use App\Services\SharePointAttachmentDeletionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SharePointAttachmentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_permanent_delete_records_activity_and_notifies_assigned_job_users(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $participant = User::factory()->create(['status' => 'active']);
        [$company, $site, $job] = $this->jobFixture($admin, $participant);
        $attachment = $this->attachment($company, $site, $job);
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('delete')
            ->once()
            ->with($attachment->sharepoint_item_id);
        Notification::fake();

        app(SharePointAttachmentDeletionService::class)->delete($attachment, $admin);

        $this->assertDatabaseMissing('sharepoint_attachments', ['id' => $attachment->getKey()]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_deleted',
        ]);
        Notification::assertSentTo($participant, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_deleted');
        Notification::assertSentTo($participant, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_deleted');
    }

    public function test_failed_permanent_delete_records_a_failure_without_removing_metadata(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $participant = User::factory()->create(['status' => 'active']);
        [$company, $site, $job] = $this->jobFixture($admin, $participant);
        $attachment = $this->attachment($company, $site, $job);
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('delete')
            ->once()
            ->with($attachment->sharepoint_item_id)
            ->andThrow(new MicrosoftGraphApiException('graph_delete_failure', 503));
        Notification::fake();

        try {
            app(SharePointAttachmentDeletionService::class)->delete($attachment, $admin);
            $this->fail('Expected SharePoint deletion to fail.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_delete_failure', $exception->reason);
        }

        $this->assertModelExists($attachment);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_failed',
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_deleted',
        ]);
        Notification::assertSentTo($participant, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_failed'
            && $notification->operation === 'delete');
        Notification::assertSentTo($participant, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_failed'
            && $notification->operation === 'delete');
    }

    public function test_permanent_delete_is_admin_only_and_does_not_call_graph_for_a_basic_user(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $participant = User::factory()->create(['status' => 'active']);
        [$company, $site, $job] = $this->jobFixture($admin, $participant);
        $attachment = $this->attachment($company, $site, $job);
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldNotReceive('delete');

        $this->expectException(AuthorizationException::class);

        app(SharePointAttachmentDeletionService::class)->delete($attachment, $participant);
    }

    public function test_lifecycle_records_database_notification_immediately_and_queues_only_email(): void
    {
        $actor = User::factory()->admin()->create(['status' => 'active']);
        $participant = User::factory()->create(['status' => 'active']);
        [$company, $site, $job] = $this->jobFixture($actor, $participant);
        $attachment = $this->attachment($company, $site, $job);
        Queue::fake();

        app(AttachmentLifecycleService::class)->uploaded($attachment, $actor);

        $this->assertDatabaseHas('notifications', [
            'type' => AttachmentLifecycleNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $participant->getKey(),
        ]);
        $stored = DB::table('notifications')->where('notifiable_id', $participant->getKey())->sole();
        $data = json_decode($stored->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('attachment_uploaded', $data['event']);
        $this->assertSame('job J-LIFECYCLE-1', $data['record']);
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $actor->getKey()]);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->notification instanceof AttachmentLifecycleEmailNotification
            && $job->notification->event === 'attachment_uploaded');
    }

    public function test_failed_operation_persists_database_notification_and_queues_email(): void
    {
        $actor = User::factory()->admin()->create(['status' => 'active']);
        $participant = User::factory()->create(['status' => 'active']);
        [$company, $site, $job] = $this->jobFixture($actor, $participant);
        Queue::fake();

        app(AttachmentLifecycleService::class)->failed($job, $actor, 'storage_unavailable');

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $actor->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_failed',
        ]);
        $this->assertDatabaseHas('notifications', [
            'type' => AttachmentLifecycleNotification::class,
            'notifiable_type' => User::class,
            'notifiable_id' => $participant->getKey(),
        ]);
        $stored = DB::table('notifications')->where('notifiable_id', $participant->getKey())->sole();
        $data = json_decode($stored->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('attachment_failed', $data['event']);
        Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $queued): bool => $queued->notification instanceof AttachmentLifecycleEmailNotification
            && $queued->notification->event === 'attachment_failed');
    }

    /** @return array{Customer, Site, WorkOrder} */
    private function jobFixture(User $admin, User $participant): array
    {
        $company = Customer::query()->create(['name' => 'Lifecycle customer']);
        $site = $company->sites()->create(['name' => 'Lifecycle site']);
        $job = WorkOrder::query()->create([
            'site_id' => $site->getKey(),
            'job_number' => 'J-LIFECYCLE-1',
            'title' => 'Lifecycle job',
            'status' => 'scheduled',
        ]);
        $job->assignedUsers()->attach($participant->getKey(), ['assigned_by_user_id' => $admin->getKey()]);

        return [$company, $site, $job];
    }

    private function attachment(Customer $company, Site $site, WorkOrder $job): SharePointAttachment
    {
        return SharePointAttachment::query()->create([
            'company_id' => $company->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
            'filename' => 'lifecycle.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 12,
            'sharepoint_drive_id' => 'drive-lifecycle',
            'sharepoint_item_id' => 'item-lifecycle-'.fake()->unique()->uuid(),
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/lifecycle.pdf',
        ]);
    }
}
