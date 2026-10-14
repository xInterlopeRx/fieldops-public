<?php

namespace App\Services;

use App\Jobs\SyncOutlookContact;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

class OutlookContactSyncCoordinator
{
    /** @var list<string> */
    public const STATUSES = ['not_synced', 'pending', 'syncing', 'synced', 'failed', 'unknown', 'paused'];

    public function __construct(private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget) {}

    public function queue(int $contactId, bool $repair = false): bool
    {
        if (! config('services.microsoft_graph.contacts_sync_enabled')) {
            return false;
        }

        return DB::transaction(function () use ($contactId, $repair): bool {
            $contact = Contact::withArchived()->lockForUpdate()->find($contactId);

            if ($contact === null
                || in_array($contact->outlook_sync_status, ['pending', 'syncing'], true)
                || ! $this->canQueue($contact, $repair)) {
                return false;
            }

            $contact->forceFill([
                'outlook_sync_status' => 'pending',
                'outlook_sync_requested_at' => now(),
                'outlook_sync_error' => null,
            ])->saveQuietly();

            SyncOutlookContact::dispatch($contactId, $repair)->afterCommit();

            return true;
        });
    }

    public function queueAfterManualReconciliation(int $contactId, bool $confirmed): bool
    {
        if (! $confirmed || ! config('services.microsoft_graph.contacts_sync_enabled')) {
            return false;
        }

        return DB::transaction(function () use ($contactId): bool {
            $contact = Contact::withArchived()->lockForUpdate()->find($contactId);
            $mailboxId = $this->runtimeTarget->notificationMailboxId();
            $configuredMailboxId = is_string($mailboxId) ? trim($mailboxId) : '';

            if (
                $contact === null ||
                $contact->is_archived ||
                $contact->outlook_sync_status !== 'unknown' ||
                (is_string($contact->outlook_contact_id) && $contact->outlook_contact_id !== '') ||
                $configuredMailboxId === '' ||
                ($contact->outlook_contact_mailbox_id !== null &&
                    (! is_string($contact->outlook_contact_mailbox_id)
                        || trim($contact->outlook_contact_mailbox_id) !== $configuredMailboxId))
            ) {
                return false;
            }

            $contact->forceFill([
                'outlook_sync_status' => 'failed',
                'outlook_sync_error' => null,
            ])->saveQuietly();

            return $this->queue($contactId);
        });
    }

    public function markSyncing(int $contactId): void
    {
        $this->update($contactId, [
            'outlook_sync_status' => 'syncing',
            'outlook_sync_error' => null,
        ]);
    }

    public function markPending(int $contactId, string $reason): void
    {
        $this->update($contactId, [
            'outlook_sync_status' => 'pending',
            'outlook_sync_error' => $this->safeReason($reason),
        ]);
    }

    public function markSynced(int $contactId): void
    {
        $this->update($contactId, [
            'outlook_sync_status' => 'synced',
            'outlook_last_synced_at' => now(),
            'outlook_sync_error' => null,
        ]);
    }

    public function markPaused(int $contactId): void
    {
        $this->update($contactId, [
            'outlook_sync_status' => 'paused',
            'outlook_sync_error' => 'outlook_contact_sync_disabled',
        ]);
    }

    public function markFailed(int $contactId, string $reason, bool $unknownOutcome = false): void
    {
        $this->update($contactId, [
            'outlook_sync_status' => $unknownOutcome ? 'unknown' : 'failed',
            'outlook_sync_error' => $this->safeReason($reason),
        ]);
    }

    private function canQueue(Contact $contact, bool $repair): bool
    {
        $outlookContactId = $contact->outlook_contact_id;
        $mailboxId = $this->runtimeTarget->notificationMailboxId();
        $configuredMailboxId = is_string($mailboxId) ? trim($mailboxId) : '';

        if ($contact->outlook_sync_status === 'unknown' && (! is_string($outlookContactId) || $outlookContactId === '')) {
            return false;
        }

        if ($contact->is_archived) {
            return ! $repair && (
                ! is_string($outlookContactId) || $outlookContactId === ''
                    ? $contact->outlook_contact_mailbox_id === null
                    : is_string($contact->outlook_contact_mailbox_id)
                        && trim($contact->outlook_contact_mailbox_id) !== ''
            );
        }

        if ($configuredMailboxId === '') {
            return false;
        }

        if ($repair) {
            return is_string($outlookContactId)
                && $outlookContactId !== ''
                && is_string($contact->outlook_contact_mailbox_id)
                && trim($contact->outlook_contact_mailbox_id) === $configuredMailboxId
                && ! in_array($contact->outlook_sync_status, ['pending', 'syncing'], true);
        }

        if (is_string($outlookContactId) && $outlookContactId !== '') {
            return is_string($contact->outlook_contact_mailbox_id)
                && trim($contact->outlook_contact_mailbox_id) === $configuredMailboxId;
        }

        if ($contact->outlook_contact_mailbox_id !== null) {
            return is_string($contact->outlook_contact_mailbox_id)
                && trim($contact->outlook_contact_mailbox_id) === $configuredMailboxId
                && in_array($contact->outlook_sync_status, ['pending', 'syncing', 'failed', 'paused'], true);
        }

        return true;
    }

    /** @param array<string, mixed> $attributes */
    private function update(int $contactId, array $attributes): void
    {
        Contact::withArchived()->whereKey($contactId)->update($attributes);
    }

    private function safeReason(string $reason): string
    {
        return substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($reason)) ?? 'outlook_contact_sync_failed', 0, 64);
    }
}
