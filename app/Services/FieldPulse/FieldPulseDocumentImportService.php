<?php

namespace App\Services\FieldPulse;

use App\Jobs\ProcessFieldPulseDocumentImport;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Customer;
use App\Models\FieldPulseImportFailure;
use App\Models\FieldPulseImportRun;
use App\Models\InstallationClaim;
use App\Models\Project;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * FieldPulse uploader: imports "our FieldPulse document" (an XLSX workbook of
 * aggregated Customer/Location/Job/Note data) into FieldOps.
 *
 * Idempotent by FieldPulse ID; records are never matched by name. No external
 * provider is contacted — this reads the uploaded document only.
 */
class FieldPulseDocumentImportService
{
    public function __construct(
        private readonly FieldPulseDocument $document,
        private readonly FieldPulseProjectImportService $projects,
    ) {}

    public static function documentDiskPath(int $runId): string
    {
        return 'fieldpulse-imports/'.$runId.'.xlsx';
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, UploadedFile $file): array
    {
        $this->assertCanImport($actor);

        $path = $file->getRealPath();

        if (! is_string($path)) {
            throw ValidationException::withMessages(['document' => 'The uploaded document could not be read.']);
        }

        try {
            $data = $this->document->read($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['document' => 'The file is not a valid FieldPulse document ('.$exception->getMessage().').']);
        }

        $projectLinkCounts = $this->projectLinkCounts($data['Projects'] ?? [], $data['Jobs']);

        return [
            'source' => 'fieldpulse_document',
            'customers' => count($data['Customers']),
            'locations' => count($data['Locations']),
            'projects' => count($data['Projects'] ?? []),
            'jobs' => count($data['Jobs']),
            ...$projectLinkCounts,
            'notes' => count($data['Notes']),
            'contacts' => count($data['Contacts'] ?? []),
            'existingRun' => $this->currentRunSummary(),
            'conflictingSource' => $this->conflictingSourceExists(),
        ];
    }

    public function start(User $actor, UploadedFile $file): FieldPulseImportRun
    {
        $this->assertCanImport($actor);

        if ($this->conflictingSourceExists()) {
            throw ValidationException::withMessages([
                'document' => 'A first-run Customer/Site import already exists from another source. Reset or complete it before starting the FieldPulse upload.',
            ]);
        }

        // Reject a document that is not readable before storing anything.
        $path = $file->getRealPath();

        if (! is_string($path)) {
            throw ValidationException::withMessages(['document' => 'The uploaded document could not be read.']);
        }

        try {
            $this->document->read($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['document' => 'The file is not a valid FieldPulse document ('.$exception->getMessage().').']);
        }

        $lock = Cache::lock('fieldpulse-document-import-start-'.app()->environment(), 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['document' => 'Another request is already starting the FieldPulse upload.']);
        }

        try {
            $run = DB::transaction(function () use ($actor): FieldPulseImportRun {
                $existing = FieldPulseImportRun::query()
                    ->where('environment', app()->environment())
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'document' => 'A FieldPulse import already exists. Resume it instead of starting a new one.',
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

                $this->audit($actor, $run, 'fieldpulse_document_import_started', 'Admin started the FieldPulse document upload.');

                return $run;
            });
        } finally {
            $lock->release();
        }

        $file->storeAs('fieldpulse-imports', $run->getKey().'.xlsx', ['disk' => 'local']);

        // Record the retained document's identity immediately, so a failed or
        // partial run is still self-describing. Nothing in this pipeline deletes
        // the uploaded workbook (see ADR-0042 amendment).
        $this->recordDocumentIdentity($run);

