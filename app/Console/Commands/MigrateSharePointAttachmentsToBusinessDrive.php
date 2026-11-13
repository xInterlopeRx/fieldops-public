<?php

namespace App\Console\Commands;

use App\Exceptions\MicrosoftGraphApiException;
use App\Models\User;
use App\Services\SharePointAttachmentMigrationService;
use Illuminate\Console\Command;

class MigrateSharePointAttachmentsToBusinessDrive extends Command
{
    protected $signature = 'attachments:migrate-sharepoint-to-business-drive
        {--execute : Copy files and update FieldOps pointers; default is a read-only dry run}
        {--limit= : Maximum number of attachments to migrate in this run}
        {--after-id= : Continue with pending attachments whose IDs are greater than this value}
        {--reconcile-source-size-id= : Audit and correct a verified source file_size mismatch for one attachment}
        {--accept-existing-nonidentical-destination-id= : Explicitly adopt one existing Business copy despite a verified byte mismatch}
        {--actor-email= : Active Admin email recorded in the cutover audit}';

    protected $description = 'Move FieldOps attachment binaries to the approved Business Attachments Drive';

    public function handle(
        SharePointAttachmentMigrationService $migration,
    ): int {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $afterId = $this->option('after-id') !== null ? (int) $this->option('after-id') : null;
        $reconcileSourceSizeId = $this->option('reconcile-source-size-id') !== null
            ? (int) $this->option('reconcile-source-size-id')
            : null;
        $acceptNonidenticalDestinationId = $this->option('accept-existing-nonidentical-destination-id') !== null
            ? (int) $this->option('accept-existing-nonidentical-destination-id')
            : null;

        if ($limit !== null && $limit < 1) {
            $this->error('The limit must be a positive integer.');

            return self::FAILURE;
        }

        if ($afterId !== null && $afterId < 0) {
            $this->error('The after-id must be a non-negative integer.');

            return self::FAILURE;
        }

        if ($reconcileSourceSizeId !== null && $reconcileSourceSizeId < 1) {
            $this->error('The reconcile-source-size-id must be a positive integer.');

            return self::FAILURE;
        }

        if ($acceptNonidenticalDestinationId !== null && $acceptNonidenticalDestinationId < 1) {
            $this->error('The accept-existing-nonidentical-destination-id must be a positive integer.');

            return self::FAILURE;
        }

        if ($reconcileSourceSizeId !== null && (! $this->option('execute') || $limit !== null || $afterId !== null)) {
            $this->error('Source-size reconciliation requires --execute and cannot be combined with --limit or --after-id.');

            return self::FAILURE;
        }

        if ($acceptNonidenticalDestinationId !== null && (
            ! $this->option('execute') ||
            $limit !== null ||
            $afterId !== null ||
            $reconcileSourceSizeId !== null
        )) {
            $this->error('Explicit destination acceptance requires --execute and cannot be combined with --limit, --after-id, or source-size reconciliation.');

            return self::FAILURE;
        }

        try {
            $inventory = $migration->inventory();
        } catch (\Throwable $exception) {
            $this->error('Business Attachments target preflight failed: '.$this->safeErrorCode($exception));

            return self::FAILURE;
        }

        $this->table(['Inventory', 'Value'], [
            ['Production environment', app()->environment()],
            ['Pending attachments', $inventory['pending']],
            ['Already on Business Attachments', $inventory['already_migrated']],
            ['Unexpected target rows', $inventory['unexpected']],
            ['Pending bytes', $inventory['pending_bytes']],
            ['Files over 20 MiB', $inventory['oversized']],
        ]);

        if (! $this->option('execute')) {
            $this->info('Read-only dry run. Pass --execute to copy files and update attachment pointers.');

            return self::SUCCESS;
        }

        if ($inventory['unexpected'] > 0 || $inventory['oversized'] > 0) {
            $this->error('Refusing to execute while unexpected or oversized attachment rows remain.');

            return self::FAILURE;
        }

        $email = trim((string) $this->option('actor-email'));
        $actor = User::query()->where('status', 'active')->where('email', $email)->first();

        if (! $actor?->can('integrations.manage')) {
            $this->error('An active Admin with integrations.manage is required for the audited migration.');

            return self::FAILURE;
        }

        if ($reconcileSourceSizeId !== null) {
            try {
                $result = $migration->reconcileSourceSize($actor, $reconcileSourceSizeId);
            } catch (\Throwable $exception) {
                $this->error('Source-size reconciliation stopped safely: '.$this->safeErrorCode($exception));

                return self::FAILURE;
            }

            $this->table(['Source-size reconciliation', 'Value'], [
                ['Attachment ID', $result['attachment_id']],
                ['Previously recorded size', $result['previous_size']],
                ['Verified source size', $result['reconciled_size']],
                ['Source SHA-256', $result['source_sha256']],
                ['Source DriveItem retained', 'yes'],
                ['Attachment pointer changed', 'no'],
            ]);

            return self::SUCCESS;
        }

        if ($acceptNonidenticalDestinationId !== null) {
            try {
                $result = $migration->adoptOperatorApprovedNonidenticalDestination($actor, $acceptNonidenticalDestinationId);
            } catch (\Throwable $exception) {
                $this->error('Approved destination adoption stopped safely: '.$this->safeErrorCode($exception));

                return self::FAILURE;
            }

            $this->table(['Approved destination adoption', 'Value'], [
                ['Attachment ID', $result['attachment_id']],
                ['Source size', $result['source_size']],
                ['Destination size', $result['destination_size']],
                ['Source SHA-256', $result['source_sha256']],
                ['Destination SHA-256', $result['destination_sha256']],
                ['Byte mismatch explicitly accepted and audited', 'yes'],
                ['Work Schedules source retained', 'yes'],
            ]);

            return self::SUCCESS;
        }

        try {
            $summary = $migration->migrate($actor, $limit, function (array $progress): void {
                $this->line(sprintf(
                    'Attachment %d: %s%s',
                    $progress['attachment_id'],
                    $progress['status'],
                    $progress['failure_code'] === null ? '' : ' ('.$progress['failure_code'].')',
                ));
            }, $afterId);
        } catch (\Throwable $exception) {
            $this->error('Business Attachments migration stopped safely: '.$this->safeErrorCode($exception));

            return self::FAILURE;
        }

        $this->table(['Result', 'Files'], [
            ['Migrated', $summary['migrated']],
            ['Reused verified destination copy', $summary['reused']],
            ['Office files transformed by SharePoint', $summary['office_transformed']],
            ['Failed', $summary['failed']],
            ['Last attempted attachment ID', $summary['last_attempted_id'] ?? 'none'],
            ['Remaining on source', $summary['remaining']],
            ['Unexpected target rows', $summary['unexpected']],
            ['Business Attachment writes auto-enabled', $summary['writes_activated'] ? 'yes' : 'no'],
        ]);

        if ($summary['failed_attachments'] !== []) {
            $this->line('Failure index: '.json_encode($summary['failed_attachments'], JSON_UNESCAPED_SLASHES));
        }

        if ($summary['failed'] > 0 || $summary['remaining'] > 0 || $summary['unexpected'] > 0) {
            $this->error('Migration is incomplete; source files were retained and the write gate remains closed.');

            return self::FAILURE;
        }

        if (! $summary['writes_activated']) {
            $this->error('All rows appear migrated, but the verified Business Attachments write gate did not activate.');

            return self::FAILURE;
        }

        $this->info('Migration verified. Source DriveItems were retained for rollback.');

        return self::SUCCESS;
    }

    private function safeErrorCode(\Throwable $exception): string
    {
        return $exception instanceof MicrosoftGraphApiException
            ? $exception->reason
            : 'sharepoint_attachment_migration_preflight_failed';
    }
}
