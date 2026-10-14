<?php

namespace App\Http\Controllers;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\Customer;
use App\Models\Project;
use App\Models\SharePointAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AttachmentLifecycleService;
use App\Services\MicrosoftGraphSharePointStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\HeaderUtils;

class OpenAttachmentController extends Controller
{
    public function __invoke(Request $request, SharePointAttachment $attachment): RedirectResponse
    {
        $this->authorizeAttachment($request, $attachment);

        $url = $this->validatedSharePointUrl($attachment->sharepoint_url);

        abort_if($url === null, 404);

        return redirect()->away($url);
    }

    public function availability(
        Request $request,
        SharePointAttachment $attachment,
        MicrosoftGraphSharePointStorageService $storage,
        AttachmentLifecycleService $attachmentLifecycle,
    ): JsonResponse {
        $this->authorizeAttachment($request, $attachment);

        try {
            $storage->metadata($attachment->sharepoint_item_id);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_record_not_found' && $exception->httpStatus === 404) {
                $this->removeMissingMetadata($request, $attachment, $attachmentLifecycle);

                return response()->json([
                    'message' => 'SharePoint no longer has this file. The stale FieldOps attachment link was removed.',
                ], 410);
            }

            return response()->json([
                'message' => 'SharePoint could not verify this file. Its attachment record was left unchanged.',
            ], $this->safeFailureStatus($exception));
        }

