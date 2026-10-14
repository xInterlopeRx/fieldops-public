<?php

namespace Tests\Feature;

use App\Exceptions\MicrosoftGraphApiException;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\GraphNotificationOutbox;
use App\Models\GraphOnboardingChallenge;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingCanary;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\MicrosoftGraphSharePointOnboardingCanary;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\ActivateMicrosoftGraphRuntimeTarget;
use App\Services\ApplicationSetupStepService;
use App\Services\MicrosoftGraphApiClient;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use App\Services\MicrosoftGraphTokenProvider;
use App\Services\OnboardingReadinessService;
use App\Services\ReconcileMicrosoftGraphRuntimeTargetRecord;
use App\Services\RunMicrosoftGraphOnboardingCanary;
use App\Services\RunMicrosoftGraphSharePointCanary;
use App\Services\SendMicrosoftGraphLinkbackChallenge;
use App\Services\ValidateMicrosoftGraphOnboardingTargets;
use App\Services\ValidateMicrosoftGraphPermissionBoundary;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MicrosoftGraphOnboardingTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.microsoft_graph.onboarding_writes_enabled', true);
    }

    public function test_onboarding_graph_writes_are_blocked_when_the_switch_is_off(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config()->set('services.microsoft_graph.onboarding_writes_enabled', false);
        Http::preventStrayRequests();

        try {
            app(MicrosoftGraphApiClient::class)->postJsonForOnboardingTarget(
                $target,
                'users/'.$target->notification_mailbox_id.'/sendMail',
                [],
            );
            $this->fail('Onboarding Graph POSTs must remain disabled unless explicitly enabled in deployment config.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_onboarding_writes_disabled', $exception->reason);
        }

        try {
            app(MicrosoftGraphApiClient::class)->deleteForOnboardingTarget(
                $target,
                'users/'.$target->notification_mailbox_id.'/events/test-event',
            );
            $this->fail('Onboarding Graph DELETEs must remain disabled unless explicitly enabled in deployment config.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_onboarding_writes_disabled', $exception->reason);
        }

        try {
            app(RunMicrosoftGraphOnboardingCanary::class)->createCalendarAndCleanup($admin);
            $this->fail('A disabled onboarding canary must not create a local attempt row.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Microsoft Graph onboarding writes are disabled by deployment configuration.',
                $exception->errors()['confirm_canary_action'][0],
            );
        }

        try {
            app(SendMicrosoftGraphLinkbackChallenge::class)->send($admin);
            $this->fail('A disabled onboarding linkback must not create a local challenge row.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Microsoft Graph onboarding writes are disabled by deployment configuration.',
                $exception->errors()['confirm_send'][0],
            );
        }

        try {
            app(RunMicrosoftGraphSharePointCanary::class)->createAndCleanup($admin);
            $this->fail('A disabled SharePoint canary must not create a local attempt row.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Microsoft Graph onboarding writes are disabled by deployment configuration.',
                $exception->errors()['confirm_canary_action'][0],
            );
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 0);
        $this->assertDatabaseCount('graph_onboarding_challenges', 0);
        $this->assertDatabaseCount('microsoft_graph_sharepoint_onboarding_canaries', 0);
    }

    public function test_new_installation_does_not_fall_back_to_environment_mailbox_before_activation(): void
    {
        [, $target] = $this->configuredAdminAndTarget();
        config([
            'services.microsoft_graph.tenant_id' => '00000000-0000-0000-0000-000000000077',
            'services.microsoft_graph.notification_mailbox_id' => 'legacy-env-mailbox-id',
        ]);
        Http::preventStrayRequests();
        Process::fake();

        $runtimeTarget = app(MicrosoftGraphRuntimeTargetResolver::class);

        $this->assertTrue($runtimeTarget->requiresManagedTarget());
        $this->assertNull($runtimeTarget->activeTarget());
        $this->assertNull($runtimeTarget->notificationMailboxId());
        $this->assertNull($target->fresh()->runtime_activated_at);

        try {
            app(MicrosoftGraphTokenProvider::class)->accessToken();
            $this->fail('An onboarding-required installation must not use environment Graph credentials without an activated target.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_configuration_incomplete', $exception->reason);
        }

        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_activation_refuses_until_readiness_and_m365_evidence_are_complete(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        Http::preventStrayRequests();
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(false);

        try {
            app(ActivateMicrosoftGraphRuntimeTarget::class)->activate($admin);
            $this->fail('Runtime target activation must fail before overall readiness is complete.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('confirm_activate_runtime', $exception->errors());
        }

        $this->assertNull($target->fresh()->runtime_activated_at);
        $this->assertDatabaseMissing('audit_events', ['event' => 'microsoft_graph_runtime_target_activated']);
        Http::assertNothingSent();
    }

    public function test_first_admin_can_activate_ready_runtime_target_without_enabling_graph_writes(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'runtime_activated_at' => null,
            'runtime_activated_by_user_id' => null,
        ])->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $customer = Customer::create(['name' => 'Active Visit owner customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Active Visit owner site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Active Visit owner job',
            'scope' => 'Test scope',
            'status' => 'created',
        ]);
        Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'confirmed-active-event-id',
            'microsoft_graph_event_tenant_id' => $target->tenant_id,
            'microsoft_graph_event_mailbox_id' => $target->notification_mailbox_id,
            'microsoft_graph_event_calendar_id' => $target->notification_calendar_id,
        ]);
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(true);
        config([
            'services.microsoft_graph.writes_enabled' => false,
            'services.microsoft_graph.calendar_writes_enabled' => false,
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.sharepoint_enabled' => false,
            'services.microsoft_graph.onboarding_writes_enabled' => false,
        ]);
        Http::preventStrayRequests();

        $activated = app(ActivateMicrosoftGraphRuntimeTarget::class)->activate($admin);

        $this->assertSame($target->notification_mailbox_id, app(MicrosoftGraphRuntimeTargetResolver::class)->notificationMailboxId());
        $this->assertSame($target->notification_mailbox_address, app(MicrosoftGraphRuntimeTargetResolver::class)->notificationMailboxAddress());
        $this->assertSame($target->notification_calendar_id, app(MicrosoftGraphRuntimeTargetResolver::class)->notificationCalendarId());
        $this->assertSame($target->tenant_id, app(MicrosoftGraphRuntimeTargetResolver::class)->tenantId());
        $this->assertSame($target->client_id, app(MicrosoftGraphRuntimeTargetResolver::class)->clientId());
        $this->assertNotNull($activated->runtime_activated_at);
        $this->assertSame($admin->getKey(), $activated->runtime_activated_by_user_id);
        $this->assertFalse(config('services.microsoft_graph.writes_enabled'));
        $this->assertFalse(config('services.microsoft_graph.calendar_writes_enabled'));
        $this->assertFalse(config('services.microsoft_graph.contacts_sync_enabled'));
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_runtime_target_activated']);
        Http::assertNothingSent();

        config(['services.microsoft_graph.writes_enabled' => true]);
        $this->fakeMicrosoftGraphBwsCredentials(
            clientId: $target->client_id,
            clientSecret: 'active-target-client-secret',
        );
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'active-target-token',
                'expires_in' => 3600,
            ]),
        ]);

        $this->assertSame('active-target-token', app(MicrosoftGraphTokenProvider::class)->accessToken());
        Http::assertSent(fn (HttpRequest $request): bool => str_contains(
            $request->url(),
            '/'.$target->tenant_id.'/oauth2/v2.0/token',
        ) && $request['client_id'] === $target->client_id);
    }

    public function test_runtime_activation_route_requires_admin_step_up_and_keeps_write_flags_off(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(true);
        config([
            'services.microsoft_graph.writes_enabled' => false,
            'services.microsoft_graph.calendar_writes_enabled' => false,
            'services.microsoft_graph.contacts_sync_enabled' => false,
            'services.microsoft_graph.sharepoint_enabled' => false,
            'services.microsoft_graph.onboarding_writes_enabled' => false,
        ]);
        Http::preventStrayRequests();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.activate-runtime-target'), [
                'confirm_activate_runtime' => true,
                'password' => 'password',
            ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertNotNull($target->fresh()->runtime_activated_at);
        $this->assertFalse(config('services.microsoft_graph.writes_enabled'));
        $this->assertFalse(config('services.microsoft_graph.calendar_writes_enabled'));
        $this->assertFalse(config('services.microsoft_graph.contacts_sync_enabled'));
        $this->assertFalse(config('services.microsoft_graph.sharepoint_enabled'));
        $this->assertFalse(config('services.microsoft_graph.onboarding_writes_enabled'));
        Http::assertNothingSent();
    }

    public function test_legacy_installation_continues_using_its_deployment_mailbox_target(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        ApplicationSetupState::query()->whereKey(1)->update(['onboarding_required' => false]);
        config([
            'services.microsoft_graph.tenant_id' => 'legacy-tenant-id',
            'services.microsoft_graph.notification_mailbox_id' => 'legacy-mailbox-id',
            'services.microsoft_graph.notification_mailbox_address' => 'legacy-mailbox@example.test',
            'services.microsoft_graph.notification_calendar_id' => 'legacy-calendar-id',
        ]);

        $runtimeTarget = app(MicrosoftGraphRuntimeTargetResolver::class);

        $this->assertFalse($runtimeTarget->requiresManagedTarget());
        $this->assertNull($runtimeTarget->activeTarget());
        $this->assertSame('legacy-mailbox-id', $runtimeTarget->notificationMailboxId());
        $this->assertSame('legacy-mailbox@example.test', $runtimeTarget->notificationMailboxAddress());
        $this->assertSame('legacy-calendar-id', $runtimeTarget->notificationCalendarId());
        $this->assertSame('legacy-tenant-id', $runtimeTarget->tenantId());
        $this->assertNull($runtimeTarget->clientId());
        $this->assertNull($target->fresh()->runtime_activated_at);
        $this->assertModelExists($admin);
    }

    public function test_activation_waits_for_pending_graph_outbox_work_to_be_reconciled(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $customer = Customer::create(['name' => 'Activation queue Customer']);
        $site = Site::create([
            'customer_id' => $customer->getKey(),
            'name' => 'Activation queue Site',
        ]);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Activation queue Job',
            'scope' => 'Test scope',
            'status' => 'created',
        ]);
        GraphNotificationOutbox::query()->create([
            'id' => (string) Str::uuid(),
            'work_order_id' => $workOrder->getKey(),
            'type' => 'job_created',
            'recipient_email' => 'client@example.test',
            'event_key' => 'pending-runtime-cutover-test',
            'status' => 'pending',
            'available_at' => now(),
            'payload' => [],
        ]);
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(true);

        try {
            app(ActivateMicrosoftGraphRuntimeTarget::class)->activate($admin);
            $this->fail('Runtime activation must wait for unresolved Graph notification work.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Reconcile pending or in-flight Graph mail, Visit, and Contact work before activating the runtime target.',
                $exception->errors()['confirm_activate_runtime'][0],
            );
        }

        $this->assertNull($target->fresh()->runtime_activated_at);
        $this->assertDatabaseMissing('audit_events', ['event' => 'microsoft_graph_runtime_target_activated']);
    }

    public function test_activation_waits_for_unknown_outlook_create_reconciliation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $contact = Contact::factory()->create();
        $contact->forceFill([
            'outlook_sync_status' => 'unknown',
            'outlook_contact_id' => null,
            'outlook_contact_mailbox_id' => null,
        ])->saveQuietly();
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(true);

        try {
            app(ActivateMicrosoftGraphRuntimeTarget::class)->activate($admin);
            $this->fail('Runtime activation must wait for an unknown Outlook create to be manually reconciled.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Reconcile pending or in-flight Graph mail, Visit, and Contact work before activating the runtime target.',
                $exception->errors()['confirm_activate_runtime'][0],
            );
        }

        $this->assertSame('unknown', $contact->fresh()->outlook_sync_status);
        $this->assertNull($target->fresh()->runtime_activated_at);
    }

    public function test_activation_refuses_unverified_visit_event_ids_even_when_deployment_target_matches(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $customer = Customer::create(['name' => 'Mailbox mismatch Customer']);
        $site = Site::create([
            'customer_id' => $customer->getKey(),
            'name' => 'Mailbox mismatch Site',
        ]);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Mailbox mismatch Job',
            'scope' => 'Test scope',
            'status' => 'created',
        ]);
        Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'old-mailbox-event-id',
        ]);
        config([
            'services.microsoft_graph.tenant_id' => $target->tenant_id,
            'services.microsoft_graph.notification_mailbox_id' => $target->notification_mailbox_id,
            'services.microsoft_graph.notification_calendar_id' => $target->notification_calendar_id,
        ]);
        $this->mock(OnboardingReadinessService::class)
            ->shouldReceive('invitationsUnlocked')
            ->once()
            ->andReturn(true);

        try {
            app(ActivateMicrosoftGraphRuntimeTarget::class)->activate($admin);
            $this->fail('Activation must stop until an existing Visit event has a confirmed mailbox owner, even when deployment config already matches the saved target.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Confirm or reconcile Visit event ownership and reconcile mailbox-scoped Outlook Contact IDs before activating the runtime target.',
                $exception->errors()['confirm_activate_runtime'][0],
            );
        }

        $this->assertNull($target->fresh()->runtime_activated_at);
        $this->assertDatabaseMissing('audit_events', ['event' => 'microsoft_graph_runtime_target_activated']);
    }

    public function test_admin_can_manually_reconcile_a_legacy_visit_event_without_graph_requests(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config([
            'services.microsoft_graph.tenant_id' => 'legacy-tenant-id',
            'services.microsoft_graph.notification_mailbox_id' => 'legacy-mailbox-id',
            'services.microsoft_graph.notification_calendar_id' => null,
        ]);
        $customer = Customer::create(['name' => 'Legacy Visit Customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Legacy Visit Site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Legacy Visit Job',
            'scope' => 'Test scope',
            'status' => 'scheduled',
        ]);
        $visit = Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Legacy Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'legacy-event-id',
            'microsoft_graph_event_tenant_id' => 'legacy-tenant-id',
            'microsoft_graph_event_mailbox_id' => 'legacy-mailbox-id',
            'microsoft_graph_transaction_id' => (string) Str::uuid(),
            'attendee_responses' => ['old@example.test' => 'accepted'],
            'rsvp_polled_at' => now(),
        ]);
        Http::preventStrayRequests();

        $reconciled = app(ReconcileMicrosoftGraphRuntimeTargetRecord::class)->visit($admin, (int) $visit->getKey(), [
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2042',
        ]);

        $this->assertNull($reconciled->microsoft_graph_event_id);
        $this->assertNull($reconciled->microsoft_graph_transaction_id);
        $this->assertSame('not_requested', $reconciled->graph_sync_status);
        $this->assertNull($reconciled->attendee_responses);
        $audit = AuditEvent::query()->where('event', 'microsoft_graph_runtime_visit_event_reconciled')->sole();
        $this->assertSame($target->getKey(), $audit->subject_id);
        $this->assertSame('legacy-event-id', $audit->metadata['provider_event_id']);
        $this->assertSame('legacy-mailbox-id', $audit->metadata['source_mailbox_id']);
        $this->assertFalse($audit->metadata['source_owner_unverified']);
        $this->assertFalse($audit->metadata['graph_request_performed']);
        Http::assertNothingSent();
    }

    public function test_first_admin_can_confirm_active_visit_event_ownership_without_clearing_the_pointer_or_calling_graph(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'runtime_activated_at' => now(),
            'runtime_activated_by_user_id' => $admin->getKey(),
        ])->save();
        config([
            'services.microsoft_graph.tenant_id' => $target->tenant_id,
            'services.microsoft_graph.notification_mailbox_id' => $target->notification_mailbox_id,
            'services.microsoft_graph.notification_calendar_id' => $target->notification_calendar_id,
        ]);
        $customer = Customer::create(['name' => 'Visit ownership Customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Visit ownership Site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Visit ownership Job',
            'scope' => 'Test scope',
            'status' => 'scheduled',
        ]);
        $visit = Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'unverified-event-id',
        ]);
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        Http::preventStrayRequests();

        $this->loginWithTotp($admin);
        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->where('runtimeReconciliation.visitCount', 1)
                ->where('runtimeReconciliation.visits.0.id', $visit->getKey())
                ->where('runtimeReconciliation.visits.0.ownerStatus', 'unverified')
                ->where('runtimeReconciliation.canConfirmVisitOwnership', true));

        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.confirm-owner', [
            'visit' => $visit->getKey(),
        ]), [
            'confirm_target_ownership' => true,
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2088',
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password');

        $visit = $visit->fresh();
        $this->assertSame('unverified-event-id', $visit->microsoft_graph_event_id);
        $this->assertSame($target->tenant_id, $visit->microsoft_graph_event_tenant_id);
        $this->assertSame($target->notification_mailbox_id, $visit->microsoft_graph_event_mailbox_id);
        $this->assertSame($target->notification_calendar_id, $visit->microsoft_graph_event_calendar_id);
        $this->assertSame('synced', $visit->graph_sync_status);

        $audit = AuditEvent::query()
            ->where('event', 'microsoft_graph_runtime_visit_event_owner_confirmed')
            ->sole();
        $this->assertSame($visit->getKey(), $audit->metadata['visit_id']);
        $this->assertSame('unverified-event-id', $audit->metadata['provider_event_id']);
        $this->assertSame('m365-admin@example.test', $audit->metadata['m365_admin_upn']);
        $this->assertFalse($audit->metadata['graph_request_performed']);
        Http::assertNothingSent();
    }

    public function test_first_admin_can_confirm_an_active_visit_event_owner_after_runtime_activation_without_graph_requests(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'runtime_activated_at' => now(),
            'runtime_activated_by_user_id' => $admin->getKey(),
        ])->save();
        config([
            'services.microsoft_graph.tenant_id' => $target->tenant_id,
            'services.microsoft_graph.notification_mailbox_id' => $target->notification_mailbox_id,
            'services.microsoft_graph.notification_calendar_id' => $target->notification_calendar_id,
        ]);
        ApplicationSetupState::query()->whereKey(1)->update([
            'readiness_completed_at' => now(),
            'onboarding_required' => true,
        ]);
        ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->update([
                'status' => ApplicationSetupStep::STATUS_PASSED,
                'completed_at' => now(),
            ]);
        $this->createPermissionVerification($target, $admin);
        $customer = Customer::create(['name' => 'Visit ownership customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Visit ownership site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Visit ownership job',
            'scope' => 'Test scope',
            'status' => 'scheduled',
        ]);
        $visit = Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'unverified-event-id',
        ]);
        Http::preventStrayRequests();

        $this->loginWithTotp($admin);
        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->where('runtimeReconciliation.visitCount', 1)
                ->where('runtimeReconciliation.visits.0.id', $visit->getKey())
                ->where('runtimeReconciliation.visits.0.ownerStatus', 'unverified')
                ->where('runtimeReconciliation.canConfirmVisitOwnership', true));

        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.confirm-owner', [
            'visit' => $visit->getKey(),
        ]), [
            'confirm_target_ownership' => true,
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2088',
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password');

        $visit = $visit->fresh();
        $this->assertSame('unverified-event-id', $visit->microsoft_graph_event_id);
        $this->assertSame($target->tenant_id, $visit->microsoft_graph_event_tenant_id);
        $this->assertSame($target->notification_mailbox_id, $visit->microsoft_graph_event_mailbox_id);
        $this->assertSame($target->notification_calendar_id, $visit->microsoft_graph_event_calendar_id);
        $this->assertSame('synced', $visit->graph_sync_status);

        $audit = AuditEvent::query()
            ->where('event', 'microsoft_graph_runtime_visit_event_owner_confirmed')
            ->sole();
        $this->assertSame($visit->getKey(), $audit->metadata['visit_id']);
        $this->assertSame('unverified-event-id', $audit->metadata['provider_event_id']);
        $this->assertSame('m365-admin@example.test', $audit->metadata['m365_admin_upn']);
        $this->assertFalse($audit->metadata['graph_request_performed']);
        Http::assertNothingSent();
    }

    public function test_first_admin_can_reconcile_an_unverified_visit_event_after_runtime_activation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'runtime_activated_at' => now(),
            'runtime_activated_by_user_id' => $admin->getKey(),
        ])->save();
        $customer = Customer::create(['name' => 'Old Visit owner customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Old Visit owner site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Old Visit owner job',
            'scope' => 'Test scope',
            'status' => 'scheduled',
        ]);
        $visit = Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Test Visit scope',
            'graph_sync_status' => 'synced',
            'microsoft_graph_event_id' => 'old-or-unknown-event-id',
        ]);
        Http::preventStrayRequests();

        $this->loginWithTotp($admin);
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.visits.store', [
            'visit' => $visit->getKey(),
        ]), [
            'confirm_remote_reconciliation' => true,
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2089',
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password');

        $visit = $visit->fresh();
        $this->assertNull($visit->microsoft_graph_event_id);
        $this->assertNull($visit->microsoft_graph_event_tenant_id);
        $this->assertNull($visit->microsoft_graph_event_mailbox_id);
        $this->assertSame('not_requested', $visit->graph_sync_status);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'microsoft_graph_runtime_visit_event_reconciled',
            'subject_id' => $target->getKey(),
        ]);
        Http::assertNothingSent();
    }

    public function test_admin_can_reconcile_an_unknown_legacy_visit_create_after_calendar_review(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config([
            'services.microsoft_graph.tenant_id' => 'legacy-tenant-id',
            'services.microsoft_graph.notification_mailbox_id' => 'legacy-mailbox-id',
            'services.microsoft_graph.notification_calendar_id' => null,
        ]);
        $customer = Customer::create(['name' => 'Unknown Visit Customer']);
        $site = Site::create(['customer_id' => $customer->getKey(), 'name' => 'Unknown Visit Site']);
        $workOrder = WorkOrder::create([
            'site_id' => $site->getKey(),
            'title' => 'Unknown Visit Job',
            'scope' => 'Test scope',
            'status' => 'scheduled',
        ]);
        $transactionId = (string) Str::uuid();
        $visit = Visit::create([
            'work_order_id' => $workOrder->getKey(),
            'scheduled_at' => now()->addDay(),
            'scope' => 'Unknown Visit scope',
            'graph_sync_status' => 'failed',
            'microsoft_graph_transaction_id' => $transactionId,
        ]);
        Http::preventStrayRequests();

        $reconciled = app(ReconcileMicrosoftGraphRuntimeTargetRecord::class)->visit($admin, (int) $visit->getKey(), [
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2046',
        ]);

        $this->assertNull($reconciled->microsoft_graph_transaction_id);
        $this->assertSame('not_requested', $reconciled->graph_sync_status);
        $audit = AuditEvent::query()->where('event', 'microsoft_graph_runtime_visit_event_reconciled')->sole();
        $this->assertSame($target->getKey(), $audit->subject_id);
        $this->assertSame($transactionId, $audit->metadata['transaction_id']);
        $this->assertTrue($audit->metadata['unknown_create_outcome']);
        $this->assertFalse($audit->metadata['graph_request_performed']);
        Http::assertNothingSent();
    }

    public function test_admin_can_manually_reconcile_a_legacy_outlook_contact_without_graph_requests(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config(['services.microsoft_graph.tenant_id' => 'legacy-tenant-id']);
        $contact = Contact::factory()->create(['first_name' => 'Legacy Outlook Contact']);
        $contact->forceFill([
            'outlook_contact_id' => 'legacy-outlook-contact-id',
            'outlook_contact_mailbox_id' => 'legacy-outlook-mailbox-id',
            'outlook_sync_status' => 'synced',
            'outlook_sync_requested_at' => now()->subDay(),
        ])->saveQuietly();
        Http::preventStrayRequests();

        $reconciled = app(ReconcileMicrosoftGraphRuntimeTargetRecord::class)->contact($admin, (int) $contact->getKey(), [
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2043',
        ]);

        $this->assertNull($reconciled->outlook_contact_id);
        $this->assertNull($reconciled->outlook_contact_mailbox_id);
        $this->assertSame('paused', $reconciled->outlook_sync_status);
        $this->assertSame('outlook_contact_target_reconciled', $reconciled->outlook_sync_error);
        $audit = AuditEvent::query()->where('event', 'microsoft_graph_runtime_outlook_contact_reconciled')->sole();
        $this->assertSame($target->getKey(), $audit->subject_id);
        $this->assertSame('legacy-outlook-contact-id', $audit->metadata['provider_contact_id']);
        $this->assertSame('legacy-outlook-mailbox-id', $audit->metadata['legacy_mailbox_id']);
        $this->assertFalse($audit->metadata['graph_request_performed']);
        Http::assertNothingSent();
    }

    public function test_admin_can_reconcile_an_unknown_legacy_contact_create_after_manual_folder_review(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config([
            'services.microsoft_graph.tenant_id' => 'legacy-tenant-id',
            'services.microsoft_graph.notification_mailbox_id' => 'legacy-mailbox-id',
        ]);
        $contact = Contact::factory()->create(['first_name' => 'Unknown legacy create']);
        $contact->forceFill([
            'outlook_contact_id' => null,
            'outlook_contact_mailbox_id' => null,
            'outlook_sync_status' => 'unknown',
            'outlook_sync_error' => 'outlook_contact_create_outcome_unknown',
        ])->saveQuietly();
        Http::preventStrayRequests();

        $reconciled = app(ReconcileMicrosoftGraphRuntimeTargetRecord::class)->contact($admin, (int) $contact->getKey(), [
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-2026-2045',
        ]);

        $this->assertSame('paused', $reconciled->outlook_sync_status);
        $audit = AuditEvent::query()->where('event', 'microsoft_graph_runtime_outlook_contact_reconciled')->sole();
        $this->assertSame($target->getKey(), $audit->subject_id);
        $this->assertNull($audit->metadata['provider_contact_id']);
        $this->assertTrue($audit->metadata['unknown_create_outcome']);
        $this->assertSame('legacy-mailbox-id', $audit->metadata['legacy_mailbox_id']);
        Http::assertNothingSent();
    }

    public function test_first_admin_can_confirm_legacy_contact_reconciliation_through_protected_route(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        config(['services.microsoft_graph.tenant_id' => 'legacy-tenant-id']);
        $contact = Contact::factory()->create(['first_name' => 'Reconcile through HTTP']);
        $contact->forceFill([
            'outlook_contact_id' => 'old-outlook-contact-id',
            'outlook_contact_mailbox_id' => 'old-mailbox-id',
            'outlook_sync_status' => 'synced',
        ])->saveQuietly();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.runtime-reconciliation.contacts.store', [
                'contact' => $contact->getKey(),
            ]), [
                'confirm_remote_reconciliation' => true,
                'm365_admin_upn' => 'm365-admin@example.test',
                'verification_reference' => 'CHG-2026-2044',
                'password' => 'password',
            ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertNull($contact->fresh()->outlook_contact_id);
        $audit = AuditEvent::query()->where('event', 'microsoft_graph_runtime_outlook_contact_reconciled')->sole();
        $this->assertSame($target->getKey(), $audit->subject_id);
        $this->assertSame('CHG-2026-2044', $audit->metadata['verification_reference']);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_graph_onboarding_rate_limits_are_relaxed_only_in_local_environment(): void
    {
        $originalEnvironment = $this->app->environment();
        $request = Request::create('/admin/integrations/microsoft-graph/onboarding-targets/send-linkback', 'POST');
        $linkbackLimiter = RateLimiter::limiter('graph-onboarding-linkback');
        $canaryLimiter = RateLimiter::limiter('graph-onboarding-canary-create');

        $this->assertNotNull($linkbackLimiter);
        $this->assertNotNull($canaryLimiter);

        try {
            $this->app->detectEnvironment(fn (): string => 'local');
            $localLinkbackLimit = $linkbackLimiter($request);
            $localCanaryLimit = $canaryLimiter($request);

            $this->assertSame(3, $localLinkbackLimit->maxAttempts);
            $this->assertSame(60, $localLinkbackLimit->decaySeconds);
            $this->assertSame(2, $localCanaryLimit->maxAttempts);
            $this->assertSame(60, $localCanaryLimit->decaySeconds);

            $this->app->detectEnvironment(fn (): string => 'production');
            $productionLinkbackLimit = $linkbackLimiter($request);
            $productionCanaryLimit = $canaryLimiter($request);

            $this->assertSame(3, $productionLinkbackLimit->maxAttempts);
            $this->assertSame(3600, $productionLinkbackLimit->decaySeconds);
            $this->assertSame(2, $productionCanaryLimit->maxAttempts);
            $this->assertSame(3600, $productionCanaryLimit->decaySeconds);
        } finally {
            $this->app->detectEnvironment(fn (): string => $originalEnvironment);
        }
    }

    public function test_admin_can_save_manual_targets_without_contacting_graph_or_bws(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        Http::preventStrayRequests();
        Process::fake();
        $targets = $this->targets();
        $targets['environment'] = 'production';

        $this->actingAs($admin)
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'));

        $target = MicrosoftGraphOnboardingTarget::query()->sole();
        $this->assertSame(app()->environment(), $target->environment);
        $this->assertSame('00000000-0000-0000-0000-000000000011', $target->tenant_id);
        $this->assertTrue($target->target_is_dedicated_shared_mailbox);
        $this->assertTrue($target->non_target_is_user_mailbox);
        $this->assertSame('pending', $target->validation_status);
        $this->assertNotNull($target->configured_at);
        $readinessStep = collect(app(OnboardingReadinessService::class)->snapshot()['steps'])
            ->firstWhere('key', ApplicationSetupStep::GRAPH_VALIDATION);
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, $readinessStep['status']);
        $this->assertNull($readinessStep['lastAttemptedAt']);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_saving_changed_targets_resets_prior_graph_validation(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $target = new MicrosoftGraphOnboardingTarget;
        $target->fill($this->targets());
        $target->forceFill([
            'environment' => app()->environment(),
            'configured_by_user_id' => $admin->getKey(),
            'configured_at' => now()->subDay(),
            'runtime_activated_at' => now()->subHour(),
            'runtime_activated_by_user_id' => $admin->getKey(),
            'validation_status' => 'verified',
            'last_validation_attempted_at' => now()->subDay(),
        ])->save();
        $permissionVerification = $this->createPermissionVerification($target, $admin);
        $challenge = GraphOnboardingChallenge::query()->create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'environment' => app()->environment(),
            'target_id' => $target->getKey(),
            'user_id' => $admin->getKey(),
            'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
            'token_hash' => hash('sha256', 'previous-linkback-token'),
            'recipient_email_hash' => hash('sha256', strtolower($admin->email)),
            'expires_at' => now()->addMinutes(10),
            'verified_at' => now(),
        ]);

        $changedTargets = $this->targets();
        $changedTargets['notification_mailbox_id'] = '00000000-0000-0000-0000-000000000022';

        $this->actingAs($admin)
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $changedTargets)
            ->assertRedirect();

        $target = MicrosoftGraphOnboardingTarget::query()->sole();
        $this->assertSame('pending', $target->validation_status);
        $this->assertNull($target->last_validation_attempted_at);
        $this->assertSame('00000000-0000-0000-0000-000000000022', $target->notification_mailbox_id);
        $this->assertNull($target->runtime_activated_at);
        $this->assertNull($target->runtime_activated_by_user_id);
        $this->assertSame(GraphOnboardingChallenge::STATUS_REPLACED, $challenge->fresh()->status);
        $this->assertModelExists($permissionVerification->fresh());
        $this->assertFalse($permissionVerification->matchesTarget($target->fresh()));
    }

    public function test_changing_non_target_user_mailbox_invalidates_saved_m365_permission_evidence(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $target = new MicrosoftGraphOnboardingTarget;
        $target->fill($this->targets());
        $target->forceFill([
            'environment' => app()->environment(),
            'configured_by_user_id' => $admin->getKey(),
            'configured_at' => now(),
        ])->save();
        $permissionVerification = $this->createPermissionVerification($target, $admin);
        $changedTargets = $this->targets();
        $changedTargets['non_target_mailbox_email'] = 'another-control@example.test';

        $this->actingAs($admin)
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $changedTargets)
            ->assertRedirect();

        $this->assertModelExists($permissionVerification->fresh());
        $this->assertFalse($permissionVerification->matchesTarget($target->fresh()));
        $this->assertSame('another-control@example.test', $target->fresh()->non_target_mailbox_email);
    }

    public function test_business_attachments_identifiers_may_remain_blank_until_the_site_is_approved(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $targets = $this->targets();
        $targets['business_attachments_site_id'] = '';
        $targets['business_attachments_drive_id'] = '';

        $this->actingAs($admin)
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertRedirect();

        $target = MicrosoftGraphOnboardingTarget::query()->sole();
        $this->assertNull($target->business_attachments_site_id);
        $this->assertNull($target->business_attachments_drive_id);
        $this->assertSame('pending', $target->validation_status);
    }

    public function test_invalid_or_incomplete_manual_target_identifiers_are_rejected(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $targets = $this->targets();
        unset($targets['work_schedules_drive_id']);
        $targets['tenant_id'] = 'not-a-tenant-guid';
        Http::preventStrayRequests();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertSessionHasErrors(['tenant_id', 'work_schedules_drive_id']);

        $this->assertDatabaseCount('microsoft_graph_onboarding_targets', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_graph_target_must_be_confirmed_as_a_dedicated_shared_mailbox(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $targets = $this->targets();
        $targets['target_is_dedicated_shared_mailbox'] = false;
        Http::preventStrayRequests();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertSessionHasErrors([
                'target_is_dedicated_shared_mailbox' => 'Confirm that the supplied object ID belongs to the dedicated shared mailbox specified by your Microsoft 365 administrator.',
            ]);

        $this->assertDatabaseCount('microsoft_graph_onboarding_targets', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_non_target_control_must_be_confirmed_as_a_user_mailbox(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $targets = $this->targets();
        $targets['non_target_is_user_mailbox'] = false;
        Http::preventStrayRequests();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertSessionHasErrors([
                'non_target_is_user_mailbox' => 'Confirm that the non-target control is an existing same-tenant user mailbox supplied by your Microsoft 365 administrator.',
            ]);

        $this->assertDatabaseCount('microsoft_graph_onboarding_targets', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_graph_preflight_makes_no_request_without_shared_mailbox_confirmation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill(['target_is_dedicated_shared_mailbox' => false])->save();
        Http::preventStrayRequests();

        try {
            app(ValidateMicrosoftGraphOnboardingTargets::class)->validate($admin);
            $this->fail('The preflight must refuse an unconfirmed mailbox target.');
        } catch (MicrosoftGraphApiException $exception) {
            $this->assertSame('graph_onboarding_shared_mailbox_confirmation_required', $exception->reason);
        }

        Http::assertNothingSent();
        $this->assertSame('pending', $target->fresh()->validation_status);
    }

    public function test_permission_boundary_requires_a_user_mailbox_control_without_graph_requests(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'non_target_is_user_mailbox' => false,
        ])->save();
        Http::preventStrayRequests();

        try {
            app(ValidateMicrosoftGraphPermissionBoundary::class)->validate($admin);
            $this->fail('The boundary probe must require an affirmed user-mailbox control.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'Confirm the selected target is a dedicated shared mailbox and the non-target control is an existing user mailbox before checking access boundaries.',
                $exception->errors()['confirm_boundary_check'][0],
            );
        }

        Http::assertNothingSent();
    }

    public function test_non_target_controls_must_be_distinct_from_saved_authorized_targets(): void
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $this->unlockAdminOnboardingAreaForTest($admin);
        $targets = $this->targets();
        $targets['non_target_mailbox_email'] = strtoupper($targets['notification_mailbox_address']);
        $targets['non_target_site_id'] = strtoupper($targets['work_schedules_site_id']);

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $targets)
            ->assertSessionHasErrors(['non_target_mailbox_email', 'non_target_site_id']);

        $this->assertDatabaseCount('microsoft_graph_onboarding_targets', 0);
    }

    public function test_basic_user_cannot_view_or_save_graph_onboarding_targets(): void
    {
        $basicUser = User::factory()->create(['status' => 'active']);

        $this->actingAs($basicUser)
            ->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertForbidden();

        $this->actingAs($basicUser)
            ->put(route('admin.integrations.microsoft-graph.onboarding-targets.store'), $this->targets())
            ->assertForbidden();

        $this->assertDatabaseCount('microsoft_graph_onboarding_targets', 0);
    }

    public function test_explicit_read_only_preflight_checks_saved_targets_without_marking_graph_readiness_complete(): void
    {
        $this->freezeTime();
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill(['notification_calendar_id' => null])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id, clientSecret: 'graph-onboarding-test-secret');
        $graphRequests = [];
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-onboarding-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests, $target) {
                $path = rawurldecode((string) parse_url($request->url(), PHP_URL_PATH));
                $graphRequests[] = [$request->method(), $path];

                return Http::response($this->graphTargetMetadata($path, $target));
            },
        ]);
        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();

        $response = $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access'), [
            'confirm_read_only_check' => true,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'));
        $target->refresh();
        $this->assertSame('read_verified', $target->validation_status);
        $this->assertNotNull($target->read_access_verified_at);
        $this->assertSame(5, $target->read_access_check_count);
        $this->assertCount(5, $graphRequests);
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/calendar', $graphRequests[0][1]);
        $this->assertNotContains('/v1.0/users/'.$target->notification_mailbox_id, array_column($graphRequests, 1));
        $this->assertNotContains('/v1.0/groups/'.$target->work_schedules_group_id, array_column($graphRequests, 1));
        $this->assertTrue(collect($graphRequests)->every(fn (array $request): bool => $request[0] === 'GET'));
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->value('status'));
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertSentCount(6);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() !== 'GET'
            && str_contains($request->url(), 'graph.microsoft.com'));
    }

    public function test_graph_permission_denial_is_sanitized_and_keeps_readiness_locked(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id, clientSecret: 'graph-onboarding-test-secret');
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-onboarding-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/*/calendars/*' => Http::response([
                'error' => ['code' => 'Authorization_RequestDenied', 'message' => 'private mailbox data must not leak'],
            ], 403),
        ]);
        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();

        $response = $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access'), [
            'confirm_read_only_check' => true,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error', 'Read-only Graph target checks did not complete (graph_permission_denied). No Graph data was written.');
        $this->assertSame('failed', $target->fresh()->validation_status);
        $this->assertSame('graph_permission_denied', $target->fresh()->last_error_code);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/users/'.$target->notification_mailbox_id.'/calendars/'.$target->notification_calendar_id));
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() !== 'GET'
            && str_contains($request->url(), 'graph.microsoft.com'));
    }

    public function test_read_boundary_probe_verifies_calendar_site_drive_and_contacts_folder_metadata(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);

        $graphRequests = [];
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-boundary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests, $target) {
                $graphRequests[] = $request;

                if (parse_url($request->url(), PHP_URL_PATH) === '/v1.0/users/'.$target->notification_mailbox_id.'/contactFolders') {
                    return Http::response(['value' => []]);
                }

                return Http::response([
                    'error' => ['code' => 'Authorization_RequestDenied', 'message' => 'control metadata is not available'],
                ], 403);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary'), [
            'confirm_boundary_check' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status');

        $target->refresh();
        $this->assertSame('denied', $target->non_target_mailbox_calendar_status);
        $this->assertSame('denied', $target->non_target_site_status);
        $this->assertSame('denied', $target->non_target_drive_status);
        $this->assertSame('accessible', $target->target_contacts_status);
        $this->assertSame('denied', $target->non_target_contacts_status);
        $this->assertNotNull($target->boundary_verified_at);
        $this->assertNotNull($target->contacts_boundary_verified_at);
        $this->assertNull($target->boundary_error_code);
        $this->assertNull($target->contacts_boundary_error_code);
        $this->assertCount(5, $graphRequests);
        $this->assertSame([
            '/v1.0/users/'.rawurlencode($target->non_target_mailbox_email).'/calendar',
            '/v1.0/sites/'.rawurlencode($target->non_target_site_id),
            '/v1.0/drives/'.$target->non_target_drive_id,
            '/v1.0/users/'.$target->notification_mailbox_id.'/contactFolders',
            '/v1.0/users/'.rawurlencode($target->non_target_mailbox_email).'/contactFolders',
        ], array_map(fn (HttpRequest $request): string => (string) parse_url($request->url(), PHP_URL_PATH), $graphRequests));
        $this->assertTrue(collect($graphRequests)->every(fn (HttpRequest $request): bool => $request->method() === 'GET'));
        $this->assertStringContainsString('%24top=1', $graphRequests[3]->url());
        $this->assertStringContainsString('%24select=id', $graphRequests[3]->url());
        $this->assertStringContainsString('%24top=1', $graphRequests[4]->url());
        $this->assertStringContainsString('%24select=id', $graphRequests[4]->url());
        $this->assertStringNotContainsString('/contacts?', $graphRequests[3]->url());
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_onboarding_non_target_denial_verified']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_onboarding_contacts_boundary_verified']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertSentCount(6);
    }

    public function test_accessible_or_not_found_non_target_probe_does_not_count_as_denial(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);

        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-boundary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/sites/*' => Http::response(['id' => $target->non_target_site_id], 200),
            'graph.microsoft.com/v1.0/drives/*' => Http::response([
                'error' => ['code' => 'itemNotFound', 'message' => 'control ID does not exist'],
            ], 404),
            'graph.microsoft.com/v1.0/users/'.rawurlencode($target->non_target_mailbox_email).'/contactFolders*' => Http::response([
                'value' => [['id' => 'non-target-contact-folder-id']],
            ]),
            'graph.microsoft.com/v1.0/*' => Http::response([
                'error' => ['code' => 'Authorization_RequestDenied', 'message' => 'not in scope'],
            ], 403),
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary'), [
            'confirm_boundary_check' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error');

        $target->refresh();
        $this->assertSame('denied', $target->non_target_mailbox_calendar_status);
        $this->assertSame('accessible', $target->non_target_site_status);
        $this->assertSame('inconclusive', $target->non_target_drive_status);
        $this->assertSame('denied', $target->target_contacts_status);
        $this->assertSame('accessible', $target->non_target_contacts_status);
        $this->assertNull($target->boundary_verified_at);
        $this->assertNull($target->contacts_boundary_verified_at);
        $this->assertSame('graph_non_target_access_allowed', $target->boundary_error_code);
        $this->assertSame('graph_non_target_contacts_access_allowed', $target->contacts_boundary_error_code);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_onboarding_non_target_denial_failed']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_onboarding_contacts_boundary_failed']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertSentCount(6);
    }

    public function test_boundary_results_are_discarded_if_control_targets_change_during_graph_requests(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $changed = false;

        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-boundary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use ($target, &$changed) {
                if (! $changed && str_contains($request->url(), '/users/')) {
                    MicrosoftGraphOnboardingTarget::query()->whereKey($target->getKey())->update([
                        'non_target_site_id' => 'example.sharepoint.com/replaced-control-site',
                    ]);
                    $changed = true;
                }

                return Http::response([
                    'error' => ['code' => 'Authorization_RequestDenied', 'message' => 'not in scope'],
                ], 403);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary'), [
            'confirm_boundary_check' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error');

        $target->refresh();
        $this->assertSame('not_checked', $target->non_target_mailbox_calendar_status);
        $this->assertSame('not_checked', $target->non_target_site_status);
        $this->assertSame('not_checked', $target->non_target_drive_status);
        $this->assertNull($target->boundary_verified_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'microsoft_graph_onboarding_non_target_denial_stale_result_discarded',
        ]);
        Http::assertSentCount(6);
    }

    public function test_non_target_probe_requires_the_current_password_and_never_calls_providers_when_it_is_wrong(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->loginWithTotp($admin);
        Http::preventStrayRequests();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-permission-boundary'), [
                'confirm_boundary_check' => true,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        Http::assertNothingSent();
        Process::assertNothingRan();
        $this->assertNull($target->fresh()->boundary_validation_attempted_at);
    }

    public function test_first_admin_can_create_and_conditionally_clean_a_calendar_canary(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $graphRequests = [];

        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-calendar-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests) {
                $graphRequests[] = $request;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/events')) {
                    return Http::response([
                        'id' => 'temporary-calendar-canary-id',
                        '@odata.etag' => 'W/"calendar-canary-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/events/temporary-calendar-canary-id')) {
                    return Http::response('', 204);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANED, $canary->status);
        $this->assertSame('temporary-calendar-canary-id', $canary->remote_resource_id);
        $this->assertSame('W/"calendar-canary-version-1"', $canary->remote_etag);
        $this->assertNotNull($canary->created_remotely_at);
        $this->assertNotNull($canary->cleaned_at);
        $this->assertCount(2, $graphRequests);

        $createRequest = $graphRequests[0];
        $this->assertSame('POST', $createRequest->method());
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/calendars/'.$target->notification_calendar_id.'/events', parse_url($createRequest->url(), PHP_URL_PATH));
        $payload = $createRequest->data();
        $this->assertSame($canary->subject, $payload['subject']);
        $this->assertSame($canary->transaction_id, $payload['transactionId']);
        $this->assertSame([], $payload['attendees']);
        $this->assertSame('free', $payload['showAs']);
        $this->assertSame('private', $payload['sensitivity']);
        $this->assertStringContainsString('No customer, Job, or staff data', $payload['body']['content']);

        $cleanupRequest = $graphRequests[1];
        $this->assertSame('DELETE', $cleanupRequest->method());
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/calendars/'.$target->notification_calendar_id.'/events/temporary-calendar-canary-id', parse_url($cleanupRequest->url(), PHP_URL_PATH));
        $this->assertSame(['W/"calendar-canary-version-1"'], $cleanupRequest->header('If-Match'));
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_calendar_canary_create_started']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_calendar_canary_created']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_calendar_canary_cleanup_started']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_calendar_canary_cleaned']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_ambiguous_calendar_canary_create_is_durable_and_blocks_blind_duplicate_creation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-calendar-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => Http::failedConnection(),
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CREATE_UNKNOWN, $canary->status);
        $this->assertNull($canary->remote_resource_id);
        $this->assertSame('graph_calendar_canary_create_unknown', $canary->failure_code);
        Http::assertSentCount(2);

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password');

        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 1);
        Http::assertSentCount(2);
    }

    public function test_calendar_canary_cleanup_failure_is_visible_and_can_be_conditionally_retried(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $deleteAttempt = 0;
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-calendar-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$deleteAttempt) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/events')) {
                    return Http::response([
                        'id' => 'temporary-calendar-canary-id',
                        '@odata.etag' => 'W/"calendar-canary-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE') {
                    $deleteAttempt++;

                    return $deleteAttempt === 1
                        ? Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)
                        : Http::response(['error' => ['code' => 'itemNotFound']], 404);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_FAILED, $canary->status);
        $this->assertSame('temporary-calendar-canary-id', $canary->remote_resource_id);
        $this->assertNull($canary->cleaned_at);

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.cleanup', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANED, $canary->fresh()->status);
        $this->assertNotNull($canary->fresh()->cleaned_at);
        Http::assertSentCount(4);
    }

    public function test_calendar_canary_etag_conflict_requires_manual_reconciliation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-calendar-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/events')) {
                    return Http::response([
                        'id' => 'temporary-calendar-canary-id',
                        '@odata.etag' => 'W/"calendar-canary-version-1"',
                    ], 201);
                }

                return Http::response(['error' => ['code' => 'preconditionFailed']], 412);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_CONFLICT, $canary->status);
        $this->assertSame('graph_resource_changed', $canary->failure_code);
        $this->assertNull($canary->cleaned_at);

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.reconcile', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_MANUALLY_RECONCILED, $canary->fresh()->status);
        $this->assertNotNull($canary->fresh()->reconciled_at);
        $this->assertSame($admin->getKey(), $canary->fresh()->reconciled_by_user_id);
        Http::assertSentCount(3);
    }

    public function test_first_admin_can_create_and_conditionally_clean_a_contacts_canary(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $graphRequests = [];

        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-contact-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests) {
                $graphRequests[] = $request;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/contacts')) {
                    return Http::response([
                        'id' => 'temporary-contact-canary-id',
                        '@odata.etag' => 'W/"contact-canary-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/contacts/temporary-contact-canary-id')) {
                    return Http::response('', 204);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('contacts_canary_feedback.type', 'success')
            ->assertSessionMissing('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::RESOURCE_CONTACT, $canary->resource_type);
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANED, $canary->status);
        $this->assertSame('temporary-contact-canary-id', $canary->remote_resource_id);
        $this->assertSame('W/"contact-canary-version-1"', $canary->remote_etag);
        $this->assertNotNull($canary->created_remotely_at);
        $this->assertNotNull($canary->cleaned_at);
        $this->assertCount(2, $graphRequests);

        $createRequest = $graphRequests[0];
        $this->assertSame('POST', $createRequest->method());
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/contacts', parse_url($createRequest->url(), PHP_URL_PATH));
        $payload = $createRequest->data();
        $this->assertSame('FieldOps Setup', $payload['givenName']);
        $this->assertStringContainsString('Canary ', $payload['surname']);
        $this->assertSame('FieldOps Setup Canary', $payload['companyName']);
        $this->assertArrayNotHasKey('emailAddresses', $payload);
        $this->assertArrayNotHasKey('businessPhones', $payload);
        $this->assertArrayNotHasKey('mobilePhone', $payload);
        $this->assertStringContainsString('No personal or business data', $payload['personalNotes']);

        $cleanupRequest = $graphRequests[1];
        $this->assertSame('DELETE', $cleanupRequest->method());
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/contacts/temporary-contact-canary-id', parse_url($cleanupRequest->url(), PHP_URL_PATH));
        $this->assertSame(['W/"contact-canary-version-1"'], $cleanupRequest->header('If-Match'));
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_contact_canary_create_started']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_contact_canary_created']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_contact_canary_cleaned']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_contacts_canary_permission_failure_is_shown_inline_at_the_canary(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-contact-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => Http::response([
                'error' => ['code' => 'ErrorAccessDenied'],
            ], 403),
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas(
                'contacts_canary_feedback.message',
                'Microsoft Graph denied the Contacts canary create (graph_permission_denied). No Contact was created. Check the selected-mailbox Contacts.ReadWrite scope and its propagation before retrying.',
            )
            ->assertSessionMissing('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_FAILED, $canary->status);
        $this->assertSame('graph_permission_denied', $canary->failure_code);
        $this->assertNull($canary->remote_resource_id);
        $this->assertNull($canary->created_remotely_at);

        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MicrosoftGraphOnboardingTargets')
                ->where('contactsCanaryFeedback.type', 'error')
                ->where('contactsCanaryFeedback.message', 'Microsoft Graph denied the Contacts canary create (graph_permission_denied). No Contact was created. Check the selected-mailbox Contacts.ReadWrite scope and its propagation before retrying.')
                ->where('error', null));

        Http::assertSentCount(2);
    }

    public function test_contacts_canary_cleanup_retry_remains_available_with_a_custom_calendar_configured(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->assertNotNull($target->notification_calendar_id);
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $contactDeleteCount = 0;
        $graphRequestCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-contact-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$contactDeleteCount, &$graphRequestCount) {
                $graphRequestCount++;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/contacts')) {
                    return Http::response([
                        'id' => 'temporary-contact-canary-id',
                        '@odata.etag' => 'W/"contact-canary-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/contacts/temporary-contact-canary-id')) {
                    $contactDeleteCount++;

                    return $contactDeleteCount === 1
                        ? Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)
                        : Http::response('', 204);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHas('contacts_canary_feedback.type', 'error')
            ->assertSessionMissing('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_FAILED, $canary->status);

        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->where('lastContactCanary.status', MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_FAILED)
                ->where('outstandingContactsCanaries.0.canRetryCleanup', true));

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.cleanup', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('contacts_canary_feedback.type', 'success');

        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANED, $canary->fresh()->status);
        $this->assertSame(2, $contactDeleteCount);
        $this->assertSame(3, $graphRequestCount);
    }

    public function test_first_admin_records_m365_permission_verification_against_the_current_saved_targets(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        GraphOnboardingChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'environment' => app()->environment(),
            'target_id' => $target->getKey(),
            'user_id' => $admin->getKey(),
            'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
            'token_hash' => hash('sha256', 'permission-verification-linkback'),
            'recipient_email_hash' => hash('sha256', strtolower($admin->email)),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.permission-verification.store'), [
                'confirm_scope_verification' => true,
                'm365_admin_upn' => 'm365-admin@example.test',
                'verification_reference' => 'CHG-2026-1042',
                'mail_send_target_verified' => true,
                'mail_send_non_target_denied' => true,
                'calendar_write_target_verified' => true,
                'calendar_write_non_target_denied' => true,
                'contacts_boundary_verified' => true,
                'sharepoint_boundary_verified' => true,
                'sharepoint_retention_reviewed' => true,
                'unscoped_app_roles_reviewed' => true,
                'password' => 'password',
            ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $verification = MicrosoftGraphOnboardingPermissionVerification::query()->sole();
        $this->assertTrue($verification->matchesTarget($target->fresh()));
        $this->assertSame('m365-admin@example.test', $verification->m365_admin_upn);
        $this->assertSame('CHG-2026-1042', $verification->verification_reference);
        $this->assertTrue($verification->target_is_dedicated_shared_mailbox);
        $this->assertTrue($verification->non_target_is_user_mailbox);
        $this->assertSame('control@example.test', $verification->non_target_mailbox_email);
        $this->assertSame($admin->getKey(), $verification->verified_by_user_id);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'microsoft_graph_permission_verification_recorded',
            'subject_id' => $target->getKey(),
        ]);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_m365_permission_verification_reference_validation_explains_the_expected_format(): void
    {
        [$admin] = $this->configuredAdminAndTarget();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.permission-verification.store'), [
                'confirm_scope_verification' => true,
                'm365_admin_upn' => 'm365-admin@example.test',
                'verification_reference' => '2026 change 1042',
                'mail_send_target_verified' => true,
                'mail_send_non_target_denied' => true,
                'calendar_write_target_verified' => true,
                'calendar_write_non_target_denied' => true,
                'contacts_boundary_verified' => true,
                'sharepoint_boundary_verified' => true,
                'sharepoint_retention_reviewed' => true,
                'unscoped_app_roles_reviewed' => true,
                'password' => 'password',
            ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHasErrors([
                'verification_reference' => 'Use a short reference such as CHG-2026-1042. Start with a letter, include no spaces, and separate the prefix and reference with -, : or #.',
            ])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('microsoft_graph_onboarding_permission_verifications', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_first_admin_can_create_and_conditionally_delete_a_business_attachments_sharepoint_canary(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createPermissionVerification($target, $admin);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $graphRequests = [];
        $this->assertDatabaseHas('microsoft_graph_onboarding_targets', [
            'id' => $target->getKey(),
            'environment' => app()->environment(),
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests) {
                $graphRequests[] = $request;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/root/children')) {
                    return Http::response([
                        'id' => 'temporary-sharepoint-folder-id',
                        'eTag' => '"folder-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/items/temporary-sharepoint-folder-id')) {
                    return Http::response('', 204);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status');

        $canary = MicrosoftGraphSharePointOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED, $canary->status);
        $this->assertSame($target->business_attachments_site_id, $canary->site_id);
        $this->assertSame($target->business_attachments_drive_id, $canary->drive_id);
        $this->assertSame('temporary-sharepoint-folder-id', $canary->remote_item_id);
        $this->assertSame('"folder-version-1"', $canary->remote_etag);
        $this->assertNotNull($canary->created_remotely_at);
        $this->assertNotNull($canary->cleaned_at);
        $this->assertCount(2, $graphRequests);

        $createRequest = $graphRequests[0];
        $this->assertSame('POST', $createRequest->method());
        $this->assertSame('/v1.0/drives/'.$target->business_attachments_drive_id.'/root/children', parse_url($createRequest->url(), PHP_URL_PATH));
        $this->assertSame($canary->folder_name, $createRequest->data()['name']);
        $this->assertArrayNotHasKey('file', $createRequest->data());
        $this->assertSame('fail', $createRequest->data()['@microsoft.graph.conflictBehavior']);

        $cleanupRequest = $graphRequests[1];
        $this->assertSame('DELETE', $cleanupRequest->method());
        $this->assertSame('/v1.0/drives/'.$target->business_attachments_drive_id.'/items/temporary-sharepoint-folder-id', parse_url($cleanupRequest->url(), PHP_URL_PATH));
        $this->assertSame(['"folder-version-1"'], $cleanupRequest->header('If-Match'));
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_sharepoint_canary_created']);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_sharepoint_canary_cleaned']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_sharepoint_canary_etag_conflict_stays_blocked_until_manual_reconciliation(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createPermissionVerification($target, $admin);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $graphRequestCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequestCount) {
                $graphRequestCount++;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/root/children')) {
                    return Http::response([
                        'id' => 'temporary-sharepoint-folder-id',
                        'eTag' => '"folder-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/items/temporary-sharepoint-folder-id')) {
                    return Http::response(['error' => ['code' => 'preconditionFailed']], 412);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('error');

        $canary = MicrosoftGraphSharePointOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANUP_CONFLICT, $canary->status);
        $this->assertSame('graph_resource_changed', $canary->failure_code);
        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->where('lastSharePointCanary.status', MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANUP_CONFLICT)
                ->where('outstandingSharePointCanaries.0.canManuallyReconcile', true));

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.reconcile', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(MicrosoftGraphSharePointOnboardingCanary::STATUS_MANUALLY_RECONCILED, $canary->fresh()->status);
        $this->assertNotNull($canary->fresh()->reconciled_at);
        $this->assertSame(2, $graphRequestCount);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    public function test_first_admin_can_retry_a_failed_sharepoint_cleanup_with_the_saved_etag(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createPermissionVerification($target, $admin);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $deleteCount = 0;
        $graphRequests = [];
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-sharepoint-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$deleteCount, &$graphRequests) {
                $graphRequests[] = $request;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/root/children')) {
                    return Http::response([
                        'id' => 'temporary-sharepoint-folder-id',
                        'eTag' => '"folder-version-1"',
                    ], 201);
                }

                if ($request->method() === 'DELETE' && str_ends_with($path, '/items/temporary-sharepoint-folder-id')) {
                    $deleteCount++;

                    return $deleteCount === 1
                        ? Http::response(['error' => ['code' => 'Authorization_RequestDenied']], 403)
                        : Http::response('', 204);
                }

                return Http::response(['error' => ['code' => 'unexpected_test_request']], 500);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('error');

        $canary = MicrosoftGraphSharePointOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANUP_FAILED, $canary->status);
        $this->get(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertInertia(fn ($page) => $page
                ->where('outstandingSharePointCanaries.0.canRetryCleanup', true));

        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.cleanup', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED, $canary->fresh()->status);
        $this->assertSame(2, $deleteCount);
        $this->assertCount(3, $graphRequests);
        $this->assertSame(['"folder-version-1"'], $graphRequests[1]->header('If-Match'));
        $this->assertSame(['"folder-version-1"'], $graphRequests[2]->header('If-Match'));
    }

    public function test_sharepoint_canary_refuses_permission_evidence_for_a_previous_drive_target(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createPermissionVerification($target, $admin);
        $target->forceFill(['business_attachments_drive_id' => 'replacement-drive-id'])->save();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('microsoft_graph_sharepoint_onboarding_canaries', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_sharepoint_canary_refuses_targets_without_mailbox_type_confirmations(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createPermissionVerification($target, $admin);
        $target->forceFill([
            'target_is_dedicated_shared_mailbox' => false,
            'non_target_is_user_mailbox' => false,
        ])->save();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.sharepoint-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('microsoft_graph_sharepoint_onboarding_canaries', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_first_admin_can_finalize_graph_readiness_only_after_every_current_graph_proof_passes(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->createVerifiedLinkback($target, $admin);
        $this->createPermissionVerification($target, $admin);
        $this->createPassedGraphCanaries($target, $admin);
        $this->createPassedSharePointCanary($target, $admin);
        Http::preventStrayRequests();
        Process::fake();

        $readiness = app(OnboardingReadinessService::class)->snapshot();
        $this->assertTrue($readiness['graphFinalizationAvailable']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.finalize-readiness'), [
            'confirm_finalize_graph' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status');

        $step = ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->sole();
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, $step->status);
        $this->assertSame($admin->getKey(), $step->actor_user_id);
        $this->assertNotNull($step->completed_at);
        $this->assertDatabaseHas('audit_events', ['event' => 'microsoft_graph_onboarding_readiness_finalized']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_graph_readiness_finalizer_refuses_to_pass_without_permission_evidence_and_all_three_canaries(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.finalize-readiness'), [
            'confirm_finalize_graph' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_finalize_graph')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertSame(
            ApplicationSetupStep::STATUS_PENDING,
            ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->value('status'),
        );
        $this->assertDatabaseMissing('audit_events', ['event' => 'microsoft_graph_onboarding_readiness_finalized']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_cleanup_does_not_use_a_new_mailbox_after_target_configuration_changes(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $graphRequests = [];
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-calendar-canary-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/*' => function (HttpRequest $request) use (&$graphRequests) {
                $graphRequests[] = $request;
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($request->method() === 'POST' && str_ends_with($path, '/events')) {
                    return Http::response([
                        'id' => 'temporary-calendar-canary-id',
                        '@odata.etag' => 'W/"calendar-canary-version-1"',
                    ], 201);
                }

                return Http::failedConnection();
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()->assertSessionHas('error');

        $canary = MicrosoftGraphOnboardingCanary::query()->sole();
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN, $canary->status);
        $this->assertCount(2, $graphRequests);

        $target->forceFill(['notification_mailbox_id' => '00000000-0000-0000-0000-000000000088'])->save();
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.cleanup', ['canary' => $canary->getKey()]), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password');

        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_UNKNOWN, $canary->fresh()->status);
        $this->assertCount(2, $graphRequests);
    }

    public function test_calendar_canary_requires_the_saved_boundary_check_and_current_password(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        Http::preventStrayRequests();
        Process::fake();
        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();

        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();

        $this->readyForCalendarCanary($target);
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'wrong-password',
        ])->assertRedirect()
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');

        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 0);
        Http::assertNothingSent();
    }

    public function test_contacts_canary_requires_the_selected_and_non_target_contacts_folder_boundary(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'target_contacts_status' => 'accessible',
            'non_target_contacts_status' => 'accessible',
            'contacts_boundary_verified_at' => null,
            'contacts_boundary_error_code' => 'graph_non_target_contacts_access_allowed',
        ])->save();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.contact-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_canary_action')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_unresolved_calendar_canary_overrides_a_passed_graph_readiness_step(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        ApplicationSetupStep::query()->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->update([
            'status' => ApplicationSetupStep::STATUS_PASSED,
            'completed_at' => now(),
        ]);
        $challenge = GraphOnboardingChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'environment' => app()->environment(),
            'target_id' => $target->getKey(),
            'user_id' => $admin->getKey(),
            'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
            'token_hash' => hash('sha256', 'readiness-linkback-token'),
            'recipient_email_hash' => hash('sha256', strtolower($admin->email)),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
        $this->createPermissionVerification($target, $admin);
        $this->createPassedSharePointCanary($target, $admin);
        MicrosoftGraphOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => app()->environment(),
            'resource_type' => MicrosoftGraphOnboardingCanary::RESOURCE_CONTACT,
            'status' => MicrosoftGraphOnboardingCanary::STATUS_CLEANED,
            'transaction_id' => (string) Str::uuid(),
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'mailbox_id' => $target->notification_mailbox_id,
            'subject' => 'Completed Contacts canary fixture',
            'attempted_at' => now()->subMinute(),
            'created_remotely_at' => now()->subMinute(),
            'cleaned_at' => now(),
        ]);
        $canary = MicrosoftGraphOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => app()->environment(),
            'resource_type' => MicrosoftGraphOnboardingCanary::RESOURCE_CALENDAR_EVENT,
            'status' => MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_FAILED,
            'transaction_id' => (string) Str::uuid(),
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'mailbox_id' => $target->notification_mailbox_id,
            'calendar_id' => $target->notification_calendar_id,
            'subject' => 'Readiness cleanup blocker canary',
            'remote_resource_id' => 'remote-calendar-event-id',
            'remote_etag' => 'W/"etag"',
            'attempted_at' => now(),
            'created_remotely_at' => now(),
            'failure_code' => 'graph_permission_denied',
        ]);

        $graphStep = collect(app(OnboardingReadinessService::class)->snapshot()['steps'])
            ->firstWhere('key', ApplicationSetupStep::GRAPH_VALIDATION);

        $this->assertSame(GraphOnboardingChallenge::STATUS_VERIFIED, $challenge->status);
        $this->assertSame(MicrosoftGraphOnboardingCanary::STATUS_CLEANUP_FAILED, $canary->status);
        $this->assertSame(ApplicationSetupStep::STATUS_FAILED, $graphStep['status']);
        $this->assertStringContainsString('outstanding Calendar canary cleanup', $graphStep['message']);
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());

        $canary->forceFill([
            'status' => MicrosoftGraphOnboardingCanary::STATUS_CLEANED,
            'cleaned_at' => now(),
            'failure_code' => null,
        ])->save();

        $resolvedGraphStep = collect(app(OnboardingReadinessService::class)->snapshot()['steps'])
            ->firstWhere('key', ApplicationSetupStep::GRAPH_VALIDATION);
        $this->assertSame(ApplicationSetupStep::STATUS_PASSED, $resolvedGraphStep['status']);
    }

    public function test_another_admin_cannot_create_the_first_admins_calendar_canary(): void
    {
        [, $target] = $this->configuredAdminAndTarget();
        $this->readyForCalendarCanary($target);
        $otherAdmin = User::factory()->admin()->create(['status' => 'active']);
        $otherSecret = app(Google2FA::class)->generateSecretKey();
        $otherAdmin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($otherSecret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['other-recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        Http::preventStrayRequests();
        Process::fake();

        $this->loginWithTotp($otherAdmin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.calendar-canaries.store'), [
            'confirm_canary_action' => true,
            'password' => 'password',
        ])->assertForbidden();

        $this->assertDatabaseCount('microsoft_graph_onboarding_canaries', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_calendar_canary_history_survives_actor_deletion_and_prevents_target_deletion(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $canary = MicrosoftGraphOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => app()->environment(),
            'resource_type' => MicrosoftGraphOnboardingCanary::RESOURCE_CALENDAR_EVENT,
            'status' => MicrosoftGraphOnboardingCanary::STATUS_FAILED,
            'transaction_id' => (string) Str::uuid(),
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'mailbox_id' => $target->notification_mailbox_id,
            'calendar_id' => $target->notification_calendar_id,
            'subject' => 'Calendar canary history fixture',
            'attempted_at' => now(),
            'failure_code' => 'graph_permission_denied',
        ]);

        $admin->delete();

        $canary->refresh();
        $this->assertNull($canary->actor_user_id);
        $this->assertModelExists($canary);

        try {
            $target->delete();
            $this->fail('A target with canary history must not be deleted.');
        } catch (QueryException) {
            $this->assertModelExists($canary);
        }
    }

    public function test_bws_client_id_must_match_the_saved_target_before_any_entra_or_graph_request(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: '00000000-0000-0000-0000-000000000099');
        Http::preventStrayRequests();
        Http::fake([]);
        $this->loginWithTotp($admin);
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access'), [
            'confirm_read_only_check' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error', 'Read-only Graph target checks did not complete (graph_onboarding_client_id_mismatch). No Graph data was written.');

        Http::assertNothingSent();
        $this->assertSame('failed', $target->fresh()->validation_status);
        $this->assertSame('graph_onboarding_client_id_mismatch', $target->fresh()->last_error_code);
    }

    public function test_read_only_preflight_requires_the_current_password_without_calling_providers(): void
    {
        [$admin] = $this->configuredAdminAndTarget();
        $this->loginWithTotp($admin);
        Http::preventStrayRequests();
        Process::fake();

        $this->actingAs($admin)
            ->from(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->post(route('admin.integrations.microsoft-graph.onboarding-targets.validate-read-access'), [
                'confirm_read_only_check' => true,
                'password' => 'wrong-password',
            ])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password');

        Process::assertNothingRan();
        Http::assertNothingSent();
    }

    public function test_first_admin_can_send_and_explicitly_confirm_a_graph_linkback_without_unlocking_invitations(): void
    {
        $this->freezeTime();
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now()->subMinute(),
            'read_access_check_count' => 5,
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);

        $graphRequests = [];
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-linkback-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/*/sendMail' => function (HttpRequest $request) use (&$graphRequests) {
                $graphRequests[] = $request;

                return Http::response('', 202);
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.send-linkback'), [
            'confirm_send' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('status');

        $challenge = GraphOnboardingChallenge::query()->sole();
        $this->assertSame(GraphOnboardingChallenge::STATUS_SENT, $challenge->status);
        $this->assertNotNull($challenge->sent_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'graph_onboarding_linkback_send_started',
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'graph_onboarding_linkback_send_accepted',
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
        ]);
        $this->assertCount(1, $graphRequests);
        $request = $graphRequests[0];
        $this->assertSame('POST', $request->method());
        $this->assertSame('/v1.0/users/'.$target->notification_mailbox_id.'/sendMail', parse_url($request->url(), PHP_URL_PATH));
        $this->assertSame($admin->email, data_get($request->data(), 'message.toRecipients.0.emailAddress.address'));
        $this->assertFalse(data_get($request->data(), 'saveToSentItems'));
        $messageBody = data_get($request->data(), 'message.body.content');
        $this->assertIsString($messageBody);
        $this->assertStringContainsString('No customer or Job information is included', $messageBody);
        $this->assertDoesNotMatchRegularExpression('/customer\/[0-9]+|Job #[0-9]+/i', $messageBody);

        preg_match('/https?:\/\/[^\s]+#token=([A-Za-z0-9]{64})/', $messageBody, $matches);
        $this->assertCount(2, $matches);
        $linkUrl = strstr($messageBody, 'http://');
        $linkUrl = $linkUrl === false ? strstr($messageBody, 'https://') : $linkUrl;
        $linkUrl = is_string($linkUrl) ? explode("\n", $linkUrl)[0] : '';
        $signedUrl = strstr($linkUrl, '#token=', true);
        $this->assertIsString($signedUrl);
        $this->assertStringNotContainsString($matches[1], $signedUrl);
        $this->assertNotSame($matches[1], $challenge->token_hash);
        $this->assertSame(hash('sha256', $matches[1]), $challenge->token_hash);

        $this->get($signedUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/GraphOnboardingLinkback')
                ->where('verified', false))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(GraphOnboardingChallenge::STATUS_SENT, $challenge->fresh()->status);

        $this->post($signedUrl, ['token' => $matches[1]])
            ->assertStatus(303)
            ->assertRedirect($signedUrl);

        $this->get($signedUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/GraphOnboardingLinkback')
                ->where('verified', true));

        $this->get($signedUrl, [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        ])
            ->assertOk()
            ->assertJsonPath('component', 'Auth/GraphOnboardingLinkback')
            ->assertJsonPath('props.verified', true)
            ->assertHeader('X-Inertia', 'true');

        $this->assertSame(GraphOnboardingChallenge::STATUS_VERIFIED, $challenge->fresh()->status);
        $this->assertNotNull($challenge->fresh()->verified_at);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'graph_onboarding_linkback_verified',
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
        ]);
        $verifiedAudit = AuditEvent::query()
            ->where('event', 'graph_onboarding_linkback_verified')
            ->firstOrFail();
        $this->assertSame($challenge->getKey(), $verifiedAudit->metadata['challenge_id']);
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->value('status'));
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
        Http::assertSentCount(2);
        Http::assertNotSent(fn (HttpRequest $sent): bool => str_contains($sent->url(), 'graph.microsoft.com')
            && $sent->method() !== 'POST');
    }

    public function test_graph_linkback_requires_a_successful_saved_target_preflight(): void
    {
        [$admin] = $this->configuredAdminAndTarget();
        Http::preventStrayRequests();
        Process::fake();
        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();

        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.send-linkback'), [
            'confirm_send' => true,
            'password' => 'password',
        ])->assertRedirect()
            ->assertSessionHasErrors('confirm_send')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.totp_code');

        $this->assertDatabaseCount('graph_onboarding_challenges', 0);
        Http::assertNothingSent();
        Process::assertNothingRan();
    }

    public function test_graph_linkback_refuses_invalid_confirmation_without_leaking_the_token_to_old_input(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        $failedRequest = null;
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-linkback-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/*/sendMail' => Http::response('', 202),
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.send-linkback'), [
            'confirm_send' => true,
            'password' => 'password',
        ])->assertRedirect();

        $challenge = GraphOnboardingChallenge::query()->sole();
        $signedUrl = URL::temporarySignedRoute(
            'graph.onboarding.linkback.verify',
            $challenge->expires_at,
            ['challenge' => $challenge->getKey()],
        );

        $this->from($signedUrl)
            ->post($signedUrl, ['token' => 'not-a-valid-token'])
            ->assertSessionHasErrors('token')
            ->assertSessionMissing('_old_input.token');

        $this->assertSame(GraphOnboardingChallenge::STATUS_SENT, $challenge->fresh()->status);
        $this->assertDatabaseMissing('audit_events', ['event' => 'graph_onboarding_linkback_verified']);
    }

    public function test_graph_linkback_timeout_is_recorded_as_unknown_without_automatic_retry(): void
    {
        [$admin, $target] = $this->configuredAdminAndTarget();
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
        ])->save();
        $this->fakeMicrosoftGraphBwsCredentials(clientId: $target->client_id);
        Http::preventStrayRequests();
        Http::fake([
            'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'mock-linkback-token',
                'expires_in' => 3600,
            ]),
            'graph.microsoft.com/v1.0/users/*/sendMail' => function (HttpRequest $request) use (&$failedRequest) {
                $failedRequest = $request;

                return Http::failedConnection();
            },
        ]);

        $this->loginWithTotp($admin);
        $this->travel(31)->seconds();
        $this->post(route('admin.integrations.microsoft-graph.onboarding-targets.send-linkback'), [
            'confirm_send' => true,
            'password' => 'password',
        ])->assertRedirect(route('admin.integrations.microsoft-graph.onboarding-targets.show'))
            ->assertSessionHas('error', 'Graph did not confirm whether the linkback email was accepted. Check the recipient mailbox before requesting another challenge.');

        $challenge = GraphOnboardingChallenge::query()->sole();
        $this->assertSame(GraphOnboardingChallenge::STATUS_UNKNOWN, $challenge->status);
        $this->assertSame('graph_linkback_delivery_unknown', $challenge->failure_code);
        $this->assertNull($challenge->sent_at);
        $this->assertSame(ApplicationSetupStep::STATUS_FAILED, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->value('status'));
        Http::assertSentCount(2);

        $this->assertInstanceOf(HttpRequest::class, $failedRequest);
        $messageBody = data_get($failedRequest->data(), 'message.body.content');
        preg_match('/https?:\/\/[^\s]+#token=([A-Za-z0-9]{64})/', (string) $messageBody, $matches);
        $this->assertCount(2, $matches);
        $link = strstr((string) $messageBody, 'http://');
        $link = $link === false ? strstr((string) $messageBody, 'https://') : $link;
        $link = is_string($link) ? explode("\n", $link)[0] : '';
        $signedUrl = strstr($link, '#token=', true);
        $this->assertIsString($signedUrl);

        $this->post($signedUrl, ['token' => $matches[1]])
            ->assertStatus(303)
            ->assertRedirect($signedUrl);
        $this->assertSame(GraphOnboardingChallenge::STATUS_VERIFIED, $challenge->fresh()->status);
        $this->assertSame(ApplicationSetupStep::STATUS_IN_PROGRESS, ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)->value('status'));
        $this->assertFalse(app(OnboardingReadinessService::class)->invitationsUnlocked());
    }

    /** @return array<string, mixed> */
    private function targets(): array
    {
        return [
            'tenant_id' => '00000000-0000-0000-0000-000000000011',
            'client_id' => '00000000-0000-0000-0000-000000000012',
            'notification_mailbox_id' => '00000000-0000-0000-0000-000000000013',
            'notification_mailbox_address' => 'fieldops@example.test',
            'target_is_dedicated_shared_mailbox' => true,
            'notification_calendar_id' => 'calendar-id_1',
            'work_schedules_group_id' => '00000000-0000-0000-0000-000000000014',
            'work_schedules_site_id' => 'example.sharepoint.com,sitecollection,webid',
            'work_schedules_drive_id' => 'drive-id_1',
            'business_attachments_site_id' => 'example.sharepoint.com,businesscollection,businessweb',
            'business_attachments_drive_id' => 'business-drive-id_1',
            'non_target_mailbox_email' => 'control@example.test',
            'non_target_is_user_mailbox' => true,
            'non_target_site_id' => 'example.sharepoint.com,nontargetcollection,nontargetweb',
            'non_target_drive_id' => 'non-target-drive-id_1',
        ];
    }

    private function readyForCalendarCanary(MicrosoftGraphOnboardingTarget $target): void
    {
        $target->forceFill([
            'validation_status' => 'read_verified',
            'read_access_verified_at' => now(),
            'boundary_validation_attempted_at' => now(),
            'boundary_verified_at' => now(),
            'non_target_mailbox_calendar_status' => 'denied',
            'non_target_site_status' => 'denied',
            'non_target_drive_status' => 'denied',
            'target_contacts_status' => 'accessible',
            'non_target_contacts_status' => 'denied',
            'boundary_error_code' => null,
            'contacts_boundary_validation_attempted_at' => now(),
            'contacts_boundary_verified_at' => now(),
            'contacts_boundary_error_code' => null,
        ])->save();
    }

    private function createVerifiedLinkback(MicrosoftGraphOnboardingTarget $target, User $admin): void
    {
        GraphOnboardingChallenge::query()->create([
            'id' => (string) Str::uuid(),
            'environment' => app()->environment(),
            'target_id' => $target->getKey(),
            'user_id' => $admin->getKey(),
            'status' => GraphOnboardingChallenge::STATUS_VERIFIED,
            'token_hash' => hash('sha256', 'finalization-linkback'),
            'recipient_email_hash' => hash('sha256', strtolower($admin->email)),
            'expires_at' => now()->addMinutes(5),
            'verified_at' => now(),
        ]);
    }

    private function createPermissionVerification(MicrosoftGraphOnboardingTarget $target, User $admin): MicrosoftGraphOnboardingPermissionVerification
    {
        return MicrosoftGraphOnboardingPermissionVerification::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'environment' => app()->environment(),
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'mailbox_id' => $target->notification_mailbox_id,
            'non_target_mailbox_email' => $target->non_target_mailbox_email,
            'target_is_dedicated_shared_mailbox' => true,
            'non_target_is_user_mailbox' => true,
            'business_site_id' => $target->business_attachments_site_id,
            'business_drive_id' => $target->business_attachments_drive_id,
            'm365_admin_upn' => 'm365-admin@example.test',
            'verification_reference' => 'CHG-TEST-1042',
            'mail_send_target_verified' => true,
            'mail_send_non_target_denied' => true,
            'calendar_write_target_verified' => true,
            'calendar_write_non_target_denied' => true,
            'contacts_boundary_verified' => true,
            'sharepoint_boundary_verified' => true,
            'sharepoint_retention_reviewed' => true,
            'unscoped_app_roles_reviewed' => true,
            'verified_by_user_id' => $admin->getKey(),
            'verified_at' => now(),
        ]);
    }

    private function createPassedGraphCanaries(MicrosoftGraphOnboardingTarget $target, User $admin): void
    {
        foreach ([
            [MicrosoftGraphOnboardingCanary::RESOURCE_CALENDAR_EVENT, $target->notification_calendar_id],
            [MicrosoftGraphOnboardingCanary::RESOURCE_CONTACT, null],
        ] as [$resourceType, $calendarId]) {
            MicrosoftGraphOnboardingCanary::query()->create([
                'id' => (string) Str::uuid(),
                'target_id' => $target->getKey(),
                'actor_user_id' => $admin->getKey(),
                'environment' => app()->environment(),
                'resource_type' => $resourceType,
                'status' => MicrosoftGraphOnboardingCanary::STATUS_CLEANED,
                'transaction_id' => (string) Str::uuid(),
                'tenant_id' => $target->tenant_id,
                'client_id' => $target->client_id,
                'mailbox_id' => $target->notification_mailbox_id,
                'calendar_id' => $calendarId,
                'subject' => 'Readiness canary fixture '.$resourceType,
                'remote_resource_id' => 'remote-id-'.$resourceType,
                'remote_etag' => 'W/"etag-'.$resourceType.'"',
                'attempted_at' => now()->subMinute(),
                'created_remotely_at' => now()->subMinute(),
                'cleaned_at' => now(),
            ]);
        }
    }

    private function createPassedSharePointCanary(MicrosoftGraphOnboardingTarget $target, User $admin): MicrosoftGraphSharePointOnboardingCanary
    {
        return MicrosoftGraphSharePointOnboardingCanary::query()->create([
            'id' => (string) Str::uuid(),
            'target_id' => $target->getKey(),
            'actor_user_id' => $admin->getKey(),
            'environment' => app()->environment(),
            'tenant_id' => $target->tenant_id,
            'client_id' => $target->client_id,
            'site_id' => $target->business_attachments_site_id,
            'drive_id' => $target->business_attachments_drive_id,
            'status' => MicrosoftGraphSharePointOnboardingCanary::STATUS_CLEANED,
            'operation_id' => (string) Str::uuid(),
            'folder_name' => 'FieldOps Setup Canary readiness',
            'remote_item_id' => 'readiness-sharepoint-folder-id',
            'remote_etag' => '"readiness-etag"',
            'attempted_at' => now()->subMinute(),
            'created_remotely_at' => now()->subMinute(),
            'cleaned_at' => now(),
        ]);
    }

    /** @return array{User, MicrosoftGraphOnboardingTarget} */
    private function configuredAdminAndTarget(): array
    {
        $admin = User::factory()->admin()->create(['status' => 'active']);
        $secret = app(Google2FA::class)->generateSecretKey();
        $admin->forceFill([
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'], JSON_THROW_ON_ERROR)),
            'two_factor_confirmed_at' => now(),
        ])->save();
        InstallationClaim::query()->whereKey(1)->update([
            'claimed_at' => now(),
            'claimed_by_user_id' => $admin->getKey(),
        ]);
        app(ApplicationSetupStepService::class)->markFirstAdminReadyIfEligible($admin->fresh());
        $this->unlockAdminOnboardingAreaForTest($admin);

        $target = new MicrosoftGraphOnboardingTarget;
        $target->fill($this->targets());
        $target->forceFill([
            'environment' => app()->environment(),
            'configured_by_user_id' => $admin->getKey(),
            'configured_at' => now(),
            'validation_status' => 'pending',
        ])->save();

        return [$admin->fresh(), $target->fresh()];
    }

    /** @param array<string, string|int> $query */
    private function loginWithTotp(User $user): void
    {
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.login'));

        $code = app(Google2FA::class)->getCurrentOtp(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
        );

        $this->post(route('two-factor.login.store'), ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->unlockAdminOnboardingAreaForTest($user);
    }

    /** @return array<string, mixed> */
    private function graphTargetMetadata(string $path, MicrosoftGraphOnboardingTarget $target): array
    {
        return match ($path) {
            '/v1.0/users/'.$target->notification_mailbox_id.'/calendar' => [
                'id' => 'default-calendar-id',
                'name' => 'Default notification calendar',
            ],
            '/v1.0/users/'.$target->notification_mailbox_id.'/calendars/'.$target->notification_calendar_id => [
                'id' => $target->notification_calendar_id,
                'name' => 'FieldOps notification calendar',
            ],
            '/v1.0/groups/'.$target->work_schedules_group_id => ['id' => $target->work_schedules_group_id],
            '/v1.0/sites/'.$target->work_schedules_site_id => ['id' => $target->work_schedules_site_id],
            '/v1.0/drives/'.$target->work_schedules_drive_id => ['id' => $target->work_schedules_drive_id],
            '/v1.0/sites/'.$target->business_attachments_site_id => ['id' => $target->business_attachments_site_id],
            '/v1.0/drives/'.$target->business_attachments_drive_id => ['id' => $target->business_attachments_drive_id],
            default => ['error' => ['code' => 'unexpected_test_request']],
        };
    }
}
