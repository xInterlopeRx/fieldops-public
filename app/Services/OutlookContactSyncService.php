<?php

namespace App\Services;

use App\Enums\ContactMethodLabel;
use App\Enums\ContactMethodType;
use App\Exceptions\MicrosoftGraphApiException;
use App\Exceptions\OutlookContactSyncException;
use App\Models\Contact;
use App\Models\ContactMethod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OutlookContactSyncService
{
    private const MAX_EMAILS = 3;

    private const MAX_BUSINESS_PHONES = 2;

    /** @var list<string> */
    private const BUSINESS_PHONE_LABELS = ['office', 'dispatch', 'after_hours', 'operations'];

    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget,
    ) {}

    public function sync(int $contactId, bool $repair = false): void
    {
        if (! config('services.microsoft_graph.contacts_sync_enabled')) {
            return;
        }

        Cache::lock('outlook-contact-sync-'.$contactId, 120)->block(5, function () use ($contactId, $repair): void {
            $contact = $this->findContact($contactId);

            if ($contact === null) {
                return;
            }

            $outlookContactId = $contact->outlook_contact_id;
            $linkedMailboxId = $contact->outlook_contact_mailbox_id;

            if ($contact->outlook_sync_status === 'unknown' && (! is_string($outlookContactId) || $outlookContactId === '')) {
                throw new OutlookContactSyncException('outlook_contact_create_reconciliation_required');
            }

            if ($contact->is_archived) {
                if ($repair) {
                    throw new OutlookContactSyncException('outlook_contact_repair_requires_active_contact');
                }

                if (! is_string($outlookContactId) || $outlookContactId === '') {
                    return;
                }

                if (! is_string($linkedMailboxId) || trim($linkedMailboxId) === '') {
                    throw new OutlookContactSyncException('outlook_contact_mailbox_untracked');
                }

                $this->graph->delete(
                    $this->contactsPath($linkedMailboxId).'/'.rawurlencode($outlookContactId),
                    $this->headers(),
                );
                $this->clearOutlookContactId($contactId, $outlookContactId, $linkedMailboxId);

                return;
            }

            $mailboxId = $this->runtimeTarget->notificationMailboxId();

            if (! is_string($mailboxId) || trim($mailboxId) === '') {
                throw new OutlookContactSyncException('outlook_contact_mailbox_unconfigured');
            }

            $mailboxId = trim($mailboxId);
            $contactsPath = $this->contactsPath($mailboxId);
            $payload = $this->payload($contact);

            if ($repair && is_string($outlookContactId) && $outlookContactId !== '') {
                if (! is_string($linkedMailboxId) || trim($linkedMailboxId) !== $mailboxId) {
                    throw new OutlookContactSyncException('outlook_contact_mailbox_mismatch');
                }

                $this->graph->delete(
                    $contactsPath.'/'.rawurlencode($outlookContactId),
                    $this->headers(),
                );
                $this->clearOutlookContactIdForRepair($contactId, $outlookContactId, $mailboxId);
                $outlookContactId = null;
            } elseif ($repair && ! $this->isRepairContinuation($contact, $linkedMailboxId, $mailboxId)) {
                throw new OutlookContactSyncException('outlook_contact_repair_requires_linked_contact');
            }

            if (! $repair && is_string($outlookContactId) && $outlookContactId !== '') {
                if (! is_string($linkedMailboxId) || trim($linkedMailboxId) === '') {
                    throw new OutlookContactSyncException('outlook_contact_mailbox_untracked');
                }

                if (trim($linkedMailboxId) !== $mailboxId) {
                    throw new OutlookContactSyncException('outlook_contact_mailbox_mismatch');
                }

                $itemPath = $contactsPath.'/'.rawurlencode($outlookContactId);

                try {
                    $this->graph->patchJson($itemPath, $payload, $this->headers());

                    return;
                } catch (MicrosoftGraphApiException $exception) {
                    if ($exception->reason !== 'graph_record_not_found') {
                        throw $exception;
                    }

                    $this->clearOutlookContactId($contactId, $outlookContactId, $mailboxId);

                    throw $exception;
                }
            } elseif (
                ! $repair &&
                $linkedMailboxId !== null &&
                ! $this->isRepairContinuation($contact, $linkedMailboxId, $mailboxId)
            ) {
                throw new OutlookContactSyncException('outlook_contact_mailbox_untracked');
            }

            $created = $this->createOutlookContact($contactsPath, $payload);
            $outlookContactId = $created['id'] ?? null;

            if (! is_string($outlookContactId) || $outlookContactId === '' || Str::length($outlookContactId) > 255) {
                throw new OutlookContactSyncException('outlook_contact_id_missing');
            }

            try {
                $stored = DB::table('contacts')
                    ->where('id', $contactId)
                    ->where('is_archived', false)
                    ->whereNull('outlook_contact_id')
                    ->where(function ($query) use ($mailboxId): void {
                        $query->whereNull('outlook_contact_mailbox_id')
                            ->orWhere('outlook_contact_mailbox_id', $mailboxId);
                    })
                    ->update([
                        'outlook_contact_id' => $outlookContactId,
                        'outlook_contact_mailbox_id' => $mailboxId,
                    ]);
            } catch (Throwable $exception) {
                $this->compensateCreate($contactsPath, $outlookContactId, $contactId);

                throw new OutlookContactSyncException('outlook_contact_id_persistence_failed', $exception);
            }

            if ($stored !== 1) {
                $this->compensateCreate($contactsPath, $outlookContactId, $contactId);

                $latest = $this->findContact($contactId);

                if ($latest === null || $latest->is_archived) {
                    return;
                }

                throw new OutlookContactSyncException('outlook_contact_id_persistence_failed');
            }
        });
    }

    private function findContact(int $contactId): ?Contact
    {
        return Contact::withArchived()
            ->with(['methods' => fn ($query) => $query->orderBy('id')])
            ->find($contactId);
    }

    /** @return array<string, mixed> */
    private function payload(Contact $contact): array
    {
        $emailValues = $this->emailValues($contact);
        $displayName = trim(implode(' ', array_filter([
            $contact->first_name,
            $contact->middle_name,
            $contact->last_name,
        ])));
        $displayName = $displayName !== ''
            ? $displayName
            : ($emailValues[0] ?? 'Contact #'.$contact->getKey());

        return [
            'displayName' => $displayName,
            'givenName' => $contact->first_name,
            'middleName' => $contact->middle_name,
            'surname' => $contact->last_name,
            'title' => $contact->title,
            'generation' => $contact->suffix,
            'emailAddresses' => array_map(
                fn (string $email): array => ['name' => $displayName, 'address' => $email],
                $emailValues,
            ),
            'businessPhones' => $this->businessPhones($contact),
            'mobilePhone' => $this->mobilePhone($contact),
        ];
    }

    /** @return list<string> */
    private function emailValues(Contact $contact): array
    {
        $methods = $contact->methods
            ->where('method_type', ContactMethodType::Email)
            ->sortBy(fn (ContactMethod $method): int => (int) $method->getKey())
            ->values();
        $primaryMethod = $methods->first(fn (ContactMethod $method): bool => $method->is_primary);
        $legacyEmail = $this->emailValue($contact->email);
        $values = [];

        if ($primaryMethod instanceof ContactMethod) {
            $this->appendEmail($values, $this->emailValue($primaryMethod->value));
            $this->appendEmail($values, $legacyEmail);
        } elseif ($legacyEmail !== null) {
            $this->appendEmail($values, $legacyEmail);
        } elseif ($methods->isNotEmpty()) {
            $this->appendEmail($values, $this->emailValue($methods->first()->value));
        }

        foreach ($methods as $method) {
            $this->appendEmail($values, $this->emailValue($method->value));
        }

        if (count($values) > self::MAX_EMAILS) {
            throw new OutlookContactSyncException('outlook_contact_email_limit_exceeded');
        }

        return $values;
    }

    private function emailValue(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $email = trim($value);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new OutlookContactSyncException('outlook_contact_email_invalid');
        }

        return $email;
    }

    /** @param list<string> $values */
    private function appendEmail(array &$values, ?string $email): void
    {
        if ($email === null) {
            return;
        }

        $normalized = Str::lower($email);

        foreach ($values as $existing) {
            if (Str::lower($existing) === $normalized) {
                return;
            }
        }

        $values[] = $email;
    }

    /** @return list<string> */
    private function businessPhones(Contact $contact): array
    {
        $methods = $contact->methods
            ->filter(fn (ContactMethod $method): bool => $method->method_type === ContactMethodType::Phone
                && in_array($method->label->value, self::BUSINESS_PHONE_LABELS, true))
            ->sortBy(fn (ContactMethod $method): int => (int) $method->getKey())
            ->values();
        $values = [];

        $primaryMethod = $methods->first(fn (ContactMethod $method): bool => $method->is_primary);
        if ($primaryMethod instanceof ContactMethod) {
            $this->appendValue($values, $primaryMethod->value);
        }

        foreach ([$contact->phone, $contact->alternate_phone] as $value) {
            $this->appendValue($values, $value);
        }

        foreach ($methods as $method) {
            if ($primaryMethod !== null && $primaryMethod->getKey() === $method->getKey()) {
                continue;
            }

            $this->appendValue($values, $method->value);
        }

        if (count($values) > self::MAX_BUSINESS_PHONES) {
            throw new OutlookContactSyncException('outlook_contact_business_phone_limit_exceeded');
        }

        return $values;
    }

    private function mobilePhone(Contact $contact): ?string
    {
        $values = [];

        foreach ([$contact->mobile_phone] as $value) {
            $this->appendValue($values, $value);
        }

        $mobileMethods = $contact->methods
            ->filter(fn (ContactMethod $method): bool => $method->method_type === ContactMethodType::Phone
                && $method->label === ContactMethodLabel::Mobile)
            ->sortBy(fn (ContactMethod $method): int => (int) $method->getKey());

        foreach ($mobileMethods as $method) {
            $this->appendValue($values, $method->value);
        }

        if (count($values) > 1) {
            throw new OutlookContactSyncException('outlook_contact_multiple_mobile_phones');
        }

        return $values[0] ?? null;
    }

    /** @param list<string> $values */
    private function appendValue(array &$values, ?string $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $value = trim($value);

        foreach ($values as $existing) {
            if (Str::lower($existing) === Str::lower($value)) {
                return;
            }
        }

        $values[] = $value;
    }

    private function createOutlookContact(string $contactsPath, array $payload): array
    {
        try {
            return $this->graph->postJson($contactsPath, $payload, $this->headers());
        } catch (MicrosoftGraphApiException $exception) {
            if (in_array($exception->reason, [
                'graph_rate_limited',
                'graph_permission_denied',
                'graph_configuration_incomplete',
                'graph_auth_unavailable',
                'graph_auth_failed',
                'graph_auth_busy',
                'graph_invalid_token_response',
            ], true)) {
                throw $exception;
            }

            throw new OutlookContactSyncException('outlook_contact_create_outcome_unknown', $exception);
        }
    }

    private function clearOutlookContactId(int $contactId, string $outlookContactId, string $mailboxId): void
    {
        Contact::withArchived()
            ->whereKey($contactId)
            ->where('outlook_contact_id', $outlookContactId)
            ->where('outlook_contact_mailbox_id', $mailboxId)
            ->update([
                'outlook_contact_id' => null,
                'outlook_contact_mailbox_id' => null,
            ]);
    }

    private function clearOutlookContactIdForRepair(int $contactId, string $outlookContactId, string $mailboxId): void
    {
        Contact::withArchived()
            ->whereKey($contactId)
            ->where('outlook_contact_id', $outlookContactId)
            ->where('outlook_contact_mailbox_id', $mailboxId)
            ->update(['outlook_contact_id' => null]);
    }

    private function isRepairContinuation(Contact $contact, mixed $linkedMailboxId, string $mailboxId): bool
    {
        return is_string($linkedMailboxId)
            && trim($linkedMailboxId) === $mailboxId
            && in_array($contact->outlook_sync_status, ['pending', 'syncing', 'failed', 'paused'], true);
    }

    private function contactsPath(string $mailboxId): string
    {
        return 'users/'.rawurlencode(trim($mailboxId)).'/contacts';
    }

    private function compensateCreate(string $contactsPath, string $outlookContactId, int $contactId): void
    {
        try {
            $this->graph->delete($contactsPath.'/'.rawurlencode($outlookContactId), $this->headers());
        } catch (Throwable $exception) {
            report($exception);
        }

        Log::warning('Outlook Contact metadata was not stored after creation', [
            'contact_id' => $contactId,
        ]);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Prefer' => 'IdType="ImmutableId"'];
    }
}
