<?php

namespace App\Services;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\AuditEvent;
use App\Models\MicrosoftGraphOnboardingTarget;
use App\Models\SharePointAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Ramsey\Uuid\Uuid;
use Throwable;

class SharePointAttachmentMigrationService
{
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;

    private const BATCH_SIZE = 25;

    public function __construct(
        private readonly SharePointAttachmentTargetResolver $targets,
        private readonly MicrosoftGraphSharePointStorageService $storage,
    ) {}

    /** @return array{source_site_id: string, source_drive_id: string, target_site_id: string, target_drive_id: string, total: int, pending: int, already_migrated: int, unexpected: int, pending_bytes: int, oversized: int} */
    public function inventory(): array
    {
        $target = $this->targets->businessAttachmentsTargetForMigration();
        $source = $this->sourceTarget();

        if ($source['site_id'] === $target->business_attachments_site_id
            || $source['drive_id'] === $target->business_attachments_drive_id) {
            throw new MicrosoftGraphApiException('graph_sharepoint_business_target_matches_legacy_target', 409);
        }

        $pending = $this->pendingQuery($source);
        $migrated = $this->migratedQuery($target);
        $total = SharePointAttachment::query()->count();
        $pendingCount = (clone $pending)->count();
        $migratedCount = (clone $migrated)->count();

        return [
            'source_site_id' => $source['site_id'],
            'source_drive_id' => $source['drive_id'],
            'target_site_id' => $target->business_attachments_site_id,
            'target_drive_id' => $target->business_attachments_drive_id,
            'total' => $total,
            'pending' => $pendingCount,
            'already_migrated' => $migratedCount,
            'unexpected' => max(0, $total - $pendingCount - $migratedCount),
            'pending_bytes' => (int) (clone $pending)->sum('file_size'),
            'oversized' => (clone $pending)->where('file_size', '>', self::MAX_FILE_BYTES)->count(),
        ];
    }

    /** @return array{attachment_id: int, previous_size: int, reconciled_size: int, source_sha256: string} */
    public function reconcileSourceSize(User $actor, int $attachmentId): array
    {
        $this->authorizeActor($actor);

        $source = $this->sourceTarget();
        $attachment = SharePointAttachment::query()->findOrFail($attachmentId);

        if (
            $attachment->sharepoint_target !== SharePointAttachment::TARGET_WORK_SCHEDULES ||
            $attachment->sharepoint_drive_id !== $source['drive_id'] ||
            ($attachment->sharepoint_site_id !== null && $attachment->sharepoint_site_id !== $source['site_id'])
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_target_mismatch', 409);
        }

        $previousSize = (int) $attachment->file_size;
        $verifiedSource = $this->storage->verifiedSourceForSizeReconciliation(
            $attachment->sharepoint_item_id,
            $source['drive_id'],
            $attachment->mime_type,
        );

        if ($verifiedSource['size'] === $previousSize) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_size_reconciliation_not_needed', 409);
        }

        return DB::transaction(function () use ($actor, $attachment, $source, $previousSize, $verifiedSource): array {
            $current = SharePointAttachment::query()->lockForUpdate()->findOrFail($attachment->getKey());

            if (
                $current->sharepoint_target !== SharePointAttachment::TARGET_WORK_SCHEDULES ||
                $current->sharepoint_drive_id !== $source['drive_id'] ||
                ($current->sharepoint_site_id !== null && $current->sharepoint_site_id !== $source['site_id']) ||
                (int) $current->file_size !== $previousSize
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_changed_during_size_reconciliation', 409);
            }

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $current->getMorphClass(),
                'subject_id' => $current->getKey(),
                'event' => 'sharepoint_attachment_source_size_reconciled',
                'summary' => $actor->name.' reconciled a SharePoint attachment source-size record.',
                'metadata' => [
                    'environment' => app()->environment(),
                    'attachment_id' => $current->getKey(),
                    'owner_context' => [
                        'company_id' => $current->company_id,
                        'site_id' => $current->site_id,
                        'project_id' => $current->project_id,
                        'job_id' => $current->job_id,
                        'uploaded_by' => $current->uploaded_by,
                    ],
                    'source_pointer' => [
                        'stored_site_id' => $current->sharepoint_site_id,
                        'resolved_site_id' => $source['site_id'],
                        'target' => $current->sharepoint_target,
                        'drive_id' => $current->sharepoint_drive_id,
                        'item_id' => $current->sharepoint_item_id,
                        'url' => $current->sharepoint_url,
                        'mime_type' => $current->mime_type,
                        'recorded_size' => $previousSize,
                    ],
                    'verified_source' => [
                        'size' => $verifiedSource['size'],
                        'content_size' => $verifiedSource['content_size'],
                        'mime_type' => $verifiedSource['mime_type'],
                        'sha256' => $verifiedSource['sha256'],
                    ],
                    'correction' => [
                        'field' => 'file_size',
                        'previous_value' => $previousSize,
                        'new_value' => $verifiedSource['size'],
                    ],
                ],
                'created_at' => now(),
            ]);

