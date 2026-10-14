<?php

namespace App\Jobs;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\GraphNotificationOutbox;
use App\Models\User;
use App\Models\WorkOrderAttachment;
use App\Services\GraphNotificationService;
use App\Services\MicrosoftGraphApiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SendGraphNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    public function __construct(public readonly string $outboxId) {}

    public function uniqueId(): string
    {
        return $this->outboxId;
    }

    public function handle(MicrosoftGraphApiClient $graph, GraphNotificationService $notifications): void
    {
        $mailboxId = $notifications->mailboxId();
        $outbox = DB::transaction(function () use ($mailboxId): ?GraphNotificationOutbox {
            $record = GraphNotificationOutbox::query()->lockForUpdate()->findOrFail($this->outboxId);

            if (in_array($record->status, ['accepted', 'unknown', 'failed', 'blocked_size', 'blocked_attachment', 'skipped_authorization'], true)) {
                return null;
            }

            if ($record->status === 'sending') {
                $record->update(['status' => 'unknown', 'last_error' => 'send_state_unknown']);

                return null;
            }

            if (! config('services.microsoft_graph.writes_enabled')
                || ! is_string($mailboxId)
                || trim($mailboxId) === '') {
                $record->update(['status' => 'pending_configuration']);

                return null;
            }

            $record->update([
                'status' => 'sending',
                'attempts' => $record->attempts + 1,
                'attempted_at' => now(),
                'last_error' => null,
            ]);

            return $record;
        });

        if ($outbox === null) {
            return;
        }

        if ($outbox->recipient_email === $notifications->senderAddress()) {
            $outbox->update(['status' => 'failed', 'last_error' => 'self_copy_blocked']);

            return;
        }

        if ($outbox->recipient_role !== null) {
            $permission = 'notifications.'.$outbox->recipient_role;
            $stillAuthorized = User::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($outbox->recipient_email)])
                ->where('status', 'active')
                ->permission($permission)
                ->exists();

            if (! $stillAuthorized) {
                $outbox->update(['status' => 'skipped_authorization', 'last_error' => 'recipient_role_no_longer_assigned']);

                return;
            }
        }

        try {
            $attachments = $this->attachments($outbox);
            $this->ensureMessageFits($outbox, $attachments);
            $message = $this->message($outbox, $attachments);
            if (! is_string($mailboxId) || trim($mailboxId) === '') {
                throw new MicrosoftGraphApiException('graph_configuration_incomplete', 503);
            }

            $graph->postJson('users/'.rawurlencode(trim($mailboxId)).'/sendMail', [
                'message' => $message,
                'saveToSentItems' => false,
            ]);

            $outbox->update(['status' => 'accepted', 'sent_at' => now(), 'last_error' => null]);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_rate_limited' && $outbox->attempts < 6) {
                $delay = $exception->retryAfterSeconds ?? 60;
                $outbox->update([
                    'status' => 'pending',
                    'available_at' => now()->addSeconds($delay),
                    'last_error' => $exception->reason,
                ]);
                $this->release($delay);

                return;
            }

            $status = $exception->reason === 'graph_unavailable' ? 'unknown' : 'failed';
            $outbox->update(['status' => $status, 'last_error' => $exception->reason]);
        } catch (\LengthException) {
            $outbox->update(['status' => 'blocked_size', 'last_error' => 'message_size_limit']);
            $notifications->deliveryBlocked($outbox->workOrder, 'attachment_set_exceeds_email_limit');
        } catch (\RuntimeException $exception) {
            $outbox->update(['status' => 'blocked_attachment', 'last_error' => 'attachment_unavailable']);
            $notifications->deliveryBlocked($outbox->workOrder, 'attachment_unavailable');
        } catch (Throwable) {
            $outbox->update(['status' => 'failed', 'last_error' => 'graph_notification_failed']);
        }
    }

    /** @return list<WorkOrderAttachment> */
    private function attachments(GraphNotificationOutbox $outbox): array
    {
        $ids = $outbox->payload['attachment_ids'] ?? [];
        if (! is_array($ids) || $ids === []) {
            return [];
        }

        $attachments = WorkOrderAttachment::query()
            ->where('work_order_id', $outbox->work_order_id)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->all();

        if (count($attachments) !== count(array_unique($ids))) {
            throw new \RuntimeException('One or more completion report files are no longer available.');
        }

        return $attachments;
    }

    /** @param list<WorkOrderAttachment> $attachments */
    private function ensureMessageFits(GraphNotificationOutbox $outbox, array $attachments): void
    {
        $maxBytes = (int) config('services.microsoft_graph.max_email_bytes', 2621440);
        $estimatedBytes = 16384;

        foreach ($attachments as $attachment) {
            $estimatedBytes += (int) ceil($attachment->size * 4 / 3) + 2048;
        }

        if ($estimatedBytes > $maxBytes) {
            throw new \LengthException('Completion report exceeds the configured Graph sendMail request budget.');
        }
    }

    /** @param list<WorkOrderAttachment> $attachments
     * @return array<string, mixed>
     */
    private function message(GraphNotificationOutbox $outbox, array $attachments): array
    {
        $workOrder = $outbox->workOrder;
        $status = str_replace('_', ' ', (string) ($outbox->payload['new_status'] ?? $workOrder->status));
        $jobLabel = $workOrder->job_number.' — '.$workOrder->title;
        $fieldNotes = trim((string) ($outbox->payload['field_notes'] ?? ''));
        $fieldNotesSection = $fieldNotes === '' ? '' : "\n\nField notes:\n{$fieldNotes}";
        [$subject, $body] = match ($outbox->type) {
            'job_created' => [
                'New Job created: '.$jobLabel,
                "A new Job has been created for you: {$workOrder->job_number} — {$workOrder->title}.",
            ],
            'job_scheduled' => [
                'Job scheduled: '.$jobLabel,
                "Job {$workOrder->job_number} — {$workOrder->title} has been scheduled.",
            ],
            'completion_summary' => [
                'Work completed: '.$jobLabel,
                trim((string) ($outbox->payload['summary'] ?? 'The scheduled work is complete.'))
                    .$fieldNotesSection
                    ."\n\nWe will send the full report and supporting files within 24 hours.",
            ],
            'completion_report' => [
                'Completion report: '.$jobLabel,
                "The full completion report and supporting files are attached for {$workOrder->job_number}.",
            ],
            'billing_ready' => [
                'Ready to bill: '.$jobLabel,
                "The billing job report for {$workOrder->job_number}, including recorded Item identities and quantities, is attached. Financial data remains in QuickBooks Online.",
            ],
            'rsvp_attention' => [
                'Visit RSVP needs attention: '.$jobLabel,
                $this->rsvpBody($outbox),
            ],
            'delivery_blocked' => [
                'Completion delivery needs attention: '.$jobLabel,
                $this->blockedBody($outbox),
            ],
            default => [
                'Job status update: '.$jobLabel,
                "The status for {$workOrder->job_number} changed to {$status}.".$fieldNotesSection,
            ],
        };

        $graphAttachments = [];
        foreach ($attachments as $attachment) {
            $bytes = Storage::disk($attachment->disk)->get($attachment->path);
            $graphAttachments[] = [
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'name' => $attachment->original_name,
                'contentType' => $attachment->mime_type,
                'contentBytes' => base64_encode($bytes),
            ];
        }

        return [
            'subject' => $subject,
            'body' => ['contentType' => 'text', 'content' => $body],
            'toRecipients' => [[
                'emailAddress' => ['address' => $outbox->recipient_email],
            ]],
            'attachments' => $graphAttachments,
        ];
    }

    private function rsvpBody(GraphNotificationOutbox $outbox): string
    {
        $reason = $outbox->payload['reason'] ?? 'rsvp_attention';
        $responses = $outbox->payload['responses'] ?? [];
        $lines = is_array($responses)
            ? collect($responses)->map(fn ($response, $email) => "{$email}: {$response}")->all()
            : [];

        return "A required Visit attendee declined or did not accept within 24 hours ({$reason}). The visit remains scheduled; management review is required.\n"
            .implode("\n", $lines);
    }

    private function blockedBody(GraphNotificationOutbox $outbox): string
    {
        return 'FieldOps did not send a partial completion package. Reason: '
            .($outbox->payload['reason'] ?? 'delivery_blocked')
            .'. Review the complete attachment set and provide an approved secure delivery path (for example, a verified specific-people SharePoint link).';
    }
}
