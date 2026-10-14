<?php

namespace Tests\Feature;

use App\Enums\TimeEntryActivityType;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Notifications\AttachmentLifecycleEmailNotification;
use App\Notifications\AttachmentLifecycleNotification;
use App\Notifications\BusinessAssignmentChanged;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_user_can_reschedule_visit_and_change_duration(): void
    {
        $user = User::factory()->create();
        $visit = $this->createAssignedVisit($user, 120);

        $response = $this->actingAs($user)
            ->from('/calendar')
            ->put(route('visits.schedule', $visit), [
                'scheduled_at' => '2026-09-26T23:00',
                'duration_minutes' => 180,
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/calendar');
        $visit->refresh();
        $this->assertSame('2026-09-26 23:00:00', $visit->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(180, $visit->duration_minutes);
    }

    public function test_reschedule_persists_timezone_qualified_timestamp_as_utc(): void
    {
        $user = User::factory()->create();
        $visit = $this->createAssignedVisit($user, 120);

        $response = $this->actingAs($user)
            ->from('/calendar')
            ->put(route('visits.schedule', $visit), [
                'scheduled_at' => '2026-09-28T04:30:00.000Z',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/calendar');
        $visit->refresh();
        $this->assertSame('2026-09-28 04:30:00', $visit->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_reschedule_without_duration_preserves_visit_duration(): void
    {
        $user = User::factory()->create();
        $visit = $this->createAssignedVisit($user, 120);

        $response = $this->actingAs($user)
            ->from('/calendar')
            ->put(route('visits.schedule', $visit), [
                'scheduled_at' => '2026-09-27T09:00',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect('/calendar');
        $visit->refresh();
        $this->assertSame('2026-09-27 09:00:00', $visit->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $visit->duration_minutes);
    }

    public function test_rejects_duration_over_one_day_without_changing_visit(): void
    {
        $user = User::factory()->create();
        $visit = $this->createAssignedVisit($user, 120);

        $response = $this->actingAs($user)
            ->from('/calendar')
            ->put(route('visits.schedule', $visit), [
                'scheduled_at' => '2026-09-27T09:00',
                'duration_minutes' => 1441,
            ]);

        $response
            ->assertSessionHasErrors([
                'duration_minutes' => 'The duration minutes field must not be greater than 1440.',
            ])
            ->assertRedirect('/calendar');

        $visit->refresh();
        $this->assertSame('2026-09-26 08:00:00', $visit->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $visit->duration_minutes);
    }

    public function test_unauthenticated_user_cannot_reschedule_visit(): void
    {
        $user = User::factory()->create();
        $visit = $this->createAssignedVisit($user, 120);

        $this->put(route('visits.schedule', $visit), [
            'scheduled_at' => '2026-09-27T09:00',
            'duration_minutes' => 180,
        ])->assertRedirect(route('login'));

        $visit->refresh();
        $this->assertSame('2026-09-26 08:00:00', $visit->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $visit->duration_minutes);
    }

    public function test_unassigned_user_cannot_reschedule_visit(): void
    {
        $assignedUser = User::factory()->create();
        $otherUser = User::factory()->create();
        $visit = $this->createAssignedVisit($assignedUser, 120);

        $response = $this->actingAs($otherUser)->put(
            route('visits.schedule', $visit),
            [
                'scheduled_at' => '2026-09-27T09:00',
                'duration_minutes' => 180,
            ],
        );

        $response->assertForbidden();
        $visit->refresh();
        $this->assertSame('2026-09-26 08:00:00', $visit->scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(120, $visit->duration_minutes);
    }

    public function test_new_job_requires_scope_and_copies_it_to_the_initial_visit(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $user = User::factory()->create(['status' => 'active']);
        $customer = Customer::create(['name' => 'Scope Customer']);
        $site = $customer->sites()->create(['name' => 'Scope Site']);
        $payload = [
            'title' => 'Scope-required job',
            'status' => 'scheduled',
            'scheduled_at' => now()->addDays(3)->toDateTimeString(),
            'customer_id' => $customer->id,
            'site_id' => $site->id,
        ];

        $this->actingAs($user)
            ->from('/calendar')
            ->post(route('work-orders.store'), $payload)
            ->assertSessionHasErrors('scope');

        $this->assertDatabaseCount('work_orders', 0);

        $this->actingAs($user)
            ->post(route('work-orders.store'), $payload + ['scope' => 'Inspect and repair the customer equipment.'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('calendar'));

        $workOrder = WorkOrder::query()->where('title', 'Scope-required job')->sole();
        $visit = $workOrder->visits()->sole();
        $this->assertSame('Inspect and repair the customer equipment.', $workOrder->scope);
        $this->assertSame($workOrder->scope, $visit->scope);
        $this->assertSame('initial', $visit->visit_type);
        $this->assertSame('pending', $visit->graph_sync_status);
        Notification::assertSentTo($user, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
        Notification::assertSentTo($admin, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
    }

    public function test_created_job_can_exist_without_a_visit_then_schedule_with_client_and_staff_emails(): void
    {
        $user = User::factory()->create(['status' => 'active', 'email' => 'tech@example.test']);
        $customer = Customer::create(['name' => 'Unscheduled customer']);
        $site = $customer->sites()->create(['name' => 'Unscheduled site']);

        $this->actingAs($user)
            ->post(route('work-orders.store'), [
                'title' => 'Unscheduled work',
                'scope' => 'Inspect the equipment.',
                'status' => 'created',
                'client_contact_email' => 'client@example.test',
                'customer_id' => $customer->id,
                'site_id' => $site->id,
            ])
            ->assertSessionHasNoErrors();

        $workOrder = WorkOrder::query()->where('title', 'Unscheduled work')->sole();
        $this->assertSame('created', $workOrder->status);
        $this->assertDatabaseCount('visits', 0);
        $this->assertDatabaseHas('work_order_status_history', [
            'work_order_id' => $workOrder->id,
            'from_status' => null,
            'to_status' => 'created',
        ]);
        $this->assertDatabaseHas('graph_notification_outbox', [
            'work_order_id' => $workOrder->id,
            'type' => 'job_created',
            'recipient_email' => 'client@example.test',
        ]);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $workOrder), [
                'status' => 'scheduled',
                'scheduled_at' => now()->addDays(2)->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('scheduled', $workOrder->fresh()->status);
        $this->assertDatabaseCount('visits', 1);
        $this->assertDatabaseHas('graph_notification_outbox', [
            'work_order_id' => $workOrder->id,
            'type' => 'job_scheduled',
            'recipient_email' => 'client@example.test',
        ]);
        $this->assertDatabaseHas('graph_notification_outbox', [
            'work_order_id' => $workOrder->id,
            'type' => 'job_scheduled',
            'recipient_email' => 'tech@example.test',
        ]);
    }

    public function test_new_job_form_upload_saves_the_attachment_to_sharepoint_with_job_context(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $customer = Customer::query()->create(['name' => 'New upload customer']);
        $site = $customer->sites()->create(['name' => 'New upload site']);
        $this->mockSharePointUpload();
        Notification::fake();

        $this->actingAs($user)
            ->post(route('work-orders.store'), [
                'title' => 'New upload job',
                'scope' => 'Inspect the equipment.',
                'status' => 'scheduled',
                'scheduled_at' => now()->addDays(3)->toDateTimeString(),
                'customer_id' => $customer->getKey(),
                'site_id' => $site->getKey(),
                'files' => [$this->powerPointFile()],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('calendar'));

        $workOrder = WorkOrder::query()->where('title', 'New upload job')->sole();
        $attachment = SharePointAttachment::query()->sole();
        $this->assertSame($customer->getKey(), $attachment->company_id);
        $this->assertSame($site->getKey(), $attachment->site_id);
        $this->assertSame($workOrder->getKey(), $attachment->job_id);
        $this->assertNull($attachment->project_id);
        $this->assertSame($user->getKey(), $attachment->uploaded_by);
        $this->assertSame('job-start.pptx', $attachment->filename);
        $this->assertSame(0, WorkOrderAttachment::query()->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $workOrder->getKey(),
            'event' => 'attachment_uploaded',
        ]);
        Notification::assertSentTo($user, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_uploaded');
        Notification::assertSentTo($user, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_uploaded');

        $this->getJson(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertJsonPath('data.attachments.0.office_app', 'powerpoint')
            ->assertJsonPath('data.attachments.0.online_url', route('attachments.open', $attachment));
    }

    public function test_dedicated_job_upload_accepts_powerpoint_files_for_microsoft_365_open(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $workOrder = $visit->workOrder;
        $this->mockSharePointUpload();
        Notification::fake();

        $this->actingAs($user)
            ->from('/calendar')
            ->post(route('work-orders.attachments.store', $workOrder), [
                'files' => [$this->powerPointFile()],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/calendar');

        $attachment = SharePointAttachment::query()->sole();
        $this->assertSame('job-start.pptx', $attachment->filename);
        $this->assertSame($workOrder->getKey(), $attachment->job_id);
        $this->assertSame(0, WorkOrderAttachment::query()->count());
        Notification::assertSentTo($user, AttachmentLifecycleNotification::class, fn (AttachmentLifecycleNotification $notification): bool => $notification->event === 'attachment_uploaded');
        Notification::assertSentTo($user, AttachmentLifecycleEmailNotification::class, fn (AttachmentLifecycleEmailNotification $notification): bool => $notification->event === 'attachment_uploaded');
    }

    public function test_revisit_inherits_job_scope_and_each_visit_scope_can_be_edited_as_a_snapshot(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $visit->workOrder->update(['scope' => 'Original job scope']);
        $visit->update(['visit_type' => 'initial', 'scope' => 'Initial snapshot']);

        $this->actingAs($user)
            ->post(route('work-orders.revisits.store', $visit->workOrder), [
                'scheduled_at' => '2026-10-12 10:00:00',
                'notes' => 'Follow-up visit',
            ])
            ->assertSessionHasNoErrors();

        $revisit = $visit->workOrder->visits()->where('visit_type', 'revisit')->sole();
        $this->assertSame('Original job scope', $revisit->scope);

        $this->actingAs($user)
            ->patch(route('visits.scope.update', $revisit), ['scope' => 'Revisit-specific scope'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Revisit-specific scope', $revisit->fresh()->scope);
        $this->assertSame('Original job scope', $visit->workOrder->fresh()->scope);
    }

    public function test_completing_a_job_requires_a_client_summary_and_records_the_completing_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);

        $this->actingAs($user)
            ->from('/calendar')
            ->patch(route('work-orders.status.update', $visit->workOrder), ['status' => 'completed'])
            ->assertSessionHasErrors('client_completion_summary');

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), [
                'status' => 'completed',
                'client_completion_summary' => 'The issue has been repaired and operation verified.',
            ])
            ->assertSessionHasNoErrors();

        $workOrder = $visit->workOrder->fresh();
        $this->assertSame('completed', $workOrder->status);
        $this->assertSame($user->id, $workOrder->completed_by);
        $this->assertSame('The issue has been repaired and operation verified.', $workOrder->client_completion_summary);
        $this->assertNotNull($workOrder->completed_at);
    }

    public function test_on_the_way_automatically_starts_a_travel_timer_for_the_assigned_user(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), [
                'status' => 'on_my_way',
                'timer_visit_id' => $visit->id,
            ])
            ->assertSessionHasNoErrors();

        $timer = TimeEntry::query()->where('user_id', $user->id)->whereNull('ended_at')->sole();
        $this->assertSame(TimeEntryActivityType::Travel, $timer->activity_type);
        $this->assertSame($visit->workOrder->id, $timer->job_id);
        $this->assertSame($visit->id, $timer->visit_id);
    }

    public function test_in_progress_stops_this_jobs_travel_timer_and_starts_labor(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $travelTimer = $this->activeTimer($user, $visit, TimeEntryActivityType::Travel);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), [
                'status' => 'in_progress',
                'timer_visit_id' => $visit->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($travelTimer->fresh()->ended_at);
        $laborTimer = TimeEntry::query()->where('user_id', $user->id)->whereNull('ended_at')->sole();
        $this->assertSame(TimeEntryActivityType::Labor, $laborTimer->activity_type);
        $this->assertSame($visit->workOrder->id, $laborTimer->job_id);
        $this->assertSame($visit->id, $laborTimer->visit_id);
    }

    public function test_in_progress_rejects_a_labor_start_when_another_job_timer_is_active(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $targetVisit = $this->createAssignedVisit($user, 60);
        $activeVisit = $this->createAssignedVisit($user, 60);
        $activeTimer = $this->activeTimer($user, $activeVisit, TimeEntryActivityType::Labor);

        $this->actingAs($user)
            ->from('/calendar')
            ->patch(route('work-orders.status.update', $targetVisit->workOrder), [
                'status' => 'in_progress',
                'timer_visit_id' => $targetVisit->id,
            ])
            ->assertSessionHasErrors('timer_start_conflict');

        $this->assertSame('pending', $targetVisit->workOrder->fresh()->status);
        $this->assertNull($activeTimer->fresh()->ended_at);
    }

    public function test_full_job_edit_applies_the_same_status_timer_automation(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $travelTimer = $this->activeTimer($user, $visit, TimeEntryActivityType::Travel);

        $this->actingAs($user)
            ->put(route('work-orders.update', $visit->workOrder), [
                'title' => $visit->workOrder->title,
                'subtitle' => null,
                'description' => null,
                'scope' => 'Work scope.',
                'client_completion_summary' => null,
                'client_contact_name' => null,
                'client_contact_phone' => null,
                'client_contact_email' => null,
                'client_contact_id' => null,
                'technical_contact_name' => null,
                'technical_contact_phone' => null,
                'technical_contact_email' => null,
                'technical_contact_id' => null,
                'project_manager_name' => null,
                'project_manager_phone' => null,
                'project_manager_email' => null,
                'project_manager_contact_id' => null,
                'contractor_check_in_name' => null,
                'contractor_check_in_phone' => null,
                'contractor_check_in_email' => null,
                'contractor_check_in_contact_id' => null,
                'job_notes' => null,
                'field_notes' => null,
                'status' => 'in_progress',
                'timer_visit_id' => $visit->id,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'scheduling_override' => false,
                'site_id' => $visit->workOrder->site_id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($travelTimer->fresh()->ended_at);
        $laborTimer = TimeEntry::query()->where('user_id', $user->id)->whereNull('ended_at')->sole();
        $this->assertSame(TimeEntryActivityType::Labor, $laborTimer->activity_type);
        $this->assertSame($visit->id, $laborTimer->visit_id);
    }

    public function test_pending_does_not_stop_the_actors_timer_for_a_different_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $targetVisit = $this->createAssignedVisit($user, 60);
        $targetVisit->workOrder->update(['status' => 'in_progress']);
        $timerVisit = $this->createAssignedVisit($user, 60);
        $timer = $this->activeTimer($user, $timerVisit, TimeEntryActivityType::Labor);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $targetVisit->workOrder), [
                'status' => 'pending',
                'pending_reason' => 'Waiting for the other technician to finish the prerequisite work.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($timer->fresh()->ended_at);
    }

    public function test_pending_stops_the_actors_active_timer_for_the_changed_job(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $visit->workOrder->update(['status' => 'in_progress']);
        $timer = $this->activeTimer($user, $visit, TimeEntryActivityType::Labor);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), ['status' => 'pending'])
            ->assertSessionHasErrors('pending_reason');

        $this->assertSame('in_progress', $visit->workOrder->fresh()->status);
        $this->assertNull($timer->fresh()->ended_at);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), [
                'status' => 'pending',
                'pending_reason' => 'Waiting for customer approval.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($timer->fresh()->ended_at);
        $this->assertDatabaseHas('work_order_status_history', [
            'work_order_id' => $visit->workOrder->id,
            'from_status' => 'in_progress',
            'to_status' => 'pending',
            'reason' => 'Waiting for customer approval.',
            'changed_by' => $user->id,
        ]);

        $this->getJson(route('work-orders.show', $visit->workOrder))
            ->assertOk()
            ->assertJsonPath('data.status_history.0.reason', 'Waiting for customer approval.')
            ->assertJsonPath('data.status_history.0.changed_by', $user->name);
    }

    public function test_completion_automatically_stops_the_assigned_users_active_job_timer(): void
    {
        Queue::fake();
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $timer = $this->activeTimer($user, $visit, TimeEntryActivityType::Labor);
        $payload = [
            'status' => 'completed',
            'client_completion_summary' => 'The work is complete.',
        ];

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $visit->workOrder->fresh()->status);
        $this->assertNotNull($timer->fresh()->ended_at);
    }

    public function test_timer_automation_rules_can_be_reconfigured_without_service_status_branches(): void
    {
        Queue::fake();
        config(['work_orders.timer_automations.on_my_way' => [
            'start_activity_type' => TimeEntryActivityType::Labor->value,
        ]]);
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);

        $this->actingAs($user)
            ->patch(route('work-orders.status.update', $visit->workOrder), ['status' => 'on_my_way'])
            ->assertSessionHasNoErrors();

        $timer = TimeEntry::query()->where('user_id', $user->id)->whereNull('ended_at')->sole();
        $this->assertSame(TimeEntryActivityType::Labor, $timer->activity_type);
    }

    public function test_work_order_detail_does_not_expose_graph_event_ids_or_rsvp_email_maps(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $visit->update([
            'microsoft_graph_event_id' => 'private-event-id',
            'microsoft_graph_event_tenant_id' => 'private-tenant-id',
            'microsoft_graph_event_mailbox_id' => 'private-mailbox-id',
            'microsoft_graph_event_calendar_id' => 'private-calendar-id',
            'microsoft_graph_transaction_id' => (string) Str::uuid(),
            'attendee_responses' => ['client@example.test' => 'accepted'],
        ]);

        $this->actingAs($user)
            ->getJson(route('work-orders.show', $visit->workOrder))
            ->assertOk()
            ->assertJsonMissingPath('data.visits.0.microsoft_graph_event_id')
            ->assertJsonMissingPath('data.visits.0.microsoft_graph_event_tenant_id')
            ->assertJsonMissingPath('data.visits.0.microsoft_graph_event_mailbox_id')
            ->assertJsonMissingPath('data.visits.0.microsoft_graph_event_calendar_id')
            ->assertJsonMissingPath('data.visits.0.microsoft_graph_transaction_id')
            ->assertJsonMissingPath('data.visits.0.attendee_responses')
            ->assertDontSee('private-event-id')
            ->assertDontSee('client@example.test');
    }

    public function test_job_upload_is_persisted_in_sharepoint_and_merged_with_legacy_attachments_after_refresh(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['status' => 'active']);
        $visit = $this->createAssignedVisit($user, 60);
        $workOrder = $visit->workOrder;
        $project = Project::query()->create([
            'customer_id' => $workOrder->site->customer_id,
            'name' => 'Attachment project',
        ]);
        $project->sites()->attach($workOrder->site_id);
        $workOrder->update(['project_id' => $project->getKey()]);

        $legacyPath = 'work-orders/documents/legacy.pdf';
        Storage::disk('local')->put($legacyPath, 'legacy job file');
        $legacyAttachment = $workOrder->attachments()->create([
            'uploaded_by' => $user->getKey(),
            'kind' => 'documents',
            'original_name' => 'legacy.pdf',
            'disk' => 'local',
            'path' => $legacyPath,
            'mime_type' => 'application/pdf',
            'size' => 15,
        ]);
        $this->mockSharePointUpload();

        $this->actingAs($user)
            ->from('/calendar')
            ->post(route('work-orders.attachments.store', $workOrder), [
                'files' => [UploadedFile::fake()->createWithContent('new-job-file.pdf', "%PDF-1.4\nfixture")],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/calendar');

        $sharePointAttachment = SharePointAttachment::query()->sole();
        $this->assertSame($workOrder->site->customer_id, $sharePointAttachment->company_id);
        $this->assertSame($workOrder->site_id, $sharePointAttachment->site_id);
        $this->assertSame($project->getKey(), $sharePointAttachment->project_id);
        $this->assertSame($workOrder->getKey(), $sharePointAttachment->job_id);
        $this->assertSame($user->getKey(), $sharePointAttachment->uploaded_by);
        Storage::disk('local')->assertMissing('work-orders/documents/new-job-file.pdf');

        $detail = $this->getJson(route('work-orders.show', $workOrder))
            ->assertOk()
            ->assertJsonCount(2, 'data.attachments');
        $attachments = collect($detail->json('data.attachments'))->keyBy('original_name');
        $legacy = $attachments->get('legacy.pdf');
        $remote = $attachments->get('new-job-file.pdf');

        $this->assertSame(route('work-order-attachments.download', $legacyAttachment), $legacy['url']);
        $this->assertSame(
            route('work-order-attachments.download', ['attachment' => $legacyAttachment->getKey(), 'download' => 1]),
            $legacy['download_url'],
        );
        $this->assertSame(route('attachments.open', $sharePointAttachment), $remote['url']);
        $this->assertSame($remote['url'], $remote['download_url']);
        $this->assertSame(route('attachments.preview', $sharePointAttachment), $remote['preview_url']);
        $this->assertNull($remote['thumbnail_url']);

        $this->get($legacy['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get($remote['url'])
            ->assertRedirect('https://example.sharepoint.com/sites/work-schedules/job-item-1');
    }

    private function createAssignedVisit(User $user, int $durationMinutes): Visit
    {
        $customer = Customer::create(['name' => 'Test Customer']);
        $site = Site::create([
            'customer_id' => $customer->id,
            'name' => 'Test Site',
        ]);
        $workOrder = WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J-'.Str::uuid(),
            'title' => 'Test Visit',
            'status' => 'pending',
        ]);
        $workOrder->assignedUsers()->attach($user->id);

        return $workOrder->visits()->create([
            'scheduled_at' => '2026-09-26 08:00:00',
            'duration_minutes' => $durationMinutes,
        ]);
    }

    private function activeTimer(User $user, Visit $visit, TimeEntryActivityType $activityType): TimeEntry
    {
        return TimeEntry::factory()->create([
            'user_id' => $user->getKey(),
            'created_by' => $user->getKey(),
            'customer_id' => $visit->workOrder->site->customer_id,
            'job_id' => $visit->workOrder->getKey(),
            'visit_id' => $visit->getKey(),
            'activity_type' => $activityType,
            'started_at' => now()->subMinutes(15),
            'ended_at' => null,
            'duration_minutes' => null,
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
                'id' => 'job-item-1',
                'name' => basename($path),
                'size' => strlen($contents),
                'mime_type' => $contentType,
                'e_tag' => 'etag-job-item-1',
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/job-item-1',
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

        return UploadedFile::fake()->createWithContent('job-start.pptx', is_string($contents) ? $contents : '');
    }
}