        ProcessFieldPulseDocumentImport::dispatch($run->getKey())
            ->onQueue(ProcessFieldPulseDocumentImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function resume(User $actor): FieldPulseImportRun
    {
        $this->assertCanImport($actor);

        $run = FieldPulseImportRun::query()->where('environment', app()->environment())->firstOrFail();

        if ($run->status === FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE) {
            throw ValidationException::withMessages(['document' => 'The FieldPulse upload is already complete.']);
        }

        if (! Storage::disk('local')->exists(self::documentDiskPath($run->getKey()))) {
            throw ValidationException::withMessages(['document' => 'The uploaded document is no longer available. Upload it again to resume.']);
        }

        $this->clearFailuresAndReopen($run);

        ProcessFieldPulseDocumentImport::dispatch($run->getKey())
            ->onQueue(ProcessFieldPulseDocumentImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function import(int $runId): void
    {
        $run = FieldPulseImportRun::query()->whereKey($runId)->first();

        if ($run === null || $run->status !== FieldPulseImportRun::STATUS_IN_PROGRESS) {
            return;
        }

        $path = Storage::disk('local')->path(self::documentDiskPath($runId));

        try {
            $data = $this->document->read($path);
        } catch (RuntimeException) {
            $this->failRun($run, 'fieldpulse_document_unreadable');

            return;
        }

        $this->importCustomers($run, $data['Customers']);
        $this->importContacts($run, $data['Contacts'] ?? []);
        $this->importLocations($run, $data['Locations']);
        $this->importProjects($run, $data['Projects'] ?? []);
        $this->importJobs($run, $data['Jobs']);
        $this->importNotes($run, $data['Notes']);

        $this->finish($run);
        $this->retainDocument($run);
    }

    public function markUnexpectedJobFailure(int $runId): void
    {
        $run = FieldPulseImportRun::query()->whereKey($runId)->first();

        if ($run !== null && $run->status === FieldPulseImportRun::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'fieldpulse_document_import_job_failed');
        }
    }

    /** @param list<array<string, string>> $rows */
    private function importCustomers(FieldPulseImportRun $run, array $rows): void
    {
        $imported = 0;
        $contacts = 0;
        $notes = 0;

        foreach ($rows as $row) {
            $sourceId = $row['fieldpulse_id'] ?? '';

            if ($sourceId === '') {
                continue;
            }

            try {
                $contactCount = DB::transaction(function () use ($row, $sourceId): int {
                    $customer = Customer::withArchived()->where('fieldpulse_id', $sourceId)->lockForUpdate()->first();

                    if ($customer !== null && $customer->is_archived) {
                        throw new RuntimeException('fieldpulse_customer_archived');
                    }

                    if ($customer === null) {
                        $customer = new Customer;
                        $customer->client_id = $this->nextClientId();
                        $customer->fieldpulse_id = $sourceId;
                    }

                    $customer->fill([
                        'import_source' => 'fieldpulse',
                        'name' => $row['display_name'] !== '' ? $row['display_name'] : ($row['company_name'] !== '' ? $row['company_name'] : trim($row['first_name'].' '.$row['last_name'])),
                        'company_name' => $this->nullIfBlank($row['company_name'] ?? ''),
                        'first_name' => $this->nullIfBlank($row['first_name'] ?? ''),
                        'last_name' => $this->nullIfBlank($row['last_name'] ?? ''),
                        'email' => $this->nullIfBlank($row['email'] ?? ''),
                        'phone' => $this->nullIfBlank($row['phone'] ?? ''),
                        'alternate_phone' => $this->nullIfBlank($row['alt_phone'] ?? ''),
                        'mobile_phone' => $this->nullIfBlank($row['mobile_phone'] ?? ''),
                        'fax' => $this->nullIfBlank($row['fax'] ?? ''),
                        'website' => $this->nullIfBlank($row['website'] ?? ''),
                        'address' => $this->nullIfBlank($row['address'] ?? ''),
                        'notes' => $this->nullIfBlank($row['notes'] ?? ''),
                        'metadata' => [
                            ...($customer->metadata ?? []),
                            'fieldpulse' => array_filter([
                                'account_type' => $this->nullIfBlank($row['account_type'] ?? ''),
                                'status' => $this->nullIfBlank($row['status'] ?? ''),
                                'lead_source' => $this->nullIfBlank($row['lead_source'] ?? ''),
                                'assigned_to' => $this->nullIfBlank($row['assigned_to'] ?? ''),
                                'created_at' => $this->nullIfBlank($row['created_at'] ?? ''),
                                ...$this->extraMetadata($row['metadata_json'] ?? ''),
                            ], static fn (mixed $value): bool => $value !== null),
                        ],
                    ])->save();

                    $contactCount = 0;
                    $contactCount += $this->saveContact($customer, 'primary', [
                        'first_name' => $row['first_name'] ?? '',
                        'last_name' => $row['last_name'] ?? '',
                        'email' => $row['email'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'alternate_phone' => $row['alt_phone'] ?? '',
                        'mobile_phone' => $row['mobile_phone'] ?? '',
                    ]) ? 1 : 0;
                    $contactCount += $this->saveContact($customer, 'secondary', [
                        'first_name' => $row['secondary_first_name'] ?? '',
                        'last_name' => $row['secondary_last_name'] ?? '',
                        'email' => $row['secondary_email'] ?? '',
                        'phone' => $row['secondary_phone'] ?? '',
                    ]) ? 1 : 0;

                    return $contactCount;
                });

                FieldPulseImportFailure::query()->where('import_id', $run->getKey())->where('resource_type', 'customer')->where('source_record_id', $sourceId)->delete();
                $imported++;
                $contacts += $contactCount;
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'customer', $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'customers_imported' => $run->customers_imported + $imported,
            'contacts_imported' => $run->contacts_imported + $contacts,
            'notes_imported' => $run->notes_imported + $notes,
            'stage' => 'locations',
            'updated_at' => now(),
        ])->save();
    }

    /** @param list<array<string, string>> $rows */
    private function importContacts(FieldPulseImportRun $run, array $rows): void
    {
        $contacts = 0;

        foreach ($rows as $row) {
            $sourceId = $row['fieldpulse_contact_id'] ?? '';

            if ($sourceId === '') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $sourceId): void {
                    $existing = Contact::withArchived()->where('fieldpulse_id', $sourceId)->lockForUpdate()->first();

                    if ($existing !== null && $existing->is_archived) {
                        throw new RuntimeException('fieldpulse_contact_archived');
                    }

                    $customer = Customer::withArchived()->where('fieldpulse_id', $row['customer_fieldpulse_id'] ?? '')->first();

                    if ($customer === null) {
                        throw new RuntimeException('fieldpulse_contact_customer_missing');
                    }

                    $type = trim($row['contact_type'] ?? '') ?: 'additional';

                    $saved = $this->saveContact($customer, $type, [
                        'first_name' => $row['first_name'] ?? '',
                        'last_name' => $row['last_name'] ?? '',
                        'email' => $row['email'] ?? '',
                        'phone' => $row['phone'] ?? '',
                        'mobile_phone' => $row['mobile_phone'] ?? '',
                        'alternate_phone' => $row['alt_phone'] ?? '',
                    ], array_filter([
                        'fieldpulse_contact_id' => $sourceId,
                        'note' => $this->nullIfBlank($row['notes'] ?? ''),
                        'created_at' => $this->nullIfBlank($row['created_at'] ?? ''),
                    ], static fn (mixed $value): bool => $value !== null));

                    if (! $saved) {
                        throw new RuntimeException('fieldpulse_contact_empty');
                    }

                    Contact::withArchived()
                        ->where(['customer_id' => $customer->getKey(), 'site_id' => null, 'contact_type' => $type])
                        ->whereNull('fieldpulse_id')
                        ->update(['fieldpulse_id' => $sourceId]);
                });

                FieldPulseImportFailure::query()->where('import_id', $run->getKey())->where('resource_type', 'contact')->where('source_record_id', $sourceId)->delete();
                $contacts++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'contact', $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'contacts_imported' => $run->contacts_imported + $contacts,
            'updated_at' => now(),
        ])->save();
    }

