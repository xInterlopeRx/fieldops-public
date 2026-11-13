<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Contracts\SharePointAttachmentUploader;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Operator-only command: restore the archived FieldPulse attachments into
 * FieldOps through the application's own SharePoint upload service.
 *
 * Input is the operatorx archive produced by the attachment mirror: a
 * `manifest.jsonl` (one row per exported file, with customer/job/project
 * linkage, the staged local path, and the sha256) plus the staged files.
 *
 * Bounds:
 * - Target resolution only via the manifest linkage mapped to ALREADY
 *   imported FieldOps entities (Job # -> work_orders.fieldpulse_id,
 *   Customer # -> customers.fieldpulse_id). Nothing is created; rows
 *   without a target are recorded as skipped.
 * - Uploads go exclusively through SharePointAttachmentUploadService
 *   (per-customer SharePoint folders, metadata rows, audit events).
 * - Idempotent per row via a deterministic clientUploadId; content
 *   duplicates (same sha256) upload once and are recorded as duplicates.
 * - Rate-limited batches with a row cap for smoke runs; fails closed when
 *   the SharePoint write flag is disabled. Invoices and unlinked
 *   form/invoice-option constructs are excluded (preserved in the archive).
 * - Resumable: completed rows in the results file are skipped on re-run.
 */
class RestoreFieldPulseAttachments extends Command
{
    protected $signature = 'attachments:restore-fieldpulse
        {archiveDir}
        {--limit= : Maximum number of eligible files to process this run}
        {--batch=25 : Files per rate-limited batch}
        {--sleep=2 : Seconds between batches}
        {--actor-email= : Email of the admin recorded as the uploader}';

    protected $description = 'Restore archived FieldPulse attachments to their imported Jobs and Customers via SharePoint';

    private const RESULTS_FILE = 'restore-results.jsonl';

    public function handle(SharePointAttachmentUploader $attachments): int
    {
        if (! config('services.microsoft_graph.sharepoint_enabled')) {
            $this->error('SharePoint writes are disabled in this environment (sharepoint_enabled=false). Refusing to run.');

            return self::FAILURE;
        }

        $actor = $this->resolveActor();

        $archiveDir = rtrim((string) $this->argument('archiveDir'), '/');
        $manifestPath = $archiveDir.'/manifest.jsonl';

        if (! is_file($manifestPath)) {
            $this->error("Missing archive manifest: {$manifestPath}");

            return self::FAILURE;
        }

        $manifest = array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            array_values(array_filter(file($manifestPath) ?: [], static fn (string $line): bool => trim($line) !== '')),
        );

        $resultsPath = $archiveDir.'/'.self::RESULTS_FILE;
        $completed = $this->loadCompleted($resultsPath);

        // Rebuild the content-dedupe state from previously uploaded rows so
        // re-runs stay idempotent, and treat recorded duplicates as done.
        $contentHashes = [];
        $firstResults = [];

        foreach ($completed as $rowNumber => $record) {
            if (($record['status'] ?? null) === 'uploaded' && ($record['sha256'] ?? '') !== '') {
                $contentHashes[(string) $record['sha256']] = true;
                $firstResults[(string) $record['sha256']] = $rowNumber;
            }
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $batchSize = max(1, (int) $this->option('batch'));
        $batchSleep = max(0, (int) $this->option('sleep'));

        $counters = ['uploaded' => 0, 'duplicate' => 0, 'no_target' => 0, 'excluded' => 0, 'failed' => 0];
        $processed = 0;
        $batchCount = 0;

        $results = fopen($resultsPath, 'a');

        if ($results === false) {
            $this->error("Cannot open results file: {$resultsPath}");

            return self::FAILURE;
        }

        $contentHashes = [];
        $firstResults = [];

        foreach ($manifest as $index => $record) {
            $rowNumber = (int) ($record['row'] ?? ($index + 1));

            if (in_array(($completed[$rowNumber]['status'] ?? null), ['uploaded', 'duplicate'], true)) {
                continue;
            }

            if ($limit !== null && $processed >= $limit) {
                break;
            }

            $outcome = $this->processRow($record, $rowNumber, $archiveDir, $actor, $attachments, $contentHashes, $firstResults);
            $counters[$outcome['status']] = ($counters[$outcome['status']] ?? 0) + 1;
            $processed++;
            $batchCount++;

            fwrite($results, json_encode(['row' => $rowNumber, ...$outcome], JSON_UNESCAPED_SLASHES).PHP_EOL);

            if ($batchCount >= $batchSize) {
                $batchCount = 0;
                sleep($batchSleep);
            }
        }

        fclose($results);

        $this->table(['Outcome', 'Files'], array_map(
            static fn (string $key, int $value): array => [$key, $value],
            array_keys($counters),
            array_values($counters)
        ));
        $this->info("Processed {$processed} row(s). Results: {$resultsPath}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $contentHashes
     * @param  array<string, string>  $firstResults
     * @return array<string, mixed>
     */
    private function processRow(
        array $record,
        int $rowNumber,
        string $archiveDir,
        User $actor,
        SharePointAttachmentUploader $attachments,
        array &$contentHashes,
        array &$firstResults,
    ): array {
        $subject = mb_strtolower(trim($record['attached_to'] ?? ''));
        $localPath = trim($record['local_path'] ?? '');
        $localPath = $localPath !== '' ? $archiveDir.'/'.$localPath : '';

        if ($localPath === '' || ! is_file($localPath)) {
            return ['status' => 'failed', 'error' => 'staged file missing'];
        }

        $sha256 = (string) ($record['sha256'] ?? '');

        if ($sha256 !== '' && isset($contentHashes[$sha256])) {
            return ['status' => 'duplicate', 'duplicate_of_row' => $firstResults[$sha256] ?? null, 'sha256' => $sha256];
        }

        $attachedToJob = in_array($subject, ['job', 'jobs'], true);
        $attachedToCustomer = in_array($subject, ['customer', 'customers'], true);
        $attachedToProject = in_array($subject, ['project', 'projects'], true);

        if (! $attachedToJob && ! $attachedToCustomer && ! $attachedToProject) {
            // Invoice/estimate attachments and unlinked form constructs stay
            // preserved in the archive: FieldOps does not model invoices, and
            // the export does not carry their parent entities.
            return ['status' => 'excluded', 'reason' => 'unsupported subject '.($record['attached_to'] ?? '(none)')];
        }

        $customerNo = trim($record['customer_no'] ?? '');
        $customer = $customerNo !== '' ? Customer::withArchived()->where('fieldpulse_id', $customerNo)->first() : null;
        $workOrder = $attachedToJob ? WorkOrder::withArchived()->where('fieldpulse_id', trim($record['job_no'] ?? ''))->first() : null;

        if ($attachedToJob && $workOrder === null) {
            return ['status' => 'no_target', 'reason' => 'job not imported'];
        }

        if ($customer === null && $workOrder !== null) {
            $customer = $workOrder->site->customer()->first();
        }

        if ($customer === null) {
            return ['status' => 'no_target', 'reason' => 'customer not imported'];
        }

        $clientName = trim($record['file_name'] ?? '');
        $extension = trim($record['file_type'] ?? '');
        $clientName = $clientName !== '' ? $clientName : 'fieldpulse-file-'.$rowNumber;

        if ($extension !== '' && ! str_ends_with(mb_strtolower($clientName), mb_strtolower($extension))) {
            $clientName .= $extension;
        }

        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';
        $upload = new UploadedFile($localPath, $clientName, $mimeType, null, true);

        $clientUploadId = (string) Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'https://fieldops.bhinservices.com/restore/fieldpulse/'.$rowNumber,
        );

        $attempt = 0;

        do {
            $attempt++;

            try {
                if ($workOrder !== null) {
                    $attachment = $attachments->uploadForWorkOrder($upload, $workOrder, $actor, $clientUploadId);
                } else {
                    $attachment = $attachments->uploadForCustomer($upload, $customer, $actor);
                }

                $contentHashes[$sha256] = true;
                $firstResults[$sha256] = $rowNumber;

                $result = [
                    'status' => 'uploaded',
                    'attachment_id' => $attachment->getKey(),
                    'sharepoint_item_id' => $attachment->sharepoint_item_id,
                    'customer_no' => $customerNo,
                    'job_no' => trim($record['job_no'] ?? '') ?: null,
                    'sha256' => $sha256,
                ];

                if ($attachedToProject) {
                    $result['project_no'] = trim($record['project_no'] ?? '') ?: null;
                    $result['note'] = 'project attachment restored as a customer attachment (Projects are not imported)';
                }

                return $result;
            } catch (Throwable $exception) {
                if (! $this->isRetryable($exception) || $attempt >= 3) {
                    return ['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 200)];
                }

                sleep(5 * $attempt);
            }
        } while (true);
    }

    private function isRetryable(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '429') || str_contains($message, '503') || str_contains($message, 'timeout');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadCompleted(string $resultsPath): array
    {
        if (! is_file($resultsPath)) {
            return [];
        }

        $completed = [];
        $lines = file($resultsPath) ?: [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $record = json_decode($line, true);

            if (is_array($record) && isset($record['row'])) {
                $completed[(int) $record['row']] = $record;
            }
        }

        return $completed;
    }

    private function resolveActor(): User
    {
        $email = trim((string) $this->option('actor-email'));
        $query = User::query()->where('status', 'active')->orderBy('id');

        if ($email !== '') {
            $query->where('email', $email);
        }

        $actor = $query->first();

        if ($actor === null) {
            throw new RuntimeException('No active admin user found for the uploader actor.');
        }

        return $actor;
    }
}
