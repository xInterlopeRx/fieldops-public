<?php

namespace App\Services;

use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\CustomerAttachment;
use App\Models\CustomerEmailAddress;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    /** @param array<string, mixed> $metadata */
    public function record(Model $subject, string $event, array $metadata = [], ?User $actor = null): void
    {
        $actor ??= Auth::user();

        if (! $actor instanceof User) {
            return;
        }

        $recordLabel = $this->recordLabel($subject);

        if ($subject instanceof User && $event === 'attachment_failed') {
            $summary = $actor->name.' failed to upload an attachment during Job creation.';
        } else {
            $action = match ($event) {
                'created' => 'created',
                'edited' => 'edited',
                'archived' => 'archived',
                'restored' => 'restored',
                'deleted' => 'deleted',
                'marked_invoiced' => 'marked as locally Invoiced',
                'quickbooks_refreshed' => 'resolved QuickBooks data for',
                'quickbooks_retry_requested' => 'requested a QuickBooks retry for',
                'quickbooks_sync_paused' => 'paused QuickBooks synchronization in',
                'quickbooks_sync_resumed' => 'resumed QuickBooks synchronization in',
                'graph_visit_retry_requested' => 'requested a Microsoft Graph Visit sync retry for',
                'graph_notification_retry_requested' => 'requested a Graph notification retry for',
                'outlook_contact_resync_requested' => 'requested an Outlook Contact resync for',
                'outlook_contact_repair_requested' => 'requested replacement of the linked Outlook Contact for',
                'outlook_contact_manual_reconciliation_confirmed' => 'confirmed manual Outlook Contact reconciliation for',
                'assigned' => 'assigned '.($metadata['assigned_user_name'] ?? 'a staff member').' to',
                'unassigned' => 'removed '.($metadata['assigned_user_name'] ?? 'a staff member').' from',
                'attachment_uploaded' => 'uploaded an attachment to',
                'attachment_deleted' => 'permanently deleted an attachment from',
                'attachment_remote_missing' => 'removed a stale SharePoint attachment link from',
                'attachment_failed' => ($metadata['operation'] ?? 'upload') === 'delete'
                    ? 'failed to delete an attachment from'
                    : 'failed to upload an attachment for',
                default => str_replace('_', ' ', $event),
            };

            $summary = $actor->name.' '.$action.' '.$recordLabel;
        }
        unset($metadata['assigned_user_name']);

        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'event' => $event,
            'summary' => mb_substr($summary, 0, 500),
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function recordLabel(Model $subject): string
    {
        return match (true) {
            $subject instanceof Customer => 'customer '.($subject->company_name ?: $subject->name),
            $subject instanceof ApplicationSetupState => 'the application',
            $subject instanceof CustomerAddress => ucfirst($subject->address_type).' address for customer #'.$subject->customer_id,
            $subject instanceof Contact => 'contact for customer #'.$subject->customer_id,
            $subject instanceof CustomerEmailAddress => 'additional email for customer #'.$subject->customer_id,
            $subject instanceof CustomerAttachment => 'customer attachment '.$subject->original_name,
            $subject instanceof SharePointAttachment => 'attachment #'.$subject->getKey(),
            $subject instanceof Site => 'site '.$subject->name,
            $subject instanceof Project => 'project '.($subject->project_number ?: $subject->name),
            $subject instanceof TimeEntry => 'time entry #'.$subject->getKey(),
            $subject instanceof ProjectAttachment => 'project attachment '.$subject->original_name,
            $subject instanceof WorkOrder => 'job '.($subject->job_number ?: $subject->title),
            $subject instanceof WorkOrderAttachment => 'job attachment '.$subject->original_name,
            $subject instanceof Visit => 'visit for job '.(WorkOrder::query()->whereKey($subject->work_order_id)->value('job_number') ?? '#'.$subject->work_order_id),
            default => strtolower(class_basename($subject)).' #'.$subject->getKey(),
        };
    }
}
