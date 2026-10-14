<?php

namespace Tests\Feature;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Exceptions\MicrosoftGraphApiException;
use App\Exceptions\OutlookContactSyncException;
use App\Jobs\SyncOutlookContact;
use App\Models\ApplicationSetupState;
use App\Models\Contact;
use App\Models\User;
use App\Services\ArchiveService;
use App\Services\ContactMethodService;
use App\Services\MicrosoftGraphApiClient;
use App\Services\OutlookContactSyncCoordinator;
use App\Services\OutlookContactSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutlookContactSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
    }

    public function test_creates_outlook_contact_from_fieldops_names_emails_and_phones_and_stores_id(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'first_name' => 'Example',
            'middle_name' => '',
            'last_name' => 'Technician',
            'title' => 'Dr.',
            'suffix' => 'Jr.',
            'email' => 'legacy@example.test',
            'phone' => '+1-555-0200',
            'mobile_phone' => '+1-555-0299',
        ]);
        $contact->methods()->createMany([
            [
                'method_type' => ContactMethodType::Email,
                'label' => ContactMethodLabel::Office,
                'value' => 'example.primary@example.test',
                'is_primary' => true,
            ],
            [
                'method_type' => ContactMethodType::Email,
                'label' => ContactMethodLabel::Personal,
                'value' => 'example.additional@example.test',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Dispatch,
                'value' => '+1-555-0210',
                'is_primary' => true,
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Mobile,
                'value' => '+1-555-0299',
            ],
            [
                'method_type' => ContactMethodType::Phone,
                'label' => ContactMethodLabel::Personal,
                'value' => '+1-555-0215',
            ],
            [
                'method_type' => ContactMethodType::Teams,
                'label' => ContactMethodLabel::Operations,
                'value' => 'example.operations',
            ],
        ]);
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('postJson')
            ->once()
            ->withArgs(function (string $path, array $payload, array $headers): bool {
                $this->assertSame('users/outlook-mailbox-test/contacts', $path);
                $this->assertSame(['Prefer' => 'IdType="ImmutableId"'], $headers);
                $this->assertSame('Example Technician', $payload['displayName']);
                $this->assertSame('Example', $payload['givenName']);
                $this->assertSame('', $payload['middleName']);
                $this->assertSame('Technician', $payload['surname']);
                $this->assertSame('Dr.', $payload['title']);
                $this->assertSame('Jr.', $payload['generation']);
                $this->assertSame([
                    ['name' => 'Example Technician', 'address' => 'example.primary@example.test'],
                    ['name' => 'Example Technician', 'address' => 'legacy@example.test'],
                    ['name' => 'Example Technician', 'address' => 'example.additional@example.test'],
                ], $payload['emailAddresses']);
                $this->assertSame(['+1-555-0210', '+1-555-0200'], $payload['businessPhones']);
                $this->assertSame('+1-555-0299', $payload['mobilePhone']);

                return true;
            })
            ->andReturn(['id' => 'immutable-outlook-contact-1']);

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $this->assertSame('immutable-outlook-contact-1', $contact->fresh()->outlook_contact_id);
        $this->assertSame('outlook-mailbox-test', $contact->fresh()->outlook_contact_mailbox_id);
        $this->assertArrayNotHasKey('outlook_contact_id', $contact->fresh()->toArray());
        $this->assertArrayNotHasKey('outlook_contact_mailbox_id', $contact->fresh()->toArray());
    }

    public function test_updates_existing_outlook_contact_without_creating_another(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'outlook_contact_id' => 'known-outlook-id',
            'first_name' => 'Updated',
            'last_name' => 'Contact',
        ]);
        $graph = $this->mock(MicrosoftGraphApiClient::class);
        $graph->shouldReceive('patchJson')
            ->once()
            ->withArgs(fn (string $path, array $payload, array $headers): bool => $path === 'users/outlook-mailbox-test/contacts/known-outlook-id'
                && $payload['displayName'] === 'Updated Contact'
                && $headers === ['Prefer' => 'IdType="ImmutableId"']);
        $graph->shouldNotReceive('postJson');

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $this->assertSame('known-outlook-id', $contact->fresh()->outlook_contact_id);
        $this->assertSame('outlook-mailbox-test', $contact->fresh()->outlook_contact_mailbox_id);
    }

    public function test_repair_replaces_a_known_outlook_contact_and_keeps_its_mailbox_owner(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'outlook_contact_id' => 'contact-to-replace',
            'first_name' => 'Repair',
            'last_name' => 'Target',
        ]);
        $graph = $this->mock(MicrosoftGraphApiClient::class);
        $graph->shouldReceive('delete')
            ->once()
            ->with('users/outlook-mailbox-test/contacts/contact-to-replace', ['Prefer' => 'IdType="ImmutableId"']);
        $graph->shouldReceive('postJson')
            ->once()
            ->withArgs(fn (string $path, array $payload): bool => $path === 'users/outlook-mailbox-test/contacts'
                && $payload['displayName'] === 'Repair Target')
            ->andReturn(['id' => 'repaired-outlook-id']);

        (new SyncOutlookContact((int) $contact->getKey(), repair: true))->handle(
            app(OutlookContactSyncService::class),
            app(OutlookContactSyncCoordinator::class),
        );

        $repairedContact = $contact->fresh();
        $this->assertSame('repaired-outlook-id', $repairedContact->outlook_contact_id);
        $this->assertSame('outlook-mailbox-test', $repairedContact->outlook_contact_mailbox_id);
        $this->assertSame('synced', $repairedContact->outlook_sync_status);
        $this->assertNotNull($repairedContact->outlook_last_synced_at);
    }

    public function test_sync_job_records_last_successful_sync_time_and_status(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact(['first_name' => 'Synced', 'last_name' => 'Contact']);
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('postJson')
            ->once()
            ->andReturn(['id' => 'synced-outlook-id']);

        (new SyncOutlookContact((int) $contact->getKey()))->handle(
            app(OutlookContactSyncService::class),
            app(OutlookContactSyncCoordinator::class),
        );

        $syncedContact = $contact->fresh();
        $this->assertSame(
            'synced',
            $syncedContact->outlook_sync_status,
            'Unexpected Outlook status '.$syncedContact->outlook_sync_status.' ('.$syncedContact->outlook_sync_error.').',
        );
        $this->assertNotNull($syncedContact->outlook_last_synced_at);
        $this->assertNull($syncedContact->outlook_sync_error);
        $this->assertSame('synced-outlook-id', $syncedContact->outlook_contact_id);
    }

    public function test_unknown_outlook_create_failure_is_persisted_for_manual_reconciliation(): void
    {
        $contact = $this->contact();

        (new SyncOutlookContact((int) $contact->getKey()))
            ->failed(new OutlookContactSyncException('outlook_contact_create_outcome_unknown'));

        $contact->refresh();
        $this->assertSame('unknown', $contact->outlook_sync_status);
        $this->assertSame('outlook_contact_create_outcome_unknown', $contact->outlook_sync_error);
        $this->assertNull($contact->outlook_contact_id);
    }

    public function test_recreates_a_missing_outlook_contact_and_replaces_its_stored_id(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'outlook_contact_id' => 'deleted-outlook-id',
            'first_name' => 'Restored',
            'last_name' => 'Contact',
        ]);
        $graph = $this->mock(MicrosoftGraphApiClient::class);
        $graph->shouldReceive('patchJson')
            ->once()
            ->andThrow(new MicrosoftGraphApiException('graph_record_not_found', 404));
        $graph->shouldReceive('postJson')
            ->once()
            ->andReturn(['id' => 'replacement-outlook-id']);

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected a missing stored Outlook ID to be cleared before the retry.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_record_not_found', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
        $this->assertNull($contact->fresh()->outlook_contact_mailbox_id);

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $this->assertSame('replacement-outlook-id', $contact->fresh()->outlook_contact_id);
        $this->assertSame('outlook-mailbox-test', $contact->fresh()->outlook_contact_mailbox_id);
    }

    public function test_refuses_to_patch_a_contact_linked_to_a_different_mailbox(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact(['outlook_contact_id' => 'other-mailbox-contact']);
        $contact->forceFill(['outlook_contact_mailbox_id' => 'previous-outlook-mailbox'])->saveQuietly();
        $this->mock(MicrosoftGraphApiClient::class)->shouldNotReceive('patchJson');

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected a mailbox-scoped ID to be protected from cross-mailbox writes.');
        } catch (OutlookContactSyncException $exception) {
            $this->assertSame('outlook_contact_mailbox_mismatch', $exception->reason);
        }

        $this->assertSame('other-mailbox-contact', $contact->fresh()->outlook_contact_id);
        $this->assertSame('previous-outlook-mailbox', $contact->fresh()->outlook_contact_mailbox_id);
    }

    public function test_does_not_retry_an_outlook_create_with_an_ambiguous_outcome(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact();
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('postJson')
            ->once()
            ->andThrow(new MicrosoftGraphApiException('graph_unavailable', 503));

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected an ambiguous Outlook create to require manual reconciliation.');
        } catch (OutlookContactSyncException $exception) {
            $this->assertSame('outlook_contact_create_outcome_unknown', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
        $this->assertNull($contact->fresh()->outlook_contact_mailbox_id);
    }

    public function test_preserves_preflight_graph_authentication_failures_for_queue_retry(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact();
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('postJson')
            ->once()
            ->andThrow(new MicrosoftGraphApiException('graph_auth_unavailable', 503));

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected the transient token acquisition error to remain retryable.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_auth_unavailable', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
        $this->assertNull($contact->fresh()->outlook_contact_mailbox_id);
    }

    public function test_deletes_outlook_copy_and_clears_its_id_when_contact_is_archived(): void
    {
        $contact = $this->contact(['outlook_contact_id' => 'archived-outlook-id']);
        $batch = app(ArchiveService::class)->archive('contacts', $contact->getKey(), null);
        config($this->outlookSyncConfig());
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('delete')
            ->once()
            ->with('users/outlook-mailbox-test/contacts/archived-outlook-id', ['Prefer' => 'IdType="ImmutableId"']);

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $archivedContact = Contact::withArchived()->findOrFail($contact->getKey());
        $this->assertSame($batch->getKey(), $archivedContact->archive_batch_id);
        $this->assertNull($archivedContact->outlook_contact_id);
        $this->assertNull($archivedContact->outlook_contact_mailbox_id);
    }

    public function test_refuses_to_truncate_fieldops_email_values_to_fit_outlook(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact(['email' => 'legacy@example.test']);
        foreach (['one', 'two', 'three'] as $address) {
            $contact->methods()->create([
                'method_type' => ContactMethodType::Email,
                'label' => ContactMethodLabel::Office,
                'value' => $address.'@example.test',
            ]);
        }
        $this->mock(MicrosoftGraphApiClient::class)->shouldNotReceive('postJson');

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected Outlook email capacity to be enforced.');
        } catch (OutlookContactSyncException $exception) {
            $this->assertSame('outlook_contact_email_limit_exceeded', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
    }

    public function test_refuses_multiple_mobile_values_for_outlooks_single_mobile_phone_field(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact(['mobile_phone' => '+1-555-0100']);
        $contact->methods()->create([
            'method_type' => ContactMethodType::Phone,
            'label' => ContactMethodLabel::Mobile,
            'value' => '+1-555-0101',
        ]);
        $this->mock(MicrosoftGraphApiClient::class)->shouldNotReceive('postJson');

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected the single Outlook mobilePhone field to be enforced.');
        } catch (OutlookContactSyncException $exception) {
            $this->assertSame('outlook_contact_multiple_mobile_phones', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
    }

    public function test_refuses_to_truncate_business_phone_values_to_fit_outlook(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'phone' => '+1-555-0300',
            'alternate_phone' => '+1-555-0301',
        ]);
        $contact->methods()->create([
            'method_type' => ContactMethodType::Phone,
            'label' => ContactMethodLabel::Dispatch,
            'value' => '+1-555-0302',
        ]);
        $this->mock(MicrosoftGraphApiClient::class)->shouldNotReceive('postJson');

        try {
            app(OutlookContactSyncService::class)->sync((int) $contact->getKey());
            $this->fail('Expected Outlook business-phone capacity to be enforced.');
        } catch (OutlookContactSyncException $exception) {
            $this->assertSame('outlook_contact_business_phone_limit_exceeded', $exception->reason);
        }

        $this->assertNull($contact->fresh()->outlook_contact_id);
    }

    public function test_maps_both_legacy_business_phone_fields_when_they_fit_outlook(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact([
            'first_name' => 'Business',
            'last_name' => 'Phones',
            'phone' => '+1-555-0310',
            'alternate_phone' => '+1-555-0311',
        ]);
        $this->mock(MicrosoftGraphApiClient::class)
            ->shouldReceive('postJson')
            ->once()
            ->withArgs(fn (string $path, array $payload): bool => $payload['businessPhones'] === ['+1-555-0310', '+1-555-0311'])
            ->andReturn(['id' => 'business-phone-outlook-id']);

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $this->assertSame('business-phone-outlook-id', $contact->fresh()->outlook_contact_id);
    }

    public function test_sync_is_inert_when_the_feature_flag_is_disabled(): void
    {
        $contact = $this->contact();
        $this->mock(MicrosoftGraphApiClient::class)->shouldNotReceive('postJson');

        app(OutlookContactSyncService::class)->sync((int) $contact->getKey());

        $this->assertNull($contact->fresh()->outlook_contact_id);
    }

    public function test_graph_patch_not_found_is_classified_as_a_missing_remote_contact(): void
    {
        config([
            'services.microsoft_graph.writes_enabled' => true,
            'services.microsoft_graph.tenant_id' => 'tenant-test',
        ]);
        $this->fakeMicrosoftGraphBwsCredentials();
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-graph-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/outlook-mailbox-test/contacts/missing-contact' => Http::response([
                'error' => ['code' => 'ErrorItemNotFound', 'message' => 'Not found'],
            ], 404),
        ]);

        try {
            app(MicrosoftGraphApiClient::class)->patchJson(
                'users/outlook-mailbox-test/contacts/missing-contact',
                ['displayName' => 'Test Contact'],
            );
            $this->fail('Expected a missing Graph contact to be identified.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_record_not_found', $exception->reason);
        }

        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/contacts/missing-contact'));
    }

    public function test_contact_creation_queues_one_way_sync_when_enabled(): void
    {
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);
        $contact = $this->contact();

        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->getKey());
    }

    public function test_contact_name_edits_queue_a_new_projection(): void
    {
        $contact = $this->contact();
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);

        $contact->update(['first_name' => 'Changed']);

        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->getKey());
    }

    public function test_contact_method_changes_queue_one_way_sync_when_enabled(): void
    {
        $contact = $this->contact();
        config($this->outlookSyncConfig());
        Queue::fake([SyncOutlookContact::class]);

        app(ContactMethodService::class)->sync($contact, [[
            'method_type' => ContactMethodType::Email->value,
            'label' => ContactMethodLabel::Office->value,
            'value' => 'new-method@example.test',
            'is_primary' => true,
        ]]);

        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->getKey());
    }

    public function test_contact_restore_queues_a_fresh_outlook_projection(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $contact = $this->contact();
        $batch = app(ArchiveService::class)->archive('contacts', $contact->getKey(), $admin);
        Queue::fake([SyncOutlookContact::class]);
        config($this->outlookSyncConfig());

        app(ArchiveService::class)->restore($batch->getKey(), $admin);

        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $contact->getKey());
    }

    public function test_sync_command_queues_active_contacts_and_existing_archived_outlook_links(): void
    {
        $activeContact = $this->contact();
        $archivedContact = $this->contact(['outlook_contact_id' => 'archived-link']);
        app(ArchiveService::class)->archive('contacts', $archivedContact->getKey(), null);
        Queue::fake([SyncOutlookContact::class]);
        config($this->outlookSyncConfig());

        $this->artisan('graph:outlook:sync-contacts')->assertSuccessful();

        Queue::assertPushedTimes(SyncOutlookContact::class, 2);
        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $activeContact->getKey());
        Queue::assertPushed(SyncOutlookContact::class, fn (SyncOutlookContact $job): bool => $job->contactId === $archivedContact->getKey());
    }

    /** @param array<string, mixed> $attributes */
    private function contact(array $attributes = []): Contact
    {
        $outlookContactId = $attributes['outlook_contact_id'] ?? null;
        unset($attributes['outlook_contact_id']);

        $contact = Contact::factory()->create($attributes);

        if (is_string($outlookContactId)) {
            $contact->forceFill([
                'outlook_contact_id' => $outlookContactId,
                'outlook_contact_mailbox_id' => 'outlook-mailbox-test',
            ])->saveQuietly();
        }

        return $contact;
    }

    /** @return array<string, mixed> */
    private function outlookSyncConfig(): array
    {
        return [
            'services.microsoft_graph.contacts_sync_enabled' => true,
            'services.microsoft_graph.notification_mailbox_id' => 'outlook-mailbox-test',
        ];
    }
}
