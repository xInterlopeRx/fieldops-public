<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\ProcessQuickBooksInitialCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\Customer;
use App\Models\InstallationClaim;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksInitialCustomerImportFailure;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuickBooksInitialCustomerImportService
{
    private const MAX_CHILDREN_PER_CUSTOMER = 50;

    private const MAX_CUSTOMERS_PER_IMPORT = 1_000_000;

    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly QuickBooksApiClient $api,
        private readonly QuickBooksCustomerSyncService $sync,
        private readonly ApplicationSetupStepService $steps,
        private readonly QuickBooksSyncControl $syncControl,
    ) {}

    /** @return array{customers: int, qboRecords: int, potentialSites: int} */
    public function preview(User $actor): array
    {
        $this->assertCanImport($actor);
        $counts = $this->api->customerImportCounts();
        $this->assertCustomerCount($counts['customers']);

        if ($counts['customers'] < 1) {
            throw new QuickBooksApiException('no_top_level_customers_found', 422);
        }

        return [
            'customers' => $counts['customers'],
            'qboRecords' => $counts['qbo_records'],
            'potentialSites' => max(0, $counts['qbo_records'] - $counts['customers']),
        ];
    }

    public function start(User $actor): QuickBooksInitialCustomerImport
    {
        $this->assertCanImport($actor);
        $environment = app()->environment();
        $connection = $this->connectionForCurrentEnvironment();
        $existing = QuickBooksInitialCustomerImport::query()
            ->where('environment', $environment)
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->first();

        if ($existing !== null) {
            $this->assertSameCompany($existing, (string) $connection->realm_id);

            if ($existing->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
                return $existing;
            }

            if (in_array($existing->status, [
                QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
                QuickBooksInitialCustomerImport::STATUS_COMPLETE,
            ], true)) {
                throw ValidationException::withMessages([
                    'quickbooks_import' => 'The Customer/Site phase is complete. Use the Item catalog sync to refresh Items; do not replay Customer/Site import.',
                ]);
            }

            if ($existing->status === QuickBooksInitialCustomerImport::STATUS_FAILED) {
                return $this->resume($actor);
            }
        }

        $counts = $this->api->customerImportCounts();
        $this->assertCustomerCount($counts['customers']);

        if ($counts['customers'] < 1) {
            throw new QuickBooksApiException('no_top_level_customers_found', 422);
        }

        $startLock = Cache::lock('quickbooks-initial-customer-import-start-'.$environment, 120);

        if (! $startLock->get()) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'Another request is already starting this Customer/Site import. Refresh this page and check its progress.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor, $connection, $counts, $environment): QuickBooksInitialCustomerImport {
                $run = QuickBooksInitialCustomerImport::query()
                    ->where('environment', $environment)
                    ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
                    ->lockForUpdate()
                    ->first();

                if ($run !== null) {
                    $this->assertSameCompany($run, (string) $connection->realm_id);

                    if ($run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
                        return $run;
                    }

                    if (in_array($run->status, [
                        QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
                        QuickBooksInitialCustomerImport::STATUS_COMPLETE,
                    ], true)) {
                        throw ValidationException::withMessages([
                            'quickbooks_import' => 'The Customer/Site phase is complete. Use the Item catalog sync to refresh Items; do not replay Customer/Site import.',
                        ]);
                    }
                } else {
                    $run = new QuickBooksInitialCustomerImport;
                }

                $run->fill([
                    'environment' => $environment,
                    'source' => QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API,
                    'realm_id' => $connection->realm_id,
                    'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_customers' => $counts['customers'],
                    'processed_customers' => 0,
                    'imported_customers' => 0,
                    'imported_sites' => 0,
                    'failure_code' => null,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                    'customer_sites_completed_at' => null,
                ])->save();

                $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
                $this->steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 0, $counts['customers']);
                $this->audit($actor, $run, 'quickbooks_customer_site_import_started', 'QuickBooks Customer/Site import was explicitly started.', $counts['customers']);

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
        $connection = $this->connectionForCurrentEnvironment();
        $counts = $this->api->customerImportCounts();
        $this->assertCustomerCount($counts['customers']);

        if ($counts['customers'] < 1) {
            throw new QuickBooksApiException('no_top_level_customers_found', 422);
        }

        $runId = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->value('id');
        abort_if($runId === null, 404);
        $lock = Cache::lock('quickbooks-initial-customer-import-'.$runId, 900);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'A Customer/Site import batch is still running. Wait for that batch to finish before resuming.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor, $connection, $counts): QuickBooksInitialCustomerImport {
                $run = QuickBooksInitialCustomerImport::query()
                    ->where('environment', app()->environment())
                    ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertSameCompany($run, (string) $connection->realm_id);

                if (in_array($run->status, [
                    QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
                    QuickBooksInitialCustomerImport::STATUS_COMPLETE,
                ], true)) {
                    throw ValidationException::withMessages([
                        'quickbooks_import' => 'The Customer/Site phase is complete. Item catalog refresh does not require replaying Customer/Site import.',
                    ]);
                }

                if ($run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS
                    && $run->updated_at?->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw ValidationException::withMessages([
                        'quickbooks_import' => 'The Customer/Site import is still running. Return to this page shortly for updated progress.',
                    ]);
                }

                $run->forceFill([
                    'status' => QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_customers' => $counts['customers'],
                    'processed_customers' => 0,
                    'imported_customers' => 0,
                    'imported_sites' => 0,
                    'failure_code' => null,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                    'customer_sites_completed_at' => null,
                ])->save();

                $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
                $this->steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 0, $counts['customers']);
                $this->audit($actor, $run, 'quickbooks_customer_site_import_resumed', 'QuickBooks Customer/Site import was explicitly resumed.', $counts['customers']);

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

    public function markUnexpectedJobFailure(int $importId): void
    {
        $run = QuickBooksInitialCustomerImport::query()->find($importId);

        if ($run !== null && $run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'customer_import_job_failed');
        }
    }

    /** Process one bounded QBO parent page. Returns true when another job should continue. */
    public function processNextCustomer(QuickBooksInitialCustomerImport $run): bool
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
                $connection = $this->connectionForCurrentEnvironment();
                $this->assertSameCompany($run, (string) $connection->realm_id);
                $page = $this->api->topLevelCustomerPage($run->next_start_position, 1);
            } catch (Throwable $exception) {
                $this->failRun($run, $this->failureCode($exception));

                return false;
            }

            if ($page['total_count'] !== null && $page['total_count'] !== $run->total_customers) {
                $this->failRun($run, 'qbo_customer_set_changed');

                return false;
            }

            if ($page['records'] === []) {
                if ($run->processed_customers < $run->total_customers) {
                    $this->failRun($run, 'quickbooks_customer_page_incomplete');

                    return false;
                }

                $this->finishCustomerSites($run);

                return false;
            }

            $parent = $page['records'][0];
            $customerId = $parent['Id'];
            $successful = false;
            $siteCount = 0;

            try {
                $siteCount = $this->importParentAndSites($parent);
                $successful = true;
                QuickBooksInitialCustomerImportFailure::query()
                    ->where('import_id', $run->getKey())
                    ->where('quickbooks_customer_id', $customerId)
                    ->delete();
            } catch (Throwable $exception) {
                if ($this->shouldPauseForProviderFailure($exception)) {
                    $this->failRun($run, $this->failureCode($exception));

                    return false;
                }

                $this->recordFailure($run, $customerId, $this->failureCode($exception));
            }

            $run->refresh();
            $run->forceFill([
                'next_start_position' => $run->next_start_position + 1,
                'processed_customers' => min($run->total_customers, $run->processed_customers + 1),
                'imported_customers' => $run->imported_customers + ($successful ? 1 : 0),
                'imported_sites' => $run->imported_sites + ($successful ? $siteCount : 0),
                'updated_at' => now(),
            ])->save();

            $stepActor = $run->startedBy;

            if ($stepActor !== null && $run->total_customers > 0) {
                $this->steps->progress(
                    ApplicationSetupStep::INITIAL_QBO_IMPORT,
                    $stepActor,
                    min($run->processed_customers, $run->total_customers),
                    $run->total_customers,
                );
            }

            if ($run->processed_customers >= $run->total_customers
                || ($page['total_count'] !== null && $run->next_start_position > $page['total_count'])) {
                $this->finishCustomerSites($run);

                return false;
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    /** @return array{status: string, source: string, customersImported: int, sitesImported: int, customersProcessed: int, customersTotal: int, failureCount: int, failures: list<array{recordId: string, row: ?int, code: string}>, failureCode: ?string, canResume: bool, updatedAt: ?string} */
    public function snapshot(?string $source = null): array
    {
        $source ??= ApplicationSetupState::query()->whereKey(1)->value('quickbooks_import_mode')
            === ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_CSV
            ? QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
            : QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API;
        $run = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', $source)
            ->first();
        $syncEnabled = $this->syncControl->isEnabled();

        if ($run === null) {
            return [
                'status' => 'pending',
                'source' => $source,
                'customersImported' => 0,
                'sitesImported' => 0,
                'customersProcessed' => 0,
                'customersTotal' => 0,
                'failureCount' => 0,
                'failures' => [],
                'failureCode' => null,
                'canResume' => false,
                'updatedAt' => null,
            ];
        }

        $canResume = ($syncEnabled || $run->source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD)
            && ($run->status === QuickBooksInitialCustomerImport::STATUS_FAILED
            || ($run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS
                && $run->updated_at?->lessThanOrEqualTo(now()->subMinutes(self::STALE_AFTER_MINUTES))));

        $status = ! $syncEnabled
            && $run->source === QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API
            && $run->status === QuickBooksInitialCustomerImport::STATUS_IN_PROGRESS
            ? 'paused'
            : $run->status;

        return [
            'status' => $status,
            'source' => $run->source,
            'customersImported' => $run->imported_customers,
            'sitesImported' => $run->imported_sites,
            'customersProcessed' => $run->processed_customers,
            'customersTotal' => $run->total_customers,
            'failureCount' => $run->failures()->count(),
            'failures' => $run->failures()
                ->orderBy('id')
                ->limit(10)
                ->get(['quickbooks_customer_id', 'failure_code'])
                ->map(fn (QuickBooksInitialCustomerImportFailure $failure): array => [
                    'recordId' => $failure->source_record_id ?? $failure->quickbooks_customer_id ?? 'unknown',
                    'row' => $failure->source_row,
                    'code' => $failure->failure_code,
                ])
                ->all(),
            'failureCode' => $run->failure_code,
            'canResume' => $canResume,
            'updatedAt' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function assertCanImport(User $actor): void
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $claim = InstallationClaim::query()->whereKey(1)->first();
        $bws = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        if (! $state->onboarding_required) {
            abort(404);
        }

        if (! $state->quickbooks_sync_opted_in) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'Opt into QuickBooks synchronization in First-run setup before starting the Customer/Site import.',
            ]);
        }

        if ($state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'QuickBooks API import is disabled in CSV import mode.',
            ]);
        }

        if (! $this->syncControl->isEnabled()) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'QuickBooks synchronization is paused by an Admin. Resume it before importing.',
            ]);
        }

        abort_unless(
            $claim !== null
                && (string) $claim->claimed_by_user_id === (string) $actor->getKey()
                && $actor->status === 'active'
                && $actor->hasVerifiedEmail()
                && $actor->hasEnabledTwoFactorAuthentication()
                && $actor->can('integrations.manage'),
            403,
        );

        if (! ($bws?->isVerifiedForCurrentEnvironment() ?? false)
            || ! $this->api->isConnectedForCurrentEnvironment()) {
            throw ValidationException::withMessages([
                'quickbooks_import' => 'Verify the BWS runtime credentials and connect the intended QuickBooks company before importing.',
            ]);
        }
    }

    private function connectionForCurrentEnvironment(): object
    {
        if (! $this->api->isConnectedForCurrentEnvironment()) {
            throw new QuickBooksApiException('connection_unavailable', 409);
        }

        return DB::table('integration_connections')
            ->where('provider', 'quickbooks')
            ->where('environment', config('services.quickbooks.environment'))
            ->firstOrFail();
    }

    private function assertSameCompany(QuickBooksInitialCustomerImport $run, string $realmId): void
    {
        if ($run->realm_id !== $realmId) {
            throw new QuickBooksApiException('quickbooks_import_company_changed', 409);
        }
    }

    private function assertCustomerCount(int $count): void
    {
        if ($count > self::MAX_CUSTOMERS_PER_IMPORT) {
            throw new QuickBooksApiException('customer_import_limit_exceeded', 422);
        }
    }

    /** @param array<string, mixed> $parent */
    private function importParentAndSites(array $parent): int
    {
        $customerId = $parent['Id'] ?? null;
        $parentName = $parent['FullyQualifiedName'] ?? $parent['DisplayName'] ?? null;

        if (! is_string($customerId) || $customerId === ''
            || ($parent['Job'] ?? false) !== false
            || isset($parent['ParentRef']['value'])
            || ! is_string($parentName) || trim($parentName) === '') {
            throw new QuickBooksApiException('unsupported_customer_hierarchy', 422);
        }

        $children = $this->api->childCustomers($customerId, $parentName, self::MAX_CHILDREN_PER_CUSTOMER);

        $seenChildIds = [];

        foreach ($children as $child) {
            $childId = $child['Id'] ?? null;

            if (! is_string($childId) || $childId === '' || strlen($childId) > 64 || isset($seenChildIds[$childId])) {
                throw new QuickBooksApiException('invalid_or_duplicate_customer_id', 502);
            }

            $seenChildIds[$childId] = true;

            if (($child['Job'] ?? false) !== true
                || (string) ($child['ParentRef']['value'] ?? '') !== $customerId) {
                throw new QuickBooksApiException('nested_or_orphaned_customer_hierarchy', 422);
            }
        }

        DB::transaction(function () use ($customerId, $parent, $children): void {
            $customer = Customer::withArchived()
                ->where('quickbooks_id', $customerId)
                ->lockForUpdate()
                ->first();

            if ($customer !== null && $customer->quickbooks_write_request_id !== null) {
                throw new QuickBooksApiException('customer_has_pending_quickbooks_write', 409);
            }

            if ($customer === null) {
                try {
                    $customer = Customer::query()->create([
                        'client_id' => $this->nextClientId(),
                        'quickbooks_id' => $customerId,
                        'name' => $parent['DisplayName'] ?? 'QuickBooks Customer '.$customerId,
                    ]);
                } catch (QueryException $exception) {
                    if (Customer::withArchived()->where('quickbooks_id', $customerId)->exists()) {
                        throw new QuickBooksApiException('customer_import_conflict', 409);
                    }

                    throw $exception;
                }
            }

            $this->sync->importCustomerGraph($customer, $parent, $children);
        });

        return count($children);
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

    private function recordFailure(QuickBooksInitialCustomerImport $run, string $customerId, string $failureCode): void
    {
        QuickBooksInitialCustomerImportFailure::query()->updateOrCreate(
            ['import_id' => $run->getKey(), 'quickbooks_customer_id' => $customerId],
            ['failure_code' => $failureCode],
        );
    }

    private function finishCustomerSites(QuickBooksInitialCustomerImport $run): void
    {
        $run->refresh();
        $actor = $run->startedBy;
        $hasFailures = $run->failures()->exists();

        try {
            $currentCustomerCount = $this->api->topLevelCustomerCount();
        } catch (Throwable $exception) {
            $this->failRun($run, $this->failureCode($exception));

            return;
        }

        if ($currentCustomerCount !== $run->total_customers) {
            $this->failRun($run, 'qbo_customer_set_changed');

            return;
        }

        if ($hasFailures) {
            $run->forceFill([
                'status' => QuickBooksInitialCustomerImport::STATUS_FAILED,
                'failure_code' => 'qbo_customer_import_records_failed',
            ])->save();

            if ($actor !== null) {
                $this->steps->fail(
                    ApplicationSetupStep::INITIAL_QBO_IMPORT,
                    $actor,
                    'qbo_customer_import_records_failed',
                );
                $this->audit(
                    $actor,
                    $run,
                    'quickbooks_customer_site_import_failed',
                    'QuickBooks Customer/Site import stopped with unresolved records.',
                    $run->total_customers,
                    $run->imported_customers,
                    $run->imported_sites,
                    $run->failures()->count(),
                );
            }

            return;
        }

        $run->forceFill([
            'status' => QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
            'failure_code' => null,
            'customer_sites_completed_at' => now(),
        ])->save();

        if ($actor !== null && $run->total_customers > 0) {
            $this->steps->progress(
                ApplicationSetupStep::INITIAL_QBO_IMPORT,
                $actor,
                $run->total_customers,
                $run->total_customers,
            );
            $this->audit(
                $actor,
                $run,
                'quickbooks_customer_site_import_phase_completed',
                'QuickBooks Customer/Site import phase completed; read-only Item catalog sync remains a separate readiness gate.',
                $run->total_customers,
                $run->imported_customers,
                $run->imported_sites,
            );
        }
    }

    private function failRun(QuickBooksInitialCustomerImport $run, string $failureCode): void
    {
        $run->forceFill([
            'status' => QuickBooksInitialCustomerImport::STATUS_FAILED,
            'failure_code' => $failureCode,
        ])->save();

        if ($run->startedBy !== null) {
            $this->steps->fail(ApplicationSetupStep::INITIAL_QBO_IMPORT, $run->startedBy, $failureCode);
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof QuickBooksApiException
            && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->reason) === 1) {
            return $exception->reason;
        }

        return 'customer_import_failed';
    }

    private function shouldPauseForProviderFailure(Throwable $exception): bool
    {
        return $exception instanceof ConnectionException
            || ($exception instanceof QuickBooksApiException
                && ($exception->statusCode === 401
                    || $exception->statusCode === 403
                    || $exception->isRetryable()));
    }

    private function audit(
        User $actor,
        QuickBooksInitialCustomerImport $run,
        string $event,
        string $summary,
        int $totalCustomers,
        ?int $importedCustomers = null,
        ?int $importedSites = null,
        ?int $failureCount = null,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => QuickBooksInitialCustomerImport::class,
            'subject_id' => $run->getKey(),
            'event' => $event,
            'summary' => $summary,
            'metadata' => array_filter([
                'environment' => app()->environment(),
                'total_customers' => $totalCustomers,
                'imported_customers' => $importedCustomers,
                'imported_sites' => $importedSites,
                'failure_count' => $failureCount,
            ], fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }
}