        return response()->json(['available' => true]);
    }

    public function preview(
        Request $request,
        SharePointAttachment $attachment,
        MicrosoftGraphSharePointStorageService $storage,
        AttachmentLifecycleService $attachmentLifecycle,
    ): Response {
        $this->authorizeAttachment($request, $attachment);

        if (! $this->supportsPreview($attachment)) {
            return $this->unavailableResponse(415);
        }

        try {
            $file = $storage->downloadWithMetadata($attachment->sharepoint_item_id);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_record_not_found' && $exception->httpStatus === 404) {
                $this->removeMissingMetadata($request, $attachment, $attachmentLifecycle);
            }

            return $this->unavailableResponse($this->safeFailureStatus($exception));
        }

        if (! $this->remoteMimeMatches($attachment, $file['metadata']['mime_type'])) {
            return $this->unavailableResponse(415);
        }

        $contents = $file['contents'];
        $mimeType = strtolower($attachment->mime_type);

        if ($this->isImageMimeType($mimeType)) {
            if (! $this->canRenderRasterMimeType($mimeType)) {
                return $this->unavailableResponse(503);
            }

            $contents = $this->renderRasterImage($contents, $mimeType, 2400, 1800);

            if ($contents === null) {
                return $this->unavailableResponse(415);
            }

            return $this->inlineResponse(
                $contents,
                'image/jpeg',
                pathinfo($attachment->filename, PATHINFO_FILENAME).'.jpg',
            );
        }

        if ($mimeType === 'application/pdf') {
            if (strpos(substr($contents, 0, 1024), '%PDF-') === false) {
                return $this->unavailableResponse(415);
            }

            return $this->inlineResponse($contents, 'application/pdf', $attachment->filename);
        }

        if ($this->isTextMimeType($mimeType) && $this->isSafeText($contents)) {
            return $this->inlineResponse($contents, 'text/plain; charset=UTF-8', $attachment->filename);
        }

        return $this->unavailableResponse(415);
    }

    public function thumbnail(
        Request $request,
        SharePointAttachment $attachment,
        MicrosoftGraphSharePointStorageService $storage,
        AttachmentLifecycleService $attachmentLifecycle,
    ): Response {
        $this->authorizeAttachment($request, $attachment);

        if (! $this->isImageMimeType(strtolower($attachment->mime_type))) {
            return $this->unavailableResponse(415);
        }

        if (! $this->canRenderRasterMimeType(strtolower($attachment->mime_type))) {
            return $this->unavailableResponse(503);
        }

        try {
            $file = $storage->thumbnail($attachment->sharepoint_item_id);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_record_not_found' && $exception->httpStatus === 404) {
                $this->removeMissingMetadata($request, $attachment, $attachmentLifecycle);
            }

            return $this->unavailableResponse($this->safeFailureStatus($exception));
        }

        $thumbnailInfo = @getimagesizefromstring($file['contents']);

        if (
            ! is_array($thumbnailInfo) ||
            ! isset($thumbnailInfo[0], $thumbnailInfo[1], $thumbnailInfo['mime']) ||
            ! $this->isImageMimeType($thumbnailInfo['mime']) ||
            $thumbnailInfo[0] !== $file['width'] ||
            $thumbnailInfo[1] !== $file['height']
        ) {
            return $this->unavailableResponse(415);
        }

        if (! $this->canRenderRasterMimeType($thumbnailInfo['mime'])) {
            return $this->unavailableResponse(503);
        }

        $thumbnail = $this->renderRasterImage($file['contents'], $thumbnailInfo['mime'], 320, 240);

        if ($thumbnail === null) {
            return $this->unavailableResponse(415);
        }

        return $this->inlineResponse($thumbnail, 'image/jpeg', 'thumbnail.jpg');
    }

    private function authorizeAttachment(Request $request, SharePointAttachment $attachment): void
    {
        $company = Customer::query()->findOrFail($attachment->company_id);
        $site = $attachment->site_id === null
            ? null
            : Site::query()->where('customer_id', $company->getKey())->findOrFail($attachment->site_id);
        $project = $attachment->project_id === null
            ? null
            : Project::query()->where('customer_id', $company->getKey())->findOrFail($attachment->project_id);

        if ($site !== null && $project !== null) {
            abort_unless($project->sites()->whereKey($site->getKey())->exists(), 404);
        }

        $workOrder = null;

        if ($attachment->job_id !== null) {
            $workOrder = WorkOrder::query()
                ->whereKey($attachment->job_id)
                ->whereHas('site', fn ($query) => $query->where('customer_id', $company->getKey()))
                ->firstOrFail();

            abort_unless($site === null || (int) $workOrder->site_id === (int) $site->getKey(), 404);
            abort_unless($project === null || (int) $workOrder->project_id === (int) $project->getKey(), 404);
        }

        $user = $request->user();
        $isAdministrator = $user?->can('admin.access') ?? false;

        abort_unless($isAdministrator || ($workOrder !== null && $this->assignedUserCanOpen($user, $workOrder)), 403);
    }

    private function removeMissingMetadata(
        Request $request,
        SharePointAttachment $attachment,
        AttachmentLifecycleService $attachmentLifecycle,
    ): void {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return;
        }

        DB::transaction(function () use ($attachment, $actor, $attachmentLifecycle): void {
            $current = SharePointAttachment::query()
                ->lockForUpdate()
                ->find($attachment->getKey());

            if ($current === null) {
                return;
            }

            $attachmentLifecycle->remoteMissing($current, $actor);
            $current->delete();
        });
    }

    private function supportsPreview(SharePointAttachment $attachment): bool
    {
        $mimeType = strtolower($attachment->mime_type);

        return $this->isImageMimeType($mimeType)
            || $mimeType === 'application/pdf'
            || $this->isTextMimeType($mimeType);
    }

    private function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    private function isTextMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['text/plain', 'text/csv', 'application/csv'], true);
    }

    private function remoteMimeMatches(SharePointAttachment $attachment, ?string $remoteMimeType): bool
    {
        return $remoteMimeType === null
            || strtolower($remoteMimeType) === strtolower($attachment->mime_type);
    }

    private function isSafeText(string $contents): bool
    {
        return ! str_contains($contents, "\0") && mb_check_encoding($contents, 'UTF-8');
    }

    private function renderRasterImage(string $contents, string $expectedMimeType, int $maxWidth, int $maxHeight): ?string
    {
        if (! $this->canRenderRasterMimeType($expectedMimeType)) {
            return null;
        }

        $info = @getimagesizefromstring($contents);

        if (
            ! is_array($info) ||
            ! isset($info[0], $info[1], $info['mime']) ||
            $info['mime'] !== $expectedMimeType ||
            $info[0] < 1 ||
            $info[1] < 1 ||
            $info[0] > 12000 ||
            $info[1] > 12000 ||
            $info[0] > intdiv(16_000_000, $info[1])
        ) {
            return null;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return null;
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = min(1, $maxWidth / $sourceWidth, $maxHeight / $sourceHeight);
        $targetWidth = max(1, (int) floor($sourceWidth * $scale));
        $targetHeight = max(1, (int) floor($sourceHeight * $scale));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($target === false) {
            imagedestroy($source);

            return null;
        }

        $background = imagecolorallocate($target, 255, 255, 255);
        imagefill($target, 0, 0, $background);
        $resampled = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        $encoded = $resampled && imagejpeg($target, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($source);
        imagedestroy($target);

        return $encoded && is_string($jpeg) ? $jpeg : null;
    }

    private function canRenderRasterMimeType(string $mimeType): bool
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg') || ! function_exists('gd_info')) {
            return false;
        }

        $supportKey = match ($mimeType) {
            'image/jpeg' => 'JPEG Support',
            'image/png' => 'PNG Support',
            'image/gif' => 'GIF Read Support',
            'image/webp' => 'WebP Support',
            default => null,
        };

        return $supportKey !== null && (gd_info()[$supportKey] ?? false) === true;
    }

    private function inlineResponse(string $contents, string $mimeType, string $filename): Response
    {
        return response($contents, 200, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', basename($filename)),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function unavailableResponse(int $status): Response
    {
        return response('Attachment preview is unavailable.', $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function safeFailureStatus(MicrosoftGraphApiException $exception): int
    {
        return in_array($exception->httpStatus, [404, 413, 429, 503], true)
            ? $exception->httpStatus
            : 502;
    }

    private function assignedUserCanOpen(?User $user, WorkOrder $workOrder): bool
    {
        if ($user === null || ! $user->can('work_orders.view')) {
            return false;
        }

        return $workOrder->assignedUsers()->whereKey($user->getKey())->exists()
            || $workOrder->visits()
                ->whereHas('assignedUsers', fn ($query) => $query->whereKey($user->getKey()))
                ->exists();
    }

    private function validatedSharePointUrl(?string $url): ?string
    {
        if (
            ! is_string($url) ||
            $url === '' ||
            strlen($url) > 4096 ||
            preg_match('/[\r\n]/', $url) === 1
        ) {
            return null;
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $sharePointHost = str_ends_with($host, '.sharepoint.com')
            || str_ends_with($host, '.sharepointonline.com');

        if (
            ! is_array($parts) ||
            ($parts['scheme'] ?? null) !== 'https' ||
            filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false ||
            ! $sharePointHost ||
            (isset($parts['port']) && $parts['port'] !== 443) ||
            isset($parts['user']) ||
            isset($parts['pass']) ||
            isset($parts['fragment'])
        ) {
            return null;
        }

        return $url;
    }
}
