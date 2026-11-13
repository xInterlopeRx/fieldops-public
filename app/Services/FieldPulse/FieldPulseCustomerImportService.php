<?php

namespace App\Services\FieldPulse;

use App\Jobs\ProcessFieldPulseCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Customer;
use App\Models\FieldPulseImportFailure;
use App\Models\FieldPulseImportRun;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Read-only FieldPulse Customer/Location/Contact import (ADR-0039).
 *
 * Bounded and resumable: each queued invocation processes one provider page and
 * dispatches the next until the run completes. Upserts are keyed by the stable
 * FieldPulse ID; no name-based matching is performed, and no write is ever sent
 * to FieldPulse.
 */
class FieldPulseCustomerImportService
{
    public const PAGE_SIZE = 100;

    public function __construct(
        private readonly FieldPulseApiClient $client,
        private readonly FieldPulseCustomerMapper $mapper,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $actor): array
    {
        $this->assertCanImport($actor);

        $customers = $this->client->get('/customers', ['limit' => 1, 'calculate_count' => 'true']);
        $locations = $this->client->get('/locations', [
            'object_type' => 'customer',
            'limit' => 1,
            'calculate_count' => 'true',
        ]);

        return [
            'source' => 'fieldpulse',
            'customers' => $customers['total_count'],
            'locations' => $locations['total_count'],
            'existingRun' => $this->currentRunSummary(),
        ];
    }

