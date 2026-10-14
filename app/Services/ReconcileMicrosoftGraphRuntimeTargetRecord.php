<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\GraphNotificationOutbox;
use App\Models\InstallationClaim;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReconcileMicrosoftGraphRuntimeTargetRecord
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->first();

        if ($target === null) {
            return [
                'canReconcile' => false,
                'legacyTenantId' => null,
                'legacyMailboxConfigured' => false,
                'legacyMailboxId' => null,
                'legacyCalendarId' => null,
                'targetConfirmed' => false,
                'controlConfirmed' => false,
                'canConfirmVisitOwnership' => false,
                'canReconcileVisits' => false,
                'visits' => [],
                'visitCount' => 0,
                'activeVisits' => [],
                'activeVisitCount' => 0,
                'canRetireActiveVisits' => false,
                'contacts' => [],
                'contactCount' => 0,
                'inFlightVisitCount' => 0,
                'inFlightContactCount' => 0,
                'unresolvedMailCount' => 0,
            ];
        }

        $legacyTenantId = $this->legacyTenantId();
        $legacyMailboxId = $this->legacyMailboxId();
        $legacyCalendarId = $this->legacyCalendarId();

        $visitQuery = Visit::withArchived()
            ->with('workOrder:id,job_number,title')
            ->where(function ($query) use ($target): void {
                $query->graphEventTargetMismatch(
                    $target->tenant_id,
                    $target->notification_mailbox_id,
                    is_string($target->notification_calendar_id) && trim($target->notification_calendar_id) !== ''
                        ? trim($target->notification_calendar_id)
                        : null,
                )->orWhere(function ($unknown): void {
                    $unknown->whereNull('microsoft_graph_event_id')
                        ->whereNotNull('microsoft_graph_transaction_id')
                        ->where('graph_sync_status', 'failed');
                });
            });
        $visitCount = (clone $visitQuery)->count();
        $visits = $visitQuery
            ->orderBy('id')
            ->limit(25)
            ->get([
                'id', 'work_order_id', 'scheduled_at', 'is_archived',
                'microsoft_graph_event_id', 'microsoft_graph_transaction_id',
                'graph_sync_status', 'microsoft_graph_event_tenant_id',
                'microsoft_graph_event_mailbox_id', 'microsoft_graph_event_calendar_id',
            ])
            ->map(function (Visit $visit) use ($target): array {
                $unknownCreate = $visit->microsoft_graph_event_id === null
                    && $visit->microsoft_graph_transaction_id !== null
                    && $visit->graph_sync_status === 'failed';

                return [
                    'id' => $visit->getKey(),
                    'jobNumber' => $visit->workOrder?->job_number,
                    'jobTitle' => $visit->workOrder?->title,
                    'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
                    'archived' => (bool) $visit->is_archived,
                    'eventId' => $visit->microsoft_graph_event_id,
                    'transactionId' => $visit->microsoft_graph_transaction_id,
                    'unknownCreate' => $unknownCreate,
                    'ownerStatus' => $unknownCreate
                        ? 'unknown_create'
                        : (! $visit->hasGraphEventTargetSnapshot()
                            ? 'unverified'
                            : ($this->visitEventOwnerMatchesTarget($visit, $target) ? 'active_target' : 'different_target')),
                    'sourceMailboxId' => $visit->microsoft_graph_event_mailbox_id,
                    'sourceCalendarId' => $visit->microsoft_graph_event_calendar_id,
                ];
            })
            ->all();

        $targetCalendarId = is_string($target->notification_calendar_id) && trim($target->notification_calendar_id) !== ''
            ? trim($target->notification_calendar_id)
            : null;
        $activeVisitQuery = Visit::withArchived()
            ->with('workOrder:id,job_number,title')
            ->whereNotNull('microsoft_graph_event_id')
            ->whereRaw('LOWER(microsoft_graph_event_tenant_id) = ?', [strtolower($target->tenant_id)])
            ->whereRaw('LOWER(microsoft_graph_event_mailbox_id) = ?', [strtolower($target->notification_mailbox_id)])
            ->when(
                $targetCalendarId === null,
                fn ($query) => $query->whereNull('microsoft_graph_event_calendar_id'),
                fn ($query) => $query->where('microsoft_graph_event_calendar_id', $targetCalendarId),
            );
        $activeVisitCount = (clone $activeVisitQuery)->count();
        $activeVisits = $activeVisitQuery
            ->orderBy('id')
            ->limit(25)
            ->get(['id', 'work_order_id', 'scheduled_at', 'is_archived', 'microsoft_graph_event_id'])
            ->map(fn (Visit $visit): array => [
                'id' => $visit->getKey(),
                'jobNumber' => $visit->workOrder?->job_number,
                'jobTitle' => $visit->workOrder?->title,
                'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
                'archived' => (bool) $visit->is_archived,
                'eventId' => $visit->microsoft_graph_event_id,
                'transactionId' => null,
                'unknownCreate' => false,
                'ownerStatus' => 'active_target',
                'sourceMailboxId' => $target->notification_mailbox_id,
                'sourceCalendarId' => $targetCalendarId,
            ])
            ->all();

        $legacyTenantMatchesTarget = $legacyTenantId !== null
            && hash_equals(strtolower($legacyTenantId), strtolower($target->tenant_id));
        $contactQuery = Contact::withArchived()
            ->with('customer:id,name')
            ->where(function ($query): void {
                $query->whereNotNull('outlook_contact_id')
                    ->orWhere(function ($unknown): void {
                        $unknown->where('outlook_sync_status', 'unknown')
                            ->whereNull('outlook_contact_id');
                    });
            })
            ->where(function ($query) use ($target, $legacyTenantMatchesTarget): void {
                if (! $legacyTenantMatchesTarget) {
                    return;
                }

                $query->where(function ($linked) use ($target): void {
                    $linked->whereNotNull('outlook_contact_id')
                        ->where(function ($mailbox) use ($target): void {
                            $mailbox->whereNull('outlook_contact_mailbox_id')
                                ->orWhereRaw('LOWER(outlook_contact_mailbox_id) <> ?', [strtolower($target->notification_mailbox_id)]);
                        });
                })->orWhere(function ($unknown): void {
                    $unknown->where('outlook_sync_status', 'unknown')
                        ->whereNull('outlook_contact_id');
                });
            });
        $contactCount = (clone $contactQuery)->count();
        $contacts = $contactQuery
            ->orderBy('id')
            ->limit(25)
            ->get([
                'id', 'customer_id', 'first_name', 'middle_name', 'last_name',
                'is_archived', 'outlook_contact_id', 'outlook_contact_mailbox_id', 'outlook_sync_status',
            ])
            ->map(function (Contact $contact): array {
                $name = trim(implode(' ', array_filter([
                    $contact->first_name,
                    $contact->middle_name,
                    $contact->last_name,
                ])));

                return [
                    'id' => $contact->getKey(),
                    'name' => $name !== '' ? $name : 'Contact #'.$contact->getKey(),
                    'customerName' => $contact->customer?->name,
                    'archived' => (bool) $contact->is_archived,
                    'outlookContactId' => $contact->outlook_contact_id,
                    'unknownCreate' => $contact->outlook_sync_status === 'unknown'
                        && $contact->outlook_contact_id === null,
                    'sourceMailboxId' => $contact->outlook_contact_mailbox_id ?? $this->legacyMailboxId(),
                ];
            })
            ->all();
        $inFlightVisitCount = Visit::withArchived()
            ->whereIn('graph_sync_status', ['pending', 'syncing'])
            ->count();
        $inFlightContactCount = Contact::withArchived()
            ->where(function ($query): void {
                $query->whereIn('outlook_sync_status', ['pending', 'syncing'])
                    ->orWhere(function ($unknown): void {
                        $unknown->where('outlook_sync_status', 'unknown')
                            ->whereNull('outlook_contact_id');
                    });
            })
            ->count();
        $unresolvedMailCount = GraphNotificationOutbox::query()
            ->whereIn('status', ['pending', 'sending', 'unknown'])
            ->count();

        return [
            'canReconcile' => $target->runtime_activated_at === null
                && $target->target_is_dedicated_shared_mailbox
                && $target->non_target_is_user_mailbox
                && $legacyTenantId !== null
                && $legacyMailboxId !== null,
            'legacyMailboxConfigured' => $legacyMailboxId !== null,
            'legacyTenantId' => $legacyTenantId,
            'targetConfirmed' => (bool) $target->target_is_dedicated_shared_mailbox,
            'controlConfirmed' => (bool) $target->non_target_is_user_mailbox,
            'legacyMailboxId' => $legacyMailboxId,
            'legacyCalendarId' => $this->legacyCalendarId(),
            'targetMailboxId' => $target->notification_mailbox_id,
            'targetTenantId' => $target->tenant_id,
            'canConfirmVisitOwnership' => $target->target_is_dedicated_shared_mailbox
                && $target->non_target_is_user_mailbox
                && $target->validation_status === 'read_verified'
                && $target->read_access_verified_at !== null,
            'canReconcileVisits' => $target->target_is_dedicated_shared_mailbox
                && $target->non_target_is_user_mailbox
                && $target->validation_status === 'read_verified'
                && $target->read_access_verified_at !== null,
            'visits' => $visits,
            'visitCount' => $visitCount,
            'activeVisits' => $activeVisits,
            'activeVisitCount' => $activeVisitCount,
            'canRetireActiveVisits' => $target->target_is_dedicated_shared_mailbox
                && $target->non_target_is_user_mailbox
                && $target->runtime_activated_at !== null
                && $target->validation_status === 'read_verified'
                && $target->read_access_verified_at !== null,
            'contacts' => $contacts,
            'contactCount' => $contactCount,
            'inFlightVisitCount' => $inFlightVisitCount,
            'inFlightContactCount' => $inFlightContactCount,
            'unresolvedMailCount' => $unresolvedMailCount,
            'displayLimit' => 25,
        ];
    }

    /** @param array{m365_admin_upn: string, verification_reference: string} $evidence */
    public function visit(User $actor, int $visitId, array $evidence): Visit
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor, $visitId, $evidence): Visit {
            $target = $this->confirmedVisitTarget(requireReadVerification: false);
            $current = Visit::withArchived()->lockForUpdate()->findOrFail($visitId);

            $unknownCreate = $current->microsoft_graph_event_id === null
                && $current->microsoft_graph_transaction_id !== null
                && $current->graph_sync_status === 'failed';

            if ($current->microsoft_graph_event_id === null && ! $unknownCreate) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'This Visit has no stored Graph event ID to reconcile.',
                ]);
            }

            if (! $unknownCreate && $this->visitEventOwnerMatchesTarget($current, $target)) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'This Visit event is confirmed as owned by the current target. Do not clear its local event pointer.',
                ]);
            }

            $oldEventId = $current->microsoft_graph_event_id;
            $oldTransactionId = $current->microsoft_graph_transaction_id;
            $sourceOwnerUnverified = ! $current->hasGraphEventTargetSnapshot();
            $sourceTenantId = $current->microsoft_graph_event_tenant_id;
            $sourceMailboxId = $current->microsoft_graph_event_mailbox_id;
            $sourceCalendarId = $current->microsoft_graph_event_calendar_id;
            $current->forceFill([
                'microsoft_graph_event_id' => null,
                'microsoft_graph_event_tenant_id' => null,
                'microsoft_graph_event_mailbox_id' => null,
                'microsoft_graph_event_calendar_id' => null,
                'microsoft_graph_transaction_id' => null,
                'graph_sync_status' => 'not_requested',
                'graph_sync_error' => null,
                'graph_synced_at' => null,
                'rsvp_polled_at' => null,
                'attendee_responses' => null,
                'rsvp_attention_notified_at' => null,
            ])->saveQuietly();

            $this->audit($actor, $target, 'microsoft_graph_runtime_visit_event_reconciled', [
                'visit_id' => $current->getKey(),
                'source_tenant_id' => $sourceTenantId,
                'source_mailbox_id' => $sourceMailboxId,
                'source_calendar_id' => $sourceCalendarId,
                'source_owner_unverified' => $sourceOwnerUnverified,
                'provider_event_id' => $oldEventId,
                'transaction_id' => $oldTransactionId,
                'unknown_create_outcome' => $unknownCreate,
                'm365_admin_upn' => strtolower($evidence['m365_admin_upn']),
                'verification_reference' => trim($evidence['verification_reference']),
                'remote_reconciliation_confirmed' => true,
                'graph_request_performed' => false,
            ]);

            return $current;
        });
    }

    /** @param array{m365_admin_upn: string, verification_reference: string} $evidence */
    public function confirmVisitEventOwnership(User $actor, int $visitId, array $evidence): Visit
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor, $visitId, $evidence): Visit {
            $target = $this->confirmedVisitTarget(requireReadVerification: true);
            $visit = Visit::withArchived()->lockForUpdate()->findOrFail($visitId);

            if (! is_string($visit->microsoft_graph_event_id) || trim($visit->microsoft_graph_event_id) === '') {
                throw ValidationException::withMessages([
                    'confirm_target_ownership' => 'This Visit has no stored Graph event ID whose mailbox ownership can be confirmed.',
                ]);
            }

            if ($visit->graph_sync_status !== 'synced') {
                throw ValidationException::withMessages([
                    'confirm_target_ownership' => 'Only a successfully synced Visit event can be confirmed as owned by the active mailbox. Reconcile other outcomes first.',
                ]);
            }

            if ($visit->hasGraphEventTargetSnapshot()) {
                throw ValidationException::withMessages([
                    'confirm_target_ownership' => 'This Visit already has an owner snapshot. Reconcile a different-target event instead of reassigning its ownership.',
                ]);
            }

            $visit->forceFill([
                'microsoft_graph_event_tenant_id' => $target->tenant_id,
                'microsoft_graph_event_mailbox_id' => $target->notification_mailbox_id,
                'microsoft_graph_event_calendar_id' => $target->notification_calendar_id,
            ])->saveQuietly();

            $this->audit($actor, $target, 'microsoft_graph_runtime_visit_event_owner_confirmed', [
                'visit_id' => $visit->getKey(),
                'provider_event_id' => $visit->microsoft_graph_event_id,
                'event_tenant_id' => $target->tenant_id,
                'event_mailbox_id' => $target->notification_mailbox_id,
                'event_calendar_id' => $target->notification_calendar_id,
                'm365_admin_upn' => strtolower($evidence['m365_admin_upn']),
                'verification_reference' => trim($evidence['verification_reference']),
                'event_pointer_preserved' => true,
                'graph_request_performed' => false,
            ]);

            return $visit;
        });
    }

    /** @param array{m365_admin_upn: string, verification_reference: string} $evidence */
    public function retireActiveVisitEventPointer(User $actor, int $visitId, array $evidence): Visit
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor, $visitId, $evidence): Visit {
            $target = $this->confirmedVisitTarget(requireReadVerification: true);

            if ($target->runtime_activated_at === null) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'The active-mailbox Visit retirement action is available only after runtime target activation.',
                ]);
            }

            $visit = Visit::withArchived()->lockForUpdate()->findOrFail($visitId);

            if (! is_string($visit->microsoft_graph_event_id)
                || trim($visit->microsoft_graph_event_id) === ''
                || ! $this->visitEventOwnerMatchesTarget($visit, $target)) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'This Visit is not linked to an event owned by the active target.',
                ]);
            }

            $eventId = $visit->microsoft_graph_event_id;
            $eventTenantId = $visit->microsoft_graph_event_tenant_id;
            $eventMailboxId = $visit->microsoft_graph_event_mailbox_id;
            $eventCalendarId = $visit->microsoft_graph_event_calendar_id;
            $visit->forceFill([
                'microsoft_graph_event_id' => null,
                'microsoft_graph_event_tenant_id' => null,
                'microsoft_graph_event_mailbox_id' => null,
                'microsoft_graph_event_calendar_id' => null,
                'microsoft_graph_transaction_id' => null,
                'graph_sync_status' => 'not_requested',
                'graph_sync_error' => null,
                'graph_synced_at' => null,
                'rsvp_polled_at' => null,
                'attendee_responses' => null,
                'rsvp_attention_notified_at' => null,
            ])->saveQuietly();

            $this->audit($actor, $target, 'microsoft_graph_runtime_visit_event_retired', [
                'visit_id' => $visit->getKey(),
                'provider_event_id' => $eventId,
                'source_tenant_id' => $eventTenantId,
                'source_mailbox_id' => $eventMailboxId,
                'source_calendar_id' => $eventCalendarId,
                'm365_admin_upn' => strtolower($evidence['m365_admin_upn']),
                'verification_reference' => trim($evidence['verification_reference']),
                'remote_reconciliation_confirmed' => true,
                'graph_request_performed' => false,
            ]);

            return $visit;
        });
    }

    /** @param array{m365_admin_upn: string, verification_reference: string} $evidence */
    public function contact(User $actor, int $contactId, array $evidence): Contact
    {
        $this->assertFirstAdmin($actor);

        return DB::transaction(function () use ($actor, $contactId, $evidence): Contact {
            $target = $this->pendingConfirmedTarget();
            $current = Contact::withArchived()->lockForUpdate()->findOrFail($contactId);
            $sourceTenantId = $this->legacyTenantId();

            if ($sourceTenantId === null) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'The legacy Graph tenant is not configured; the source tenant for this Outlook Contact ID cannot be established.',
                ]);
            }

            $unknownCreate = $current->outlook_sync_status === 'unknown'
                && $current->outlook_contact_id === null;

            if ((! is_string($current->outlook_contact_id) || trim($current->outlook_contact_id) === '')
                && ! $unknownCreate) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'This Contact has no stored Outlook Contact ID to reconcile.',
                ]);
            }

            $sourceMailboxId = is_string($current->outlook_contact_mailbox_id)
                && trim($current->outlook_contact_mailbox_id) !== ''
                    ? trim($current->outlook_contact_mailbox_id)
                    : $this->legacyMailboxId();

            if ($sourceMailboxId === null
                || (! $unknownCreate
                    && $sourceTenantId !== null
                    && hash_equals(strtolower($sourceTenantId), strtolower($target->tenant_id))
                    && hash_equals(strtolower($sourceMailboxId), strtolower($target->notification_mailbox_id)))) {
                throw ValidationException::withMessages([
                    'confirm_remote_reconciliation' => 'The Contact source mailbox is unknown or already matches the saved runtime target; do not clear this link through legacy-target reconciliation.',
                ]);
            }

            $oldContactId = $current->outlook_contact_id;
            $current->forceFill([
                'outlook_contact_id' => null,
                'outlook_contact_mailbox_id' => null,
                'outlook_sync_status' => 'paused',
                'outlook_sync_requested_at' => null,
                'outlook_sync_error' => 'outlook_contact_target_reconciled',
            ])->saveQuietly();

            $this->audit($actor, $target, 'microsoft_graph_runtime_outlook_contact_reconciled', [
                'contact_id' => $current->getKey(),
                'legacy_tenant_id' => $sourceTenantId,
                'legacy_mailbox_id' => $sourceMailboxId,
                'provider_contact_id' => $oldContactId,
                'unknown_create_outcome' => $unknownCreate,
                'm365_admin_upn' => strtolower($evidence['m365_admin_upn']),
                'verification_reference' => trim($evidence['verification_reference']),
                'remote_reconciliation_confirmed' => true,
                'graph_request_performed' => false,
            ]);

            return $current;
        });
    }

    private function pendingConfirmedTarget(): MicrosoftGraphOnboardingTarget
    {
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $target->target_is_dedicated_shared_mailbox
            || ! $target->non_target_is_user_mailbox
            || $target->runtime_activated_at !== null) {
            throw ValidationException::withMessages([
                'confirm_remote_reconciliation' => 'Confirm the dedicated shared-mailbox target and user-mailbox control before reconciling, and do so before runtime activation.',
            ]);
        }

        return $target;
    }

    private function confirmedVisitTarget(bool $requireReadVerification): MicrosoftGraphOnboardingTarget
    {
        $target = MicrosoftGraphOnboardingTarget::query()
            ->where('environment', app()->environment())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $target->target_is_dedicated_shared_mailbox
            || ! $target->non_target_is_user_mailbox
            || ($requireReadVerification
                && ($target->validation_status !== 'read_verified'
                    || $target->read_access_verified_at === null))) {
            throw ValidationException::withMessages([
                $requireReadVerification ? 'confirm_target_ownership' : 'confirm_remote_reconciliation' => $requireReadVerification
                        ? 'Verify the saved shared-mailbox target and user-mailbox control before recording Visit event ownership.'
                        : 'Confirm the shared-mailbox target and user-mailbox control before reconciling a Visit event.',
            ]);
        }

        return $target;
    }

    private function hasVisitEventOwnerSnapshot(Visit $visit): bool
    {
        return $visit->microsoft_graph_event_tenant_id !== null
            || $visit->microsoft_graph_event_mailbox_id !== null
            || $visit->microsoft_graph_event_calendar_id !== null;
    }

    private function visitEventOwnerMatchesTarget(Visit $visit, MicrosoftGraphOnboardingTarget $target): bool
    {
        return $visit->graphEventTargetMatches(
            $target->tenant_id,
            $target->notification_mailbox_id,
            is_string($target->notification_calendar_id) && trim($target->notification_calendar_id) !== ''
                ? trim($target->notification_calendar_id)
                : null,
        );
    }

    private function legacyMailboxId(): ?string
    {
        $mailboxId = config('services.microsoft_graph.notification_mailbox_id');

        return is_string($mailboxId) && trim($mailboxId) !== '' ? trim($mailboxId) : null;
    }

    private function legacyTenantId(): ?string
    {
        $tenantId = config('services.microsoft_graph.tenant_id');

        return is_string($tenantId) && trim($tenantId) !== '' ? trim($tenantId) : null;
    }

    private function legacyCalendarId(): ?string
    {
        $calendarId = config('services.microsoft_graph.notification_calendar_id');

        return is_string($calendarId) && trim($calendarId) !== '' ? trim($calendarId) : null;
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $actor, MicrosoftGraphOnboardingTarget $target, string $event, array $metadata): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $target->getMorphClass(),
            'subject_id' => $target->getKey(),
            'event' => $event,
            'summary' => match ($event) {
                'microsoft_graph_runtime_visit_event_owner_confirmed' => $actor->name.' recorded target ownership confirmation for one Visit event.',
                'microsoft_graph_runtime_visit_event_retired' => $actor->name.' recorded remote reconciliation of one active Visit event.',
                default => $actor->name.' recorded manual reconciliation of one legacy Microsoft mailbox item.',
            },
            'metadata' => [
                'environment' => $target->environment,
                'target_id' => $target->getKey(),
                'target_mailbox_id' => $target->notification_mailbox_id,
                ...$metadata,
            ],
            'created_at' => now(),
        ]);
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
