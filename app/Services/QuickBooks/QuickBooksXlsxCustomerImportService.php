<?php

namespace App\Services\QuickBooks;

use App\Jobs\ProcessQuickBooksXlsxImport;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\FieldPulseImportRun;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksInitialCustomerImportFailure;
use App\Models\User;
use App\Services\ApplicationSetupStepService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * QuickBooks first-run file import: reads the "Customer Contact List" .xlsx
 * export and loads Customers with their billing/shipping addresses.
 *
 * The report has no stable QuickBooks identifier, so records carry a synthetic
 * source key and are never matched by name; no QuickBooks API call is made and
 * no QuickBooks link is created.
 */
class QuickBooksXlsxCustomerImportService
{
    public function __construct(
        private readonly QuickBooksCustomerContactListParser $parser,
        private readonly ApplicationSetupStepService $steps,
    ) {}

    public static function documentDiskPath(int $runId): string
    {
        return 'quickbooks-imports/'.$runId.'.xlsx';
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, UploadedFile $file): array
    {
        $this->assertCanImport($actor);
        $parsed = $this->parse($file);
        $sourceIds = collect($parsed['records'])->pluck('source_record_id')->all();

        $conflictCount = Customer::withArchived()
            ->whereIn('source_customer_id', $sourceIds)
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX))
            ->count();

        return [
            ...$parsed['preview'],
            'source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX,
            'conflictCount' => $conflictCount,
            'existingRun' => $this->currentRunSummary(),
            'conflictingSource' => $this->conflictingSourceExists(),
            'canStart' => $conflictCount === 0 && ! $this->conflictingSourceExists() && $this->currentRunSummary() === null,
        ];
    }

    public function start(User $actor, UploadedFile $file): QuickBooksInitialCustomerImport
    {
        $this->assertCanImport($actor);
        $parsed = $this->parse($file);

        if ($this->conflictingSourceExists()) {
            throw ValidationException::withMessages([
                'quickbooks_file' => 'A first-run Customer/Site import already exists from another source. Reset or complete it before importing this file.',
            ]);
        }

        $sourceIds = collect($parsed['records'])->pluck('source_record_id')->all();
        $conflicts = Customer::withArchived()
            ->whereIn('source_customer_id', $sourceIds)
            ->where(fn ($query) => $query->whereNull('import_source')->orWhere('import_source', '!=', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX))
            ->exists();

        if ($conflicts) {
            throw ValidationException::withMessages([
                'quickbooks_file' => 'One or more Customers already belong to records from another source. No name-based merge was attempted.',
            ]);
        }

        $lock = Cache::lock('quickbooks-xlsx-import-start-'.app()->environment(), 120);

        if (! $lock->get()) {
            throw ValidationException::withMessages(['quickbooks_file' => 'Another request is already starting this import.']);
        }

        try {
            $run = DB::transaction(function () use ($actor, $parsed): QuickBooksInitialCustomerImport {
                $existing = QuickBooksInitialCustomerImport::query()
                    ->where('environment', app()->environment())
                    ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'quickbooks_file' => 'A QuickBooks file import already exists. Resume it instead of starting a new one.',
                    ]);
                }

                $run = QuickBooksInitialCustomerImport::query()->create([
                    'environment' => app()->environment(),
                    'source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX,
                    'realm_id' => null,
                    'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_customers' => count($parsed['records']),
                    'processed_customers' => 0,
                    'imported_customers' => 0,
                    'imported_sites' => 0,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                ]);

                $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
                AuditEvent::query()->create([
                    'actor_user_id' => $actor->getKey(),
                    'subject_type' => QuickBooksInitialCustomerImport::class,
                    'subject_id' => $run->getKey(),
                    'event' => 'quickbooks_file_import_started',
                    'summary' => $actor->name.' started the QuickBooks Customer Contact List import.',
                    'metadata' => ['environment' => app()->environment(), 'source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX],
                    'created_at' => now(),
                ]);

                return $run;
            });
        } finally {
            $lock->release();
        }

        $file->storeAs('quickbooks-imports', $run->getKey().'.xlsx', ['disk' => 'local']);

        // Retain-on-import: the uploaded report is the only record of the
        // synthetic QBX- keys it produced, so record its identity and keep it.
        // Nothing in this pipeline deletes the uploaded workbook.
        $relative = self::documentDiskPath($run->getKey());

        if (Storage::disk('local')->exists($relative)) {
            $run->forceFill([
                'source_sha256' => hash_file('sha256', Storage::disk('local')->path($relative)) ?: null,
            ])->save();
        }

        ProcessQuickBooksXlsxImport::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksXlsxImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function resume(User $actor): QuickBooksInitialCustomerImport
    {
        $this->assertCanImport($actor);

        $run = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX)
            ->firstOrFail();

        if (! Storage::disk('local')->exists(self::documentDiskPath($run->getKey()))) {
            throw ValidationException::withMessages(['quickbooks_file' => 'The uploaded file is no longer available. Upload it again to resume.']);
        }

        QuickBooksInitialCustomerImportFailure::query()->where('import_id', $run->getKey())->delete();
        $run->forceFill(['status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS, 'failure_code' => null])->save();

        ProcessQuickBooksXlsxImport::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksXlsxImport::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function import(int $runId): void
    {
        $run = QuickBooksInitialCustomerImport::query()
            ->whereKey($runId)
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX)
            ->first();

        if ($run === null || $run->status !== QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
            return;
        }

        try {
            $parsed = $this->parser->parse(Storage::disk('local')->path(self::documentDiskPath($runId)));
        } catch (RuntimeException) {
            $this->failRun($run, 'quickbooks_file_unreadable');

            return;
        }

        $imported = 0;
        $sites = 0;

        foreach ($parsed['records'] as $record) {
            $sourceId = (string) $record['source_record_id'];

            try {
                $accounting = $this->importRecord($record);
                QuickBooksInitialCustomerImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('source_record_id', $sourceId)
                    ->delete();
                $imported++;
                $sites += $accounting ? 1 : 0;
            } catch (Throwable $exception) {
                $this->recordFailure($run, $sourceId, $this->failureCode($exception));
            }
        }

        $run->refresh();
        $run->forceFill([
            'processed_customers' => $run->processed_customers + $imported,
            'imported_customers' => $run->imported_customers + $imported,
            'imported_sites' => $run->imported_sites + $sites,
        ])->save();

        $this->finish($run);
        // The uploaded report is retained deliberately (see ADR-0042 amendment):
        // it is the only record of the synthetic QBX- keys this run created.
    }

    public function markUnexpectedJobFailure(int $runId): void
    {
        $run = QuickBooksInitialCustomerImport::query()->whereKey($runId)->first();

        if ($run !== null && $run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'quickbooks_file_import_job_failed');
        }
    }

    /** @param array<string, mixed> $record */
    private function importRecord(array $record): bool
    {
        return DB::transaction(function () use ($record): bool {
            $sourceId = (string) $record['source_record_id'];
            $customer = Customer::withArchived()->where('source_customer_id', $sourceId)->lockForUpdate()->first();

            if ($customer !== null && $customer->is_archived) {
                throw new RuntimeException('quickbooks_customer_archived');
            }

            if ($customer === null) {
                $customer = new Customer;
                $customer->client_id = $this->nextClientId();
                $customer->source_customer_id = $sourceId;
            }

            $customer->fill([
                'import_source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX,
                'name' => $record['name'],
                'first_name' => $record['first_name'],
                'last_name' => $record['last_name'],
                'email' => $record['email'],
                'phone' => $record['phone'],
            ])->save();

            $hasSite = $this->saveAddress($customer, CustomerAddress::TYPE_SHIPPING, $record['shipping_address'] ?? null);
            $this->saveAddress($customer, CustomerAddress::TYPE_BILLING, $record['billing_address'] ?? null);

            return $hasSite;
        });
    }

    /** @param array<string, mixed>|null $address */
    private function saveAddress(Customer $customer, string $type, ?array $address): bool
    {
        if (! is_array($address) || $address === []) {
            return false;
        }

        $record = CustomerAddress::query()->firstOrNew([
            'customer_id' => $customer->getKey(),
            'address_type' => $type,
            'source' => CustomerAddress::SOURCE_FILE_IMPORT,
        ]);

        $record->fill([
            ...$address,
            'source_site_id' => null,
            'label' => $type === CustomerAddress::TYPE_BILLING ? 'Imported billing address' : 'Imported service address',
            'is_default' => true,
        ])->save();

        return true;
    }

    private function finish(QuickBooksInitialCustomerImport $run): void
    {
        $run->refresh();
        $actor = $run->startedBy;

        if ($run->failures()->exists()) {
            $run->forceFill([
                'status' => QuickBooksInitialCustomerImport::STATUS_FAILED,
                'failure_code' => 'quickbooks_file_import_records_failed',
            ])->save();

            return;
        }

        $run->forceFill([
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'failure_code' => null,
            'customer_sites_completed_at' => now(),
            'processed_customers' => $run->total_customers,
        ])->save();

        if ($actor !== null) {
            $this->steps->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
        }
    }

    private function failRun(QuickBooksInitialCustomerImport $run, string $code): void
    {
        $run->forceFill(['status' => QuickBooksInitialCustomerImport::STATUS_FAILED, 'failure_code' => $code])->save();
    }

    private function recordFailure(QuickBooksInitialCustomerImport $run, string $sourceId, string $code): void
    {
        QuickBooksInitialCustomerImportFailure::query()->updateOrCreate(
            ['import_id' => $run->getKey(), 'source_record_id' => $sourceId],
            ['source_row' => null, 'failure_code' => $code],
        );
    }

    /** @return array{records: list<array<string, mixed>>, preview: array<string, mixed>} */
    private function parse(UploadedFile $file): array
    {
        $path = $file->getRealPath();

        if (! is_string($path)) {
            throw ValidationException::withMessages(['quickbooks_file' => 'The uploaded file could not be read.']);
        }

        try {
            return $this->parser->parse($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['quickbooks_file' => 'The file is not a valid QuickBooks Customer Contact List export ('.$exception->getMessage().').']);
        }
    }

    /** @return array<string, mixed>|null */
    public function currentRunSummary(): ?array
    {
        $run = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_XLSX)
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'status' => $run->status,
            'customers_imported' => $run->imported_customers,
            'customers_total' => $run->total_customers,
            'failure_count' => $run->failures()->count(),
            'failure_code' => $run->failure_code,
        ];
    }

    private function conflictingSourceExists(): bool
    {
        return FieldPulseImportRun::query()->where('environment', app()->environment())->exists();
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

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof RuntimeException && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->getMessage()) === 1) {
            return $exception->getMessage();
        }

        return 'quickbooks_file_import_failed';
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
}
