<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Services\MicrosoftGraphRuntimeTargetResolver;
use App\Services\OutlookContactSyncCoordinator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('graph:outlook:sync-contacts')]
#[Description('Queue active and previously synchronized FieldOps Contacts for one-way Outlook synchronization.')]
class QueueOutlookContactSync extends Command
{
    public function handle(OutlookContactSyncCoordinator $sync, MicrosoftGraphRuntimeTargetResolver $runtimeTarget): int
    {
        if (! config('services.microsoft_graph.contacts_sync_enabled')) {
            $this->error('Outlook Contact synchronization is disabled.');

            return self::FAILURE;
        }

        $mailboxId = $runtimeTarget->notificationMailboxId();

        if (! is_string($mailboxId) || trim($mailboxId) === '') {
            $this->error('The Outlook Contact target mailbox is not configured.');

            return self::FAILURE;
        }

        $queued = 0;

        Contact::withArchived()
            ->where(function (Builder $query): void {
                $query->where('is_archived', false)
                    ->orWhereNotNull('outlook_contact_id');
            })
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use (&$queued, $sync): void {
                foreach ($contacts as $contact) {
                    if ($sync->queue((int) $contact->getKey())) {
                        $queued++;
                    }
                }
            });

        $this->info("Queued {$queued} Contact synchronization jobs.");

        return self::SUCCESS;
    }
}