    public function start(User $actor): FieldPulseImportRun
    {
        $this->assertCanImport($actor);

        if ($this->conflictingSourceExists()) {
            throw ValidationException::withMessages([
                'fieldpulse' => 'A first-run Customer/Site import already exists from another source. Resume it or reset before starting the FieldPulse import.',
            ]);
        }

        $lock = Cache::lock('fieldpulse-customer-import-start-'.app()->environment(), 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'fieldpulse' => 'Another request is already starting the FieldPulse import. Refresh and check its progress.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor): FieldPulseImportRun {
                $existing = FieldPulseImportRun::query()
                    ->where('environment', app()->environment())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'fieldpulse' => 'A FieldPulse import already exists. Resume it instead of starting a new one.',
                    ]);
                }

                $run = FieldPulseImportRun::query()->create([
                    'environment' => app()->environment(),
                    'status' => FieldPulseImportRun::STATUS_IN_PROGRESS,
                    'stage' => 'customers',
                    'customers_page' => 1,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                ]);

                $this->audit($actor, $run, 'fieldpulse_import_started', 'Admin started the read-only FieldPulse Customer/Site import.');

                return $run;
            });
        } finally {
            $lock->release();
        }

        $this->dispatch($run);

        return $run;
    }

    public function resume(User $actor): FieldPulseImportRun
    {
        $this->assertCanImport($actor);

        $run = FieldPulseImportRun::query()
            ->where('environment', app()->environment())
            ->firstOrFail();

        if ($run->status === FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE) {
            throw ValidationException::withMessages([
                'fieldpulse' => 'The FieldPulse Customer/Site import is already complete.',
            ]);
        }

        $run->forceFill([
            'status' => FieldPulseImportRun::STATUS_IN_PROGRESS,
            'failure_code' => null,
        ])->save();

        $this->dispatch($run);

        return $run;
    }

    /**
     * Process one provider page for the current stage.
     *
     * @return bool True when more work remains (the caller should dispatch again).
     */
    public function processNextPage(int $runId): bool
    {
        $lock = Cache::lock('fieldpulse-customer-import-'.$runId, 900);

        if (! $lock->get()) {
            return false;
        }

        try {
            $run = FieldPulseImportRun::query()->whereKey($runId)->first();

            if ($run === null || $run->status !== FieldPulseImportRun::STATUS_IN_PROGRESS) {
                return false;
            }

            return match ($run->stage) {
                'customers' => $this->processCustomers($run),
                'locations' => $this->processLocations($run),
                'jobs' => $this->processJobs($run),
                'notes' => $this->processNotes($run),
                'comments' => $this->processComments($run),
                default => false,
            };
        } finally {
            $lock->release();
        }
    }

    public function markUnexpectedJobFailure(int $runId): void
    {
        $run = FieldPulseImportRun::query()->whereKey($runId)->first();

        if ($run !== null && $run->status === FieldPulseImportRun::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'fieldpulse_import_job_failed');
        }
    }

    private function processCustomers(FieldPulseImportRun $run): bool
    {
        $page = max(1, $run->customers_page);
        $result = $this->client->get('/customers', [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'calculate_count' => 'true',
        ]);

        $total = $result['total_count'];
        $records = $result['data'];
        $imported = 0;
        $contacts = 0;
        $sitesCount = 0;
        $notes = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $sourceId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
                ? (string) $record['id']
                : 'unknown';

            try {
                [$customerCreated, $contactCount, $noteCount] = $this->importCustomer($record);
                FieldPulseImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('resource_type', FieldPulseImportRun::FAILURE_RESOURCE_CUSTOMER)
                    ->where('source_record_id', $sourceId)
                    ->delete();
                $imported++;
                $contacts += $contactCount;
                $notes += $noteCount;
            } catch (Throwable $exception) {
                $this->recordFailure($run, FieldPulseImportRun::FAILURE_RESOURCE_CUSTOMER, $sourceId, $this->failureCode($exception));
            }
        }

        $processedSoFar = ($page - 1) * self::PAGE_SIZE + count($records);
        $moreCustomers = $records !== [] && ($total === null || $processedSoFar < $total);

        $run->refresh();
        $run->forceFill([
            'customers_page' => $moreCustomers ? $page + 1 : $page,
            'customers_total' => $total ?? max($run->customers_total, $processedSoFar),
            'customers_imported' => $run->customers_imported + $imported,
            'contacts_imported' => $run->contacts_imported + $contacts,
            'notes_imported' => $run->notes_imported + $notes,
            'sites_imported' => $run->sites_imported + $sitesCount,
            'updated_at' => now(),
        ])->save();

        if (! $moreCustomers) {
            $run->forceFill(['stage' => 'locations', 'customers_page' => 1])->save();

            return true;
        }

        return true;
    }

    private function processLocations(FieldPulseImportRun $run): bool
    {
        $page = max(1, $run->customers_page);
        $result = $this->client->get('/locations', [
            'object_type' => 'customer',
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'calculate_count' => 'true',
        ]);

        $total = $result['total_count'];
        $records = $result['data'];
        $sites = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $sourceId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
                ? (string) $record['id']
                : 'unknown';

            // Locations can belong to Jobs as well as Customers; only Customer
            // locations become Sites. Skip others without recording a failure.
            $objectType = is_string($record['object_type'] ?? null) ? $record['object_type'] : '';

            if (stripos($objectType, 'customer') === false) {
                continue;
            }

            try {
                if ($this->importLocation($record)) {
                    $sites++;
                }
                FieldPulseImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('resource_type', FieldPulseImportRun::FAILURE_RESOURCE_LOCATION)
                    ->where('source_record_id', $sourceId)
                    ->delete();
            } catch (Throwable $exception) {
                $this->recordFailure($run, FieldPulseImportRun::FAILURE_RESOURCE_LOCATION, $sourceId, $this->failureCode($exception));
            }
        }

        $processedSoFar = ($page - 1) * self::PAGE_SIZE + count($records);
        $moreLocations = $records !== [] && ($total === null || $processedSoFar < $total);

        $run->refresh();
        $run->forceFill([
            'customers_page' => $moreLocations ? $page + 1 : $page,
            'sites_imported' => $run->sites_imported + $sites,
            'updated_at' => now(),
        ])->save();

        if (! $moreLocations) {
            $run->forceFill(['stage' => 'jobs', 'customers_page' => 1])->save();

            return true;
        }

        return true;
    }

    private function processJobs(FieldPulseImportRun $run): bool
    {
        $page = max(1, $run->customers_page);
        $result = $this->client->get('/jobs', [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'calculate_count' => 'true',
        ]);

        $total = $result['total_count'];
        $records = $result['data'];
        $statusMap = $this->jobStatusMap();
        $jobs = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $sourceId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
                ? (string) $record['id']
                : 'unknown';

            try {
                $this->importJob($record, $statusMap);
                FieldPulseImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('resource_type', FieldPulseImportRun::FAILURE_RESOURCE_JOB)
                    ->where('source_record_id', $sourceId)
                    ->delete();
                $jobs++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, FieldPulseImportRun::FAILURE_RESOURCE_JOB, $sourceId, $this->failureCode($exception));
            }
        }

        $processedSoFar = ($page - 1) * self::PAGE_SIZE + count($records);
        $moreJobs = $records !== [] && ($total === null || $processedSoFar < $total);

        $run->refresh();
        $run->forceFill([
            'customers_page' => $moreJobs ? $page + 1 : $page,
            'jobs_imported' => $run->jobs_imported + $jobs,
            'updated_at' => now(),
        ])->save();

        if (! $moreJobs) {
            $run->forceFill(['stage' => 'notes', 'customers_page' => 1])->save();

            return true;
        }

        return true;
    }

    /**
     * Map FieldPulse workflow status IDs to the FieldOps WorkOrder status set,
     * keyed by the FieldPulse status `type`.
     *
     * @return array<string, string>
     */
    private function jobStatusMap(): array
    {
        $result = $this->client->get('/jobs/status-workflow-statuses', ['limit' => 100]);
        $map = [];

        foreach ($result['data'] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;
            $type = is_string($row['type'] ?? null) ? strtolower($row['type']) : null;

            if ((is_int($id) || is_string($id)) && $type !== null) {
                $map[(string) $id] = match ($type) {
                    'new' => 'created',
                    'travel_time' => 'on_my_way',
                    'in_progress' => 'in_progress',
                    'pending' => 'pending',
                    'completed' => 'completed',
                    'canceled', 'cancelled' => 'cancelled',
                    default => 'created',
                };
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, string>  $statusMap
     */
    private function importJob(array $record, array $statusMap): bool
    {
        $jobId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
            ? (string) $record['id']
            : null;

        if ($jobId === null) {
            throw new RuntimeException('fieldpulse_job_id_missing');
        }

        $customerFieldpulseId = is_int($record['customer_id'] ?? null) || is_string($record['customer_id'] ?? null)
            ? (string) $record['customer_id']
            : null;

        if ($customerFieldpulseId === null) {
            throw new RuntimeException('fieldpulse_job_customer_missing');
        }

        return DB::transaction(function () use ($record, $statusMap, $jobId, $customerFieldpulseId): bool {
            $customer = Customer::withArchived()->where('fieldpulse_id', $customerFieldpulseId)->first();

            if ($customer === null) {
                throw new RuntimeException('fieldpulse_job_customer_missing');
            }

            $site = null;

            if (is_int($record['location_id'] ?? null) || is_string($record['location_id'] ?? null)) {
                $site = Site::withArchived()->where('fieldpulse_id', (string) $record['location_id'])->first();
            }

            // work_orders.site_id is required; fall back to any Site for the Customer.
            $site ??= Site::withArchived()
                ->where('customer_id', $customer->getKey())
                ->orderByRaw("CASE WHEN import_source = 'fieldpulse' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();

            if ($site === null) {
                throw new RuntimeException('fieldpulse_job_site_missing');
            }

            $statusId = is_int($record['status_id'] ?? null) || is_string($record['status_id'] ?? null)
                ? (string) $record['status_id']
                : null;
            $status = ($statusId !== null ? ($statusMap[$statusId] ?? null) : null) ?? 'created';

            $title = $this->firstString($record, ['job_type', 'subtitle', 'type']) ?? 'Job '.$jobId;
            $subtitle = is_string($record['subtitle'] ?? null) && trim($record['subtitle']) !== ''
                ? trim($record['subtitle'])
                : null;
            $notes = is_string($record['notes'] ?? null) && trim($record['notes']) !== '' ? $record['notes'] : null;
            $fieldNotes = is_string($record['field_notes'] ?? null) && trim($record['field_notes']) !== '' ? $record['field_notes'] : null;

            $clientContactId = null;

            if (is_int($record['customer_contact_id'] ?? null) || is_string($record['customer_contact_id'] ?? null)) {
                $clientContactId = Contact::withArchived()
                    ->where('fieldpulse_id', (string) $record['customer_contact_id'])
                    ->value('id');
            }

            $workOrder = WorkOrder::withArchived()->where('fieldpulse_id', $jobId)->lockForUpdate()->first();

            if ($workOrder !== null && $workOrder->is_archived) {
                throw new RuntimeException('fieldpulse_job_archived');
            }

            $workOrder ??= new WorkOrder;
            $workOrder->fill([
                'fieldpulse_id' => $jobId,
                'site_id' => $site->getKey(),
                'title' => $title,
                'subtitle' => $subtitle,
                'status' => $status,
                'job_notes' => $notes,
                'field_notes' => $fieldNotes,
                'completed_at' => $this->parseOccurredAt($record['completed_at'] ?? null),
                'client_contact_id' => $clientContactId,
                'metadata' => [
                    ...($workOrder->metadata ?? []),
                    'fieldpulse' => array_filter([
                        'job_id' => $jobId,
                        'job_type' => is_string($record['job_type'] ?? null) ? $record['job_type'] : null,
                        'status_id' => $statusId,
                        'start_time' => is_string($record['start_time'] ?? null) ? $record['start_time'] : null,
                        'end_time' => is_string($record['end_time'] ?? null) ? $record['end_time'] : null,
                        'created_at' => is_string($record['created_at'] ?? null) ? $record['created_at'] : null,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                ],
            ])->save();

            return true;
        });
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $keys
     */
    private function firstString(array $record, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (is_string($record[$key] ?? null) && trim($record[$key]) !== '') {
                return trim($record[$key]);
            }
        }

        return null;
    }

    private function processNotes(FieldPulseImportRun $run): bool
    {
        $page = max(1, $run->customers_page);
        $result = $this->client->get('/jobs', [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'calculate_count' => 'true',
        ]);

        $total = $result['total_count'];
        $records = $result['data'];
        $notes = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $sourceId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
                ? (string) $record['id']
                : 'unknown';

            try {
                $notes += $this->importJobNotes($record);
                FieldPulseImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('resource_type', 'note')
                    ->where('source_record_id', $sourceId)
                    ->delete();
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'note', $sourceId, $this->failureCode($exception));
            }
        }

        $processedSoFar = ($page - 1) * self::PAGE_SIZE + count($records);
        $moreJobs = $records !== [] && ($total === null || $processedSoFar < $total);

        $run->refresh();
        $run->forceFill([
            'customers_page' => $moreJobs ? $page + 1 : $page,
            'notes_imported' => $run->notes_imported + $notes,
            'updated_at' => now(),
        ])->save();

        if (! $moreJobs) {
            $run->forceFill(['stage' => 'comments', 'customers_page' => 1])->save();

            return true;
        }

        return true;
    }

    private function processComments(FieldPulseImportRun $run): bool
    {
        $page = max(1, $run->customers_page);
        $result = $this->client->get('/comments', [
            'page' => $page,
            'limit' => self::PAGE_SIZE,
            'calculate_count' => 'true',
        ]);

        $total = $result['total_count'];
        $records = $result['data'];
        $comments = 0;

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $sourceId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
                ? (string) $record['id']
                : 'unknown';

            try {
                $imported = $this->importComment($record);

                if ($imported) {
                    FieldPulseImportFailure::query()
                        ->where('import_id', $run->getKey())
                        ->where('resource_type', 'comment')
                        ->where('source_record_id', $sourceId)
                        ->delete();
                    $comments++;
                }
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'comment', $sourceId, $this->failureCode($exception));
            }
        }

        $processedSoFar = ($page - 1) * self::PAGE_SIZE + count($records);
        $moreComments = $records !== [] && ($total === null || $processedSoFar < $total);

        $run->refresh();
        $run->forceFill([
            'customers_page' => $moreComments ? $page + 1 : $page,
            'comments_imported' => $run->comments_imported + $comments,
            'updated_at' => now(),
        ])->save();

        if (! $moreComments) {
            $this->finish($run);

            return false;
        }

        return true;
    }

    /**
     * Import one FieldPulse comment as a Customer timeline event.
     *
     * @param  array<string, mixed>  $record
     * @return bool True when a timeline event was written; false when skipped
     *              (deleted, or a subject type this gateway does not model).
     */
    private function importComment(array $record): bool
    {
        $commentId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
            ? (string) $record['id']
            : null;

        if ($commentId === null) {
            throw new RuntimeException('fieldpulse_comment_id_missing');
        }

        if (($record['deleted_at'] ?? null) !== null) {
            return false;
        }

        $commentableType = is_string($record['commentable_type'] ?? null) ? $record['commentable_type'] : '';

        // Only Job and Customer subjects are modeled here; skip others, do not fail.
        $isJob = str_contains($commentableType, 'Job');
        $isCustomer = str_contains($commentableType, 'Customer') || str_contains($commentableType, 'Account');

        if (! $isJob && ! $isCustomer) {
            return false;
        }

        $text = is_string($record['text'] ?? null) ? trim($record['text']) : '';

        if ($text === '') {
            return false;
        }

        $subjectId = is_int($record['commentable_id'] ?? null) || is_string($record['commentable_id'] ?? null)
            ? (string) $record['commentable_id']
            : null;

        if ($subjectId === null) {
            throw new RuntimeException('fieldpulse_comment_subject_missing');
        }

        $customer = null;
        $site = null;

        if ($isJob) {
            $workOrder = WorkOrder::withArchived()->where('fieldpulse_id', $subjectId)->first();

            if ($workOrder === null) {
                throw new RuntimeException('fieldpulse_comment_job_missing');
            }

            $site = Site::withArchived()->whereKey($workOrder->site_id)->first();
            $customer = $site === null
                ? null
                : Customer::withArchived()->whereKey($site->customer_id)->first();
        } else {
            $customer = Customer::withArchived()->where('fieldpulse_id', $subjectId)->first();
        }

        if ($customer === null) {
            throw new RuntimeException('fieldpulse_comment_customer_missing');
        }

        $this->upsertTimelineEvent(
            $customer,
            $site,
            TimelineEvent::KIND_COMMENT,
            $text,
            'comment:'.$commentId,
            $this->parseOccurredAt($record['created_at'] ?? null),
            array_filter([
                'fieldpulse_job_id' => $isJob ? $subjectId : null,
                'author_type' => is_string($record['author_type'] ?? null) ? $record['author_type'] : null,
                'visible_in_customer_portal' => is_bool($record['is_visible_in_customer_portal'] ?? null)
                    ? $record['is_visible_in_customer_portal']
                    : null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{0: bool, 1: int} Created flag and imported contact count.
     */
    private function importCustomer(array $record): array
    {
        $mapped = $this->mapper->customer($record);
        $sourceId = $mapped['source_record_id'];

        return DB::transaction(function () use ($mapped, $sourceId): array {
            $customer = Customer::withArchived()
                ->where('fieldpulse_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($customer !== null && $customer->is_archived) {
                throw new RuntimeException('fieldpulse_customer_archived');
            }

            $created = $customer === null;

            if ($created) {
                $customer = new Customer;
                $customer->client_id = $this->nextClientId();
                $customer->fieldpulse_id = $sourceId;
            }

            $data = $mapped['customer'];

            $customer->fill([
                'import_source' => 'fieldpulse',
                'name' => $data['name'],
                'company_name' => $data['company_name'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'alternate_phone' => $data['alternate_phone'],
                'mobile_phone' => $data['mobile_phone'],
                'fax' => $data['fax'],
                'website' => $data['website'],
                'address' => $data['address'],
                'notes' => $data['notes'],
                'metadata' => [
                    ...($customer->metadata ?? []),
                    'fieldpulse' => $data['metadata'],
                ],
            ])->save();

            $contacts = 0;
            $contacts += $this->saveContact($customer, 'primary', $mapped['primary_contact']) ? 1 : 0;
            $contacts += $this->saveContact($customer, 'secondary', $mapped['secondary_contact']) ? 1 : 0;

            $notes = 0;

            if (is_string($data['notes']) && trim($data['notes']) !== '') {
                $this->upsertTimelineEvent(
                    $customer,
                    null,
                    TimelineEvent::KIND_CUSTOMER_NOTE,
                    $data['notes'],
                    'customer:'.$sourceId.':notes',
                    $this->parseOccurredAt($data['metadata']['created_at'] ?? null),
                    [],
                );
                $notes = 1;
            }

            return [$created, $contacts, $notes];
        });
    }

    /** @param array<string, mixed> $record */
    private function importLocation(array $record): bool
    {
        $mapped = $this->mapper->location($record);
        $sourceId = $mapped['source_record_id'];

        return DB::transaction(function () use ($mapped, $sourceId): bool {
            $customer = Customer::withArchived()
                ->where('fieldpulse_id', $mapped['customer_id'])
                ->first();

            if ($customer === null) {
                throw new RuntimeException('fieldpulse_location_owner_missing');
            }

            $site = Site::withArchived()
                ->where('fieldpulse_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($site !== null && $site->is_archived) {
                throw new RuntimeException('fieldpulse_site_archived');
            }

            $site ??= new Site;
            $site->fill([
                'customer_id' => $customer->getKey(),
                'fieldpulse_id' => $sourceId,
                'import_source' => 'fieldpulse',
                'name' => $mapped['site']['name'],
                'address' => $mapped['site']['address'],
                'metadata' => [
                    ...($site->metadata ?? []),
                    'fieldpulse' => $mapped['site']['metadata'],
                ],
            ])->save();

            return true;
        });
    }

    /**
     * Import a FieldPulse Job's notes and field notes as Customer timeline events.
     *
     * @param  array<string, mixed>  $record
     * @return int Number of timeline events created or updated.
     */
    private function importJobNotes(array $record): int
    {
        $jobId = is_int($record['id'] ?? null) || is_string($record['id'] ?? null)
            ? (string) $record['id']
            : null;

        if ($jobId === null) {
            throw new RuntimeException('fieldpulse_job_id_missing');
        }

        $customerFieldpulseId = is_int($record['customer_id'] ?? null) || is_string($record['customer_id'] ?? null)
            ? (string) $record['customer_id']
            : null;

        if ($customerFieldpulseId === null) {
            throw new RuntimeException('fieldpulse_job_customer_missing');
        }

        $customer = Customer::withArchived()->where('fieldpulse_id', $customerFieldpulseId)->first();

        if ($customer === null) {
            throw new RuntimeException('fieldpulse_job_customer_missing');
        }

        $site = null;

        if (is_int($record['location_id'] ?? null) || is_string($record['location_id'] ?? null)) {
            $site = Site::withArchived()->where('fieldpulse_id', (string) $record['location_id'])->first();
        }

        $occurredAt = $this->parseOccurredAt(
            $record['completed_at'] ?? $record['start_time'] ?? $record['created_at'] ?? null,
        );

        $metadata = array_filter([
            'fieldpulse_job_id' => $jobId,
            'job_type' => is_string($record['job_type'] ?? null) ? $record['job_type'] : null,
            'subtitle' => is_string($record['subtitle'] ?? null) ? $record['subtitle'] : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $count = 0;

        $notes = is_string($record['notes'] ?? null) ? trim($record['notes']) : '';

        if ($notes !== '') {
            $this->upsertTimelineEvent($customer, $site, TimelineEvent::KIND_JOB_NOTE, $notes, 'job:'.$jobId.':notes', $occurredAt, $metadata);
            $count++;
        }

        $fieldNotes = is_string($record['field_notes'] ?? null) ? trim($record['field_notes']) : '';

        if ($fieldNotes !== '') {
            $this->upsertTimelineEvent($customer, $site, TimelineEvent::KIND_FIELD_NOTE, $fieldNotes, 'job:'.$jobId.':field_notes', $occurredAt, $metadata);
            $count++;
        }

        return $count;
    }

    /** @param array<string, mixed> $metadata */
    private function upsertTimelineEvent(
        Customer $customer,
        ?Site $site,
        string $kind,
        string $body,
        string $sourceRecordId,
        ?Carbon $occurredAt,
        array $metadata,
    ): void {
        TimelineEvent::query()->updateOrCreate(
            ['source' => TimelineEvent::SOURCE_FIELDPULSE, 'source_record_id' => $sourceRecordId],
            [
                'customer_id' => $customer->getKey(),
                'site_id' => $site?->getKey(),
                'occurred_at' => $occurredAt,
                'kind' => $kind,
                'body' => $body,
                'metadata' => $metadata === [] ? null : $metadata,
            ],
        );
    }

    private function parseOccurredAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, string|null> $data */
    private function saveContact(Customer $customer, string $type, array $data): bool
    {
        $hasData = collect($data)->contains(fn (?string $value): bool => $value !== null && trim($value) !== '');

        if (! $hasData) {
            return false;
        }

        $contact = Contact::withArchived()->where([
            'customer_id' => $customer->getKey(),
            'site_id' => null,
            'contact_type' => $type,
        ])->lockForUpdate()->first();

        if ($contact !== null && ($contact->is_archived || $contact->source !== 'fieldpulse')) {
            throw new RuntimeException('fieldpulse_contact_conflict');
        }

        $contact ??= new Contact([
            'customer_id' => $customer->getKey(),
            'site_id' => null,
            'contact_type' => $type,
        ]);
        $contact->fill([
            'source' => 'fieldpulse',
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'alternate_phone' => $type === 'primary' ? ($data['alternate_phone'] ?? null) : null,
            'mobile_phone' => $type === 'primary' ? ($data['mobile_phone'] ?? null) : null,
        ]);
        $contact->saveQuietly();

        foreach ([
            ['type' => 'email', 'label' => 'office', 'value' => $data['email'] ?? null, 'primary' => true],
            ['type' => 'email', 'label' => 'personal', 'value' => $data['alternate_email'] ?? null, 'primary' => false],
            ['type' => 'phone', 'label' => 'office', 'value' => $data['phone'] ?? null, 'primary' => true],
            ['type' => 'phone', 'label' => 'mobile', 'value' => $data['mobile_phone'] ?? $data['alternate_phone'] ?? null, 'primary' => false],
        ] as $methodData) {
            if (! is_string($methodData['value']) || trim($methodData['value']) === '') {
                continue;
            }

            $method = ContactMethod::query()->firstOrNew([
                'contact_id' => $contact->getKey(),
                'method_type' => $methodData['type'],
                'label' => $methodData['label'],
                'value' => $methodData['value'],
            ]);
            $method->is_primary = $methodData['primary'];
            $method->save();
        }

        return true;
    }

    private function finish(FieldPulseImportRun $run): void
    {
        $run->refresh();
        $actor = $run->startedBy;

        if ($run->failures()->exists()) {
            $run->forceFill([
                'status' => FieldPulseImportRun::STATUS_FAILED,
                'failure_code' => 'fieldpulse_customer_import_records_failed',
            ])->save();

            if ($actor !== null) {
                $this->audit($actor, $run, 'fieldpulse_import_records_failed', 'FieldPulse import finished with failed records; review the failure list.');
            }

            return;
        }

        $run->forceFill([
            'stage' => 'complete',
            'status' => FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE,
            'failure_code' => null,
            'customer_sites_completed_at' => now(),
        ])->save();

        if ($actor !== null) {
            $this->audit($actor, $run, 'fieldpulse_import_completed', 'Admin completed the read-only FieldPulse Customer/Site import.');
        }
    }

    private function failRun(FieldPulseImportRun $run, string $code): void
    {
        $run->forceFill([
            'status' => FieldPulseImportRun::STATUS_FAILED,
            'failure_code' => $code,
        ])->save();
    }

    private function recordFailure(FieldPulseImportRun $run, string $resourceType, string $sourceId, string $code): void
    {
        FieldPulseImportFailure::query()->updateOrCreate(
            ['import_id' => $run->getKey(), 'resource_type' => $resourceType, 'source_record_id' => $sourceId],
            ['failure_code' => $code],
        );
    }

    private function dispatch(FieldPulseImportRun $run): void
    {
        ProcessFieldPulseCustomerImport::dispatch($run->getKey())
            ->onQueue(ProcessFieldPulseCustomerImport::QUEUE)
            ->afterCommit();
    }

    /** @return array<string, mixed>|null */
    public function currentRunSummary(): ?array
    {
        $run = FieldPulseImportRun::query()->where('environment', app()->environment())->first();

        if ($run === null) {
            return null;
        }

        return [
            'status' => $run->status,
            'stage' => $run->stage,
            'customers_imported' => $run->customers_imported,
            'customers_total' => $run->customers_total,
            'sites_imported' => $run->sites_imported,
            'contacts_imported' => $run->contacts_imported,
            'jobs_imported' => $run->jobs_imported,
            'notes_imported' => $run->notes_imported,
            'comments_imported' => $run->comments_imported,
            'failure_count' => $run->failures()->count(),
            'failure_code' => $run->failure_code,
        ];
    }

    private function conflictingSourceExists(): bool
    {
        return QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->exists();
    }

    private function nextClientId(): string
    {
        $lastClientId = Customer::query()
            ->whereNotNull('client_id')
            ->orderByDesc('client_id')
            ->value('client_id');
        $nextNumber = is_string($lastClientId) && preg_match('/\AC(\d+)\z/', $lastClientId, $matches) === 1
            ? max(1001, (int) $matches[1] + 1)
            : 1001;

        do {
            $clientId = 'C'.str_pad((string) $nextNumber++, 5, '0', STR_PAD_LEFT);
        } while (Customer::query()->where('client_id', $clientId)->exists());

        return $clientId;
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return match ((string) $exception->getCode()) {
                '23505' => 'fieldpulse_import_unique_conflict',
                '23503' => 'fieldpulse_import_relationship_conflict',
                '23502' => 'fieldpulse_import_required_field_missing',
                '22001' => 'fieldpulse_import_value_too_long',
                default => 'fieldpulse_import_persistence_failed',
            };
        }

        if ($exception instanceof RuntimeException
            && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }

        if ($exception instanceof TypeError) {
            return 'fieldpulse_import_type_error';
        }

        if ($exception instanceof LogicException) {
            return 'fieldpulse_import_logic_error';
        }

        if ($exception instanceof \Error) {
            return 'fieldpulse_import_internal_error';
        }

        return 'fieldpulse_customer_import_failed';
    }

    private function assertCanImport(User $actor): void
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $claim = InstallationClaim::query()->whereKey(1)->first();

        abort_unless(
            $state->onboarding_required
                && $claim !== null
                && (string) $claim->claimed_by_user_id === (string) $actor->getKey()
                && $actor->status === 'active'
                && $actor->hasVerifiedEmail()
                && $actor->hasEnabledTwoFactorAuthentication()
                && $actor->can('integrations.manage'),
            403,
        );
    }

    private function audit(User $actor, FieldPulseImportRun $run, string $event, string $summary): void
    {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => FieldPulseImportRun::class,
            'subject_id' => $run->getKey(),
            'event' => $event,
            'summary' => $summary,
            'metadata' => array_filter([
                'environment' => app()->environment(),
                'source' => 'fieldpulse',
                'customers_imported' => $run->customers_imported,
                'sites_imported' => $run->sites_imported,
                'contacts_imported' => $run->contacts_imported,
            ], static fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }
}
