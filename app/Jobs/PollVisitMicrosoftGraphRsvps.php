<?php

namespace App\Jobs;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Visit;
use App\Services\GraphNotificationService;
use App\Services\MicrosoftGraphCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PollVisitMicrosoftGraphRsvps implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly int $visitId) {}

    public function uniqueId(): string
    {
        return (string) $this->visitId;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(MicrosoftGraphCalendarService $calendar, GraphNotificationService $notifications): void
    {
        if (! config('services.microsoft_graph.calendar_writes_enabled')) {
            return;
        }

        $visit = Visit::query()->with('workOrder')->findOrFail($this->visitId);
        if (! $visit->microsoft_graph_event_id || $visit->graph_sync_status !== 'synced') {
            return;
        }

        try {
            $responses = $calendar->pollResponses($visit);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_rate_limited') {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            throw $exception;
        }

        $visit->attendee_responses = $responses;
        $visit->rsvp_polled_at = now();
        $visit->save();

        if ($visit->rsvp_attention_notified_at || $responses === []) {
            return;
        }

        $responses = collect($responses);
        $declined = $responses->contains(fn (string $response): bool => $response === 'declined');
        $timedOut = $visit->graph_synced_at !== null
            && $visit->graph_synced_at->copy()->addHours(24)->isPast()
            && $responses->contains(fn (string $response): bool => $response !== 'accepted');

        if ($declined || $timedOut) {
            $reason = $declined ? 'attendee_declined' : 'rsvp_timeout';
            $notifications->rsvpNeedsAttention($visit, $responses->all(), $reason);
            $visit->update(['rsvp_attention_notified_at' => now()]);
        }
    }
}
