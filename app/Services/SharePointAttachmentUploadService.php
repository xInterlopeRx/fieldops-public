<?php

namespace App\Services;

use App\Exceptions\AttachmentUploadFailed;
use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SharePointAttachmentUploadService
{
    public function __construct(
        private readonly MicrosoftGraphSharePointStorageService $storage,
        private readonly AttachmentLifecycleService $lifecycle,
    ) {}

    public function uploadForCustomer(UploadedFile $file, Customer $customer, User $uploadedBy): SharePointAttachment
    {
        return $this->store($file, $customer, (int) $customer->getKey(), $uploadedBy);
    }

    public function uploadForProject(UploadedFile $file, Project $project, User $uploadedBy): SharePointAttachment
    {
        return $this->store(
            $file,
            $project,
            (int) $project->customer_id,
            $uploadedBy,
            projectId: (int) $project->getKey(),
        );
    }

    public function uploadForWorkOrder(
        UploadedFile $file,
        WorkOrder $workOrder,
        User $uploadedBy,
        ?string $clientUploadId = null,
    ): SharePointAttachment {
        $site = Site::query()->findOrFail($workOrder->site_id);

        if ($clientUploadId !== null) {
            if (! Str::isUuid($clientUploadId)) {
                throw new AttachmentUploadFailed(422, new MicrosoftGraphApiException('graph_sharepoint_attachment_upload_id_invalid', 422));
            }

            $lock = Cache::lock('sharepoint-attachment-upload-'.$clientUploadId, 180);

            if (! $lock->get()) {
                throw new AttachmentUploadFailed(409, new MicrosoftGraphApiException('graph_sharepoint_attachment_upload_in_progress', 409));
            }

            try {
                $existing = SharePointAttachment::query()->where('client_upload_id', $clientUploadId)->first();

                if ($existing !== null) {
                    $sameUpload = (int) $existing->company_id === (int) $site->customer_id
                        && (int) $existing->site_id === (int) $site->getKey()
                        && (int) $existing->job_id === (int) $workOrder->getKey()
                        && (int) $existing->uploaded_by === (int) $uploadedBy->getKey()
                        && $existing->filename === $this->safeClientFilename($file)
                        && (int) $existing->file_size === (int) $file->getSize()
                        && strtolower($existing->mime_type) === strtolower($file->getMimeType() ?: 'application/octet-stream');

                    if (! $sameUpload) {
                        throw new AttachmentUploadFailed(409, new MicrosoftGraphApiException('graph_sharepoint_attachment_upload_id_conflict', 409));
                    }

                    return $existing;
                }

                return $this->store(
                    $file,
                    $workOrder,
                    (int) $site->customer_id,
                    $uploadedBy,
                    siteId: (int) $site->getKey(),
                    projectId: $workOrder->project_id === null ? null : (int) $workOrder->project_id,
                    jobId: (int) $workOrder->getKey(),
                    clientUploadId: $clientUploadId,
                );
            } finally {
                $lock->release();
            }
        }

        return $this->store(
            $file,
            $workOrder,
            (int) $site->customer_id,
            $uploadedBy,
            siteId: (int) $site->getKey(),
            projectId: $workOrder->project_id === null ? null : (int) $workOrder->project_id,
            jobId: (int) $workOrder->getKey(),
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<SharePointAttachment>
     */
    public function uploadManyForWorkOrder(
        array $files,
        WorkOrder $workOrder,
        User $uploadedBy,
        ?string $clientUploadId = null,
    ): array {
        $attachments = [];

        try {
            DB::transaction(function () use ($files, $workOrder, $uploadedBy, $clientUploadId, &$attachments): void {
                foreach ($files as $index => $file) {
                    $attachments[] = $this->uploadForWorkOrder(
                        $file,
                        $workOrder,
                        $uploadedBy,
                        $index === 0 ? $clientUploadId : null,
                    );
                }
            });
        } catch (Throwable $exception) {
            foreach (array_reverse($attachments) as $attachment) {
                $this->compensate($attachment);
            }

            throw $exception;
        }

        return $attachments;
    }

    private function store(
        UploadedFile $file,
        Customer|Project|WorkOrder $owner,
        int $companyId,
        User $uploadedBy,
        ?int $siteId = null,
        ?int $projectId = null,
        ?int $jobId = null,
        ?string $clientUploadId = null,
    ): SharePointAttachment {
        try {
            return $this->persist($file, $owner, $companyId, $uploadedBy, $siteId, $projectId, $jobId, $clientUploadId);
        } catch (MicrosoftGraphApiException $exception) {
            Log::warning('SharePoint-backed attachment upload failed', [
                'error_code' => $exception->reason,
                'http_status' => $exception->httpStatus,
            ]);

            throw new AttachmentUploadFailed($exception->httpStatus, $exception);
        }
    }

    private function persist(
        UploadedFile $file,
        Customer|Project|WorkOrder $owner,
        int $companyId,
        User $uploadedBy,
        ?int $siteId = null,
        ?int $projectId = null,
        ?int $jobId = null,
        ?string $clientUploadId = null,
    ): SharePointAttachment {
        $this->validateOwnerContext($companyId, $siteId, $projectId, $jobId);

        $temporaryPath = $file->getRealPath();
        $contents = is_string($temporaryPath) ? file_get_contents($temporaryPath) : false;

        if (! is_string($contents)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_unavailable', 503);
        }

        $fileSize = $file->getSize();

        if (! is_int($fileSize) || $fileSize !== strlen($contents)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_size_unavailable', 503);
        }

        $mimeType = $file->getMimeType() ?: 'application/octet-stream';
        $extension = $file->guessExtension();
        $safeExtension = is_string($extension) && preg_match('/^[a-z0-9]{1,12}$/iD', $extension) === 1
            ? strtolower($extension)
            : 'bin';
        $relativePath = 'FieldOps/customer-'.$companyId.'/'.($clientUploadId ?? Str::uuid()).'.'.$safeExtension;
        $driveId = config('services.microsoft_graph.work_schedules.drive_id');

        if (! is_string($driveId) || trim($driveId) === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_configuration_incomplete', 503);
        }

        $uploaded = $this->storage->upload($relativePath, $contents, $mimeType);
        $itemId = $uploaded['id'] ?? null;

        try {
            if (
                ! is_string($itemId) || $itemId === '' || strlen($itemId) > 255 ||
                ! is_int($uploaded['size'] ?? null) || $uploaded['size'] !== $fileSize ||
                ! $this->isSharePointUrl($uploaded['sharepoint_url'] ?? null)
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_attachment_metadata_invalid', 502);
            }

            $filename = $this->safeClientFilename($file);

            $remoteFileWasAlreadyRegistered = false;

            return DB::transaction(function () use (
                $companyId,
                $siteId,
                $projectId,
                $jobId,
                $uploadedBy,
                $filename,
                $mimeType,
                $fileSize,
                $driveId,
                $itemId,
                $uploaded,
                $clientUploadId,
                &$remoteFileWasAlreadyRegistered,
            ): SharePointAttachment {
                $existing = SharePointAttachment::query()
                    ->where('sharepoint_drive_id', trim($driveId))
                    ->where('sharepoint_item_id', $itemId)
                    ->first();

                if ($existing !== null) {
                    $remoteFileWasAlreadyRegistered = true;

                    if ($clientUploadId !== null && $existing->client_upload_id === $clientUploadId) {
                        return $existing;
                    }

                    throw new MicrosoftGraphApiException('graph_sharepoint_attachment_item_already_linked', 409);
                }

                $attachment = SharePointAttachment::query()->create([
                    'company_id' => $companyId,
                    'site_id' => $siteId,
                    'project_id' => $projectId,
                    'job_id' => $jobId,
                    'uploaded_by' => $uploadedBy->getKey(),
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'sharepoint_drive_id' => trim($driveId),
                    'sharepoint_item_id' => $itemId,
                    'sharepoint_url' => $uploaded['sharepoint_url'],
                    'client_upload_id' => $clientUploadId,
                ]);

                $this->lifecycle->uploaded($attachment, $uploadedBy);

                return $attachment;
            });
        } catch (Throwable $exception) {
            if (! $remoteFileWasAlreadyRegistered && is_string($itemId) && $itemId !== '') {
                try {
                    $this->storage->delete($itemId);
                } catch (Throwable) {
                    Log::warning('SharePoint uploaded attachment could not be removed after metadata failure', [
                        'drive_id' => trim($driveId),
                        'item_id' => $itemId,
                    ]);
                }
            }

            throw $exception;
        }
    }

    private function isSharePointUrl(?string $url): bool
    {
        if (! is_string($url) || $url === '' || strlen($url) > 4096 || preg_match('/[\r\n]/', $url) === 1) {
            return false;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
            && (str_ends_with($host, '.sharepoint.com') || str_ends_with($host, '.sharepointonline.com'))
            && (! isset($parts['port']) || $parts['port'] === 443)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['fragment']);
    }

    private function safeClientFilename(UploadedFile $file): string
    {
        $filename = trim(str_replace(["\0", "\r", "\n"], '', basename(str_replace('\\', '/', $file->getClientOriginalName()))));
        $filename = Str::limit($filename, 255, '');

        return $filename === '' ? 'attachment' : $filename;
    }

    private function compensate(SharePointAttachment $attachment): void
    {
        try {
            $this->storage->delete($attachment->sharepoint_item_id);
        } catch (Throwable) {
            Log::warning('SharePoint attachment could not be removed after a batch upload failure', [
                'drive_id' => $attachment->sharepoint_drive_id,
                'item_id' => $attachment->sharepoint_item_id,
            ]);

            return;
        }

        $attachment->delete();
    }

    private function validateOwnerContext(int $companyId, ?int $siteId, ?int $projectId, ?int $jobId): void
    {
        if (! Customer::query()->whereKey($companyId)->exists()) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_company_not_found', 404);
        }

        if ($siteId !== null && ! Site::query()->where('customer_id', $companyId)->whereKey($siteId)->exists()) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_site_mismatch', 422);
        }

        if ($projectId !== null && ! Project::query()->where('customer_id', $companyId)->whereKey($projectId)->exists()) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_project_mismatch', 422);
        }

        if ($jobId === null) {
            return;
        }

        $workOrder = WorkOrder::query()
            ->whereKey($jobId)
            ->whereHas('site', fn ($query) => $query->where('customer_id', $companyId))
            ->first();

        if (
            $workOrder === null ||
            ($siteId !== null && (int) $workOrder->site_id !== $siteId) ||
            ($projectId !== null && (int) $workOrder->project_id !== $projectId)
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_job_mismatch', 422);
        }

        if (
            $siteId !== null &&
            $projectId !== null &&
            ! Project::query()->whereKey($projectId)->firstOrFail()->sites()->whereKey($siteId)->exists()
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_project_site_mismatch', 422);
        }
    }
}
