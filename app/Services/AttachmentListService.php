<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAttachment;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\SharePointAttachment;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Support\Collection;

class AttachmentListService
{
    private const MAX_ATTACHMENTS = 100;

    /** @return list<array{id: int, name: string, mimeType: string, size: int, uploadedBy: string|null, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string|null, availabilityUrl: string|null, officeApp: 'word'|'excel'|'powerpoint'|null}> */
    public function forCustomerWorkspace(Customer $customer): array
    {
        $local = $customer->attachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (CustomerAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'name' => $attachment->original_name,
                'mimeType' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'uploadedBy' => $attachment->uploader?->name,
                'createdAt' => $attachment->created_at?->toIso8601String() ?? '',
                'url' => route('admin.customer-attachments.show', $attachment),
                'previewUrl' => null,
                'thumbnailUrl' => null,
                'onlineUrl' => null,
                'availabilityUrl' => null,
                'officeApp' => null,
            ]);

        $sharePoint = $customer->sharePointAttachments()
            ->with('uploader:id,name')
            ->whereNull('site_id')
            ->whereNull('project_id')
            ->whereNull('job_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (SharePointAttachment $attachment): array => $this->workspaceItem($attachment));

        return $this->merge($local, $sharePoint);
    }

    /** @return list<array{id: int, name: string, mimeType: string, size: int, uploadedBy: string|null, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string|null, availabilityUrl: string|null, officeApp: 'word'|'excel'|'powerpoint'|null}> */
    public function forProjectWorkspace(Project $project): array
    {
        $local = $project->attachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (ProjectAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'name' => $attachment->original_name,
                'mimeType' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'uploadedBy' => $attachment->uploader?->name,
                'createdAt' => $attachment->created_at?->toIso8601String() ?? '',
                'url' => route('admin.projects.attachments.show', $attachment),
                'previewUrl' => null,
                'thumbnailUrl' => null,
                'onlineUrl' => null,
                'availabilityUrl' => null,
                'officeApp' => null,
            ]);

        $sharePoint = $project->sharePointAttachments()
            ->with('uploader:id,name')
            ->whereNull('job_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (SharePointAttachment $attachment): array => $this->workspaceItem($attachment));

        return $this->merge($local, $sharePoint);
    }

    /** @return list<array{id: int, name: string, mimeType: string, size: int, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string|null, availabilityUrl: string|null, officeApp: 'word'|'excel'|'powerpoint'|null}> */
    public function forJobWorkspace(WorkOrder $workOrder): array
    {
        $local = $workOrder->attachments()
            ->where('path', 'not like', 'work-orders/reports/billing/%')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (WorkOrderAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'name' => $attachment->original_name,
                'mimeType' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'createdAt' => $attachment->created_at?->toIso8601String() ?? '',
                'url' => route('admin.jobs.attachments.show', $attachment),
                'previewUrl' => null,
                'thumbnailUrl' => null,
                'onlineUrl' => null,
                'availabilityUrl' => null,
                'officeApp' => null,
            ]);

