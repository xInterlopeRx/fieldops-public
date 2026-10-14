<?php

namespace App\Services;

use App\Jobs\BuildWorkOrderCompletionReport;
use App\Jobs\SendGraphNotification;
use App\Models\GraphNotificationOutbox;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderStatusHistory;
use Illuminate\Support\Str;

class GraphNotificationService
{
    public function __construct(private readonly MicrosoftGraphRuntimeTargetResolver $runtimeTarget) {}

    public function jobCreated(WorkOrder $workOrder, WorkOrderStatusHistory $history): void
    {
        $workOrder->loadMissing('clientContact');
        $this->queueForAddress(
            $workOrder,
            'job_created',
            $workOrder->clientContact?->email ?? $workOrder->client_contact_email,
            null,
            "job-lifecycle:{$workOrder->id}:{$history->id}:created:client",
            ['new_status' => $history->to_status],
        );
    }

    public function jobScheduled(WorkOrder $workOrder, WorkOrderStatusHistory $history): void
    {
        $workOrder->loadMissing('clientContact');
        $event = "job-lifecycle:{$workOrder->id}:{$history->id}:scheduled";
        $clientEmail = $workOrder->clientContact?->email ?? $workOrder->client_contact_email;
        $this->queueForAddress(
            $workOrder,
            'job_scheduled',
            $clientEmail,
            null,
            "{$event}:client",
            ['new_status' => $history->to_status],
        );

        $normalizedClientEmail = is_string($clientEmail) ? strtolower(trim($clientEmail)) : null;
        foreach ($workOrder->assignedUsers()->where('status', 'active')->orderBy('users.id')->get(['users.id', 'users.email']) as $user) {
            if (strtolower(trim($user->email)) === $normalizedClientEmail) {
                continue;
            }

            $this->queueForAddress(
                $workOrder,
                'job_scheduled',
                $user->email,
                null,
                "{$event}:user:{$user->getKey()}",
                ['new_status' => $history->to_status],
            );
        }
    }

    public function statusChanged(WorkOrder $workOrder, string $oldStatus, string $newStatus): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $workOrder->loadMissing('clientContact');
        $type = $newStatus === config('work_orders.completion_status') ? 'completion_summary' : 'status_update';
        $eventVersion = $newStatus === config('work_orders.completion_status')
            ? ($workOrder->completed_at?->timestamp ?? now()->timestamp)
            : ($workOrder->updated_at?->timestamp ?? now()->timestamp);
        $transition = $oldStatus.'-'.$newStatus;
        $payload = [
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'summary' => $workOrder->client_completion_summary,
            'field_notes' => $workOrder->field_notes,
        ];

        $clientEmail = $workOrder->clientContact?->email ?? $workOrder->client_contact_email;
        $this->queueForAddress(
            $workOrder,
            $type,
            $clientEmail,
            null,
            "status:{$workOrder->id}:{$eventVersion}:{$transition}:{$type}:client",
            $payload,
        );

        $normalizedClientEmail = is_string($clientEmail) ? strtolower(trim($clientEmail)) : null;
        foreach ($this->recipients('notifications.management') as $email) {
            if ($email === $normalizedClientEmail) {
                continue;
            }

            $this->queueForAddress(
                $workOrder,
                $type,
                $email,
                'management',
                "status:{$workOrder->id}:{$eventVersion}:{$transition}:{$type}:management:{$email}",
                $payload,
            );
        }

