<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Jobs\BuildWorkOrderCompletionReport;
use App\Jobs\PollVisitMicrosoftGraphRsvps;
use App\Jobs\SendGraphNotification;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GraphNotificationOutbox;
use App\Models\Item;
use App\Models\JobItem;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\AttachmentListService;
use App\Services\GraphNotificationService;
use App\Services\MicrosoftGraphApiClient;
use App\Services\MicrosoftGraphCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MicrosoftGraphWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_visit_calendar_event_includes_assigned_staff_and_linked_client_contact(): void
    {
        $this->configureGraphWrites();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/fieldops%40example.test/events' => Http::response(['id' => 'event-1'], 201),
        ]);

        [$workOrder, $visit, $technician, $contact] = $this->workOrderWithVisit();
        app(MicrosoftGraphCalendarService::class)->syncVisit($visit);

        $visit->refresh();
        $this->assertSame('event-1', $visit->microsoft_graph_event_id);
        $this->assertSame('synced', $visit->graph_sync_status);
        $this->assertNotEmpty($visit->microsoft_graph_transaction_id);
        $this->assertSame('tenant-test-1', $visit->microsoft_graph_event_tenant_id);
        $this->assertSame('fieldops@example.test', $visit->microsoft_graph_event_mailbox_id);
        $this->assertNull($visit->microsoft_graph_event_calendar_id);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/users/fieldops%40example.test/events')
            && $request['transactionId'] === $visit->microsoft_graph_transaction_id
            && collect($request['attendees'])->pluck('emailAddress.address')->contains($technician->email)
            && collect($request['attendees'])->pluck('emailAddress.address')->contains($contact->email)
            && $request['body']['contentType'] === 'text'
            && str_contains($request['body']['content'], 'Visit scope:'));
    }

    public function test_visit_event_updates_existing_graph_event_instead_of_creating_a_duplicate(): void
    {
        $this->configureGraphWrites();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/fieldops%40example.test/events/event-1' => Http::response([], 200),
        ]);

        [, $visit] = $this->workOrderWithVisit();
        $visit->update([
            'microsoft_graph_event_id' => 'event-1',
            'microsoft_graph_transaction_id' => (string) Str::uuid(),
            'microsoft_graph_event_tenant_id' => 'tenant-test-1',
            'microsoft_graph_event_mailbox_id' => 'fieldops@example.test',
        ]);

        app(MicrosoftGraphCalendarService::class)->syncVisit($visit->fresh());

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PATCH'
            && str_contains($request->url(), '/events/event-1')
            && ! isset($request['transactionId']));
    }

    public function test_rsvp_poll_is_blocked_when_the_visit_event_owner_is_unverified(): void
    {
        $this->configureGraphWrites();
        Http::preventStrayRequests();
        [, $visit] = $this->workOrderWithVisit();
        $visit->update([
            'microsoft_graph_event_id' => 'unverified-event-id',
            'graph_sync_status' => 'synced',
        ]);

        try {
            app(MicrosoftGraphCalendarService::class)->pollResponses($visit->fresh());
            $this->fail('RSVP polling must not address an event without verified mailbox ownership.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_visit_event_target_unverified', $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_visit_event_update_is_blocked_when_its_owner_snapshot_differs_from_the_current_target(): void
    {
        $this->configureGraphWrites();
        Http::preventStrayRequests();
        [, $visit] = $this->workOrderWithVisit();
        $visit->update([
            'microsoft_graph_event_id' => 'unverified-event-id',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_tenant_id' => 'tenant-test-1',
            'microsoft_graph_event_mailbox_id' => 'previous-mailbox@example.test',
        ]);

        try {
            app(MicrosoftGraphCalendarService::class)->syncVisit($visit->fresh());
            $this->fail('An event without a verified mailbox owner must not be updated through Graph.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_visit_event_target_unverified', $exception->reason);
        }

        $this->assertSame('unverified-event-id', $visit->fresh()->microsoft_graph_event_id);
        $this->assertSame('synced', $visit->fresh()->graph_sync_status);
        Http::assertNothingSent();
    }

    public function test_visit_calendar_writer_targets_the_notification_mailbox_not_the_group_calendar(): void
    {
        $this->configureGraphWrites();
        config([
            'services.microsoft_graph.calendar_writes_enabled' => true,
            'services.microsoft_graph.notification_mailbox_id' => 'smtp@example.test',
            'services.microsoft_graph.notification_mailbox_address' => 'smtp@example.test',
            'services.microsoft_graph.work_schedules.group_id' => 'group-test-id',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/smtp%40example.test/events' => Http::response(['id' => 'smtp-event-1'], 201),
        ]);
        [, $visit] = $this->workOrderWithVisit();

        app(MicrosoftGraphCalendarService::class)->syncVisit($visit);

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/users/smtp%40example.test/events')
            && ! str_contains($request->url(), '/groups/group-test-id/'));
        $this->assertSame('smtp-event-1', $visit->fresh()->microsoft_graph_event_id);
    }

    public function test_status_notifications_go_to_client_and_management_but_not_technicians(): void
    {
        config(['services.microsoft_graph.writes_enabled' => false]);
        [$workOrder, , $technician] = $this->workOrderWithVisit();
        $workOrder->update([
            'job_notes' => 'Internal note must not be emailed.',
            'field_notes' => 'Client-facing field note.',
        ]);
        $manager = User::factory()->create(['email' => 'manager@example.test', 'status' => 'active']);
        $manager->assignRole('management');

        app(GraphNotificationService::class)->statusChanged($workOrder, 'scheduled', 'in_progress');

        $outbox = GraphNotificationOutbox::query()->get();
        $this->assertCount(2, $outbox);
        $this->assertEqualsCanonicalizing(
            ['client@example.test', 'manager@example.test'],
            $outbox->pluck('recipient_email')->all(),
        );
        $this->assertNotContains($technician->email, $outbox->pluck('recipient_email')->all());
        $this->assertSame(['pending_configuration'], $outbox->pluck('status')->unique()->all());
        $this->assertSame(['Client-facing field note.'], $outbox->pluck('payload.field_notes')->unique()->all());
        $this->assertFalse($outbox->contains(fn (GraphNotificationOutbox $record): bool => array_key_exists('job_notes', $record->payload)));
    }

    public function test_client_status_email_includes_field_notes_and_excludes_internal_job_notes(): void
    {
        $this->configureGraphWrites();
        config([
            'services.microsoft_graph.notification_mailbox_id' => 'smtp@example.test',
            'services.microsoft_graph.notification_mailbox_address' => 'smtp@example.test',
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/smtp%40example.test/sendMail' => Http::response([], 202),
        ]);

        [$workOrder] = $this->workOrderWithVisit();
        $workOrder->update([
            'job_notes' => 'Internal note must not be emailed.',
            'field_notes' => 'Client-facing field note.',
        ]);
        $outbox = GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $workOrder->id,
            'type' => 'status_update',
            'recipient_email' => 'client@example.test',
            'event_key' => (string) Str::uuid(),
            'status' => 'pending',
            'available_at' => now(),
            'payload' => [
                'new_status' => 'in_progress',
                'field_notes' => $workOrder->field_notes,
            ],
        ]);

        (new SendGraphNotification($outbox->id))->handle(
            app(MicrosoftGraphApiClient::class),
            app(GraphNotificationService::class),
        );

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/users/smtp%40example.test/sendMail')
            && str_contains($request['message']['body']['content'], 'Client-facing field note.')
            && ! str_contains($request['message']['body']['content'], 'Internal note must not be emailed.'));
    }

    public function test_completion_report_is_stored_on_the_initial_visit_and_billing_receives_only_the_pdf(): void
    {
        $this->travelTo('2026-10-02 12:00:00');
        Storage::fake('local');
        $client = Customer::create(['name' => 'Report Customer']);
        $site = Site::create(['customer_id' => $client->id, 'name' => 'Report Site']);
        $contact = Contact::create([
            'customer_id' => $client->id,
            'contact_type' => 'primary',
            'first_name' => 'Client',
            'email' => 'client@example.test',
        ]);
        $technician = User::factory()->create(['status' => 'active']);
        $billing = User::factory()->create(['email' => 'billing@example.test', 'status' => 'active']);
        $billing->assignRole('billing');
        $workOrder = WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J20001',
            'title' => 'Completed report job',
            'scope' => 'Inspect and repair the equipment.',
            'client_completion_summary' => 'The system has been repaired and tested.',
            'client_contact_id' => $contact->id,
            'client_contact_email' => $contact->email,
            'job_notes' => 'Internal diagnostic notes.',
            'field_notes' => 'Technician verified operation.',
            'status' => 'completed',
            'completed_at' => now(),
            'completed_by' => $technician->id,
        ]);
        $workOrder->assignedUsers()->attach($technician->id);
        $visit = $workOrder->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->subHour(),
            'scope' => 'Complete the initial inspection.',
            'notes' => 'Visit-specific technical note.',
        ]);
        $followUpVisit = $workOrder->visits()->create([
            'visit_type' => 'revisit',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Recheck the repaired equipment.',
            'notes' => 'Follow-up visit notes for the client report.',
        ]);
        $visit->assignedUsers()->attach($technician->id);
        $followUpVisit->assignedUsers()->attach($technician->id);
        $item = Item::query()->create([
            'quickbooks_realm_id' => 'report-realm',
            'quickbooks_id' => 'ITEM-77',
            'name' => 'Synthetic replacement filter',
            'description' => 'Replace the filter element.',
            'type' => Item::TYPE_NON_INVENTORY,
            'active' => true,
        ]);
        JobItem::query()->create([
            'visit_id' => $visit->id,
            'item_id' => $item->id,
            'quickbooks_item_id' => $item->quickbooks_id,
            'item_name_snapshot' => $item->name,
            'item_description_snapshot' => $item->description,
            'quantity' => '2.500',
            'added_by_user_id' => $technician->id,
        ]);
        Storage::disk('local')->put('work-orders/documents/customer-file.pdf', 'supporting document');
        $workOrder->attachments()->create([
            'uploaded_by' => $technician->id,
            'kind' => 'documents',
            'original_name' => 'Customer supporting file.pdf',
            'disk' => 'local',
            'path' => 'work-orders/documents/customer-file.pdf',
            'mime_type' => 'application/pdf',
            'size' => 19,
        ]);

        (new BuildWorkOrderCompletionReport($workOrder->id))->handle(app(GraphNotificationService::class));

        $report = WorkOrderAttachment::query()
            ->where('work_order_id', $workOrder->id)
            ->where('original_name', 'like', 'Completion Report -%')
            ->sole();
        $billingReport = WorkOrderAttachment::query()
            ->where('work_order_id', $workOrder->id)
            ->where('original_name', 'like', 'Billing Job Report -%')
            ->sole();
        $this->assertSame($visit->id, $report->visit_id);
        $this->assertSame($visit->id, $billingReport->visit_id);
        Storage::disk('local')->assertExists($report->path);
        Storage::disk('local')->assertExists($billingReport->path);

        $clientDelivery = GraphNotificationOutbox::query()->where('type', 'completion_report')->sole();
        $billingDelivery = GraphNotificationOutbox::query()->where('type', 'billing_ready')->sole();
        $this->assertContains($report->id, $clientDelivery->payload['attachment_ids']);
        $this->assertNotContains($billingReport->id, $clientDelivery->payload['attachment_ids']);
        $this->assertCount(2, $clientDelivery->payload['attachment_ids']);
        $this->assertSame([$billingReport->id], $billingDelivery->payload['attachment_ids']);
        $calendarAttachments = app(AttachmentListService::class)->forCalendarJob($workOrder);
        $this->assertFalse(collect($calendarAttachments)->contains('id', $billingReport->id));

        $clientReportHtml = view('exports.work-order', [
            'workOrder' => $workOrder->load('site.customer', 'visits.assignedUsers', 'attachments'),
            'completionReport' => true,
        ])->render();

        $this->assertStringContainsString('Technician verified operation.', $clientReportHtml);
        $this->assertStringNotContainsString('Internal diagnostic notes.', $clientReportHtml);
        $firstVisitNotePosition = strpos($clientReportHtml, 'Visit-specific technical note.');
        $followUpVisitNotePosition = strpos($clientReportHtml, 'Follow-up visit notes for the client report.');
        $this->assertIsInt($firstVisitNotePosition);
        $this->assertIsInt($followUpVisitNotePosition);
        $this->assertLessThan($followUpVisitNotePosition, $firstVisitNotePosition);
        $this->assertStringContainsString('<h3>October 2, 2026</h3>', $clientReportHtml);
        $this->assertStringContainsString('<h3>October 3, 2026</h3>', $clientReportHtml);
        $this->assertStringNotContainsString('Synthetic replacement filter', $clientReportHtml);
        $this->assertStringNotContainsString('Replace the filter element.', $clientReportHtml);

        $billingReportHtml = view('exports.billing-work-order', [
            'workOrder' => $workOrder->load('site.customer', 'visits.jobItems.item'),
        ])->render();
        $this->assertStringContainsString('Synthetic replacement filter', $billingReportHtml);
        $this->assertStringContainsString('Replace the filter element.', $billingReportHtml);
        $this->assertStringContainsString('QuickBooks Item ITEM-77', $billingReportHtml);
        $this->assertStringContainsString('2.500', $billingReportHtml);
        $this->assertStringNotContainsString('UnitPrice', $billingReportHtml);
        $this->assertStringNotContainsString('PurchaseCost', $billingReportHtml);
    }

    public function test_sendmail_acceptance_is_recorded_without_a_self_copy_and_with_the_full_attachment(): void
    {
        $this->configureGraphWrites();
        config([
            'services.microsoft_graph.notification_mailbox_id' => 'smtp@example.test',
            'services.microsoft_graph.notification_mailbox_address' => 'smtp@example.test',
        ]);
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/smtp%40example.test/sendMail' => Http::response([], 202),
        ]);

        [$workOrder, , $technician] = $this->workOrderWithVisit();
        $billing = User::factory()->create(['email' => 'billing@example.test', 'status' => 'active']);
        $billing->assignRole('billing');
        Storage::disk('local')->put('reports/report.pdf', 'complete pdf bytes');
        $attachment = $workOrder->attachments()->create([
            'uploaded_by' => $technician->id,
            'kind' => 'documents',
            'original_name' => 'Completion Report.pdf',
            'disk' => 'local',
            'path' => 'reports/report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 18,
        ]);
        $outbox = GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $workOrder->id,
            'type' => 'billing_ready',
            'recipient_email' => $billing->email,
            'recipient_role' => 'billing',
            'event_key' => (string) Str::uuid(),
            'status' => 'pending',
            'available_at' => now(),
            'payload' => ['attachment_ids' => [$attachment->id]],
        ]);

        (new SendGraphNotification($outbox->id))->handle(
            app(MicrosoftGraphApiClient::class),
            app(GraphNotificationService::class),
        );

        $this->assertSame('accepted', $outbox->fresh()->status);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/users/smtp%40example.test/sendMail')
            && $request['message']['toRecipients'][0]['emailAddress']['address'] === $billing->email
            && ! isset($request['message']['ccRecipients'])
            && ! isset($request['message']['bccRecipients'])
            && $request['message']['attachments'][0]['name'] === 'Completion Report.pdf'
            && $request['saveToSentItems'] === false);
    }

    public function test_oversized_completion_bundle_is_held_without_sending_a_partial_email(): void
    {
        $this->configureGraphWrites();
        config(['services.microsoft_graph.max_email_bytes' => 20_000]);
        Storage::fake('local');
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
        ]);

        [$workOrder, , $technician] = $this->workOrderWithVisit();
        $manager = User::factory()->create(['email' => 'manager@example.test', 'status' => 'active']);
        $manager->assignRole('management');
        Storage::disk('local')->put('reports/large.pdf', 'placeholder');
        $attachment = $workOrder->attachments()->create([
            'uploaded_by' => $technician->id,
            'kind' => 'documents',
            'original_name' => 'large.pdf',
            'disk' => 'local',
            'path' => 'reports/large.pdf',
            'mime_type' => 'application/pdf',
            'size' => 25_000,
        ]);
        $outbox = GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $workOrder->id,
            'type' => 'completion_report',
            'recipient_email' => 'client@example.test',
            'event_key' => (string) Str::uuid(),
            'status' => 'pending',
            'available_at' => now(),
            'payload' => ['attachment_ids' => [$attachment->id]],
        ]);

        (new SendGraphNotification($outbox->id))->handle(
            app(MicrosoftGraphApiClient::class),
            app(GraphNotificationService::class),
        );

        $this->assertSame('blocked_size', $outbox->fresh()->status);
        $this->assertDatabaseHas('graph_notification_outbox', [
            'work_order_id' => $workOrder->id,
            'type' => 'delivery_blocked',
            'recipient_email' => $manager->email,
        ]);
        Http::assertNothingSent();
    }

    public function test_rsvp_decline_or_24_hour_timeout_alerts_management_without_cancelling_the_visit(): void
    {
        $this->configureGraphWrites();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/fieldops%40example.test/events/event-1*' => Http::response([
                'id' => 'event-1',
                'attendees' => [
                    ['emailAddress' => ['address' => 'client@example.test'], 'status' => ['response' => 'notResponded']],
                    ['emailAddress' => ['address' => 'tech@example.test'], 'status' => ['response' => 'accepted']],
                ],
            ]),
        ]);

        [$workOrder, $visit] = $this->workOrderWithVisit();
        $manager = User::factory()->create(['email' => 'manager@example.test', 'status' => 'active']);
        $manager->assignRole('management');
        $visit->update([
            'microsoft_graph_event_id' => 'event-1',
            'microsoft_graph_transaction_id' => (string) Str::uuid(),
            'graph_sync_status' => 'synced',
            'graph_synced_at' => now()->subHours(25),
            'microsoft_graph_event_tenant_id' => 'tenant-test-1',
            'microsoft_graph_event_mailbox_id' => 'fieldops@example.test',
        ]);

        (new PollVisitMicrosoftGraphRsvps($visit->id))->handle(
            app(MicrosoftGraphCalendarService::class),
            app(GraphNotificationService::class),
        );

        $this->assertSame('notResponded', $visit->fresh()->attendee_responses['client@example.test']);
        $this->assertNotNull($visit->fresh()->rsvp_attention_notified_at);
        $this->assertSame('scheduled', $workOrder->fresh()->status);
        $this->assertDatabaseHas('graph_notification_outbox', [
            'visit_id' => $visit->id,
            'type' => 'rsvp_attention',
            'recipient_email' => $manager->email,
        ]);
    }

    /** @return array{WorkOrder, Visit, User, Contact} */
    private function workOrderWithVisit(): array
    {
        $customer = Customer::create(['name' => 'Graph Customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Graph Site', 'address' => '100 Test Street']);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'contact_type' => 'primary',
            'first_name' => 'Client',
            'email' => 'client@example.test',
        ]);
        $technician = User::factory()->create(['email' => 'tech@example.test', 'status' => 'active']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J20001',
            'title' => 'Graph workflow visit',
            'scope' => 'Perform the agreed maintenance.',
            'client_contact_id' => $contact->id,
            'client_contact_email' => $contact->email,
            'status' => 'scheduled',
        ]);
        $workOrder->assignedUsers()->attach($technician->id);
        $visit = $workOrder->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 90,
            'scope' => 'Inspect the equipment and complete the maintenance.',
        ]);
        $visit->assignedUsers()->attach($technician->id);

        return [$workOrder, $visit, $technician, $contact];
    }

    private function configureGraphWrites(): void
    {
        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
        Cache::flush();
        config(['services.microsoft_graph' => [
            'writes_enabled' => true,
            'calendar_writes_enabled' => true,
            'tenant_id' => 'tenant-test-1',
            'notification_mailbox_id' => 'fieldops@example.test',
            'notification_mailbox_address' => 'fieldops@example.test',
            'notification_calendar_id' => null,
            'work_schedules' => ['group_id' => null],
            'max_email_bytes' => 2621440,
        ]]);
        $this->fakeMicrosoftGraphBwsCredentials();
    }
}