        $sharePoint = $workOrder->sharePointAttachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (SharePointAttachment $attachment): array => $this->workspaceItem($attachment));

        return $this->merge($local, $sharePoint);
    }

    /** @return list<array{id: int, kind: string, original_name: string, mime_type: string, size: int, url: string, download_url: string, preview_url: string|null, thumbnail_url: string|null, online_url: string|null, availability_url: string|null, office_app: 'word'|'excel'|'powerpoint'|null, created_at: string}> */
    public function forCalendarJob(WorkOrder $workOrder): array
    {
        $local = $workOrder->attachments()
            ->where('path', 'not like', 'work-orders/reports/billing/%')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (WorkOrderAttachment $attachment): array => [
                'id' => (int) $attachment->getKey(),
                'kind' => $attachment->kind,
                'original_name' => $attachment->original_name,
                'mime_type' => $attachment->mime_type,
                'size' => (int) $attachment->size,
                'url' => route('work-order-attachments.download', $attachment),
                'download_url' => route('work-order-attachments.download', [
                    'attachment' => $attachment->getKey(),
                    'download' => 1,
                ]),
                'preview_url' => null,
                'thumbnail_url' => null,
                'online_url' => null,
                'availability_url' => null,
                'office_app' => null,
                'created_at' => $attachment->created_at?->toIso8601String() ?? '',
            ]);

        $sharePoint = $workOrder->sharePointAttachments()
            ->with('uploader:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ATTACHMENTS)
            ->get()
            ->map(fn (SharePointAttachment $attachment): array => $this->calendarItem($attachment));

        return $this->merge($local, $sharePoint);
    }

    /** @return array{id: int, name: string, mimeType: string, size: int, uploadedBy: string|null, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string, availabilityUrl: string, officeApp: 'word'|'excel'|'powerpoint'|null} */
    public function customerUploadItem(SharePointAttachment $attachment): array
    {
        return $this->workspaceItem($attachment);
    }

    /** @return array{id: int, name: string, mimeType: string, size: int, uploadedBy: string|null, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string, availabilityUrl: string, officeApp: 'word'|'excel'|'powerpoint'|null} */
    public function projectUploadItem(SharePointAttachment $attachment): array
    {
        return $this->workspaceItem($attachment);
    }

    /** @return array{id: int, name: string, mimeType: string, size: int, uploadedBy: string|null, createdAt: string, url: string, previewUrl: string|null, thumbnailUrl: string|null, onlineUrl: string, availabilityUrl: string, officeApp: 'word'|'excel'|'powerpoint'|null} */
    public function workOrderUploadItem(SharePointAttachment $attachment): array
    {
        return $this->workspaceItem($attachment);
    }

    private function workspaceItem(SharePointAttachment $attachment): array
    {
        $mimeType = strtolower($attachment->mime_type);
        $isImage = in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
        $officeApp = $this->officeApp($attachment);
        $isPreviewable = $isImage
            || $mimeType === 'application/pdf'
            || in_array($mimeType, ['text/plain', 'text/csv', 'application/csv'], true);

        return [
            'id' => (int) $attachment->getKey(),
            'name' => $attachment->filename,
            'mimeType' => $attachment->mime_type,
            'size' => (int) $attachment->file_size,
            'uploadedBy' => $attachment->uploader?->name,
            'createdAt' => $attachment->created_at?->toIso8601String() ?? '',
            'url' => route('attachments.open', $attachment),
            'previewUrl' => $isPreviewable ? route('attachments.preview', $attachment) : null,
            'thumbnailUrl' => $isImage ? route('attachments.thumbnail', $attachment) : null,
            'onlineUrl' => route('attachments.open', $attachment),
            'availabilityUrl' => route('attachments.availability', $attachment),
            'officeApp' => $officeApp,
        ];
    }

    private function officeApp(SharePointAttachment $attachment): ?string
    {
        $mimeType = strtolower($attachment->mime_type);
        $extension = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));

        return match (true) {
            in_array($mimeType, ['application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)
                || in_array($extension, ['doc', 'docx'], true) => 'word',
            in_array($mimeType, ['application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)
                || in_array($extension, ['xls', 'xlsx'], true) => 'excel',
            in_array($mimeType, ['application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'], true)
                || in_array($extension, ['ppt', 'pptx'], true) => 'powerpoint',
            default => null,
        };
    }

    private function calendarItem(SharePointAttachment $attachment): array
    {
        $url = route('attachments.open', $attachment);
        $attachmentItem = $this->workspaceItem($attachment);

        return [
            'id' => (int) $attachment->getKey(),
            'kind' => str_starts_with($attachment->mime_type, 'image/') ? 'photos' : 'documents',
            'original_name' => $attachment->filename,
            'mime_type' => $attachment->mime_type,
            'size' => (int) $attachment->file_size,
            'url' => $url,
            'download_url' => $url,
            'preview_url' => $attachmentItem['previewUrl'],
            'thumbnail_url' => $attachmentItem['thumbnailUrl'],
            'online_url' => $attachmentItem['onlineUrl'],
            'availability_url' => $attachmentItem['availabilityUrl'],
            'office_app' => $attachmentItem['officeApp'],
            'created_at' => $attachment->created_at?->toIso8601String() ?? '',
        ];
    }

    /** @param Collection<int, array<string, mixed>> $local
     * @param  Collection<int, array<string, mixed>>  $sharePoint
     * @return list<array<string, mixed>>
     */
    private function merge(Collection $local, Collection $sharePoint): array
    {
        return $local->concat($sharePoint)
            ->sort(static function (array $left, array $right): int {
                $leftCreatedAt = (string) ($left['createdAt'] ?? $left['created_at'] ?? '');
                $rightCreatedAt = (string) ($right['createdAt'] ?? $right['created_at'] ?? '');
                $createdAtOrder = strcmp($rightCreatedAt, $leftCreatedAt);

                return $createdAtOrder !== 0
                    ? $createdAtOrder
                    : strcmp((string) $left['url'], (string) $right['url']);
            })
            ->take(self::MAX_ATTACHMENTS)
            ->values()
            ->all();
    }
}
