<?php

namespace App\Jobs;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Visit;
use App\Services\MicrosoftGraphCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncVisitMicrosoftGraphEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public function __construct(public readonly int $visitId) {}

    public function uniqueId(): string
    {
        return (string) $this->visitId;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(MicrosoftGraphCalendarService $calendar): void
    {
        $visit = Visit::query()->with('workOrder')->findOrFail($this->visitId);

        if (! config('services.microsoft_graph.calendar_writes_enabled')) {
            $visit->update(['graph_sync_status' => 'pending_configuration']);

            return;
        }

        try {
            $calendar->syncVisit($visit);
        } catch (MicrosoftGraphApiException $exception) {
            $visit->update([
                'graph_sync_status' => $exception->reason === 'graph_rate_limited' ? 'pending' : 'failed',
                'graph_sync_error' => $exception->reason,
            ]);

            if ($exception->reason === 'graph_rate_limited') {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            throw $exception;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Visit::query()->whereKey($this->visitId)->update([
            'graph_sync_status' => 'failed',
            'graph_sync_error' => $exception instanceof MicrosoftGraphApiException
                ? $exception->reason
                : 'graph_sync_failed',
        ]);
    }
}