            $current->forceFill(['file_size' => $verifiedSource['size']])->save();

            return [
                'attachment_id' => (int) $current->getKey(),
                'previous_size' => $previousSize,
                'reconciled_size' => $verifiedSource['size'],
                'source_sha256' => $verifiedSource['sha256'],
            ];
        });
    }

    /** @return array{attachment_id: int, source_size: int, destination_size: int, source_sha256: string, destination_sha256: string} */
    public function adoptOperatorApprovedNonidenticalDestination(User $actor, int $attachmentId): array
    {
        $this->authorizeActor($actor);

        $source = $this->sourceTarget();
        $targetModel = $this->targets->businessAttachmentsTargetForMigration();
        $target = [
            'site_id' => $targetModel->business_attachments_site_id,
            'drive_id' => $targetModel->business_attachments_drive_id,
        ];
        $attachment = SharePointAttachment::query()->findOrFail($attachmentId);

        if (
            $attachment->sharepoint_target !== SharePointAttachment::TARGET_WORK_SCHEDULES ||
            $attachment->sharepoint_drive_id !== $source['drive_id'] ||
            ($attachment->sharepoint_site_id !== null && $attachment->sharepoint_site_id !== $source['site_id'])
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_target_mismatch', 409);
        }

        $this->storage->verifyTarget($source['site_id'], $source['drive_id']);
        $this->storage->verifyTarget($target['site_id'], $target['drive_id']);

        $sourceFile = $this->storage->downloadWithMetadata(
            $attachment->sharepoint_item_id,
            $source['drive_id'],
        );
        $sourceMetadata = $sourceFile['metadata'];

        if (
            strlen($sourceFile['contents']) !== $sourceMetadata['size'] ||
            $sourceMetadata['size'] !== (int) $attachment->file_size ||
            ($sourceMetadata['mime_type'] !== null && strtolower($sourceMetadata['mime_type']) !== strtolower($attachment->mime_type))
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_verification_failed', 409);
        }

        $destinationMetadata = $this->storage->metadataByPath(
            $target['drive_id'],
            $this->deterministicDestinationPath($attachment),
        );

        if ($destinationMetadata === null) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_approved_destination_not_found', 404);
        }

        $destinationFile = $this->storage->downloadWithMetadata(
            $destinationMetadata['id'],
            $target['drive_id'],
        );
        $destinationMeta = $destinationFile['metadata'];

        if (
            $destinationMeta['id'] !== $destinationMetadata['id'] ||
            strlen($destinationFile['contents']) !== $destinationMeta['size'] ||
            $destinationMeta['size'] !== $sourceMetadata['size'] ||
            ($destinationMeta['mime_type'] !== null && strtolower($destinationMeta['mime_type']) !== strtolower($attachment->mime_type)) ||
            ! $this->isSharePointUrl($destinationMeta['sharepoint_url'])
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_approved_destination_verification_failed', 409);
        }

        $sourceHash = hash('sha256', $sourceFile['contents']);
        $destinationHash = hash('sha256', $destinationFile['contents']);

        if (hash_equals($sourceHash, $destinationHash)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_is_byte_identical', 409);
        }

        $copy = [
            'metadata' => $destinationMeta,
            'sha256' => $sourceHash,
            'destination_sha256' => $destinationHash,
            'source_size' => $sourceMetadata['size'],
            'source_mime_type' => $sourceMetadata['mime_type'],
            'destination_mime_type' => $destinationMeta['mime_type'],
            'transformed' => false,
            'reused' => true,
            'operator_accepted_byte_mismatch' => true,
        ];

        if (! $this->updateAttachmentPointer($attachment, $source, $target, $copy, $actor, operatorApprovedByteMismatch: true)) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_pointer_not_updated', 409);
        }

        return [
            'attachment_id' => (int) $attachment->getKey(),
            'source_size' => $sourceMetadata['size'],
            'destination_size' => $destinationMeta['size'],
            'source_sha256' => $sourceHash,
            'destination_sha256' => $destinationHash,
        ];
    }

    /**
     * @param  null|callable(array{attachment_id: int, status: string, failure_code: string|null, exception_type: string|null, exception_location: string|null}): void  $onProgress
     * @return array{attempted: int, migrated: int, reused: int, office_transformed: int, failed: int, failed_attachments: list<array{attachment_id: int, failure_code: string}>, last_attempted_id: int|null, remaining: int, unexpected: int, writes_activated: bool}
     */
    public function migrate(
        User $actor,
        ?int $limit = null,
        ?callable $onProgress = null,
        ?int $afterId = null,
    ): array {
        $this->authorizeActor($actor);

        if ($afterId !== null && $afterId < 0) {
            throw new MicrosoftGraphApiException('graph_sharepoint_migration_after_id_invalid', 422);
        }

        $targetModel = $this->targets->businessAttachmentsTargetForMigration();
        $source = $this->sourceTarget();
        $target = [
            'site_id' => $targetModel->business_attachments_site_id,
            'drive_id' => $targetModel->business_attachments_drive_id,
        ];
        $inventory = $this->inventory();

        if ($inventory['unexpected'] > 0 || $inventory['oversized'] > 0) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_migration_preflight_failed', 409);
        }

        $this->storage->verifyTarget($source['site_id'], $source['drive_id']);
        $this->storage->verifyTarget($target['site_id'], $target['drive_id']);

        $query = $this->pendingQuery($source);

        if ($afterId !== null) {
            $query->where('id', '>', $afterId);
        }

        $query->orderBy('id');

        $attempted = 0;
        $migrated = 0;
        $reused = 0;
        $officeTransformed = 0;
        $officeTransformations = [];
        $failed = 0;
        $failedAttachments = [];
        $lastAttemptedId = null;

        $query->chunkById(self::BATCH_SIZE, function ($attachments) use (
            $actor,
            $source,
            $target,
            $onProgress,
            &$attempted,
            &$migrated,
            &$reused,
            &$officeTransformed,
            &$officeTransformations,
            &$failed,
            &$failedAttachments,
            &$lastAttemptedId,
            $limit,
        ): bool {
            foreach ($attachments as $attachment) {
                if ($limit !== null && $attempted >= max(1, $limit)) {
                    return false;
                }

                $attempted++;
                $lastAttemptedId = (int) $attachment->getKey();

                try {
                    $copy = $this->copyAttachmentWithRetries($attachment, $source, $target);
                    if (! $this->isSharePointUrl($copy['metadata']['sharepoint_url'])) {
                        throw new MicrosoftGraphApiException('graph_sharepoint_attachment_destination_url_invalid', 502);
                    }

                    $updated = $this->updateAttachmentPointer($attachment, $source, $target, $copy, $actor);

                    if ($updated) {
                        $migrated++;
                    }

                    if ($copy['reused']) {
                        $reused++;
                    }

                    if ($copy['transformed']) {
                        $officeTransformed++;
                        $officeTransformations[] = [
                            'attachment_id' => (int) $attachment->getKey(),
                            'source_size' => $copy['source_size'],
                            'destination_size' => $copy['metadata']['size'],
                            'source_sha256' => $copy['sha256'],
                            'destination_sha256' => $copy['destination_sha256'],
                        ];
                    }

                    if ($onProgress !== null) {
                        $onProgress([
                            'attachment_id' => (int) $attachment->getKey(),
                            'status' => $copy['transformed']
                                ? ($updated ? 'migrated_office_transformed' : 'already_migrated_office_transformed')
                                : ($updated ? 'migrated' : 'already_migrated'),
                            'failure_code' => null,
                            'exception_type' => null,
                            'exception_location' => null,
                        ]);
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    $failureCode = $exception instanceof MicrosoftGraphApiException
                        ? $exception->reason
                        : 'sharepoint_attachment_migration_failed';
                    $failedAttachments[] = [
                        'attachment_id' => (int) $attachment->getKey(),
                        'failure_code' => $failureCode,
                    ];

                    Log::warning('SharePoint attachment target migration failed', [
                        'attachment_id' => $attachment->getKey(),
                        'failure_code' => $failureCode,
                        'exception_type' => get_class($exception),
                        'exception_location' => basename($exception->getFile()).':'.$exception->getLine(),
                    ]);

                    if ($onProgress !== null) {
                        $onProgress([
                            'attachment_id' => (int) $attachment->getKey(),
                            'status' => 'failed',
                            'failure_code' => $failureCode,
                            'exception_type' => get_class($exception),
                            'exception_location' => basename($exception->getFile()).':'.$exception->getLine(),
                        ]);
                    }
                }

                if ($attempted % self::BATCH_SIZE === 0) {
                    Sleep::for(2)->seconds();
                }
            }

            return true;
        });

        $finalInventory = $this->inventory();
        $remaining = $finalInventory['pending'];
        $unexpected = $finalInventory['unexpected'];
        $writesActivated = false;

        if ($remaining === 0 && $unexpected === 0 && $failed === 0) {
            $writesActivated = $this->targets->activateBusinessAttachmentWritesIfReady($actor, $targetModel);
        }

        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => $targetModel->getMorphClass(),
            'subject_id' => $targetModel->getKey(),
            'event' => 'sharepoint_attachments_migrated_to_business_target',
            'summary' => $actor->name.' reconciled SharePoint attachment storage targets.',
            'metadata' => [
                'source_site_id' => $source['site_id'],
                'source_drive_id' => $source['drive_id'],
                'target_site_id' => $target['site_id'],
                'target_drive_id' => $target['drive_id'],
                'after_id' => $afterId,
                'attempted' => $attempted,
                'last_attempted_id' => $lastAttemptedId,
                'migrated' => $migrated,
                'reused' => $reused,
                'office_transformed' => $officeTransformed,
                'office_transformations' => $officeTransformations,
                'failed' => $failed,
                'failed_attachments' => $failedAttachments,
                'remaining' => $remaining,
                'unexpected' => $unexpected,
                'business_attachments_writes_activated' => $writesActivated,
            ],
            'created_at' => now(),
        ]);

        return [
            'attempted' => $attempted,
            'migrated' => $migrated,
            'reused' => $reused,
            'office_transformed' => $officeTransformed,
            'failed' => $failed,
            'failed_attachments' => $failedAttachments,
            'last_attempted_id' => $lastAttemptedId,
            'remaining' => $remaining,
            'unexpected' => $unexpected,
            'writes_activated' => $writesActivated,
        ];
    }

    /** @return array{site_id: string, drive_id: string} */
    private function sourceTarget(): array
    {
        $siteId = config('services.microsoft_graph.work_schedules.site_id');
        $driveId = config('services.microsoft_graph.work_schedules.drive_id');

        if (! is_string($siteId) || trim($siteId) === '' || ! is_string($driveId) || trim($driveId) === '') {
            throw new MicrosoftGraphApiException('graph_sharepoint_legacy_target_incomplete', 503);
        }

        return ['site_id' => trim($siteId), 'drive_id' => trim($driveId)];
    }

    /** @param array{site_id: string, drive_id: string} $source */
    private function pendingQuery(array $source): Builder
    {
        return SharePointAttachment::query()
            ->where('sharepoint_target', SharePointAttachment::TARGET_WORK_SCHEDULES)
            ->where('sharepoint_drive_id', $source['drive_id'])
            ->where(function ($query) use ($source): void {
                $query->whereNull('sharepoint_site_id')
                    ->orWhere('sharepoint_site_id', $source['site_id']);
            });
    }

    private function migratedQuery(MicrosoftGraphOnboardingTarget $target): Builder
    {
        return SharePointAttachment::query()
            ->where('sharepoint_target', SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS)
            ->where('sharepoint_site_id', $target->business_attachments_site_id)
            ->where('sharepoint_drive_id', $target->business_attachments_drive_id);
    }

    /** @param array{site_id: string, drive_id: string} $source
     * @param  array{site_id: string, drive_id: string}  $target
     * @return array{metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}, sha256: string, destination_sha256: string, source_size: int, source_mime_type: string|null, destination_mime_type: string|null, transformed: bool, reused: bool}
     */
    private function copyAttachment(SharePointAttachment $attachment, array $source, array $target): array
    {
        if (
            $attachment->sharepoint_target !== SharePointAttachment::TARGET_WORK_SCHEDULES ||
            $attachment->sharepoint_drive_id !== $source['drive_id'] ||
            ($attachment->sharepoint_site_id !== null && $attachment->sharepoint_site_id !== $source['site_id'])
        ) {
            throw new MicrosoftGraphApiException('graph_sharepoint_attachment_source_target_mismatch', 409);
        }

        return $this->storage->copyFileToTarget(
            $attachment->sharepoint_item_id,
            $source['drive_id'],
            $target['site_id'],
            $target['drive_id'],
            $this->deterministicDestinationPath($attachment),
            (int) $attachment->file_size,
            $attachment->mime_type,
            $this->allowsSharePointOfficeSizeVariance($attachment->mime_type),
        );
    }

    /** @param array{site_id: string, drive_id: string} $source
     * @param  array{site_id: string, drive_id: string}  $target
     * @return array{metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}, sha256: string, destination_sha256: string, source_size: int, source_mime_type: string|null, destination_mime_type: string|null, transformed: bool, reused: bool}
     */
    private function copyAttachmentWithRetries(SharePointAttachment $attachment, array $source, array $target): array
    {
        $attempt = 0;

        do {
            $attempt++;

            try {
                return $this->copyAttachment($attachment, $source, $target);
            } catch (Throwable $exception) {
                $retryable = $exception instanceof MicrosoftGraphApiException
                    && (
                        in_array($exception->httpStatus, [429, 503], true) ||
                        ($exception->reason === 'graph_sharepoint_storage_size_mismatch'
                            && $this->allowsSharePointOfficeSizeVariance($attachment->mime_type))
                    );

                if (! $retryable || $attempt >= 3) {
                    throw $exception;
                }

                Sleep::for(5 * $attempt)->seconds();
            }
        } while (true);
    }

    /** @param array{site_id: string, drive_id: string} $source
     * @param  array{site_id: string, drive_id: string}  $target
     * @param  array{metadata: array{id: string, name: string, size: int, mime_type: string|null, e_tag: string|null, c_tag: string|null, last_modified_at: string|null, sharepoint_url: string|null}, sha256: string, destination_sha256: string, source_size: int, source_mime_type?: string|null, destination_mime_type?: string|null, transformed: bool, reused: bool, operator_accepted_byte_mismatch?: bool}  $copy
     */
    private function updateAttachmentPointer(
        SharePointAttachment $attachment,
        array $source,
        array $target,
        array $copy,
        User $actor,
        bool $operatorApprovedByteMismatch = false,
    ): bool {
        return DB::transaction(function () use ($attachment, $source, $target, $copy, $actor, $operatorApprovedByteMismatch): bool {
            $metadata = $copy['metadata'];
            $current = SharePointAttachment::query()->lockForUpdate()->findOrFail($attachment->getKey());

            if (
                $current->sharepoint_target === SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS &&
                $current->sharepoint_site_id === $target['site_id'] &&
                $current->sharepoint_drive_id === $target['drive_id']
            ) {
                if (
                    $current->sharepoint_item_id !== $metadata['id'] ||
                    $current->sharepoint_url !== $metadata['sharepoint_url']
                ) {
                    throw new MicrosoftGraphApiException('graph_sharepoint_attachment_pointer_conflict', 409);
                }

                if ($current->file_size !== $metadata['size']) {
                    $current->forceFill(['file_size' => $metadata['size']])->save();
                }

                return false;
            }

            if (
                $current->sharepoint_target !== SharePointAttachment::TARGET_WORK_SCHEDULES ||
                $current->sharepoint_drive_id !== $source['drive_id'] ||
                ($current->sharepoint_site_id !== null && $current->sharepoint_site_id !== $source['site_id']) ||
                $current->sharepoint_item_id !== $attachment->sharepoint_item_id ||
                (int) $current->file_size !== (int) $attachment->file_size ||
                $current->mime_type !== $attachment->mime_type
            ) {
                throw new MicrosoftGraphApiException('graph_sharepoint_attachment_pointer_changed', 409);
            }

            if ($operatorApprovedByteMismatch) {
                AuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'subject_type' => $current->getMorphClass(),
                    'subject_id' => $current->getKey(),
                    'event' => 'sharepoint_attachment_nonidentical_destination_accepted',
                    'summary' => $actor->name.' approved an existing non-identical Business Attachments copy.',
                    'metadata' => [
                        'environment' => app()->environment(),
                        'attachment_id' => $current->getKey(),
                        'acceptance_basis' => 'explicit_operator_approval',
                        'owner_context' => [
                            'company_id' => $current->company_id,
                            'site_id' => $current->site_id,
                            'project_id' => $current->project_id,
                            'job_id' => $current->job_id,
                            'uploaded_by' => $current->uploaded_by,
                        ],
                        'source_pointer' => [
                            'stored_site_id' => $current->sharepoint_site_id,
                            'resolved_site_id' => $source['site_id'],
                            'target' => $current->sharepoint_target,
                            'drive_id' => $current->sharepoint_drive_id,
                            'item_id' => $current->sharepoint_item_id,
                            'url' => $current->sharepoint_url,
                            'size' => $copy['source_size'],
                            'mime_type' => $copy['source_mime_type'],
                        ],
                        'destination_pointer' => [
                            'site_id' => $target['site_id'],
                            'target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
                            'drive_id' => $target['drive_id'],
                            'item_id' => $copy['metadata']['id'],
                            'url' => $copy['metadata']['sharepoint_url'],
                            'size' => $copy['metadata']['size'],
                            'mime_type' => $copy['destination_mime_type'],
                        ],
                        'source_sha256' => $copy['sha256'],
                        'destination_sha256' => $copy['destination_sha256'],
                        'source_destination_byte_identical' => false,
                        'source_retained' => true,
                    ],
                    'created_at' => now(),
                ]);
            }

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $current->getMorphClass(),
                'subject_id' => $current->getKey(),
                'event' => 'sharepoint_attachment_target_migrated',
                'summary' => $actor->name.' moved an attachment pointer to Business Attachments; the source was retained.',
                'metadata' => [
                    'environment' => app()->environment(),
                    'attachment_id' => $current->getKey(),
                    'owner_context' => [
                        'company_id' => $current->company_id,
                        'site_id' => $current->site_id,
                        'project_id' => $current->project_id,
                        'job_id' => $current->job_id,
                        'uploaded_by' => $current->uploaded_by,
                    ],
                    'source_pointer' => [
                        'stored_site_id' => $current->sharepoint_site_id,
                        'resolved_site_id' => $source['site_id'],
                        'target' => $current->sharepoint_target,
                        'drive_id' => $current->sharepoint_drive_id,
                        'item_id' => $current->sharepoint_item_id,
                        'url' => $current->sharepoint_url,
                        'recorded_size' => $current->file_size,
                    ],
                    'destination_pointer' => [
                        'site_id' => $target['site_id'],
                        'target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
                        'drive_id' => $target['drive_id'],
                        'item_id' => $metadata['id'],
                        'url' => $metadata['sharepoint_url'],
                        'size' => $metadata['size'],
                    ],
                    'source_sha256' => $copy['sha256'],
                    'destination_sha256' => $copy['destination_sha256'],
                    'source_mime_type' => $copy['source_mime_type'] ?? null,
                    'destination_mime_type' => $copy['destination_mime_type'] ?? $metadata['mime_type'],
                    'office_transformed_by_sharepoint' => $copy['transformed'],
                    'operator_accepted_nonidentical_destination' => $operatorApprovedByteMismatch,
                ],
                'created_at' => now(),
            ]);

            $current->forceFill([
                'file_size' => $metadata['size'],
                'sharepoint_site_id' => $target['site_id'],
                'sharepoint_target' => SharePointAttachment::TARGET_BUSINESS_ATTACHMENTS,
                'sharepoint_drive_id' => $target['drive_id'],
                'sharepoint_item_id' => $metadata['id'],
                'sharepoint_url' => $metadata['sharepoint_url'],
            ])->save();

            return true;
        });
    }

    private function allowsSharePointOfficeSizeVariance(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ], true);
    }

    private function deterministicDestinationPath(SharePointAttachment $attachment): string
    {
        $extension = strtolower(pathinfo($attachment->filename, PATHINFO_EXTENSION));

        if (preg_match('/^[a-z0-9]{1,12}$/iD', $extension) !== 1) {
            $extension = 'bin';
        }

        $attachmentUuid = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/sharepoint-migration/'.$attachment->getKey(),
        );

        return 'FieldOps/customer-'.$attachment->company_id.'/'.$attachmentUuid.'.'.$extension;
    }

    private function authorizeActor(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->can('integrations.manage')) {
            throw new MicrosoftGraphApiException('graph_sharepoint_migration_actor_forbidden', 403);
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
}
