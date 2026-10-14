<?php

namespace App\Observers;

use App\Models\Contact;
use App\Services\OutlookContactSyncCoordinator;

class OutlookContactObserver
{
    public function __construct(private readonly OutlookContactSyncCoordinator $sync) {}

    /** @var list<string> */
    private const SYNCED_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'title',
        'suffix',
        'email',
        'phone',
        'alternate_phone',
        'mobile_phone',
        'is_archived',
    ];

    public function created(Contact $contact): void
    {
        $this->queueSync($contact);
    }

    public function updated(Contact $contact): void
    {
        if ($contact->wasChanged(self::SYNCED_FIELDS)) {
            $this->queueSync($contact);
        }
    }

    private function queueSync(Contact $contact): void
    {
        if ($contact->getKey() === null) {
            return;
        }

        $this->sync->queue((int) $contact->getKey());
    }
}
