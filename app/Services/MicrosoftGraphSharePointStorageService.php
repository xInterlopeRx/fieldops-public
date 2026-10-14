<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphSharePointStorageService
{
    private const MAX_FILE_BYTES = MicrosoftGraphApiClient::MAX_BINARY_CONTENT_BYTES;

    private const MAX_THUMBNAIL_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private readonly MicrosoftGraphApiClient $graph,
        private readonly MicrosoftGraphSharePointService $sharepoint,
    ) {}

    /**
     * Store or replace a file at a relative path in the configured SharePoint drive.
     * Missing parent folders are created. The returned metadata never contains a download URL.
     *
     * @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}
     */
    public function upload(string $path, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $segments = $this->pathSegments($path);
        $this->validateContentType($contentType);
        $size = strlen($contents);

        if ($size > self::MAX_FILE_BYTES) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_file_too_large', 422);
        }

        ['site_id' => $siteId, 'drive_id' => $driveId] = $this->configuredTarget();
        $this->ensureParentFolders($driveId, $segments);

        $destination = implode('/', $segments);
        $existingItem = $this->driveItemByPath($driveId, $destination);

        if ($existingItem !== null && ! is_array($existingItem['file'] ?? null)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_destination_is_not_file', 409);
        }

        $item = $this->graph->putContent(
            'drives/'.rawurlencode($driveId).'/root:/'.$this->encodeDrivePath($destination).':/content',
            $contents,
            contentType: $contentType,
        );
        $metadata = $this->normalizeFileMetadata($item, basename($destination), $size, $contentType);

        Log::info('SharePoint storage file uploaded', [
            'site_id' => $siteId,
            'drive_id' => $driveId,
            'item_id' => $metadata['id'],
            'size' => $metadata['size'],
        ]);

        return $metadata;
    }

    /**
     * @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}
     */
    public function metadata(string $itemId): array
    {
        $itemId = $this->validateItemId($itemId);
        ['drive_id' => $driveId] = $this->configuredTarget();

        return $this->fileMetadataById($driveId, $itemId);
    }

    public function download(string $itemId): string
    {
        return $this->downloadWithMetadata($itemId)['contents'];
    }

    /**
     * Download a bounded file and return the metadata verified immediately before the read.
     *
     * @return array{
     *     metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null},
     *     contents: string
     * }
     */
    public function downloadWithMetadata(string $itemId): array
    {
        $itemId = $this->validateItemId($itemId);
        ['drive_id' => $driveId] = $this->configuredTarget();
        $metadata = $this->fileMetadataById($driveId, $itemId);

        if ($metadata['size'] > self::MAX_FILE_BYTES) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_file_too_large', 413);
        }

        $contents = $this->graph->getContent(
            'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId).'/content',
            $metadata['size'],
        );

        if (strlen($contents) !== $metadata['size']) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_size_mismatch', 502);
        }

        return [
            'metadata' => $metadata,
            'contents' => $contents,
        ];
    }

    /**
     * Fetch a Graph-generated medium thumbnail without downloading the original file.
     * The short-lived thumbnail URL is consumed server-side and is never returned or logged.
     *
     * @return array{contents: string, width: int, height: int}
     */
    public function thumbnail(string $itemId): array
    {
        $itemId = $this->validateItemId($itemId);
        ['drive_id' => $driveId] = $this->configuredTarget();
        $metadata = $this->fileMetadataById($driveId, $itemId);

        if (! in_array($metadata['mime_type'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_thumbnail_not_supported', 415);
        }

        $response = $this->graph->getJson(
            'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId).'/thumbnails',
        );
        $thumbnailSets = $response['value'] ?? null;

        if (! is_array($thumbnailSets)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_thumbnail_invalid', 502);
        }

        $thumbnail = null;

        foreach (['medium', 'small', 'large'] as $size) {
            foreach ($thumbnailSets as $thumbnailSet) {
                $candidate = is_array($thumbnailSet) ? ($thumbnailSet[$size] ?? null) : null;

                if (
                    is_array($candidate) &&
                    is_string($candidate['url'] ?? null) &&
                    is_int($candidate['width'] ?? null) &&
                    is_int($candidate['height'] ?? null) &&
                    $candidate['width'] > 0 &&
                    $candidate['height'] > 0
                ) {
                    $thumbnail = $candidate;

                    break 2;
                }
            }
        }

        if ($thumbnail === null) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_thumbnail_unavailable', 404);
        }

        $contents = $this->graph->getPreauthenticatedContent($thumbnail['url'], self::MAX_THUMBNAIL_BYTES);

        return [
            'contents' => $contents,
            'width' => $thumbnail['width'],
            'height' => $thumbnail['height'],
        ];
    }

    public function delete(string $itemId): void
    {
        $itemId = $this->validateItemId($itemId);
        ['site_id' => $siteId, 'drive_id' => $driveId] = $this->configuredTarget();

        try {
            $metadata = $this->fileMetadataById($driveId, $itemId);
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_record_not_found' && $exception->httpStatus === 404) {
                return;
            }

            throw $exception;
        }

        if (! is_string($metadata['e_tag']) || $metadata['e_tag'] === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_remote_version_unavailable', 409);
        }

        $this->graph->delete(
            'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId),
            ['If-Match' => $metadata['e_tag']],
        );

        Log::info('SharePoint storage file deleted', [
            'site_id' => $siteId,
            'drive_id' => $driveId,
            'item_id' => $itemId,
        ]);
    }

    /** @return array{site_id: string, drive_id: string} */
    private function configuredTarget(): array
    {
        if (! config('services.microsoft_graph.sharepoint_enabled')) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_disabled', 503);
        }

        $siteId = config('services.microsoft_graph.work_schedules.site_id');
        $driveId = config('services.microsoft_graph.work_schedules.drive_id');

        if (
            ! is_string($siteId) || trim($siteId) === '' ||
            ! is_string($driveId) || trim($driveId) === ''
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_configuration_incomplete', 503);
        }

        $siteId = trim($siteId);
        $driveId = trim($driveId);
        $drives = $this->sharepoint->discoverDrives($siteId);

        foreach ($drives as $drive) {
            if ($drive['id'] === $driveId) {
                return ['site_id' => $siteId, 'drive_id' => $driveId];
            }
        }

        throw new MicrosoftGraphApiException('graph_sharepoint_configured_drive_not_in_site', 422);
    }

    /** @param list<string> $segments */
    private function ensureParentFolders(string $driveId, array $segments): void
    {
        $parentSegments = array_slice($segments, 0, -1);

        if ($parentSegments === []) {
            return;
        }

        $currentPath = '';
        $parentId = 'root';

        foreach ($parentSegments as $segment) {
            $currentPath = $currentPath === '' ? $segment : $currentPath.'/'.$segment;
            $folder = $this->driveItemByPath($driveId, $currentPath);

            if ($folder === null) {
                $childrenPath = $parentId === 'root'
                    ? 'drives/'.rawurlencode($driveId).'/root/children'
                    : 'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($parentId).'/children';

                try {
                    $folder = $this->graph->postJson($childrenPath, [
                        'name' => $segment,
                        'folder' => (object) [],
                        '@microsoft.graph.conflictBehavior' => 'fail',
                    ]);
                } catch (MicrosoftGraphApiException $exception) {
                    // A concurrent upload may have created this exact folder after the GET.
                    try {
                        $folder = $this->driveItemByPath($driveId, $currentPath);
                    } catch (MicrosoftGraphApiException) {
                        throw $exception;
                    }

                    if ($folder === null) {
                        throw $exception;
                    }
                }
            }

            $folderId = $folder['id'] ?? null;

            if (
                ! is_string($folderId) || $folderId === '' ||
                ($folder['name'] ?? null) !== $segment ||
                ! is_array($folder['folder'] ?? null)
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_storage_invalid_parent_folder', 502);
            }

            $parentId = $folderId;
        }
    }

    /** @return array<string, mixed>|null */
    private function driveItemByPath(string $driveId, string $path): ?array
    {
        try {
            return $this->graph->getJson(
                'drives/'.rawurlencode($driveId).'/root:/'.$this->encodeDrivePath($path),
                ['$select' => 'id,name,file,folder'],
            );
        } catch (MicrosoftGraphApiException $exception) {
            if ($exception->reason === 'graph_record_not_found' && $exception->httpStatus === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /** @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null} */
    private function fileMetadataById(string $driveId, string $itemId): array
    {
        $item = $this->graph->getJson(
            'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId),
            ['$select' => 'id,name,size,eTag,cTag,file,folder,lastModifiedDateTime,webUrl'],
        );

        return $this->normalizeFileMetadata($item, expectedId: $itemId);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}
     */
    private function normalizeFileMetadata(
        array $item,
        ?string $expectedName = null,
        ?int $expectedSize = null,
        ?string $fallbackMimeType = null,
        ?string $expectedId = null,
    ): array {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? null;
        $size = $item['size'] ?? null;
        $file = $item['file'] ?? null;

        if (
            ! is_string($id) || $id === '' ||
            ($expectedId !== null && $id !== $expectedId) ||
            ! is_string($name) || $name === '' ||
            ($expectedName !== null && $name !== $expectedName) ||
            ! is_int($size) || $size < 0 ||
            ($expectedSize !== null && $size !== $expectedSize) ||
            ! is_array($file) ||
            is_array($item['folder'] ?? null)
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_invalid_file_metadata', 502);
        }

        $mimeType = data_get($file, 'mimeType');

        return [
            'id' => $id,
            'name' => $name,
            'size' => $size,
            'mime_type' => is_string($mimeType) ? $mimeType : $fallbackMimeType,
            'e_tag' => is_string($item['eTag'] ?? null) ? $item['eTag'] : null,
            'c_tag' => is_string($item['cTag'] ?? null) ? $item['cTag'] : null,
            'last_modified_at' => is_string($item['lastModifiedDateTime'] ?? null)
                ? $item['lastModifiedDateTime']
                : null,
            'sharepoint_url' => is_string($item['webUrl'] ?? null) ? $item['webUrl'] : null,
        ];
    }

    /** @return list<string> */
    private function pathSegments(string $path): array
    {
        if (
            $path === '' ||
            strlen($path) > 1024 ||
            str_starts_with($path, '/') ||
            str_ends_with($path, '/') ||
            str_contains($path, '\\') ||
            preg_match('/[\x00-\x1F\x7F]/', $path) === 1
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_path_invalid', 422);
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if (
                $segment === '' ||
                $segment === '.' ||
                $segment === '..' ||
                strlen($segment) > 255 ||
                trim($segment) !== $segment ||
                str_ends_with($segment, '.') ||
                strpbrk($segment, '*:<>?"|') !== false
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_storage_path_invalid', 422);
            }
        }

        return $segments;
    }

    private function validateContentType(string $contentType): void
    {
        if (
            strlen($contentType) > 255 ||
            preg_match('/^[a-z0-9][a-z0-9!#$&^_.+-]*\/[a-z0-9][a-z0-9!#$&^_.+-]*$/iD', $contentType) !== 1
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_content_type_invalid', 422);
        }
    }

    private function validateItemId(string $itemId): string
    {
        if (
            $itemId === '' ||
            $itemId !== trim($itemId) ||
            strlen($itemId) > 512 ||
            preg_match('/[\x00-\x1F\x7F]/', $itemId) === 1
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_item_id_invalid', 422);
        }

        return $itemId;
    }

    private function encodeDrivePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
