<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use Illuminate\Support\Facades\Log;
use Throwable;

class MicrosoftGraphSharePointUploadService
{
    private const README_NAME = 'README.md';

    private const ALLOWED_FILES = ['README.md', 'MEMORY.md', 'TASKLOG.md'];

    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly MicrosoftGraphSharePointService $sharepoint,
    ) {}

    /** @return array{id: string, name: string, size: int, webUrl: string|null} */
    public function uploadReadme(): array
    {
        return $this->uploadRepositoryFile(self::README_NAME);
    }

    /** @return array{id: string, name: string, size: int, webUrl: string|null} */
    public function uploadRepositoryFile(string $fileName, ?string $content = null): array
    {
        $siteId = config('services.microsoft_graph.work_schedules.site_id');
        $driveId = config('services.microsoft_graph.work_schedules.drive_id');
        $safeFileName = in_array($fileName, self::ALLOWED_FILES, true) ? $fileName : 'not-allowed';

        try {
            if (! in_array($fileName, self::ALLOWED_FILES, true)) {
                throw new MicrosoftGraphApiException('graph_sharepoint_upload_file_not_allowed', 422);
            }

            if (! config('services.microsoft_graph.sharepoint_enabled')) {
                throw new MicrosoftGraphApiException('graph_sharepoint_upload_disabled', 503);
            }

            if (! is_string($siteId) || trim($siteId) === '' || ! is_string($driveId) || trim($driveId) === '') {
                throw new MicrosoftGraphApiException('graph_sharepoint_upload_configuration_incomplete', 503);
            }

            $siteId = trim($siteId);
            $driveId = trim($driveId);

            if ($content === null) {
                $filePath = base_path($fileName);

                if (! is_file($filePath) || ! is_readable($filePath)) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_repository_file_unavailable', 503);
                }

                $content = file_get_contents($filePath);

                if (! is_string($content)) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_repository_file_unavailable', 503);
                }
            }

            $fileSize = strlen($content);

            if ($fileSize > self::MAX_FILE_BYTES) {
                throw new MicrosoftGraphApiException('graph_sharepoint_repository_file_too_large', 422);
            }

            $drives = $this->sharepoint->discoverDrives($siteId);
            $driveBelongsToSite = false;

            foreach ($drives as $drive) {
                if ($drive['id'] === $driveId) {
                    $driveBelongsToSite = true;

                    break;
                }
            }

            if (! $driveBelongsToSite) {
                throw new MicrosoftGraphApiException('graph_sharepoint_configured_drive_not_in_site', 422);
            }

            $item = $this->graph->putContent(
                'drives/'.rawurlencode($driveId).'/root:/'.$fileName.':/content',
                $content,
            );
            $itemId = $item['id'] ?? null;
            $itemName = $item['name'] ?? null;

            if (! is_string($itemId) || $itemId === '' || ! is_string($itemName) || $itemName !== $fileName) {
                throw new MicrosoftGraphApiException('graph_sharepoint_invalid_upload_response', 502);
            }

            $result = [
                'id' => $itemId,
                'name' => $itemName,
                'size' => $fileSize,
                'webUrl' => is_string($item['webUrl'] ?? null) ? $item['webUrl'] : null,
            ];

            Log::info($fileName === self::README_NAME ? 'SharePoint README upload succeeded' : 'SharePoint repository file upload succeeded', [
                'site_id' => $siteId,
                'drive_id' => $driveId,
                'file_name' => $fileName,
                'item_id' => $itemId,
                'size' => $fileSize,
            ]);

            return $result;
        } catch (MicrosoftGraphApiException $exception) {
            Log::warning($safeFileName === self::README_NAME ? 'SharePoint README upload failed' : 'SharePoint repository file upload failed', [
                'site_id' => is_string($siteId) ? $siteId : null,
                'drive_id' => is_string($driveId) ? $driveId : null,
                'file_name' => $safeFileName,
                'error_code' => $exception->reason,
                'http_status' => $exception->httpStatus,
            ]);

            throw $exception;
        } catch (Throwable) {
            Log::warning($safeFileName === self::README_NAME ? 'SharePoint README upload failed' : 'SharePoint repository file upload failed', [
                'site_id' => is_string($siteId) ? $siteId : null,
                'drive_id' => is_string($driveId) ? $driveId : null,
                'file_name' => $safeFileName,
                'error_code' => 'graph_sharepoint_repository_file_upload_failed',
            ]);

            throw new MicrosoftGraphApiException('graph_sharepoint_repository_file_upload_failed', 502);
        }
    }
}
