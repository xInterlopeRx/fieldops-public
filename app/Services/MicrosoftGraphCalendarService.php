<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Support\Str;

class MicrosoftGraphCalendarService
{
    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget,
    ) {}

    public function syncVisit(Visit $visit): void
    {
        $this->ensureWritesEnabled();
        $visit->loadMissing([
            'assignedUsers',
            'workOrder.assignedUsers',
            'workOrder.clientContact',
            'workOrder.site',
        ]);

        $target = $this->eventTargetSnapshot();

        if ($visit->microsoft_graph_event_id !== null) {
            $this->assertVisitEventTarget($visit, $target);
        } elseif ($visit->hasGraphEventTargetSnapshot()) {
            $this->assertVisitEventTarget($visit, $target);
        } elseif ($visit->microsoft_graph_transaction_id !== null) {
            throw new MicrosoftGraphApiException('graph_visit_event_target_unverified', 409);
        } else {
            $visit->microsoft_graph_event_tenant_id = $target['tenant_id'];
            $visit->microsoft_graph_event_mailbox_id = $target['mailbox_id'];
            $visit->microsoft_graph_event_calendar_id = $target['calendar_id'];
        }

        $visit->microsoft_graph_transaction_id ??= (string) Str::uuid();
        $visit->graph_sync_status = 'syncing';
        $visit->graph_sync_error = null;
        $visit->save();

        $workOrder = $visit->workOrder;
        $visitScope = trim((string) ($visit->scope ?? $workOrder->scope));
        $event = [
            'subject' => $workOrder->job_number.': '.$workOrder->title.' — Visit',
            'body' => [
                'contentType' => 'text',
                'content' => implode("\n", array_filter([
                    'Job '.$workOrder->job_number,
                    $visitScope !== '' ? "Visit scope:\n{$visitScope}" : null,
                ])),
            ],
            'start' => [
                'dateTime' => $visit->scheduled_at->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'end' => [
                'dateTime' => $visit->scheduled_at->copy()->addMinutes($visit->duration_minutes ?: 60)->utc()->format('Y-m-d\TH:i:s'),
                'timeZone' => 'UTC',
            ],
            'location' => ['displayName' => $workOrder->site?->address ?: ($workOrder->site?->name ?? '')],
            'attendees' => $this->attendees($visit),
            'transactionId' => $visit->microsoft_graph_transaction_id,
        ];

        $calendarPath = $this->calendarPath($target);
        if ($visit->microsoft_graph_event_id) {
            unset($event['transactionId']);
            $this->graph->patchJson($calendarPath.'/events/'.rawurlencode($visit->microsoft_graph_event_id), $event);
        } else {
            $created = $this->graph->postJson($calendarPath.'/events', $event);
            $eventId = $created['id'] ?? null;

            if (! is_string($eventId) || $eventId === '') {
                throw new MicrosoftGraphApiException('graph_invalid_event_response', 502);
            }

            $visit->microsoft_graph_event_id = $eventId;
        }

        $visit->graph_sync_status = 'synced';
        $visit->graph_sync_error = null;
        $visit->graph_synced_at = now();
        $visit->save();
    }

    /** @return array<string, string> */
    public function pollResponses(Visit $visit): array
    {
        $this->ensureWritesEnabled();
        if (! $visit->microsoft_graph_event_id) {
            throw new MicrosoftGraphApiException('graph_event_not_found', 404);
        }

        $target = $this->eventTargetSnapshot();
        $this->assertVisitEventTarget($visit, $target);

        $payload = $this->graph->getJson(
            $this->calendarPath($target).'/events/'.rawurlencode($visit->microsoft_graph_event_id),
            ['$select' => 'id,attendees'],
            ['Prefer' => 'IdType="ImmutableId"'],
        );

        $attendees = $payload['attendees'] ?? [];
        if (! is_array($attendees)) {
            throw new MicrosoftGraphApiException('graph_invalid_event_response', 502);
        }

        $responses = [];
        foreach ($attendees as $attendee) {
            $email = data_get($attendee, 'emailAddress.address');
            $response = data_get($attendee, 'status.response');

            if (is_string($email) && is_string($response)) {
                $responses[strtolower($email)] = $response;
            }
        }

        return $responses;
    }

    /** @return list<array{emailAddress: array{address: string}, type: string}> */
    private function attendees(Visit $visit): array
    {
        $emails = $visit->assignedUsers
            ->merge($visit->workOrder->assignedUsers)
            ->filter(fn (User $user): bool => $user->status === 'active')
            ->pluck('email')
            ->all();

        $clientEmail = $visit->workOrder->clientContact?->email ?? $visit->workOrder->client_contact_email;
        if (is_string($clientEmail) && $clientEmail !== '') {
            $emails[] = $clientEmail;
        }

        $organizerAddress = $this->configuredMailboxAddress();

        return collect($emails)
            ->filter(fn (mixed $email): bool => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->reject(fn (string $email): bool => $organizerAddress !== null && $email === $organizerAddress)
            ->unique()
            ->map(fn (string $email): array => [
                'emailAddress' => ['address' => $email],
                'type' => 'required',
            ])
            ->values()
            ->all();
    }

    private function ensureWritesEnabled(): void
    {
        if (! config('services.microsoft_graph.calendar_writes_enabled')) {
            throw new MicrosoftGraphApiException('graph_calendar_writes_disabled', 503);
        }

    }

    /** @return array{tenant_id: string, mailbox_id: string, calendar_id: string|null} */
    private function eventTargetSnapshot(): array
    {
        $tenantId = $this->runtimeTarget->tenantId();
        $mailboxId = $this->runtimeTarget->notificationMailboxId();

        if (! is_string($tenantId) || trim($tenantId) === ''
            || ! is_string($mailboxId) || trim($mailboxId) === '') {
            throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
        }

        $calendarId = $this->runtimeTarget->notificationCalendarId();

        return [
            'tenant_id' => trim($tenantId),
            'mailbox_id' => trim($mailboxId),
            'calendar_id' => is_string($calendarId) && trim($calendarId) !== '' ? trim($calendarId) : null,
        ];
    }

    /** @param array{tenant_id: string, mailbox_id: string, calendar_id: string|null} $target */
    private function calendarPath(array $target): string
    {
        $mailboxPath = 'users/'.rawurlencode($target['mailbox_id']);

        return $target['calendar_id'] !== null
            ? $mailboxPath.'/calendars/'.rawurlencode($target['calendar_id'])
            : $mailboxPath;
    }

    /** @param array{tenant_id: string, mailbox_id: string, calendar_id: string|null} $target */
    private function assertVisitEventTarget(Visit $visit, array $target): void
    {
        if (! $visit->graphEventTargetMatches(
            $target['tenant_id'],
            $target['mailbox_id'],
            $target['calendar_id'],
        )) {
            throw new MicrosoftGraphApiException('graph_visit_event_target_unverified', 409);
        }
    }

    private function configuredMailboxAddress(): ?string
    {
        $address = $this->runtimeTarget->notificationMailboxAddress();

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($address))
            : null;
    }
}
