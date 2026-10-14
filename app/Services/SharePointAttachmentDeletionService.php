<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

class SharePointAttachmentDeletionService
{
    public function __construct(
        private readonly MicrosoftGraphSharePointStorageService $storage,
        private readonly AttachmentLifecycleService $lifecycle,
    ) {}

    public function delete(SharePointAttachment $attachment, User $actor): void
    {
        abort_unless($actor->status === 'active', 403);
        Gate::forUser($actor)->authorize('admin.access');
        $owner = $this->owner($attachment);

        try {
            $this->storage->delete($attachment->sharepoint_item_id);
        } catch (Throwable $exception) {
            if ($owner !== null) {
                $this->lifecycle->failed($owner, $actor, 'storage_unavailable', 'delete');
            }

            throw $exception;
        }

        try {
            DB::transaction(function () use ($attachment, $actor): void {
                $this->lifecycle->deleted($attachment, $actor);
                $attachment->delete();
            });
        } catch (Throwable $exception) {
            if ($owner !== null) {
                $this->lifecycle->failed($owner, $actor, 'metadata_persistence_failed', 'delete');
            }

            throw $exception;
        }
    }

    private function owner(SharePointAttachment $attachment): ?Model
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
}