    /** @param list<array<string, string>> $rows */
    private function importLocations(FieldPulseImportRun $run, array $rows): void
    {
        $sites = 0;

        foreach ($rows as $row) {
            $sourceId = $row['fieldpulse_id'] ?? '';

            if ($sourceId === '') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $sourceId): void {
                    $customer = Customer::withArchived()->where('fieldpulse_id', $row['customer_fieldpulse_id'] ?? '')->first();

                    if ($customer === null) {
                        throw new RuntimeException('fieldpulse_location_owner_missing');
                    }

                    $site = Site::withArchived()->where('fieldpulse_id', $sourceId)->lockForUpdate()->first();

                    if ($site !== null && $site->is_archived) {
                        throw new RuntimeException('fieldpulse_site_archived');
                    }

                    $site ??= new Site;
                    $site->fill([
                        'customer_id' => $customer->getKey(),
                        'fieldpulse_id' => $sourceId,
                        'import_source' => 'fieldpulse',
                        'name' => ($row['title'] ?? '') !== '' ? $row['title'] : 'Location',
                        'address' => $this->nullIfBlank($row['address'] ?? ''),
                        'metadata' => [
                            ...($site->metadata ?? []),
                            'fieldpulse' => array_filter([
                                'is_main_location' => ($row['is_main_location'] ?? '') !== '',
                                'is_primary_location' => ($row['is_primary_location'] ?? '') !== '',
                                'latitude' => $this->nullIfBlank($row['latitude'] ?? ''),
                                'longitude' => $this->nullIfBlank($row['longitude'] ?? ''),
                                ...$this->extraMetadata($row['metadata_json'] ?? ''),
                            ], static fn (mixed $value): bool => $value !== null && $value !== false),
                        ],
                    ])->save();
                });

