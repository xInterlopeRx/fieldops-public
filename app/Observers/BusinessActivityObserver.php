<?php

namespace App\Observers;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerAttachment;
use App\Models\CustomerEmailAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\ActivityLogService;
use Illuminate\Database\Eloquent\Model;

class BusinessActivityObserver
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function created(Model $model): void
    {
        if ($this->isBusinessRecord($model)) {
            $this->activityLog->record($model, 'created');
        }
    }

    public function updated(Model $model): void
    {
        if (! $this->isBusinessRecord($model)) {
            return;
        }

        $changedFields = array_values(array_diff(array_keys($model->getChanges()), [
            'updated_at',
            'is_archived',
            'archived_at',
            'archive_batch_id',
            'quickbooks_sync_status',
            'quickbooks_sync_error',
            'quickbooks_sync_requested_at',
            'quickbooks_last_synced_at',
            'quickbooks_write_request_id',
            'quickbooks_write_payload',
            'quickbooks_write_fields',
            'graph_sync_status',
            'graph_sync_error',
            'graph_synced_at',
            'microsoft_graph_event_tenant_id',
            'microsoft_graph_event_mailbox_id',
            'microsoft_graph_event_calendar_id',
            'rsvp_polled_at',
            'attendee_responses',
        ]));

        if ($changedFields !== []) {
            $this->activityLog->record($model, 'edited', ['changed_fields' => $changedFields]);
        }
    }

    private function isBusinessRecord(Model $model): bool
    {
        return $model instanceof Customer
            || $model instanceof Site
            || $model instanceof Project
            || $model instanceof WorkOrder
            || $model instanceof Visit
            || $model instanceof Contact
            || $model instanceof CustomerAddress
            || $model instanceof CustomerAttachment
            || $model instanceof CustomerEmailAddress
            || $model instanceof ProjectAttachment
            || $model instanceof WorkOrderAttachment;
    }
}
