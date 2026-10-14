<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\GraphNotificationOutbox;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingPermissionVerification;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateMicrosoftGraphRuntimeTarget
{
    public function __construct(private readonly OnboardingReadinessService $readiness) {}

    public function activate(User $actor): MicrosoftGraphOnboardingTarget
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor): MicrosoftGraphOnboardingTarget {
            $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $target = MicrosoftGraphOnboardingTarget::query()
                ->where('environment', app()->environment())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $state->onboarding_required
                || $target->runtime_activated_at !== null
                || ! $target->target_is_dedicated_shared_mailbox
                || ! $target->non_target_is_user_mailbox
                || $target->validation_status !== 'read_verified'
                || $target->read_access_verified_at === null
                || ! $this->graphReadinessPassed()
                || ! $this->readiness->invitationsUnlocked()) {
                throw ValidationException::withMessages([
                    'confirm_activate_runtime' => 'Complete onboarding readiness and exact-target M365 verification before activating this runtime target.',
                ]);
            }

            $verification = MicrosoftGraphOnboardingPermissionVerification::query()
                ->where('target_id', $target->getKey())
                ->where('environment', $target->environment)
                ->where('tenant_id', $target->tenant_id)
                ->where('client_id', $target->client_id)
                ->where('mailbox_id', $target->notification_mailbox_id)
                ->where('non_target_mailbox_email', $target->non_target_mailbox_email)
                ->where('business_site_id', $target->business_attachments_site_id)
                ->where('business_drive_id', $target->business_attachments_drive_id)
                ->latest('verified_at')
                ->latest('id')
                ->first();

            if ($verification === null || ! $verification->matchesTarget($target)) {
                throw ValidationException::withMessages([
                    'confirm_activate_runtime' => 'The M365-admin permission evidence does not match the exact saved shared mailbox and user-mailbox control.',
                ]);
            }

            $this->assertMailboxScopedRecordsCanUseTarget($target);
            $this->assertRuntimeWorkIsReconciled();

            $target->forceFill([
                'runtime_activated_at' => now(),
                'runtime_activated_by_user_id' => $actor->getKey(),
            ])->save();

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $target->getMorphClass(),
                'subject_id' => $target->getKey(),
                'event' => 'microsoft_graph_runtime_target_activated',
                'summary' => $actor->name.' activated the verified FieldOps shared-mailbox runtime target.',
                'metadata' => [
                    'environment' => $target->environment,
                    'target_id' => $target->getKey(),
                    'mailbox_id' => $target->notification_mailbox_id,
                    'non_target_mailbox_email' => $target->non_target_mailbox_email,
                    'permission_verification_id' => $verification->getKey(),
                    'activated_by_user_id' => $actor->getKey(),
                    'write_flags' => [
                        'mail' => (bool) config('services.microsoft_graph.writes_enabled'),
                        'calendar' => (bool) config('services.microsoft_graph.calendar_writes_enabled'),
                        'contacts' => (bool) config('services.microsoft_graph.contacts_sync_enabled'),
                        'sharepoint' => (bool) config('services.microsoft_graph.sharepoint_enabled'),
                        'onboarding' => (bool) config('services.microsoft_graph.onboarding_writes_enabled'),
                    ],
                ],
                'created_at' => now(),
            ]);

            return $target;
        });
    }

    private function graphReadinessPassed(): bool
    {
        return ApplicationSetupStep::query()
            ->where('step_key', ApplicationSetupStep::GRAPH_VALIDATION)
            ->where('status', ApplicationSetupStep::STATUS_PASSED)
            ->whereNotNull('completed_at')
            ->exists();
    }

    private function assertMailboxScopedRecordsCanUseTarget(MicrosoftGraphOnboardingTarget $target): void
    {
        $targetMailboxId = trim($target->notification_mailbox_id);
        $targetCalendarId = is_string($target->notification_calendar_id)
            && trim($target->notification_calendar_id) !== ''
            ? trim($target->notification_calendar_id)
            : null;
        $configuredTenantId = config('services.microsoft_graph.tenant_id');
        $legacyTenantId = is_string($configuredTenantId) ? trim($configuredTenantId) : '';
        $legacyTenantMatches = $legacyTenantId !== ''
            && hash_equals(strtolower($legacyTenantId), strtolower($target->tenant_id));
        $hasUnresolvedVisitOwnership = Visit::withArchived()
            ->where(function ($query) use ($target, $targetMailboxId, $targetCalendarId): void {
                $query->graphEventTargetMismatch(
                    $target->tenant_id,
                    $targetMailboxId,
                    $targetCalendarId,
                )->orWhere(function ($unknown): void {
                    $unknown->whereNull('microsoft_graph_event_id')
                        ->whereNotNull('microsoft_graph_transaction_id')
                        ->where('graph_sync_status', 'failed');
                });
            })
            ->exists();
        $hasContactIdsForAnotherMailbox = Contact::withArchived()
            ->whereNotNull('outlook_contact_id')
            ->where(function ($query) use ($targetMailboxId, $legacyTenantMatches): void {
                if (! $legacyTenantMatches) {
                    return;
                }

                $query->whereNull('outlook_contact_mailbox_id')
                    ->orWhereRaw('LOWER(outlook_contact_mailbox_id) <> ?', [strtolower($targetMailboxId)]);
            })
            ->exists();

        if ($hasUnresolvedVisitOwnership || $hasContactIdsForAnotherMailbox) {
            throw ValidationException::withMessages([
                'confirm_activate_runtime' => 'Confirm or reconcile Visit event ownership and reconcile mailbox-scoped Outlook Contact IDs before activating the runtime target.',
            ]);
        }
    }

    private function assertRuntimeWorkIsReconciled(): void
    {
        // Pending-configuration intents have not reached Graph; they remain held
        // behind feature flags after activation.
        $hasUnresolvedMail = GraphNotificationOutbox::query()
            ->whereIn('status', ['pending', 'sending', 'unknown'])
            ->exists();
        $hasInFlightVisits = Visit::query()
            ->whereIn('graph_sync_status', ['pending', 'syncing'])
            ->exists();
        $hasInFlightContacts = Contact::withArchived()
            ->where(function ($query): void {
                $query->whereIn('outlook_sync_status', ['pending', 'syncing'])
                    ->orWhere(function ($unknown): void {
                        $unknown->where('outlook_sync_status', 'unknown')
                            ->whereNull('outlook_contact_id');
                    });
            })
            ->exists();

        if ($hasUnresolvedMail || $hasInFlightVisits || $hasInFlightContacts) {
            throw ValidationException::withMessages([
                'confirm_activate_runtime' => 'Reconcile pending or in-flight Graph mail, Visit, and Contact work before activating the runtime target.',
            ]);
        }
    }

    private function assertFirstAdmin(User $actor): void
    {
        $claim = InstallationClaim::query()->whereKey(1)->firstOrFail();

        if ((string) $claim->claimed_by_user_id !== (string) $actor->getKey()
            || $actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasEnabledTwoFactorAuthentication()
            || ! $actor->can('integrations.manage')) {
            abort(403);
        }
    }
}