                FieldPulseImportFailure::query()->where('import_id', $run->getKey())->where('resource_type', 'location')->where('source_record_id', $sourceId)->delete();
                $sites++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'location', $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'sites_imported' => $run->sites_imported + $sites,
            'stage' => 'jobs',
            'updated_at' => now(),
        ])->save();
    }

    /** @param list<array<string, string>> $rows */
    private function importProjects(FieldPulseImportRun $run, array $rows): void
    {
        $projects = 0;

        foreach ($rows as $row) {
            $sourceId = trim($row['fieldpulse_id'] ?? '');

            if ($sourceId === '') {
                continue;
            }

            try {
                $this->projects->upsert($row);

                FieldPulseImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('resource_type', FieldPulseImportRun::FAILURE_RESOURCE_PROJECT)
                    ->where('source_record_id', $sourceId)
                    ->delete();
                $projects++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, FieldPulseImportRun::FAILURE_RESOURCE_PROJECT, $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'projects_imported' => $run->projects_imported + $projects,
            'updated_at' => now(),
        ])->save();
    }

    /** @param list<array<string, string>> $rows */
    private function importJobs(FieldPulseImportRun $run, array $rows): void
    {
        $jobs = 0;

        foreach ($rows as $row) {
            $sourceId = $row['fieldpulse_id'] ?? '';

            if ($sourceId === '') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $sourceId): void {
                    $site = $row['location_fieldpulse_id'] !== ''
                        ? Site::withArchived()->where('fieldpulse_id', $row['location_fieldpulse_id'])->first()
                        : null;

                    $customer = Customer::withArchived()->where('fieldpulse_id', $row['customer_fieldpulse_id'] ?? '')->first();

                    $site ??= $customer === null
                        ? null
                        : Site::withArchived()->where('customer_id', $customer->getKey())->orderBy('id')->first();

                    if ($site === null) {
                        throw new RuntimeException('fieldpulse_job_site_missing');
                    }

                    $workOrder = WorkOrder::withArchived()->where('fieldpulse_id', $sourceId)->lockForUpdate()->first();

                    if ($workOrder !== null && $workOrder->is_archived) {
                        throw new RuntimeException('fieldpulse_job_archived');
                    }

                    $status = $row['status'] ?? '';
                    $valid = ['created', 'scheduled', 'pending', 'on_my_way', 'in_progress', 'completed', 'cancelled'];

                    if (! in_array($status, $valid, true)) {
                        $status = 'created';
                    }

                    $metadata = json_decode($row['metadata_json'] ?? '', true);
                    $projectNo = is_array($metadata) ? trim((string) ($metadata['project_no'] ?? '')) : '';
                    $project = $projectNo === ''
                        ? null
                        : Project::withArchived()->where('fieldpulse_id', $projectNo)->lockForUpdate()->first();

                    if ($project !== null && $project->is_archived) {
                        throw new RuntimeException('fieldpulse_job_project_archived');
                    }

                    if ($project !== null && (int) $project->customer_id !== (int) $site->customer_id) {
                        throw new RuntimeException('fieldpulse_job_project_customer_mismatch');
                    }

                    $workOrder ??= new WorkOrder;
                    $attributes = [
                        'fieldpulse_id' => $sourceId,
                        'site_id' => $site->getKey(),
                        'title' => ($row['title'] ?? '') !== '' ? $row['title'] : 'Job '.$sourceId,
                        'subtitle' => $this->nullIfBlank($row['subtitle'] ?? ''),
                        'status' => $status,
                        'job_notes' => $this->nullIfBlank($row['job_notes'] ?? ''),
                        'field_notes' => $this->nullIfBlank($row['field_notes'] ?? ''),
                        'metadata' => [
                            ...($workOrder->metadata ?? []),
                            'fieldpulse' => is_array($metadata) ? $metadata : [],
                        ],
                    ];

                    if ($project !== null) {
                        $attributes['project_id'] = $project->getKey();
                    }

                    $workOrder->fill($attributes);

                    $completedAt = is_array($metadata) ? ($metadata['completed_at'] ?? null) : null;

                    if (is_string($completedAt) && $completedAt !== '' && ($parsed = $this->parseTime($completedAt)) !== null) {
                        $workOrder->completed_at = $parsed;
                    }

                    $workOrder->save();
                });

                FieldPulseImportFailure::query()->where('import_id', $run->getKey())->where('resource_type', 'job')->where('source_record_id', $sourceId)->delete();
                $jobs++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'job', $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'jobs_imported' => $run->jobs_imported + $jobs,
            'stage' => 'notes',
            'updated_at' => now(),
        ])->save();
    }

    /** @param list<array<string, string>> $rows */
    private function importNotes(FieldPulseImportRun $run, array $rows): void
    {
        $notes = 0;

        foreach ($rows as $row) {
            $sourceRecordId = $row['source_record_id'] ?? '';

            if ($sourceRecordId === '') {
                continue;
            }

            try {
                DB::transaction(function () use ($row, $sourceRecordId): void {
                    $customer = Customer::withArchived()->where('fieldpulse_id', $row['customer_fieldpulse_id'] ?? '')->first();

                    if ($customer === null) {
                        throw new RuntimeException('fieldpulse_note_customer_missing');
                    }

                    $site = ($row['site_fieldpulse_id'] ?? '') !== ''
                        ? Site::withArchived()->where('fieldpulse_id', $row['site_fieldpulse_id'])->first()
                        : null;

                    $metadata = json_decode($row['metadata_json'] ?? '', true);

                    $this->upsertEvent(
                        $customer,
                        $site,
                        ($row['kind'] ?? '') !== '' ? $row['kind'] : TimelineEvent::KIND_JOB_NOTE,
                        $row['body'] ?? '',
                        $sourceRecordId,
                        $this->parseTime($row['occurred_at'] ?? ''),
                        is_array($metadata) ? $metadata : [],
                    );
                });

                FieldPulseImportFailure::query()->where('import_id', $run->getKey())->where('resource_type', 'note')->where('source_record_id', $sourceRecordId)->delete();
                $notes++;
            } catch (Throwable $exception) {
                $this->recordFailure($run, 'note', $sourceRecordId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'notes_imported' => $run->notes_imported + $notes,
            'stage' => 'complete',
            'updated_at' => now(),
        ])->save();
    }

    /** @param array<string, string> $data */
    private function saveContact(Customer $customer, string $type, array $data, array $metadata = []): bool
    {
        $hasData = collect($data)->contains(fn (string $value): bool => trim($value) !== '');

        if (! $hasData) {
            return false;
        }

        $contact = Contact::withArchived()->where(['customer_id' => $customer->getKey(), 'site_id' => null, 'contact_type' => $type])->lockForUpdate()->first();

        if ($contact !== null && ($contact->is_archived || $contact->source !== 'fieldpulse')) {
            throw new RuntimeException('fieldpulse_contact_conflict');
        }

        $contact ??= new Contact(['customer_id' => $customer->getKey(), 'site_id' => null, 'contact_type' => $type]);
        $contact->fill([
            'source' => 'fieldpulse',
            'first_name' => $this->nullIfBlank($data['first_name'] ?? ''),
            'last_name' => $this->nullIfBlank($data['last_name'] ?? ''),
            'email' => $this->nullIfBlank($data['email'] ?? ''),
            'phone' => $this->nullIfBlank($data['phone'] ?? ''),
            'alternate_phone' => $type === 'primary' ? $this->nullIfBlank($data['alternate_phone'] ?? '') : null,
            'mobile_phone' => $type === 'primary' ? $this->nullIfBlank($data['mobile_phone'] ?? '') : null,
            'metadata' => $metadata !== [] ? array_merge($contact->metadata ?? [], ['fieldpulse' => $metadata]) : ($contact->metadata ?? []),
        ])->saveQuietly();

        foreach ([
            ['type' => 'email', 'label' => 'office', 'value' => $data['email'] ?? '', 'primary' => true],
            ['type' => 'phone', 'label' => 'office', 'value' => $data['phone'] ?? '', 'primary' => true],
            ['type' => 'phone', 'label' => 'mobile', 'value' => $data['mobile_phone'] ?? ($data['alternate_phone'] ?? ''), 'primary' => false],
        ] as $methodData) {
            if (trim($methodData['value']) === '') {
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

    /** @param array<string, mixed> $metadata */
    private function upsertEvent(Customer $customer, ?Site $site, string $kind, string $body, string $sourceRecordId, ?Carbon $occurredAt, array $metadata): void
    {
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

    /**
     * Record the retained uploaded document's path, SHA-256 and byte size.
     *
     * The workbook is the migration's source of record: once the FieldPulse
     * service ends, it is the only copy of the aggregated provider data. A
     * successful import must never discard it.
     */
    private function recordDocumentIdentity(FieldPulseImportRun $run): void
    {
        $relative = self::documentDiskPath($run->getKey());

        if (! Storage::disk('local')->exists($relative)) {
            return;
        }

        $absolute = Storage::disk('local')->path($relative);

        $run->forceFill([
            'document_path' => $relative,
            'document_sha256' => hash_file('sha256', $absolute) ?: null,
            'document_bytes' => filesize($absolute) ?: null,
        ])->save();
    }

    /**
     * Mark the uploaded workbook as retained (and expire nothing).
     *
     * Deliberately does not delete it: the end of a successful first-run import
     * used to be the moment the only copy of the aggregated FieldPulse document
     * disappeared, and a cancelled provider cannot regenerate it.
     */
    private function retainDocument(FieldPulseImportRun $run): void
    {
        $this->recordDocumentIdentity($run);
        $run->refresh();

        if (! Storage::disk('local')->exists(self::documentDiskPath($run->getKey()))) {
            return;
        }

        $run->forceFill(['document_retained_at' => now()])->save();
    }

    private function finish(FieldPulseImportRun $run): void
    {
        $run->refresh();
        $actor = $run->startedBy;

        if ($run->failures()->exists()) {
            $run->forceFill([
                'status' => FieldPulseImportRun::STATUS_FAILED,
                'failure_code' => 'fieldpulse_document_import_records_failed',
            ])->save();

            return;
        }

        $run->forceFill([
            'stage' => 'complete',
            'status' => FieldPulseImportRun::STATUS_CUSTOMER_SITES_COMPLETE,
            'failure_code' => null,
            'customer_sites_completed_at' => now(),
        ])->save();

        if ($actor !== null) {
            $this->audit($actor, $run, 'fieldpulse_document_import_completed', 'Admin completed the FieldPulse document upload.');
        }
    }

    private function failRun(FieldPulseImportRun $run, string $code): void
    {
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_FAILED, 'failure_code' => $code])->save();
    }

    private function clearFailuresAndReopen(FieldPulseImportRun $run): void
    {
        FieldPulseImportFailure::query()->where('import_id', $run->getKey())->delete();
        $run->forceFill(['status' => FieldPulseImportRun::STATUS_IN_PROGRESS, 'stage' => 'customers', 'failure_code' => null])->save();
    }

    private function recordFailure(FieldPulseImportRun $run, string $resourceType, string $sourceId, string $code): void
    {
        FieldPulseImportFailure::query()->updateOrCreate(
            ['import_id' => $run->getKey(), 'resource_type' => $resourceType, 'source_record_id' => $sourceId],
            ['failure_code' => $code],
        );
    }

    private function nextClientId(): string
    {
        $lastClientId = Customer::query()->whereNotNull('client_id')->orderByDesc('client_id')->value('client_id');
        $nextNumber = is_string($lastClientId) && preg_match('/\AC(\d+)\z/', $lastClientId, $matches) === 1
            ? max(1001, (int) $matches[1] + 1)
            : 1001;

        do {
            $clientId = 'C'.str_pad((string) $nextNumber++, 5, '0', STR_PAD_LEFT);
        } while (Customer::query()->where('client_id', $clientId)->exists());

        return $clientId;
    }

    private function nullIfBlank(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @param list<array<string, string>> $projects
     * @param  list<array<string, string>>  $jobs
     * @return array{jobs_with_project_reference: int, jobs_with_unmatched_project_reference: int, jobs_with_project_customer_mismatch: int}
     */
    private function projectLinkCounts(array $projects, array $jobs): array
    {
        $projectsById = [];

        foreach ($projects as $project) {
            $sourceId = trim($project['fieldpulse_id'] ?? '');

            if ($sourceId !== '') {
                $projectsById[$sourceId] = $project;
            }
        }

        $counts = [
            'jobs_with_project_reference' => 0,
            'jobs_with_unmatched_project_reference' => 0,
            'jobs_with_project_customer_mismatch' => 0,
        ];

        foreach ($jobs as $job) {
            $metadata = $this->extraMetadata($job['metadata_json'] ?? '');
            $projectNo = trim((string) ($metadata['project_no'] ?? ''));

            if ($projectNo === '') {
                continue;
            }

            $counts['jobs_with_project_reference']++;
            $project = $projectsById[$projectNo] ?? null;

            if ($project === null) {
                $counts['jobs_with_unmatched_project_reference']++;

                continue;
            }

            $projectCustomer = trim($project['customer_fieldpulse_id'] ?? '');
            $jobCustomer = trim($job['customer_fieldpulse_id'] ?? '');

            if ($projectCustomer !== '' && $jobCustomer !== '' && $projectCustomer !== $jobCustomer) {
                $counts['jobs_with_project_customer_mismatch']++;
            }
        }

        return $counts;
    }

    private function parseTime(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }

        return 'fieldpulse_document_import_failed';
    }

    /**
     * Decode a document metadata_json cell into a plain array.
     *
     * @return array<string, mixed>
     */
    private function extraMetadata(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
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
            'projects_imported' => $run->projects_imported,
            'contacts_imported' => $run->contacts_imported,
            'jobs_imported' => $run->jobs_imported,
            'notes_imported' => $run->notes_imported,
            'comments_imported' => $run->comments_imported,
            'failure_count' => $run->failures()->count(),
            'failure_code' => $run->failure_code,
            'failures' => $run->failures()->orderBy('id')->limit(10)->get([
                'resource_type',
                'source_record_id',
                'failure_code',
            ])->toArray(),
            'document_sha256' => $run->document_sha256,
            'document_retained_at' => $run->document_retained_at?->toISOString(),
        ];
    }

    private function conflictingSourceExists(): bool
    {
        return QuickBooksInitialCustomerImport::query()->where('environment', app()->environment())->exists();
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
            'metadata' => ['environment' => app()->environment(), 'source' => 'fieldpulse_document'],
            'created_at' => now(),
        ]);
    }
}
