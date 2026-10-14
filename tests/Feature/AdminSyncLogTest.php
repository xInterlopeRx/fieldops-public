<?php

namespace Tests\Feature;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\PushQuickBooksCustomer;
use App\Jobs\SendGraphNotification;
use App\Jobs\SyncOutlookContact;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GraphNotificationOutbox;
use App\Models\IntegrationConnection;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\QuickBooksApiClient;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSyncLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_sync_health_and_admin_log_show_failed_provider_work_and_project_job_activity(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $customer = Customer::create([
            'name' => 'Sync customer',
            'quickbooks_id' => '100',
            'quickbooks_sync_status' => 'failed',
            'quickbooks_sync_error' => 'quickbooks_conflict',
        ]);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Sync site']);
        $job = WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J-801',
            'title' => 'Sync job',
            'scope' => 'Inspect the synchronization test fixture.',
            'status' => 'scheduled',
        ]);
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Inspect the fixture.',
            'graph_sync_status' => 'failed',
            'graph_sync_error' => 'graph_forbidden',
        ]);
        GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $job->id,
            'visit_id' => $visit->id,
            'type' => 'status_update',
            'recipient_email' => 'client@example.test',
            'event_key' => (string) Str::uuid(),
            'status' => 'blocked_attachment',
            'available_at' => now(),
            'last_error' => 'attachment_unavailable',
        ]);

        $this->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('health.status', 'error')
                ->where('health.errors', 3)
                ->where('quickBooks.0.label', 'Sync customer')
                ->where('quickBooks.0.error', 'quickbooks_conflict')
                ->where('visits.0.id', $visit->id)
                ->where('notifications.0.status', 'blocked_attachment')
                ->where('activities', fn ($events): bool => $events->contains(fn (array $event): bool => $event['type'] === 'Job' && $event['record'] === 'Admin Sync Tester created job J-801'))
                ->where('activities', fn ($events): bool => $events->contains(fn (array $event): bool => $event['type'] === 'Visit' && $event['record'] === 'Admin Sync Tester created visit for job J-801'))
                ->missing('notifications.0.recipientEmail'));
    }

    public function test_sync_logs_display_outlook_contact_id_status_and_last_sync(): void
    {
        $admin = $this->admin();
        $contact = Contact::factory()->create([
            'first_name' => 'Outlook',
            'last_name' => 'Diagnostic',
        ]);
        $contact->forceFill([
            'outlook_contact_id' => 'diagnostic-outlook-id',
            'outlook_contact_mailbox_id' => 'notification-mailbox-id',
            'outlook_sync_status' => 'failed',
            'outlook_sync_error' => 'outlook_contact_email_invalid',
            'outlook_last_synced_at' => now()->subHour(),
        ])->saveQuietly();

        $this->actingAs($admin)
            ->get(route('admin.sync-logs', ['contact_q' => 'Outlook']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('outlookContacts.data.0.id', $contact->id)
                ->where('outlookContacts.data.0.contact', 'Outlook Diagnostic')
                ->where('outlookContacts.data.0.customerId', $contact->customer_id)
                ->where('outlookContacts.data.0.outlookContactId', 'diagnostic-outlook-id')
                ->where('outlookContacts.data.0.status', 'failed')
                ->where('outlookContacts.data.0.error', 'outlook_contact_email_invalid')
                ->where('health.graph.outlookContacts.failed', 1)
                ->where('outlookContacts.data.0.lastSyncedAt', $contact->fresh()->outlook_last_synced_at->toIso8601String()));
    }

    public function test_outlook_sync_queue_defaults_to_unreconciled_contacts_and_can_show_all_statuses(): void
    {
        $admin = $this->admin();
        $pending = Contact::factory()->create(['first_name' => 'Needs sync']);
        $pending->forceFill(['outlook_sync_status' => 'failed'])->saveQuietly();
        $synced = Contact::factory()->create(['first_name' => 'Already synced']);
        $synced->forceFill([
            'outlook_contact_id' => 'known-outlook-id',
            'outlook_contact_mailbox_id' => 'notification-mailbox-id',
            'outlook_sync_status' => 'synced',
            'outlook_last_synced_at' => now(),
        ])->saveQuietly();

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('outlookContactFilters.status', 'action_needed')
                ->where('outlookContacts.total', 1)
                ->where('outlookContacts.data.0.id', $pending->getKey()));

        $this->get(route('admin.sync-logs', ['contact_status' => 'all']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('outlookContactFilters.status', 'all')
                ->where('outlookContacts.total', 2));
    }

    public function test_m365_reconciled_outlook_contacts_are_not_listed_as_action_needed_but_remain_filterable(): void
    {
        Http::preventStrayRequests();
        $admin = $this->admin();
        $activeContact = Contact::factory()->create(['first_name' => 'Reconciled active']);
        $archivedContact = Contact::factory()->create(['first_name' => 'Reconciled archived']);

        foreach ([$activeContact, $archivedContact] as $contact) {
            $contact->forceFill([
                'is_archived' => $contact->is($archivedContact),
                'outlook_contact_id' => null,
                'outlook_contact_mailbox_id' => null,
                'outlook_sync_status' => 'paused',
                'outlook_sync_error' => 'outlook_contact_target_reconciled',
            ])->saveQuietly();
        }

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('outlookContactFilters.status', 'action_needed')
                ->where('outlookContacts.total', 0)
                ->where('outlookContactFilters.statuses', fn ($statuses): bool => $statuses->contains('reconciled')));

        $this->get(route('admin.sync-logs', ['contact_status' => 'reconciled']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('outlookContactFilters.status', 'reconciled')
                ->where('outlookContacts.total', 2)
                ->where('outlookContacts.data', fn ($contacts): bool => $contacts->every(fn (array $contact): bool => $contact['status'] === 'reconciled'
                    && $contact['outlookContactId'] === null
                    && $contact['canResync'] === false
                    && $contact['canRepair'] === false
                    && str_contains($contact['blockedReason'], 'already reconciled'))));

        $this->assertSame('paused', $activeContact->fresh()->outlook_sync_status);
        $this->assertSame('outlook_contact_target_reconciled', $activeContact->fresh()->outlook_sync_error);
        $this->assertTrue($archivedContact->fresh()->is_archived);
        Http::assertNothingSent();
    }

    public function test_sync_logs_explain_stale_queued_work_and_prevent_duplicate_contact_resyncs(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'default',
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        $contact = Contact::factory()->create(['first_name' => 'Pending']);
        $contact->forceFill([
            'outlook_sync_status' => 'pending',
            'outlook_sync_requested_at' => now()->subMinutes(4),
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $createdAt = now()->subMinutes(4)->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $createdAt,
            'created_at' => $createdAt,
        ]);
        Queue::fake([SyncOutlookContact::class]);

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('queueBacklog.monitoringSupported', true)
                ->where('queueBacklog.queue', 'default')
                ->where('queueBacklog.totalJobs', 1)
                ->where('queueBacklog.readyJobs', 1)
                ->where('queueBacklog.workerAttentionRequired', true)
                ->where('outlookContacts.data.0.id', $contact->getKey())
                ->where('outlookContacts.data.0.status', 'pending')
                ->where('outlookContacts.data.0.canResync', false)
                ->where('outlookContacts.data.0.blockedReason', 'A Contact sync is already queued or running. Wait for the background worker before requesting another resync.'));

        $this->postJson(route('admin.sync-logs.outlook-contacts.resync', $contact->getKey()))
            ->assertConflict()
            ->assertJsonPath('message', 'This Contact sync is already queued or running. Wait for the background worker before requesting another resync.');

        $this->assertSame('pending', $contact->fresh()->outlook_sync_status);
        Queue::assertNothingPushed();
    }

    public function test_sync_logs_show_worker_heartbeat_and_failed_job_count_without_exposing_failed_payloads(): void
    {
        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'default',
        ]);
        Cache::store('database')->flush();
        $admin = $this->admin();
        Event::dispatch(new Looping('database', 'default,quickbooks-sync,quickbooks-initial-import'));
        $queuedAt = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'quickbooks-initial-import',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\SyncQuickBooksCustomer',
                'data' => ['command' => 'Sensitive queued command data'],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $queuedAt,
            'created_at' => $queuedAt,
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => SyncVisitMicrosoftGraphEvent::class,
                'data' => ['command' => 'Sensitive failed command data'],
            ], JSON_THROW_ON_ERROR),
            'exception' => 'Sensitive raw exception text',
            'failed_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('queueBacklog.worker.state', 'recently_seen')
                ->where('queueBacklog.additionalQueues.0.queue', 'quickbooks-sync')
                ->where('queueBacklog.additionalQueues.0.worker.state', 'recently_seen')
                ->where('queueBacklog.additionalQueues.1.queue', 'quickbooks-initial-import')
                ->where('queueBacklog.additionalQueues.1.worker.state', 'recently_seen')
                ->where('queueBacklog.additionalQueues.1.readyJobs', 1)
                ->where('queueBacklog.failedJobsCount', 1)
                ->where('queueBacklog.reviewItems.0.type', 'QuickBooks customer sync')
                ->where('queueBacklog.reviewItems.0.state', 'Ready')
                ->where('queueBacklog.failedJobReviewItems.0.type', 'Visit calendar sync'))
            ->assertDontSee('Sensitive raw exception text')
            ->assertDontSee('Sensitive queued command data')
            ->assertDontSee('Sensitive failed command data');
    }

    public function test_outlook_contact_search_treats_query_text_as_a_bound_value(): void
    {
        $admin = $this->admin();
        Contact::factory()->create(['first_name' => 'Visible']);
        Contact::factory()->create(['first_name' => 'Other']);

        $this->actingAs($admin)
            ->get(route('admin.sync-logs', ['contact_q' => "%' OR 1=1 --"]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('outlookContacts.data', fn ($contacts): bool => $contacts->isEmpty())
                ->where('outlookContacts.total', 0));
    }

    public function test_admin_can_resync_a_contact_and_status_becomes_pending(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create(['first_name' => 'Resync']);
        $contact->forceFill([
            'outlook_sync_status' => 'failed',
            'outlook_sync_error' => 'graph_unavailable',
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.resync', $contact->id))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $contact->refresh();
        $this->assertSame('pending', $contact->outlook_sync_status);
        $this->assertNull($contact->outlook_sync_error);
        $this->assertNotNull($contact->outlook_sync_requested_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'event' => 'outlook_contact_resync_requested',
        ]);
        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->id && ! $job->repair);
    }

    public function test_admin_repair_queues_replacement_only_for_a_contact_linked_to_the_configured_mailbox(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create(['first_name' => 'Repair']);
        $contact->forceFill([
            'outlook_contact_id' => 'known-outlook-id',
            'outlook_contact_mailbox_id' => 'notification-mailbox-id',
            'outlook_sync_status' => 'failed',
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.repair', $contact->id))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $contact->refresh();
        $this->assertSame('known-outlook-id', $contact->outlook_contact_id);
        $this->assertSame('pending', $contact->outlook_sync_status);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'event' => 'outlook_contact_repair_requested',
        ]);
        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->id && $job->repair);
    }

    public function test_admin_repair_refuses_a_contact_id_owned_by_a_different_mailbox(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create();
        $contact->forceFill([
            'outlook_contact_id' => 'foreign-mailbox-outlook-id',
            'outlook_contact_mailbox_id' => 'another-mailbox-id',
            'outlook_sync_status' => 'failed',
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.repair', $contact->id))
            ->assertConflict();

        $contact->refresh();
        $this->assertSame('foreign-mailbox-outlook-id', $contact->outlook_contact_id);
        $this->assertSame('another-mailbox-id', $contact->outlook_contact_mailbox_id);
        $this->assertSame('failed', $contact->outlook_sync_status);
        Queue::assertNothingPushed();
    }

    public function test_unknown_outlook_create_outcome_cannot_be_resynced_automatically(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create(['first_name' => 'Unknown']);
        $contact->forceFill([
            'outlook_sync_status' => 'unknown',
            'outlook_sync_error' => 'outlook_contact_create_outcome_unknown',
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.resync', $contact->id))
            ->assertConflict();

        $this->assertSame('unknown', $contact->fresh()->outlook_sync_status);
        Queue::assertNothingPushed();
    }

    public function test_unknown_outlook_create_repair_requires_manual_reconciliation_confirmation(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create();
        $contact->forceFill([
            'outlook_sync_status' => 'unknown',
            'outlook_sync_error' => 'outlook_contact_create_outcome_unknown',
        ])->saveQuietly();
        config(['services.microsoft_graph.contacts_sync_enabled' => true]);
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.repair', $contact->id))
            ->assertConflict();

        $this->assertSame('unknown', $contact->fresh()->outlook_sync_status);
        Queue::assertNothingPushed();
    }

    public function test_admin_can_confirm_unknown_create_reconciliation_before_queueing_a_new_create(): void
    {
        config([
            'services.microsoft_graph.contacts_sync_enabled' => true,
            'services.microsoft_graph.notification_mailbox_id' => 'notification-mailbox-id',
        ]);
        $admin = $this->admin();
        Queue::fake([SyncOutlookContact::class]);
        $contact = Contact::factory()->create();
        $contact->forceFill([
            'outlook_sync_status' => 'unknown',
            'outlook_sync_error' => 'outlook_contact_create_outcome_unknown',
        ])->saveQuietly();
        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.outlook-contacts.repair', $contact->id), [
                'manual_reconciliation_confirmed' => true,
            ])
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertSame('pending', $contact->fresh()->outlook_sync_status);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Contact::class,
            'subject_id' => $contact->id,
            'event' => 'outlook_contact_manual_reconciliation_confirmed',
        ]);
        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->id && ! $job->repair);
    }

    public function test_shared_header_exposes_sync_health_only_to_integration_admins(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)
            ->get(route('admin.home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can_view_sync_logs', true)
                ->has('sync_health.status'));

        $basicUser = User::factory()->create(['status' => 'active']);
        $this->actingAs($basicUser)
            ->get(route('calendar'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can_view_sync_logs', false)
                ->where('sync_health', null));
    }

    public function test_admin_pages_fail_closed_when_saved_quickbooks_tokens_use_another_encryption_key(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update(['quickbooks_sync_enabled' => true]);
        config(['services.quickbooks.environment' => 'sandbox']);
        Http::preventStrayRequests();
        $admin = $this->admin();
        $connection = IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-realm-123',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);
        $foreignEncrypter = new Encrypter(str_repeat('x', 32), 'AES-256-CBC');
        DB::table('integration_connections')->where('id', $connection->getKey())->update([
            'access_token' => $foreignEncrypter->encryptString('synthetic-access-token'),
            'refresh_token' => $foreignEncrypter->encryptString('synthetic-refresh-token'),
        ]);
        Customer::query()->create([
            'name' => 'Linked QuickBooks customer',
            'quickbooks_id' => '100',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.customers'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Customers')
                ->where('sync_health.status', 'warning')
                ->where('sync_health.quickBooks.connected', false));

        $this->unlockAdminOnboardingAreaForTest($admin);

        $this->get(route('admin.integrations.quickbooks.show'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/QuickBooksIntegration')
                ->where('connection.connected', false));

        try {
            app(QuickBooksApiClient::class)->customer('100');
            $this->fail('Unreadable saved OAuth tokens must require reauthorization.');
        } catch (QuickBooksApiException $exception) {
            $this->assertSame('reauthorization_required', $exception->reason);
            $this->assertSame(401, $exception->statusCode);
        }

        $this->assertModelExists($connection->fresh());
        Http::assertNothingSent();
    }

    public function test_basic_user_cannot_open_sync_logs_or_retry_a_failed_visit(): void
    {
        $job = $this->job();
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test scope.',
            'graph_sync_status' => 'failed',
        ]);
        $contact = Contact::factory()->create();
        $basicUser = User::factory()->create(['status' => 'active']);
        Queue::fake([SyncOutlookContact::class]);

        $this->actingAs($basicUser)
            ->get(route('admin.sync-logs'))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->postJson(route('admin.sync-logs.visits.retry', $visit))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->postJson(route('admin.sync-logs.outlook-contacts.resync', $contact->id))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->postJson(route('admin.sync-logs.outlook-contacts.repair', $contact->id))
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_failed_visit_retry_requires_graph_calendar_writes_to_be_enabled(): void
    {
        config(['services.microsoft_graph.calendar_writes_enabled' => false]);
        $admin = $this->admin();
        $visit = $this->job()->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test scope.',
            'graph_sync_status' => 'failed',
        ]);
        Queue::fake([SyncVisitMicrosoftGraphEvent::class]);

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.visits.retry', $visit))
            ->assertConflict();

        $this->assertSame('failed', $visit->fresh()->graph_sync_status);
        Queue::assertNothingPushed();
    }

    public function test_pending_calendar_sync_shows_worker_recovery_context_without_offering_a_duplicate_retry(): void
    {
        config([
            'cache.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database.queue' => 'default',
            'services.microsoft_graph.calendar_writes_enabled' => true,
        ]);
        $admin = $this->admin();
        $visit = $this->job()->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test scope.',
            'graph_sync_status' => 'pending',
        ]);
        $queuedAt = now()->timestamp;
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => SyncVisitMicrosoftGraphEvent::class], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $queuedAt,
            'created_at' => $queuedAt,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('visits.0.id', $visit->getKey())
                ->where('visits.0.status', 'pending')
                ->where('visits.0.canRetry', false)
                ->where('queueBacklog.readyJobs', 1)
                ->where('queueBacklog.worker.state', 'unknown'));
    }

    public function test_admin_can_retry_a_failed_unlinked_customer_create_in_the_sandbox(): void
    {
        config(['services.quickbooks.environment' => 'sandbox']);
        IntegrationConnection::query()->create([
            'provider' => 'quickbooks',
            'environment' => 'sandbox',
            'realm_id' => 'sandbox-sync-log-realm',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'connected_at' => now(),
        ]);
        $admin = $this->admin();
        $customer = Customer::create([
            'name' => 'Failed sandbox create',
            'quickbooks_sync_status' => 'failed',
            'quickbooks_sync_error' => 'quickbooks_unavailable',
        ]);
        Queue::fake([PushQuickBooksCustomer::class]);

        $this->actingAs($admin)
            ->get(route('admin.sync-logs'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/SyncLogs')
                ->where('quickBooks.0.canRetryCreate', true));

        $this->postJson(route('admin.sync-logs.customers.retry-create', $customer))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $customer->refresh();
        $this->assertNull($customer->quickbooks_id);
        $this->assertSame('pending', $customer->quickbooks_sync_status);
        $this->assertNotNull($customer->quickbooks_write_request_id);
        Queue::assertPushed(PushQuickBooksCustomer::class, fn (PushQuickBooksCustomer $job): bool => $job->customerId === $customer->id
            && $job->requestId === $customer->quickbooks_write_request_id);
    }

    public function test_failed_visit_can_be_retried_after_graph_calendar_writes_are_enabled(): void
    {
        config(['services.microsoft_graph.calendar_writes_enabled' => true]);
        $admin = $this->admin();
        $visit = $this->job()->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test scope.',
            'graph_sync_status' => 'failed',
            'graph_sync_error' => 'graph_forbidden',
        ]);
        Queue::fake([SyncVisitMicrosoftGraphEvent::class]);

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.visits.retry', $visit))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');

        $this->assertSame('pending', $visit->fresh()->graph_sync_status);
        $this->assertNull($visit->fresh()->graph_sync_error);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => $visit->getMorphClass(),
            'subject_id' => $visit->id,
            'event' => 'graph_visit_retry_requested',
        ]);
        Queue::assertPushed(SyncVisitMicrosoftGraphEvent::class, fn (SyncVisitMicrosoftGraphEvent $job): bool => $job->visitId === $visit->id);
    }

    public function test_only_known_failed_graph_notifications_can_be_retried(): void
    {
        config(['services.microsoft_graph.writes_enabled' => true]);
        $admin = $this->admin();
        $job = $this->job();
        $failed = $this->outbox($job, 'failed');
        $unknown = $this->outbox($job, 'unknown');
        Queue::fake([SendGraphNotification::class]);

        $this->actingAs($admin)
            ->postJson(route('admin.sync-logs.notifications.retry', $failed))
            ->assertAccepted()
            ->assertJsonPath('status', 'pending');
        $this->postJson(route('admin.sync-logs.notifications.retry', $unknown))
            ->assertConflict();

        $this->assertSame('pending', $failed->fresh()->status);
        $this->assertSame('unknown', $unknown->fresh()->status);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->id,
            'event' => 'graph_notification_retry_requested',
        ]);
        Queue::assertPushed(SendGraphNotification::class, fn (SendGraphNotification $send): bool => $send->outboxId === $failed->id);
        Queue::assertNotPushed(SendGraphNotification::class, fn (SendGraphNotification $send): bool => $send->outboxId === $unknown->id);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Admin Sync Tester',
            'status' => 'active',
        ]);
    }

    private function job(): WorkOrder
    {
        $customer = Customer::create(['name' => 'Sync action customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Sync action site']);

        return WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J-SYNC-1',
            'title' => 'Sync action job',
            'scope' => 'Check the sync action.',
            'status' => 'scheduled',
        ]);
    }

    private function outbox(WorkOrder $job, string $status): GraphNotificationOutbox
    {
        return GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $job->id,
            'type' => 'status_update',
            'recipient_email' => 'client@example.test',
            'event_key' => (string) Str::uuid(),
            'status' => $status,
            'available_at' => now(),
            'last_error' => $status === 'failed' ? 'graph_forbidden' : 'send_state_unknown',
        ]);
    }
}
