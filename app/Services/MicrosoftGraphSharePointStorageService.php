<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use Illuminate\Support\Facades\Log;

class MicrosoftGraphSharePointStorageService
{
    private const MAX_FILE_BYTES = MicrosoftGraphApiClient::MAX_BINARY_CONTENT_BYTES;

    private const MAX_THUMBNAIL_BYTES = 2 * 1024 * 1024;

    /** @var array<string, true> */
    private array $verifiedTargets = [];

    private const MAX_OFFICE_PACKAGE_ENTRIES = 2048;

    private const MAX_OFFICE_PACKAGE_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

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
        $this->pathSegments($path);
        $this->validateContentType($contentType);

        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_file_too_large', 422);
        }

        $target = $this->configuredTarget();

        return $this->uploadToTarget(
            $target['site_id'],
            $target['drive_id'],
            $path,
            $contents,
            $contentType,
        );
    }

    /**
     * Store or replace a file in a server-selected, validated Site/Drive target.
     * The caller must never take these identifiers from a request.
     *
     * @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}
     */
    public function uploadToTarget(
        string $siteId,
        string $driveId,
        string $path,
        string $contents,
        string $contentType = 'application/octet-stream',
    ): array {
        $segments = $this->pathSegments($path);
        $this->validateContentType($contentType);
        $size = strlen($contents);

        if ($size > self::MAX_FILE_BYTES) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_file_too_large', 422);
        }

        ['site_id' => $siteId, 'drive_id' => $driveId] = $this->verifiedTarget($siteId, $driveId);

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
        $metadata = $this->normalizeFileMetadata($item, basename($destination), $size, $contentType, strictSize: false);

        Log::info('SharePoint storage file uploaded', [
            'site_id' => $siteId,
            'drive_id' => $driveId,
            'item_id' => $metadata['id'],
            'size' => $metadata['size'],
        ]);

        return $metadata;
    }

    public function verifyTarget(string $siteId, string $driveId): void
    {
        $this->verifiedTarget($siteId, $driveId);
    }

    /**
     * @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}
     */
    public function metadata(string $itemId, ?string $driveId = null): array
    {
        $itemId = $this->validateItemId($itemId);
        $driveId = $this->driveId($driveId);

        return $this->fileMetadataById($driveId, $itemId);
    }

    public function download(string $itemId, ?string $driveId = null): string
    {
        return $this->downloadWithMetadata($itemId, $driveId)['contents'];
    }

    /**
     * Download a bounded file and return the metadata verified immediately before the read.
     *
     * @return array{
     *     metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null},
     *     contents: string
     * }
     */
    public function downloadWithMetadata(string $itemId, ?string $driveId = null): array
    {
        $itemId = $this->validateItemId($itemId);
        $driveId = $this->driveId($driveId);
        $metadata = $this->fileMetadataById($driveId, $itemId);

        if ($metadata['size'] > self::MAX_FILE_BYTES) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_file_too_large', 413);
        }

        $contents = $this->graph->getContent(
            'drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId).'/content',
            self::MAX_FILE_BYTES,
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
     * Verify the bounded source bytes and MIME type before an audited recorded-size correction.
     *
     * @return array{size: int, content_size: int, mime_type: string|null, sha256: string}
     */
    public function verifiedSourceForSizeReconciliation(
        string $itemId,
        string $driveId,
        string $expectedMimeType,
    ): array {
        $this->validateContentType($expectedMimeType);
        $source = $this->downloadWithMetadata($itemId, $driveId);
        $metadata = $source['metadata'];

        if (
            $metadata['mime_type'] !== null &&
            strtolower($metadata['mime_type']) !== strtolower($expectedMimeType)
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_verification_failed', 409);
        }

        return [
            'size' => $metadata['size'],
            'content_size' => strlen($source['contents']),
            'mime_type' => $metadata['mime_type'],
            'sha256' => hash('sha256', $source['contents']),
        ];
    }

    /**
     * Fetch a Graph-generated medium thumbnail without downloading the original file.
     * The short-lived thumbnail URL is consumed server-side and is never returned or logged.
     *
     * @return array{contents: string, width: int, height: int}
     */
    public function thumbnail(string $itemId, ?string $driveId = null): array
    {
        $itemId = $this->validateItemId($itemId);
        $driveId = $this->driveId($driveId);
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

    public function delete(string $itemId, ?string $driveId = null, ?string $siteId = null): void
    {
        $itemId = $this->validateItemId($itemId);
        if ($driveId === null) {
            $target = $this->configuredTarget();
            $siteId = $target['site_id'];
            $driveId = $target['drive_id'];
        } else {
            $driveId = $this->validateDriveId($driveId);
            $siteId = is_string($siteId) && trim($siteId) !== '' ? trim($siteId) : null;
        }

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

        return $this->verifiedTarget(trim($siteId), trim($driveId));
    }

    /** @return array{site_id: string, drive_id: string} */
    private function verifiedTarget(string $siteId, string $driveId): array
    {
        if (! config('services.microsoft_graph.sharepoint_enabled')) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_disabled', 503);
        }

        if (
            $siteId === '' || strlen($siteId) > 512 || preg_match('/[\/\\?#\r\n]/', $siteId) === 1 ||
            $driveId === '' || $driveId !== trim($driveId) || strlen($driveId) > 512 || preg_match('/[\x00-\x20\x7F]/', $driveId) === 1
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_configuration_incomplete', 503);
        }

        $cacheKey = hash('sha256', $siteId."\0".$driveId);

        if (! isset($this->verifiedTargets[$cacheKey])) {
            $drives = $this->sharepoint->discoverDrives($siteId);

            if (! collect($drives)->contains(fn (array $drive): bool => $drive['id'] === $driveId)) {
                throw new MicrosoftGraphApiException('graph_sharepoint_configured_drive_not_in_site', 422);
            }

            $this->verifiedTargets[$cacheKey] = true;
        }

        return ['site_id' => $siteId, 'drive_id' => $driveId];
    }

    private function driveId(?string $driveId): string
    {
        if ($driveId === null) {
            return $this->configuredTarget()['drive_id'];
        }

        if ($driveId === '' || $driveId !== trim($driveId) || strlen($driveId) > 512 || preg_match('/[\x00-\x20\x7F]/', $driveId) === 1) {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_drive_id_invalid', 422);
        }

        return $driveId;
    }

    /** @return array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}|null */
    public function metadataByPath(string $driveId, string $path): ?array
    {
        $driveId = $this->driveId($driveId);
        $segments = $this->pathSegments($path);
        $item = $this->driveItemByPath($driveId, implode('/', $segments));

        if ($item === null) {
            return null;
        }

        $itemId = $item['id'] ?? null;

        if (! is_string($itemId) || $itemId === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_storage_invalid_file_metadata', 502);
        }

        return $this->fileMetadataById($driveId, $itemId);
    }

    /**
     * Copy one bounded file between configured SharePoint drives without deleting the source.
     * A deterministic destination path makes retries safe after an interrupted DB update.
     *
     * @return array{metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}, sha256: string, destination_sha256: string, source_size: int, source_mime_type: string|null, destination_mime_type: string|null, transformed: bool, reused: bool}
     */
    public function copyFileToTarget(
        string $sourceItemId,
        string $sourceDriveId,
        string $targetSiteId,
        string $targetDriveId,
        string $targetPath,
        int $expectedSize,
        string $expectedMimeType,
        bool $allowOfficeTransformation = false,
    ): array {
        $this->verifiedTarget($targetSiteId, $targetDriveId);
        $source = $this->downloadWithMetadata($sourceItemId, $sourceDriveId);
        $sourceBytes = $source['contents'];
        $sourceMetadata = $source['metadata'];
        $this->validateContentType($expectedMimeType);

        if (
            strlen($sourceBytes) !== $sourceMetadata['size'] ||
            (! $allowOfficeTransformation && $sourceMetadata['size'] !== $expectedSize) ||
            ($sourceMetadata['mime_type'] !== null && strtolower($sourceMetadata['mime_type']) !== strtolower($expectedMimeType))
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_verification_failed', 409);
        }

        $sourceHash = hash('sha256', $sourceBytes);
        $existing = $this->metadataByPath($targetDriveId, $targetPath);

        if ($existing !== null) {
            if (
                (! $allowOfficeTransformation && $existing['size'] !== $sourceMetadata['size']) ||
                ! $this->destinationMimeTypeMatches($existing['mime_type'], $expectedMimeType, $targetPath)
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_conflict', 409);
            }

            $existingFile = $this->downloadWithMetadata($existing['id'], $targetDriveId);
            $destinationHash = hash('sha256', $existingFile['contents']);
            $transformed = ! hash_equals($sourceHash, $destinationHash);

            if ($transformed && (
                ! $allowOfficeTransformation ||
                ! $this->officeTransformationPreservesContent($sourceBytes, $existingFile['contents'], $expectedMimeType)
            )) {
                throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_conflict', 409);
            }

            return [
                'metadata' => $existingFile['metadata'],
                'sha256' => $sourceHash,
                'destination_sha256' => $destinationHash,
                'source_size' => $sourceMetadata['size'],
                'source_mime_type' => $sourceMetadata['mime_type'],
                'destination_mime_type' => $existingFile['metadata']['mime_type'],
                'transformed' => $transformed,
                'reused' => true,
            ];
        }

        $targetMetadata = $this->uploadToTarget(
            $targetSiteId,
            $targetDriveId,
            $targetPath,
            $sourceBytes,
            $expectedMimeType,
        );

        if (! $allowOfficeTransformation && $targetMetadata['size'] !== $sourceMetadata['size']) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_size_mismatch', 502);
        }

        $verifiedTarget = $this->downloadWithMetadata($targetMetadata['id'], $targetDriveId);
        $destinationHash = hash('sha256', $verifiedTarget['contents']);
        $transformed = ! hash_equals($sourceHash, $destinationHash);

        if (
            (! $allowOfficeTransformation && $verifiedTarget['metadata']['size'] !== $sourceMetadata['size']) ||
            ! $this->destinationMimeTypeMatches($verifiedTarget['metadata']['mime_type'], $expectedMimeType, $targetPath) ||
            ($transformed && (
                ! $allowOfficeTransformation ||
                ! $this->officeTransformationPreservesContent($sourceBytes, $verifiedTarget['contents'], $expectedMimeType)
            ))
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_verification_failed', 502);
        }

        return [
            'metadata' => $verifiedTarget['metadata'],
            'sha256' => $sourceHash,
            'destination_sha256' => $destinationHash,
            'source_size' => $sourceMetadata['size'],
            'source_mime_type' => $sourceMetadata['mime_type'],
            'destination_mime_type' => $verifiedTarget['metadata']['mime_type'],
            'transformed' => $transformed,
            'reused' => false,
        ];
    }

    private function destinationMimeTypeMatches(?string $actualMimeType, string $expectedMimeType, string $targetPath): bool
    {
        if ($actualMimeType === null || strtolower($actualMimeType) === strtolower($expectedMimeType)) {
            return true;
        }

        return strtolower(pathinfo($targetPath, PATHINFO_EXTENSION)) === 'eml'
            && strtolower($expectedMimeType) === 'text/plain'
            && strtolower($actualMimeType) === 'message/rfc822';
    }

    private function officeTransformationPreservesContent(
        string $source,
        string $destination,
        string $mimeType,
    ): bool {
        $requiredPart = match (strtolower($mimeType)) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word/document.xml',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xl/workbook.xml',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt/presentation.xml',
            default => null,
        };

        if ($requiredPart === null) {
            return false;
        }

        $sourceParts = $this->officePackagePartHashes($source);
        $destinationParts = $this->officePackagePartHashes($destination);

        if (
            $sourceParts === null ||
            $destinationParts === null ||
            ! isset($sourceParts['[Content_Types].xml'], $sourceParts[$requiredPart]) ||
            array_keys($sourceParts) !== array_keys($destinationParts)
        ) {
            return false;
        }

        foreach ($sourceParts as $name => $hash) {
            if (hash_equals($hash, $destinationParts[$name])) {
                continue;
            }

            // SharePoint may normalize package metadata while preserving the
            // workbook/document/presentation parts. Those package metadata
            // changes are allowed only after the operator accepts the Office
            // transformation; all other parts must remain byte-identical.
            if (! str_starts_with($name, 'docProps/') && ! str_starts_with($name, 'customXml/')) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string>|null */
    private function officePackagePartHashes(string $contents): ?array
    {
        if (! class_exists(\ZipArchive::class)) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'fieldops-office-package-');

        if (! is_string($temporaryPath)) {
            return null;
        }

        try {
            if (file_put_contents($temporaryPath, $contents) !== strlen($contents)) {
                return null;
            }

            $archive = new \ZipArchive;
            $opened = $archive->open($temporaryPath, \ZipArchive::RDONLY);

            if ($opened !== true || $archive->numFiles > self::MAX_OFFICE_PACKAGE_ENTRIES) {
                if ($opened === true) {
                    $archive->close();
                }

                return null;
            }

            $parts = [];
            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $name = $archive->getNameIndex($index);
                $stat = $archive->statIndex($index);

                if (
                    ! is_string($name) || isset($parts[$name]) || ! is_array($stat) ||
                    ! is_int($stat['size'] ?? null) || $stat['size'] < 0
                ) {
                    $archive->close();

                    return null;
                }

                $totalUncompressedBytes += $stat['size'];

                if ($totalUncompressedBytes > self::MAX_OFFICE_PACKAGE_UNCOMPRESSED_BYTES) {
                    $archive->close();

                    return null;
                }

                if (str_ends_with($name, '/') && $stat['size'] === 0) {
                    $parts[$name] = hash('sha256', '');

                    continue;
                }

                $stream = $archive->getStream($name);

                if (! is_resource($stream)) {
                    $archive->close();

                    return null;
                }

                $hash = hash_init('sha256');
                $readBytes = 0;

                while (! feof($stream)) {
                    $chunk = fread($stream, 64 * 1024);

                    if (! is_string($chunk)) {
                        fclose($stream);
                        $archive->close();

                        return null;
                    }

                    if ($chunk === '' && ! feof($stream)) {
                        fclose($stream);
                        $archive->close();

                        return null;
                    }

                    $readBytes += strlen($chunk);

                    if ($readBytes > $stat['size']) {
                        fclose($stream);
                        $archive->close();

                        return null;
                    }

                    hash_update($hash, $chunk);
                }

                fclose($stream);

                if ($readBytes !== $stat['size']) {
                    $archive->close();

                    return null;
                }

                $parts[$name] = hash_final($hash);
            }

            $archive->close();
            ksort($parts);

            return $parts;
        } finally {
            @unlink($temporaryPath);
        }
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
        bool $strictSize = true,
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
            ($strictSize && $expectedSize !== null && $size !== $expectedSize) ||
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
