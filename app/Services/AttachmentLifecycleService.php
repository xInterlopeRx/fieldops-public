<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\AttachmentLifecycleEmailNotification;
use App\Notifications\AttachmentLifecycleNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AttachmentLifecycleService
{
    private const RECIPIENT_CHUNK_SIZE = 250;

    public function __construct(private readonly ActivityLogService $activityLog) {}

    public function uploaded(SharePointAttachment $attachment, User $actor): void
    {
        $owner = $this->attachmentOwner($attachment) ?? $attachment;
        $this->activityLog->record($owner, 'attachment_uploaded', [
            'attachment_id' => $attachment->getKey(),
        ], $actor);
        $this->notify('attachment_uploaded', $attachment, $actor);
    }

    public function deleted(SharePointAttachment $attachment, User $actor): void
    {
        $owner = $this->attachmentOwner($attachment) ?? $attachment;
        $this->activityLog->record($owner, 'attachment_deleted', [
            'attachment_id' => $attachment->getKey(),
        ], $actor);
        $this->notify('attachment_deleted', $attachment, $actor, operation: 'delete');
    }

    public function remoteMissing(SharePointAttachment $attachment, User $actor): void
    {
        $owner = $this->attachmentOwner($attachment);

        if ($owner === null) {
            return;
        }

        $this->activityLog->record($owner, 'attachment_remote_missing', [
            'attachment_id' => $attachment->getKey(),
        ], $actor);
    }

    public function failed(
        Model $owner,
        User $actor,
        string $failureCode = 'attachment_operation_failed',
        string $operation = 'upload',
    ): void {
        try {
            $safeFailureCode = in_array($failureCode, [
                'attachment_operation_failed',
                'storage_unavailable',
                'metadata_persistence_failed',
            ], true) ? $failureCode : 'attachment_operation_failed';

            DB::transaction(function () use ($owner, $actor, $safeFailureCode, $operation): void {
                $this->activityLog->record($owner, 'attachment_failed', [
                    'failure_code' => $safeFailureCode,
                    'operation' => $operation,
                ], $actor);

                $this->notify('attachment_failed', $owner, $actor, $operation);
            });
        } catch (Throwable $exception) {
            Log::warning('Attachment failure event could not be recorded', [
                'error_type' => class_basename($exception),
            ]);
        }
    }

    /** @return Builder<User> */
    private function recipients(Model $owner): Builder
    {
        if ($owner instanceof SharePointAttachment) {
            $owner = $this->attachmentOwner($owner);

            if ($owner === null) {
                return User::query()->whereRaw('1 = 0');
            }
        }

        if ($owner instanceof User) {
            return User::query()
                ->whereKey($owner->getKey())
                ->where('status', 'active');
        }

        return User::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($owner): void {
                if ($owner instanceof Customer) {
                    $query->whereHas('assignedCustomers', fn (Builder $assigned): Builder => $assigned->whereKey($owner->getKey()));

                    return;
                }

                if ($owner instanceof Project) {
                    $query->whereHas('assignedProjects', fn (Builder $assigned): Builder => $assigned->whereKey($owner->getKey()))
                        ->orWhereHas('assignedWorkOrders', fn (Builder $assigned): Builder => $assigned->where('work_orders.project_id', $owner->getKey()))
                        ->orWhereHas('assignedVisits.workOrder', fn (Builder $assigned): Builder => $assigned->where('work_orders.project_id', $owner->getKey()));

                    return;
                }

                if ($owner instanceof Site) {
                    $query->whereHas('assignedWorkOrders', fn (Builder $assigned): Builder => $assigned->where('work_orders.site_id', $owner->getKey()))
                        ->orWhereHas('assignedVisits.workOrder', fn (Builder $assigned): Builder => $assigned->where('work_orders.site_id', $owner->getKey()))
                        ->orWhereHas('assignedProjects.sites', fn (Builder $assigned): Builder => $assigned->whereKey($owner->getKey()));

                    return;
                }

                if ($owner instanceof WorkOrder) {
                    $query->whereHas('assignedWorkOrders', fn (Builder $assigned): Builder => $assigned->whereKey($owner->getKey()))
                        ->orWhereHas('assignedVisits', fn (Builder $assigned): Builder => $assigned->where('visits.work_order_id', $owner->getKey()));

                    if ($owner->project_id !== null) {
                        $query->orWhereHas('assignedProjects', fn (Builder $assigned): Builder => $assigned->whereKey($owner->project_id));
                    }
                }
            })
            ->orderBy('users.id');
    }

    private function notify(
        string $event,
        Model $owner,
        User $actor,
        string $operation = 'upload',
    ): void {
        $recordLabel = $this->recordLabel($owner);

        $this->recipients($owner)
            ->select(['users.id', 'users.name', 'users.email'])
            ->chunkById(self::RECIPIENT_CHUNK_SIZE, function (Collection $recipients) use ($event, $recordLabel, $actor, $operation): void {
                $notification = new AttachmentLifecycleNotification(
                    event: $event,
                    recordLabel: $recordLabel,
                    actorName: $actor->name,
                    operation: $operation,
                );

                Notification::sendNow($recipients, $notification, ['database']);

                $emailNotification = (new AttachmentLifecycleEmailNotification(
                    event: $event,
                    recordLabel: $recordLabel,
                    actorName: $actor->name,
                    operation: $operation,
                ))->afterCommit();

                Notification::send($recipients, $emailNotification);
            });
    }

    private function attachmentOwner(SharePointAttachment $attachment): ?Model
    {
        if ($attachment->job_id !== null) {
            return WorkOrder::withArchived()->find($attachment->job_id);
        }

        if ($attachment->project_id !== null) {
            return Project::withArchived()->find($attachment->project_id);
        }

        if ($attachment->site_id !== null) {
            return Site::withArchived()->find($attachment->site_id);
        }

        return Customer::withArchived()->find($attachment->company_id);
    }

    private function recordLabel(Model $owner): string
    {
        if ($owner instanceof SharePointAttachment) {
            $owner = $this->attachmentOwner($owner) ?? $owner;
        }

        return match (true) {
            $owner instanceof Customer => 'customer #'.$owner->getKey(),
            $owner instanceof Project => 'project #'.$owner->getKey(),
            $owner instanceof Site => 'site #'.$owner->getKey(),
            $owner instanceof WorkOrder => 'job '.$owner->job_number,
            $owner instanceof User => 'Job creation',
            default => 'record #'.$owner->getKey(),
        };
    }
}
