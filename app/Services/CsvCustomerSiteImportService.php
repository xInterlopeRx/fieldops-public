<?php

namespace App\Services;

use App\Jobs\ProcessQuickBooksInitialCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\ContactMethod;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksInitialCustomerImportFailure;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;
use RuntimeException;
use Throwable;
use TypeError;

class CsvCustomerSiteImportService
{
    private const CHUNK_SIZE = 25;

    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly CustomerExportCsvParser $parser,
        private readonly ApplicationSetupStepService $steps,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, UploadedFile $file): array
    {
        $this->assertCanImport($actor);
        $parsed = $this->parser->parse($file);
        $existing = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->first();

        if ($existing !== null) {
            throw ValidationException::withMessages([
                'csv_file' => 'A first-run import already exists. Resume or use the guarded local reset before starting a new source.',
            ]);
        }

        $sourceIds = collect($parsed['records'])->pluck('source_record_id')->all();
        $conflictCount = Customer::withArchived()
            ->whereIn('source_customer_id', $sourceIds)
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD))
            ->count();

        return [
            ...$parsed['preview'],
            'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'conflictCount' => $conflictCount,
            'canStart' => $parsed['preview']['invalidRowCount'] === 0 && $conflictCount === 0,
        ];
    }

    public function start(User $actor, UploadedFile $file, string $previewSha256): QuickBooksInitialCustomerImport
    {
        $this->assertCanImport($actor);
        $parsed = $this->parser->parse($file);

        if (! hash_equals($parsed['sha256'], $previewSha256)) {
            throw ValidationException::withMessages([
                'csv_file' => 'The selected CSV differs from the preview. Preview the file again before importing.',
            ]);
        }

        if ($parsed['preview']['invalidRowCount'] !== 0) {
            throw ValidationException::withMessages([
                'csv_file' => 'The CSV contains invalid rows. Correct the listed row errors and preview it again.',
            ]);
        }

        if ($parsed['records'] === []) {
            throw ValidationException::withMessages(['csv_file' => 'The CSV contains no importable Customer records.']);
        }

        $sourceIds = collect($parsed['records'])->pluck('source_record_id')->all();
        $conflicts = Customer::withArchived()
            ->whereIn('source_customer_id', $sourceIds)
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD))
            ->exists();

        if ($conflicts) {
            throw ValidationException::withMessages([
                'csv_file' => 'One or more Customer IDs already belong to records from another source. No name-based merge was attempted.',
            ]);
        }

        try {
            $payload = json_encode($parsed['records'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw ValidationException::withMessages(['csv_file' => 'The CSV could not be safely prepared for import.']);
        }

        $startLock = Cache::lock('quickbooks-initial-customer-import-start-'.app()->environment(), 120);

        if (! $startLock->get()) {
            throw ValidationException::withMessages([
                'csv_file' => 'Another request is already starting the Customer/Site import. Refresh and check its progress.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor, $parsed, $payload): QuickBooksInitialCustomerImport {
                $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();

                if ($state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'Select CSV import mode before starting a CSV import.',
                    ]);
                }

                $existingRun = QuickBooksInitialCustomerImport::query()
                    ->where('environment', app()->environment())
                    ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
                    ->lockForUpdate()
                    ->first();

                if ($existingRun !== null) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'A first-run import already exists. Resume or use the guarded local reset before starting a new source.',
                    ]);
                }

                $run = QuickBooksInitialCustomerImport::query()->create([
                    'environment' => app()->environment(),
                    'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
                    'realm_id' => null,
                    'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_customers' => count($parsed['records']),
                    'processed_customers' => 0,
                    'imported_customers' => 0,
                    'imported_sites' => 0,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                    'source_payload' => $payload,
                    'source_sha256' => $parsed['sha256'],
                ]);

                $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
                $this->steps->progress(
                    ApplicationSetupStep::INITIAL_QBO_IMPORT,
                    $actor,
                    0,
                    count($parsed['records']),
                );
                $this->audit(
                    $actor,
                    $run,
                    'customer_csv_import_started',
                    'Admin started the Customer/Site CSV import in local-only mode.',
                    count($parsed['records']),
                );

                return $run;
            });
        } finally {
            $startLock->release();
        }

        ProcessQuickBooksInitialCustomerImport::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksInitialCustomerImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function resume(User $actor): QuickBooksInitialCustomerImport
    {
        $this->assertCanImport($actor);
        $runId = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->value('id');
        abort_if($runId === null, 404);

        $lock = Cache::lock('quickbooks-initial-customer-import-'.$runId, 900);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'csv_file' => 'A CSV import batch is still running. Wait for it to finish before resuming.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor): QuickBooksInitialCustomerImport {
                $run = QuickBooksInitialCustomerImport::query()
                    ->where('environment', app()->environment())
                    ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! is_string($run->source_payload) || $run->source_payload === '') {
                    throw ValidationException::withMessages([
                        'csv_file' => 'The protected CSV source is no longer available. Use the guarded local reset to start a fresh first-run import.',
                    ]);
                }

                if ($run->status === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'The local Customer/Site CSV import is complete. To add QuickBooks later, switch to API mode; a separate QBO import will preserve these CSV rows as local-only records.',
                    ]);
                }

                if ($run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS
                    && $run->updated_at?->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw ValidationException::withMessages([
                        'csv_file' => 'The CSV import is still running. Return to this page shortly for updated progress.',
                    ]);
                }

                $run->forceFill([
                    'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'processed_customers' => 0,
                    'imported_customers' => 0,
                    'imported_sites' => 0,
                    'failure_code' => null,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                    'customer_sites_completed_at' => null,
                ])->save();

                $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
                $this->steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 0, $run->total_customers);
                $this->audit(
                    $actor,
                    $run,
                    'customer_csv_import_resumed',
                    'Admin resumed the local-only Customer/Site CSV import.',
                    $run->total_customers,
                );

                return $run;
            });
        } finally {
            $lock->release();
        }

        ProcessQuickBooksInitialCustomerImport::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksInitialCustomerImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function processNextBatch(QuickBooksInitialCustomerImport $run): bool
    {
        $lock = Cache::lock('quickbooks-initial-customer-import-'.$run->getKey(), 900);

        if (! $lock->get()) {
            return false;
        }

        try {
            $run->refresh();

            if ($run->status !== QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
                return false;
            }

            try {
                $records = $this->decodePayload($run->source_payload);
            } catch (Throwable) {
                $this->failRun($run, 'csv_import_payload_unavailable');

                return false;
            }

            if (count($records) !== $run->total_customers) {
                $this->failRun($run, 'csv_import_payload_mismatch');

                return false;
            }

            $batch = array_slice($records, $run->next_start_position - 1, self::CHUNK_SIZE);

            if ($batch === [] && $run->processed_customers < $run->total_customers) {
                $this->failRun($run, 'csv_import_cursor_invalid');

                return false;
            }

            $importedCustomers = 0;
            $importedSites = 0;

            foreach ($batch as $record) {
                $sourceId = (string) ($record['source_record_id'] ?? '');
                $rowNumber = (int) ($record['row'] ?? 0);

                try {
                    $createdSite = $this->importRecord($record);
                    QuickBooksInitialCustomerImportFailure::query()
                        ->where('import_id', $run->getKey())
                        ->where('source_record_id', $sourceId)
                        ->delete();
                    $importedCustomers++;
                    $importedSites += $createdSite ? 1 : 0;
                } catch (Throwable $exception) {
                    $this->recordFailure($run, $sourceId, $rowNumber, $this->failureCode($exception));
                }
            }

            $run->refresh();
            $processed = min($run->total_customers, $run->processed_customers + count($batch));
            $run->forceFill([
                'next_start_position' => $run->next_start_position + count($batch),
                'processed_customers' => $processed,
                'imported_customers' => $run->imported_customers + $importedCustomers,
                'imported_sites' => $run->imported_sites + $importedSites,
                'updated_at' => now(),
            ])->save();

            if ($run->startedBy !== null) {
                $this->steps->progress(
                    ApplicationSetupStep::INITIAL_QBO_IMPORT,
                    $run->startedBy,
                    $processed,
                    $run->total_customers,
                );
            }

            if ($processed >= $run->total_customers) {
                $this->finish($run);

                return false;
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    public function markUnexpectedJobFailure(int $importId): void
    {
        $run = QuickBooksInitialCustomerImport::query()
            ->whereKey($importId)
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->first();

        if ($run !== null && $run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'csv_import_job_failed');
        }
    }

    /** @param array<string, mixed> $record */
    private function importRecord(array $record): bool
    {
        $sourceId = $record['source_record_id'] ?? null;
        $customerData = $record['customer'] ?? null;

        if (! is_string($sourceId) || $sourceId === '' || ! is_array($customerData)) {
            throw new RuntimeException('csv_import_record_invalid');
        }

        return DB::transaction(function () use ($sourceId, $customerData, $record): bool {
            $customer = Customer::withArchived()
                ->where('source_customer_id', $sourceId)
                ->lockForUpdate()
                ->first();

            if ($customer !== null) {
                if ($customer->import_source !== QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
                    throw new RuntimeException('csv_source_identifier_conflict');
                }

                if ($customer->is_archived) {
                    throw new RuntimeException('csv_customer_archived');
                }
            } else {
                $customer = new Customer;
                $customer->client_id = $this->nextClientId();
                $customer->source_customer_id = $sourceId;
            }

            $customer->fill([
                'import_source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
                'name' => $customerData['name'],
                'company_name' => $customerData['company_name'],
                'first_name' => $customerData['first_name'],
                'last_name' => $customerData['last_name'],
                'email' => $customerData['email'],
                'phone' => $customerData['phone'],
                'alternate_phone' => $customerData['alternate_phone'],
                'address' => $this->formatAddress($record['service_address'] ?? null),
                'notes' => $customerData['notes'],
                'metadata' => [
                    ...($customer->metadata ?? []),
                    'import_source' => 'customer_export_csv',
                ],
            ])->save();

            $site = $this->savePrimarySite($customer, $record['service_address'] ?? null);

            if ($site !== false) {
                $this->saveSiteAddress($customer, $site, $record['service_address'] ?? null);
            }

            $this->saveContact($customer, 'primary', $record['primary_contact'] ?? []);
            $this->saveContact($customer, 'secondary', $record['secondary_contact'] ?? []);
            $this->saveBillingAddress($customer, $record['billing_address'] ?? null);

            return $site !== false;
        });
    }

    private function savePrimarySite(Customer $customer, mixed $address): Site|false
    {
        if (! is_array($address) || $address === []) {
            return false;
        }

        $site = Site::withArchived()
            ->where('customer_id', $customer->getKey())
            ->where('import_source', QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            ->lockForUpdate()
            ->first();

        if ($site !== null && $site->is_archived) {
            throw new RuntimeException('csv_site_archived');
        }

        $site ??= new Site;
        $site->fill([
            'customer_id' => $customer->getKey(),
            'import_source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
            'name' => 'Primary Site',
            'address' => $this->formatAddress($address),
            'metadata' => [
                ...($site->metadata ?? []),
                'import_source' => 'customer_export_csv',
                'source_customer_id' => $customer->source_customer_id,
            ],
        ])->save();

        return $site;
    }

    private function saveSiteAddress(Customer $customer, Site $site, mixed $data): void
    {
        if (! is_array($data) || $data === []) {
            return;
        }

        $address = CustomerAddress::query()->firstOrNew([
            'customer_id' => $customer->getKey(),
            'source_site_id' => $site->getKey(),
            'address_type' => CustomerAddress::TYPE_SHIPPING,
            'source' => CustomerAddress::SOURCE_CSV_IMPORT,
        ]);
        $address->fill([
            ...$data,
            'label' => 'Imported service address',
            'is_default' => false,
        ])->save();
    }

    /** @param array<string, mixed> $data */
    private function saveContact(Customer $customer, string $type, array $data): void
    {
        $hasData = collect($data)->contains(fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        if (! $hasData) {
            return;
        }

        $contact = Contact::withArchived()->where([
            'customer_id' => $customer->getKey(),
            'site_id' => null,
            'contact_type' => $type,
        ])->lockForUpdate()->first();

        if ($contact !== null && ($contact->is_archived || $contact->source !== 'csv_import')) {
            throw new RuntimeException('csv_contact_conflict');
        }

        $contact ??= new Contact([
            'customer_id' => $customer->getKey(),
            'site_id' => null,
            'contact_type' => $type,
        ]);
        $contact->fill([
            'source' => 'csv_import',
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'alternate_phone' => $type === 'primary' ? ($data['alternate_phone'] ?? null) : null,
        ]);
        $contact->saveQuietly();

        foreach ([
            ['type' => 'email', 'label' => 'office', 'value' => $data['email'] ?? null, 'primary' => true],
            ['type' => 'email', 'label' => 'personal', 'value' => $data['alternate_email'] ?? null, 'primary' => false],
            ['type' => 'phone', 'label' => 'office', 'value' => $data['phone'] ?? null, 'primary' => true],
            ['type' => 'phone', 'label' => 'mobile', 'value' => $data['alternate_phone'] ?? null, 'primary' => false],
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
    }

    private function saveBillingAddress(Customer $customer, mixed $data): void
    {
        if (! is_array($data) || $data === []) {
            return;
        }

        $address = CustomerAddress::query()->firstOrNew([
            'customer_id' => $customer->getKey(),
            'source_site_id' => null,
            'address_type' => CustomerAddress::TYPE_BILLING,
            'source' => CustomerAddress::SOURCE_CSV_IMPORT,
        ]);
        $existingDefault = CustomerAddress::query()
            ->where('customer_id', $customer->getKey())
            ->where('address_type', CustomerAddress::TYPE_BILLING)
            ->where('is_default', true)
            ->first();
        $hasOtherDefault = $existingDefault !== null && ! $existingDefault->is($address);
        $address->fill([
            ...$data,
            'label' => 'Imported billing address',
            'is_default' => ! $hasOtherDefault,
        ])->save();
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

    private function formatAddress(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $parts = array_filter($address, fn (mixed $value): bool => is_string($value) && trim($value) !== '');

        return $parts === [] ? null : implode(', ', array_values($parts));
    }

    /** @param array<string, mixed> $records */
    private function decodePayload(?string $payload): array
    {
        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('csv_import_payload_unavailable');
        }

        try {
            $records = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('csv_import_payload_invalid');
        }

        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('csv_import_payload_invalid');
        }

        return $records;
    }

    private function recordFailure(QuickBooksInitialCustomerImport $run, string $sourceId, int $rowNumber, string $code): void
    {
        QuickBooksInitialCustomerImportFailure::query()->updateOrCreate(
            ['import_id' => $run->getKey(), 'source_record_id' => $sourceId],
            ['source_row' => $rowNumber, 'failure_code' => $code],
        );
    }

    private function finish(QuickBooksInitialCustomerImport $run): void
    {
        $run->refresh();
        $actor = $run->startedBy;

        if ($run->failures()->exists()) {
            $run->forceFill([
                'status' => QuickBooksInitialCustomerImport::STATUS_FAILED,
                'failure_code' => 'csv_customer_import_records_failed',
            ])->save();

            if ($actor !== null) {
                $this->steps->fail(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 'csv_customer_import_records_failed');
            }

            return;
        }

        $run->forceFill([
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'failure_code' => null,
            'source_payload' => null,
            'customer_sites_completed_at' => now(),
        ])->save();

        if ($actor !== null && $run->total_customers > 0) {
            $this->steps->progress(
                ApplicationSetupStep::INITIAL_QBO_IMPORT,
                $actor,
                $run->total_customers,
                $run->total_customers,
            );
            $this->steps->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
            $this->audit($actor, $run, 'customer_csv_import_phase_completed', 'Admin completed the local-only Customer/Site CSV import.', $run->total_customers, $run->imported_customers, $run->imported_sites);
        }
    }

    private function failRun(QuickBooksInitialCustomerImport $run, string $code): void
    {
        $run->forceFill([
            'status' => QuickBooksInitialCustomerImport::STATUS_FAILED,
            'failure_code' => $code,
        ])->save();

        if ($run->startedBy !== null) {
            $this->steps->fail(ApplicationSetupStep::INITIAL_QBO_IMPORT, $run->startedBy, $code);
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return match ((string) $exception->getCode()) {
                '23505' => 'csv_import_unique_conflict',
                '23503' => 'csv_import_relationship_conflict',
                '23502' => 'csv_import_required_field_missing',
                '22001' => 'csv_import_value_too_long',
                default => 'csv_import_persistence_failed',
            };
        }

        if ($exception instanceof RuntimeException
            && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }

        if ($exception instanceof TypeError) {
            return 'csv_import_type_error';
        }

        if ($exception instanceof LogicException) {
            return 'csv_import_logic_error';
        }

        if ($exception instanceof \Error) {
            return 'csv_import_internal_error';
        }

        return 'csv_customer_import_failed';
    }

    private function assertCanImport(User $actor): void
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $claim = InstallationClaim::query()->whereKey(1)->first();

        abort_unless(
            $state->onboarding_required
                && $state->quickbooks_import_mode === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV
                && $claim !== null
                && (string) $claim->claimed_by_user_id === (string) $actor->getKey()
                && $actor->status === 'active'
                && $actor->hasVerifiedEmail()
                && $actor->hasEnabledTwoFactorAuthentication()
                && $actor->can('integrations.manage'),
            403,
        );
    }

    private function audit(
        User $actor,
        QuickBooksInitialCustomerImport $run,
        string $event,
        string $summary,
        int $totalCustomers,
        ?int $importedCustomers = null,
        ?int $importedSites = null,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => QuickBooksInitialCustomerImport::class,
            'subject_id' => $run->getKey(),
            'event' => $event,
            'summary' => $summary,
            'metadata' => array_filter([
                'environment' => app()->environment(),
                'source' => QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD,
                'total_customers' => $totalCustomers,
                'imported_customers' => $importedCustomers,
                'imported_sites' => $importedSites,
            ], fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }
}