        if ($newStatus === config('work_orders.completion_status')) {
            BuildWorkOrderCompletionReport::dispatch($workOrder->id, $workOrder->completed_at?->timestamp)
                ->delay(now()->addMinutes(15))
                ->afterCommit();
        }
    }

    public function rsvpNeedsAttention(Visit $visit, array $responses, string $reason): void
    {
        $visit->loadMissing('workOrder');

        foreach ($this->recipients('notifications.management') as $email) {
            $this->queueForAddress(
                $visit->workOrder,
                'rsvp_attention',
                $email,
                'management',
                "rsvp:{$visit->id}:{$reason}:{$email}",
                ['visit_id' => $visit->id, 'reason' => $reason, 'responses' => $responses],
                $visit,
            );
        }
    }

    public function completionReportReady(
        WorkOrder $workOrder,
        WorkOrderAttachment $clientReport,
        WorkOrderAttachment $billingReport,
    ): void {
        $workOrder->loadMissing('clientContact');
        $attachments = $workOrder->attachments()
            ->whereNotIn('id', [$clientReport->id, $billingReport->id])
            ->where('path', 'not like', 'work-orders/reports/%')
            ->orderBy('id')
            ->get(['id']);
        $attachmentIds = $attachments->pluck('id')->push($clientReport->id)->values()->all();
        $eventVersion = $workOrder->completed_at?->timestamp ?? now()->timestamp;
        $payload = ['attachment_ids' => $attachmentIds];

        $clientEmail = $workOrder->clientContact?->email ?? $workOrder->client_contact_email;
        if (! $this->queueForAddress(
            $workOrder,
            'completion_report',
            $clientEmail,
            null,
            "report:{$workOrder->id}:{$eventVersion}:client",
            $payload,
        )) {
            $this->deliveryBlocked($workOrder, 'client_email_unavailable');
        }

        foreach ($this->recipients('notifications.billing') as $email) {
            $this->queueForAddress(
                $workOrder,
                'billing_ready',
                $email,
                'billing',
                "report:{$workOrder->id}:{$eventVersion}:billing:{$email}",
                ['attachment_ids' => [$billingReport->id]],
            );
        }
    }

    public function deliveryBlocked(WorkOrder $workOrder, string $reason): void
    {
        foreach ($this->recipients('notifications.management') as $email) {
            $this->queueForAddress(
                $workOrder,
                'delivery_blocked',
                $email,
                'management',
                "delivery-blocked:{$workOrder->id}:".($workOrder->completed_at?->timestamp ?? 'unknown').":{$reason}:{$email}",
                ['reason' => $reason],
            );
        }
    }

    /** @return list<string> */
    private function recipients(string $permission): array
    {
        $self = $this->senderAddress();

        return User::query()
            ->permission($permission)
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('email')
            ->map(fn (string $email): string => strtolower(trim($email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) && $email !== $self)
            ->unique()
            ->values()
            ->all();
    }

    private function queueForAddress(
        WorkOrder $workOrder,
        string $type,
        ?string $email,
        ?string $role,
        string $eventKey,
        array $payload,
        ?Visit $visit = null,
    ): bool {
        $email = is_string($email) ? strtolower(trim($email)) : '';
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $email === $this->senderAddress()) {
            return false;
        }

        $mailboxId = $this->mailboxId();
        $hasRuntimeTarget = is_string($mailboxId) && trim($mailboxId) !== '';
        $canSend = config('services.microsoft_graph.writes_enabled') && $hasRuntimeTarget;
        $status = $canSend ? 'pending' : 'pending_configuration';
        $outbox = GraphNotificationOutbox::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'id' => (string) Str::uuid(),
                'work_order_id' => $workOrder->id,
                'visit_id' => $visit?->id,
                'type' => $type,
                'recipient_email' => $email,
                'recipient_role' => $role,
                'status' => $status,
                'available_at' => now(),
                'payload' => $payload,
            ],
        );

        if ($canSend && in_array($outbox->status, ['pending', 'pending_configuration'], true)) {
            SendGraphNotification::dispatch($outbox->id)->afterCommit();
        }

        return true;
    }

    public function mailboxId(): ?string
    {
        return $this->runtimeTarget->notificationMailboxId();
    }

    public function senderAddress(): ?string
    {
        $address = $this->runtimeTarget->notificationMailboxAddress();

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL)
            ? strtolower(trim($address))
            : null;
    }
}
