<?php

namespace Tests\Feature;

use App\Enums\TimeEntryActivityType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Notifications\BusinessAssignmentChanged;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminJobWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_a_job_workspace_with_scoped_sites_contacts_visits_and_activity(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        [$customer, $site, $job] = $this->jobFixture();
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Taylor',
            'email' => 'taylor@example.test',
        ]);
        $job->contacts()->attach($contact->id);
        $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Inspect the site.',
            'notes' => 'Visit-specific update.',
        ]);
        $legacyAttachment = $job->attachments()->create([
            'uploaded_by' => $admin->getKey(),
            'kind' => 'documents',
            'original_name' => 'legacy-job.pdf',
            'disk' => 'local',
            'path' => 'work-orders/documents/legacy-job.pdf',
            'mime_type' => 'application/pdf',
            'size' => 20,
        ]);
        $sharePointAttachment = SharePointAttachment::query()->create([
            'company_id' => $customer->getKey(),
            'site_id' => $site->getKey(),
            'job_id' => $job->getKey(),
            'uploaded_by' => $admin->getKey(),
            'filename' => 'sharepoint-job.png',
            'mime_type' => 'image/png',
            'file_size' => 22,
            'sharepoint_drive_id' => 'drive-admin-job',
            'sharepoint_item_id' => 'item-admin-job',
            'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/item-admin-job',
        ]);

        $response = $this->getJson(route('admin.jobs.workspace.show', $job))
            ->assertOk()
            ->assertJsonPath('data.job.number', 'J-401')
            ->assertJsonPath('data.job.scope', 'Inspect and repair the equipment.')
            ->assertJsonPath('data.sites.0.name', $site->name)
            ->assertJsonPath('data.contacts.0.email', 'taylor@example.test')
            ->assertJsonPath('data.additionalContactIds.0', $contact->id)
            ->assertJsonPath('data.visits.0.notes', 'Visit-specific update.')
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonFragment(['summary' => 'Admin Job Tester created job J-401']);

        $attachments = collect($response->json('data.attachments'))->keyBy('name');
        $this->assertSame(
            route('admin.jobs.attachments.show', $legacyAttachment),
            $attachments->get('legacy-job.pdf')['url'],
        );
        $this->assertSame(
            route('attachments.open', $sharePointAttachment),
            $attachments->get('sharepoint-job.png')['url'],
        );
        $this->assertSame(
            route('attachments.preview', $sharePointAttachment),
            $attachments->get('sharepoint-job.png')['previewUrl'],
        );
        $this->assertSame(
            route('attachments.thumbnail', $sharePointAttachment),
            $attachments->get('sharepoint-job.png')['thumbnailUrl'],
        );
    }

    public function test_admin_can_upload_an_attachment_from_the_job_workspace(): void
    {
        $admin = $this->admin();
        [$customer, $site, $job] = $this->jobFixture();
        Notification::fake();
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-test',
        ]);
        $uploadIndex = 0;
        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('upload')
            ->twice()
            ->withArgs(function (string $path, string $contents, string $contentType) use ($customer): bool {
                $this->assertMatchesRegularExpression(
                    '/\AFieldOps\/customer-'.$customer->getKey().'\/[0-9a-f-]{36}\.[a-z0-9]{1,12}\z/',
                    $path,
                );

                return true;
            })
            ->andReturnUsing(function (string $path, string $contents, string $contentType) use (&$uploadIndex): array {
                $itemId = 'job-item-'.++$uploadIndex;

                return [
                    'id' => $itemId,
                    'name' => basename($path),
                    'size' => strlen($contents),
                    'mime_type' => $contentType,
                    'e_tag' => 'etag-'.$itemId,
                    'c_tag' => null,
                    'last_modified_at' => null,
                    'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/'.$itemId,
                ];
            });

        $response = $this->actingAs($admin)
            ->post(route('admin.jobs.attachments.store', $job), [
                'files' => [
                    UploadedFile::fake()->createWithContent('job-photo.jpg', "\xFF\xD8\xFFfixture"),
                    UploadedFile::fake()->createWithContent('job-checklist.pdf', "%PDF-1.4\nfixture"),
                ],
            ])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.uploadedBy', $admin->name)
            ->assertJsonPath('data.1.uploadedBy', $admin->name);

        $attachments = SharePointAttachment::query()->orderBy('id')->get();
        $this->assertCount(2, $attachments);
        foreach ($attachments as $attachment) {
            $this->assertSame($customer->getKey(), $attachment->company_id);
            $this->assertSame($site->getKey(), $attachment->site_id);
            $this->assertSame($job->getKey(), $attachment->job_id);
        }
        $this->assertSame(
            $attachments->map(fn (SharePointAttachment $attachment) => route('attachments.open', $attachment))->all(),
            collect($response->json('data'))->pluck('url')->all(),
        );
        $this->assertSame(['job-checklist.pdf', 'job-photo.jpg'], $attachments->pluck('filename')->sort()->values()->all());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->getKey(),
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->getKey(),
            'event' => 'attachment_uploaded',
        ]);

        $this->getJson(route('admin.jobs.workspace.show', $job))
            ->assertOk()
            ->assertJsonCount(2, 'data.attachments')
            ->assertJsonFragment(['name' => 'job-photo.jpg'])
            ->assertJsonFragment(['name' => 'job-checklist.pdf']);
    }

    public function test_repeating_a_persisted_upload_id_does_not_create_a_duplicate_sharepoint_attachment(): void
    {
        $admin = $this->admin();
        [, , $job] = $this->jobFixture();
        Notification::fake();
        config([
            'services.microsoft_graph.sharepoint_enabled' => true,
            'services.microsoft_graph.work_schedules.drive_id' => 'drive-test',
        ]);
        $clientUploadId = \Illuminate\Support\Str::uuid()->toString();

        $this->mock(MicrosoftGraphSharePointStorageService::class)
            ->shouldReceive('upload')
            ->once()
            ->withArgs(fn (string $path): bool => str_contains($path, '/'.$clientUploadId.'.pdf'))
            ->andReturn([
                'id' => 'job-item-idempotent',
                'name' => $clientUploadId.'.pdf',
                'size' => 16,
                'mime_type' => 'application/pdf',
                'e_tag' => 'etag-job-item-idempotent',
                'c_tag' => null,
                'last_modified_at' => null,
                'sharepoint_url' => 'https://example.sharepoint.com/sites/work-schedules/job-item-idempotent',
            ]);

        $payload = [
            'files' => [UploadedFile::fake()->createWithContent('repeatable.pdf', "%PDF-1.4\nfixture")],
            'client_upload_id' => $clientUploadId,
        ];

        $first = $this->actingAs($admin)
            ->post(route('admin.jobs.attachments.store', $job), $payload)
            ->assertCreated()
            ->json('data.0.id');
        $second = $this->post(route('admin.jobs.attachments.store', $job), [
            'files' => [UploadedFile::fake()->createWithContent('repeatable.pdf', "%PDF-1.4\nfixture")],
            'client_upload_id' => $clientUploadId,
        ])
            ->assertCreated()
            ->json('data.0.id');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('sharepoint_attachments', 1);
        $this->assertDatabaseHas('sharepoint_attachments', [
            'id' => $first,
            'client_upload_id' => $clientUploadId,
        ]);
        $this->assertArrayNotHasKey('client_upload_id', SharePointAttachment::query()->findOrFail($first)->toArray());
    }

    public function test_admin_can_update_job_scope_notes_contacts_site_and_staff_assignments(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $staff = User::factory()->create(['name' => 'Job technician', 'status' => 'active']);
        [$customer, , $job] = $this->jobFixture();
        $newSite = Site::create(['customer_id' => $customer->id, 'name' => 'Secondary site']);
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Additional',
            'last_name' => 'Contact',
            'email' => 'additional@example.test',
            'phone' => '555-0135',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.jobs.workspace.update', $job), $this->jobPayload($newSite->id, $contact->id, $staff->id))
            ->assertOk()
            ->assertJsonPath('message', 'Job saved.');

        $job->refresh();
        $this->assertSame($newSite->id, $job->site_id);
        $this->assertSame('Updated required scope.', $job->scope);
        $this->assertSame('Internal service note.', $job->job_notes);
        $this->assertSame('Client-facing update note.', $job->field_notes);
        $this->assertSame($contact->id, $job->contacts()->sole()->id);
        $this->assertDatabaseHas('work_order_user', [
            'work_order_id' => $job->id,
            'user_id' => $staff->id,
            'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => WorkOrder::class,
            'subject_id' => $job->id,
            'event' => 'edited',
        ]);
        Notification::assertSentTo($staff, BusinessAssignmentChanged::class, fn (BusinessAssignmentChanged $notification): bool => $notification->change === 'assigned');
    }

    public function test_admin_can_change_job_status_from_the_preview_without_updating_other_job_fields(): void
    {
        Queue::fake();
        $admin = $this->admin();
        [, , $job] = $this->jobFixture();
        $originalScope = $job->scope;
        $originalTitle = $job->title;

        $this->actingAs($admin)
            ->patch(route('work-orders.status.update', $job), ['status' => 'scheduled'])
            ->assertSessionHasNoErrors();

        $job->refresh();
        $this->assertSame('scheduled', $job->status);
        $this->assertSame($originalScope, $job->scope);
        $this->assertSame($originalTitle, $job->title);
    }

    public function test_admin_job_workspace_uses_the_configured_status_timer_automation(): void
    {
        $admin = $this->admin();
        [$customer, $site, $job] = $this->jobFixture();
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
        ]);
        $job->assignedUsers()->attach($admin->id, ['assigned_by_user_id' => $admin->id]);
        $payload = array_replace($this->jobPayload($site->id), [
            'status' => 'in_progress',
            'timer_visit_id' => $visit->id,
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.jobs.workspace.update', $job), $payload)
            ->assertOk();

        $timer = TimeEntry::query()->where('user_id', $admin->id)->whereNull('ended_at')->sole();
        $this->assertSame(TimeEntryActivityType::Labor, $timer->activity_type);
        $this->assertSame($customer->id, $timer->customer_id);
        $this->assertSame($job->id, $timer->job_id);
        $this->assertSame($visit->id, $timer->visit_id);
    }

    public function test_job_workspace_rejects_sites_and_contacts_from_other_customers(): void
    {
        $admin = $this->admin();
        [$customer, , $job] = $this->jobFixture();
        $otherCustomer = Customer::create(['name' => 'Other customer']);
        $foreignSite = Site::create(['customer_id' => $otherCustomer->id, 'name' => 'Foreign site']);
        $foreignContact = Contact::create([
            'customer_id' => $otherCustomer->id,
            'contact_type' => 'other',
            'source' => 'manual',
            'first_name' => 'Foreign',
        ]);

        $this->actingAs($admin)
            ->putJson(route('admin.jobs.workspace.update', $job), [
                ...$this->jobPayload($foreignSite->id),
                'client_contact_id' => $foreignContact->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('site_id');

        $this->assertSame($customer->sites()->firstOrFail()->id, $job->fresh()->site_id);
        $this->assertSame('Inspect and repair the equipment.', $job->fresh()->scope);
    }

    public function test_basic_user_cannot_open_or_change_job_workspace(): void
    {
        [, , $job] = $this->jobFixture();
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->getJson(route('admin.jobs.workspace.show', $job))
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson(route('admin.jobs.workspace.update', $job), $this->jobPayload($job->site_id))
            ->assertForbidden();
    }

    public function test_admin_can_edit_visit_scope_and_notes_from_its_flyout(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        [, , $job] = $this->jobFixture();
        $visit = $job->visits()->create([
            'visit_type' => 'initial',
            'scheduled_at' => now()->addDay(),
            'scope' => 'Initial visit scope.',
            'notes' => 'Old visit notes.',
        ]);

        $this->getJson(route('admin.visits.workspace.show', $visit))
            ->assertOk()
            ->assertJsonPath('data.visit.jobNumber', 'J-401')
            ->assertJsonPath('data.visit.scope', 'Initial visit scope.');

        $this->putJson(route('admin.visits.workspace.update', $visit), [
            'scope' => 'Updated visit scope snapshot.',
            'notes' => 'Visit notes for the completion report.',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Visit saved.');

        $this->assertSame('Updated visit scope snapshot.', $visit->fresh()->scope);
        $this->assertSame('Visit notes for the completion report.', $visit->fresh()->notes);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'subject_type' => Visit::class,
            'subject_id' => $visit->id,
            'event' => 'edited',
        ]);
    }

    public function test_basic_user_cannot_upload_an_attachment_from_the_admin_job_workspace(): void
    {
        [, , $job] = $this->jobFixture();
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user)
            ->post(route('admin.jobs.attachments.store', $job), [
                'files' => [UploadedFile::fake()->createWithContent('job-photo.jpg', "\xFF\xD8\xFFfixture")],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('sharepoint_attachments', 0);
    }

    /** @return array<string, mixed> */
    private function jobPayload(int $siteId, ?int $additionalContactId = null, ?int $assignedUserId = null): array
    {
        return [
            'site_id' => $siteId,
            'title' => 'Updated job title',
            'subtitle' => null,
            'description' => 'Work at the customer site.',
            'scope' => 'Updated required scope.',
            'job_notes' => 'Internal service note.',
            'field_notes' => 'Client-facing update note.',
            'status' => 'scheduled',
            'client_completion_summary' => null,
            'client_contact_id' => null,
            'technical_contact_id' => null,
            'project_manager_contact_id' => null,
            'contractor_check_in_contact_id' => null,
            'additional_contact_ids' => $additionalContactId === null ? [] : [$additionalContactId],
            'assigned_user_ids' => $assignedUserId === null ? [] : [$assignedUserId],
        ];
    }

    /** @return array{Customer, Site, WorkOrder} */
    private function jobFixture(): array
    {
        $customer = Customer::create(['name' => 'Job workspace customer']);
        $site = Site::create(['customer_id' => $customer->id, 'name' => 'Primary service site']);
        $job = WorkOrder::create([
            'site_id' => $site->id,
            'job_number' => 'J-401',
            'title' => 'Inspect equipment',
            'scope' => 'Inspect and repair the equipment.',
            'job_notes' => 'Private note before edits.',
            'status' => 'scheduled',
        ]);

        return [$customer, $site, $job];
    }

    private function admin(): User
    {
        return User::factory()->admin()->create([
            'name' => 'Admin Job Tester',
            'status' => 'active',
        ]);
    }
}
