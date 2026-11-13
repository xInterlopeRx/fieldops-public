<?php

namespace App\Services\Contracts;

use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;

/**
 * The SharePoint-backed attachment upload contract. Type-hint this interface
 * where uploads are orchestrated (commands, jobs) so tests can bind a plain
 * fake without subclassing the concrete service.
 */
interface SharePointAttachmentUploader
{
    public function uploadForCustomer(UploadedFile $file, Customer $customer, User $uploadedBy): SharePointAttachment;

    public function uploadForProject(UploadedFile $file, Project $project, User $uploadedBy): SharePointAttachment;

    /**
     * @param  string|null  $clientUploadId  UUID; makes the upload idempotent.
     */
    public function uploadForWorkOrder(UploadedFile $file, WorkOrder $workOrder, User $uploadedBy, ?string $clientUploadId = null): SharePointAttachment;
}
