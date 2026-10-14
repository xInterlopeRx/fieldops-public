<?php

namespace App\Jobs;

use App\Exceptions\MicrosoftGraphApiException;
use App\Exceptions\OutlookContactSyncException;
use App\Models\Contact;
use App\Services\OutlookContactSyncCoordinator;
use App\Services\OutlookContactSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOutlookContact implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;

    public int $timeout = 70;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $contactId, public readonly bool $repair = false) {}

    public function uniqueId(): string
    {
        return (string) $this->contactId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600];
    }

    public function handle(OutlookContactSyncService $sync, OutlookContactSyncCoordinator $coordinator): void
    {
        $contact = Contact::withArchived()
            ->find($this->contactId);

        if (
            $contact === null ||
            ($contact->outlook_sync_status === 'unknown'
                && (! is_string($contact->outlook_contact_id) || $contact->outlook_contact_id === ''))
        ) {
            return;
        }

        if (! config('services.microsoft_graph.contacts_sync_enabled')) {
            $coordinator->markPaused($this->contactId);

            return;
        }

        $coordinator->markSyncing($this->contactId);

        try {
            $sync->sync($this->contactId, $this->repair);
            $coordinator->markSynced($this->contactId);
        } catch (OutlookContactSyncException $exception) {
            $this->fail($exception);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_rate_limited') {
                $coordinator->markPending($this->contactId, $exception->reason);
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            if ($exception->reason === 'graph_permission_denied') {
                $this->fail($exception);

                return;
            }

            $coordinator->markPending($this->contactId, $exception->reason);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $reason = match (true) {
            $exception instanceof OutlookContactSyncException => $exception->reason,
            $exception instanceof MicrosoftGraphApiException => $exception->reason,
            default => 'outlook_contact_sync_failed',
        };
        $unknownOutcome = in_array($reason, [
            'outlook_contact_create_outcome_unknown',
            'outlook_contact_create_reconciliation_required',
            'outlook_contact_id_missing',
            'outlook_contact_id_persistence_failed',
        ], true);

        app(OutlookContactSyncCoordinator::class)->markFailed($this->contactId, $reason, $unknownOutcome);

        Log::warning('Outlook Contact synchronization failed', [
            'contact_id' => $this->contactId,
            'reason' => $reason,
        ]);
    }
}
