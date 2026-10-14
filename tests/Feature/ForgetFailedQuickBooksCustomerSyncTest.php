<?php

namespace Tests\Feature;

use App\Jobs\SyncQuickBooksCustomer;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

class ForgetFailedQuickBooksCustomerSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_forget_a_failed_quickbooks_sync_record_after_confirming_without_replaying_it(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $admin = $this->adminWithTwoFactor();
        $failedJobId = $this->failedJob(SyncQuickBooksCustomer::class);
        $otherFailedJobId = $this->failedJob(SyncVisitMicrosoftGraphEvent::class, 'failed-visit-queue');

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('queueBacklog.failedJobReviewItems.0.type', 'Visit calendar sync')
                ->where('queueBacklog.failedJobReviewItems.0.canForget', false)
                ->where('queueBacklog.failedJobReviewItems.1.type', 'QuickBooks customer sync')
                ->where('queueBacklog.failedJobReviewItems.1.canForget', true))
            ->assertDontSee('Sensitive serialized failed job data')
            ->assertDontSee('Sensitive failed exception text');

        $this->postJson(route('admin.sync-logs.failed-jobs.forget', $failedJobId), [
            'confirm_forget' => true,
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('status', 'forgotten')
            ->assertJsonPath('failed_job_id', $failedJobId)
            ->assertJsonPath('message', 'The failed QuickBooks customer sync record was forgotten. It was not retried and no QuickBooks request was made.');

        $this->assertDatabaseMissing('failed_jobs', ['id' => $failedJobId]);
        $this->assertDatabaseHas('failed_jobs', ['id' => $otherFailedJobId]);
        $event = AuditEvent::query()->where('event', 'failed_queue_record_forgotten')->sole();
        $this->assertSame($admin->getKey(), $event->actor_user_id);
        $this->assertSame(ApplicationSetupState::class, $event->subject_type);
        $this->assertSame(1, $event->subject_id);
        $this->assertSame($failedJobId, $event->metadata['failed_job_id']);
        $this->assertSame('quickbooks_customer_sync', $event->metadata['job_type']);
        $this->assertSame(false, $event->metadata['retry_performed']);
        $this->assertSame(false, $event->metadata['provider_call_performed']);
        $this->assertArrayNotHasKey('payload', $event->metadata);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_failed_quickbooks_sync_cannot_be_forgotten_without_confirmation_or_correct_password(): void
    {
        $admin = $this->adminWithTwoFactor();
        $failedJobId = $this->failedJob(SyncQuickBooksCustomer::class);

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.failed-jobs.forget', $failedJobId), [
                'confirm_forget' => false,
                'password' => 'password',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm_forget']);

        $this->postJson(route('admin.sync-logs.failed-jobs.forget', $failedJobId), [
            'confirm_forget' => true,
            'password' => 'wrong-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'failed_queue_record_forgotten']);
    }

    public function test_failed_non_quickbooks_job_cannot_be_forgotten_from_the_quickbooks_action(): void
    {
        $admin = $this->adminWithTwoFactor();
        $failedJobId = $this->failedJob(SyncVisitMicrosoftGraphEvent::class);

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.failed-jobs.forget', $failedJobId), [
                'confirm_forget' => true,
                'password' => 'password',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Only a failed QuickBooks customer sync record can be forgotten from this action.');

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'failed_queue_record_forgotten']);
    }

    public function test_basic_user_cannot_forget_a_failed_quickbooks_sync_record(): void
    {
        $basicUser = User::factory()->create(['status' => 'active']);
        $failedJobId = $this->failedJob(SyncQuickBooksCustomer::class);

        $this->actingAs($basicUser)
            ->postJson(route('admin.sync-logs.failed-jobs.forget', $failedJobId), [
                'confirm_forget' => true,
                'password' => 'password',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('failed_jobs', ['id' => $failedJobId]);
        $this->assertDatabaseMissing('audit_events', ['event' => 'failed_queue_record_forgotten']);
    }

    private function failedJob(string $displayName, string $queue = 'default'): int
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => $queue,
            'payload' => json_encode([
                'displayName' => $displayName,
                'data' => ['command' => 'Sensitive serialized failed job data'],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'Sensitive failed exception text',
            'failed_at' => now(),
        ]);

        return (int) DB::table('failed_jobs')->where('uuid', $uuid)->value('id');
    }

    private function adminWithTwoFactor(): User
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Queue Admin',
            'status' => 'active',
        ]);
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin->fresh();
    }
}
