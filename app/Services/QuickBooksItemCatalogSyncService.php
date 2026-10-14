<?php

namespace App\Services;

use App\Exceptions\QuickBooksApiException;
use App\Jobs\ProcessQuickBooksItemCatalogSyncPage;
use App\Models\ApplicationSetupState;
use App\Models\ApplicationSetupStep;
use App\Models\AuditEvent;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\InstallationClaim;
use App\Models\IntegrationConnection;
use App\Models\Item;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\QuickBooksItemCatalogSync;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class QuickBooksItemCatalogSyncService
{
    private const MAX_ITEMS = 1_000_000;

    private const STALE_AFTER_MINUTES = 15;

    public function __construct(
        private readonly QuickBooksApiClient $api,
        private readonly QuickBooksOAuthService $oauth,
        private readonly ApplicationSetupStepService $steps,
        private readonly QuickBooksSyncControl $syncControl,
    ) {}

    /** @return array{items: int} */
    public function preview(User $actor): array
    {
        $this->assertCanSync($actor);
        $this->assertCustomerSitePhaseComplete();
        $count = $this->api->itemImportCount();

        if ($count > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'item_import' => 'The QuickBooks Item catalog exceeds the supported import limit.',
            ]);
        }

        return ['items' => $count];
    }

    public function start(User $actor, bool $allowRefresh = false): QuickBooksItemCatalogSync
    {
        $this->assertCanSync($actor);
        $this->assertCustomerSitePhaseComplete();
        $connection = $this->connection();
        $count = $this->api->itemImportCount();

        if ($count > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'item_import' => 'The QuickBooks Item catalog exceeds the supported import limit.',
            ]);
        }

        $startLock = Cache::lock('quickbooks-item-catalog-start-'.app()->environment(), 120);

        if (! $startLock->get()) {
            throw ValidationException::withMessages([
                'item_import' => 'Another request is already starting the Item catalog sync. Refresh and check its progress.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor, $connection, $count, $allowRefresh): QuickBooksItemCatalogSync {
                $run = QuickBooksItemCatalogSync::query()
                    ->where('environment', app()->environment())
                    ->lockForUpdate()
                    ->first();

                if ($run?->status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS) {
                    return $run;
                }

                if ($run?->status === QuickBooksItemCatalogSync::STATUS_COMPLETE && ! $allowRefresh) {
                    throw ValidationException::withMessages([
                        'item_import' => 'The initial QuickBooks Item catalog sync is complete.',
                    ]);
                }

                $run ??= new QuickBooksItemCatalogSync;
                $run->fill([
                    'environment' => app()->environment(),
                    'realm_id' => $connection->realm_id,
                    'status' => QuickBooksItemCatalogSync::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_items' => $count,
                    'processed_items' => 0,
                    'imported_items' => 0,
                    'failure_code' => null,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                ])->save();

                if (ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
                    $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);

                    if ($count > 0) {
                        $this->steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 0, $count);
                    }
                }

                $this->audit(
                    $actor,
                    $run,
                    'quickbooks_item_catalog_sync_started',
                    'QuickBooks Item catalog sync was explicitly started.',
                    $count,
                );

                return $run;
            });
        } finally {
            $startLock->release();
        }

        ProcessQuickBooksItemCatalogSyncPage::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksItemCatalogSyncPage::QUEUE)
            ->afterCommit();

        return $run;
    }

    public function resume(User $actor): QuickBooksItemCatalogSync
    {
        $this->assertCanSync($actor);
        $this->assertCustomerSitePhaseComplete();
        $connection = $this->connection();
        $count = $this->api->itemImportCount();

        if ($count > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'item_import' => 'The QuickBooks Item catalog exceeds the supported import limit.',
            ]);
        }

        $syncId = QuickBooksItemCatalogSync::query()
            ->where('environment', app()->environment())
            ->value('id');
        abort_if($syncId === null, 404);
        $lock = Cache::lock('quickbooks-item-catalog-sync-'.$syncId, 900);

        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'item_import' => 'An Item catalog batch is still running. Wait for it to finish before resuming.',
            ]);
        }

        try {
            $run = DB::transaction(function () use ($actor, $connection, $count): QuickBooksItemCatalogSync {
                $run = QuickBooksItemCatalogSync::query()
                    ->where('environment', app()->environment())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($run->realm_id !== $connection->realm_id) {
                    throw ValidationException::withMessages([
                        'item_import' => 'The authorized QuickBooks company changed. Preview the current company before syncing Items.',
                    ]);
                }

                if ($run->status === QuickBooksItemCatalogSync::STATUS_COMPLETE) {
                    throw ValidationException::withMessages([
                        'item_import' => 'The initial QuickBooks Item catalog sync is complete; use Refresh Item catalog to reconcile current values.',
                    ]);
                }

                if ($run->status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS
                    && $run->updated_at?->greaterThan(now()->subMinutes(self::STALE_AFTER_MINUTES))) {
                    throw ValidationException::withMessages([
                        'item_import' => 'The Item catalog sync is still running. Return shortly for updated progress.',
                    ]);
                }

                $run->forceFill([
                    'status' => QuickBooksItemCatalogSync::STATUS_IN_PROGRESS,
                    'next_start_position' => 1,
                    'total_items' => $count,
                    'processed_items' => 0,
                    'imported_items' => 0,
                    'failure_code' => null,
                    'started_by_user_id' => $actor->getKey(),
                    'started_at' => now(),
                ])->save();

                if (ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
                    $this->steps->begin(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);

                    if ($count > 0) {
                        $this->steps->progress(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor, 0, $count);
                    }
                }

                $this->audit(
                    $actor,
                    $run,
                    'quickbooks_item_catalog_sync_resumed',
                    'QuickBooks Item catalog sync was explicitly resumed.',
                    $count,
                );

                return $run;
            });
        } finally {
            $lock->release();
        }

        ProcessQuickBooksItemCatalogSyncPage::dispatch($run->getKey())
            ->onQueue(ProcessQuickBooksItemCatalogSyncPage::QUEUE)
            ->afterCommit();

        return $run;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $state = ApplicationSetupState::query()->whereKey(1)->first();
        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->where('environment', config('services.quickbooks.environment'))
            ->first();
        $bws = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();
        $run = QuickBooksItemCatalogSync::query()
            ->where('environment', app()->environment())
            ->first();

        if ($run !== null && $connection !== null && $run->realm_id !== $connection->realm_id) {
            $run = null;
        }

        $catalog = Item::query()
            ->when($connection !== null, fn ($query) => $query->where('quickbooks_realm_id', $connection->realm_id), fn ($query) => $query->whereRaw('1 = 0'));
        $status = $run?->status ?? QuickBooksItemCatalogSync::STATUS_PENDING;
        $syncEnabled = $this->syncControl->isEnabled();
        $canResume = $syncEnabled && $run !== null && ($run->status === QuickBooksItemCatalogSync::STATUS_FAILED
            || ($run->status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS
                && $run->updated_at?->lessThanOrEqualTo(now()->subMinutes(self::STALE_AFTER_MINUTES))));
        $customerImport = $state?->onboarding_required
            ? QuickBooksInitialCustomerImport::query()
                ->where('environment', app()->environment())
                ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
                ->first()
            : null;
        $customerPhaseComplete = ! ($state?->onboarding_required ?? false)
            || ($customerImport?->source === QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API
                && $connection !== null
                && $customerImport->realm_id === $connection->realm_id
                && in_array($customerImport->status, [
                    QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
                    QuickBooksInitialCustomerImport::STATUS_COMPLETE,
                ], true));
        $canSync = $syncEnabled
            && $this->oauth->apiModeEnabled()
            && ($bws?->isVerifiedForCurrentEnvironment() ?? false)
            && $this->api->isConnectedForCurrentEnvironment()
            && $customerPhaseComplete
            && $status !== QuickBooksItemCatalogSync::STATUS_IN_PROGRESS;

        return [
            'status' => ! $syncEnabled && $status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS ? 'paused' : $status,
            'totalItems' => $run?->total_items ?? 0,
            'processedItems' => $run?->processed_items ?? 0,
            'importedItems' => $run?->imported_items ?? 0,
            'activeItems' => (clone $catalog)->where('active', true)->count(),
            'selectableItems' => (clone $catalog)->where('active', true)->whereIn('type', Item::SELECTABLE_TYPES)->count(),
            'failureCount' => $run?->status === QuickBooksItemCatalogSync::STATUS_FAILED ? 1 : 0,
            'failures' => [],
            'failureCode' => $run?->failure_code,
            'canResume' => $canResume,
            'canSync' => $canSync,
            'updatedAt' => $run?->updated_at?->toIso8601String(),
        ];
    }

    public function processNextPage(QuickBooksItemCatalogSync $run): bool
    {
        $lock = Cache::lock('quickbooks-item-catalog-sync-'.$run->getKey(), 900);

        if (! $lock->get()) {
            return false;
        }

        try {
            $run->refresh();

            if ($run->status !== QuickBooksItemCatalogSync::STATUS_IN_PROGRESS) {
                return false;
            }

            try {
                $connection = $this->connection();

                if ($run->realm_id !== $connection->realm_id) {
                    throw new QuickBooksApiException('quickbooks_import_company_changed', 409);
                }

                $page = $this->api->itemPage($run->next_start_position);
            } catch (Throwable $exception) {
                $this->failRun($run, $this->failureCode($exception));

                return false;
            }

            if ($page['total_count'] !== null && $page['total_count'] !== $run->total_items) {
                $this->failRun($run, 'qbo_item_catalog_changed');

                return false;
            }

            if ($page['records'] === [] && $run->processed_items < $run->total_items) {
                $this->failRun($run, 'quickbooks_item_page_incomplete');

                return false;
            }

            $importedItems = 0;

            try {
                DB::transaction(function () use ($page, $run, &$importedItems): void {
                    $ids = [];

                    foreach ($page['records'] as $record) {
                        if (isset($ids[$record['id']])) {
                            throw new QuickBooksApiException('duplicate_quickbooks_item_id', 502);
                        }
                        $ids[$record['id']] = true;

                        Item::query()->updateOrCreate(
                            [
                                'quickbooks_realm_id' => $run->realm_id,
                                'quickbooks_id' => $record['id'],
                            ],
                            [
                                'name' => $record['name'],
                                'description' => $record['description'],
                                'type' => $record['type'],
                                'active' => $record['active'],
                                'last_synced_at' => now(),
                            ],
                        );
                        $importedItems++;
                    }
                });
            } catch (Throwable $exception) {
                $this->failRun($run, $this->failureCode($exception));

                return false;
            }

            $run->refresh();
            $processed = min($run->total_items, $run->processed_items + count($page['records']));
            $nextPosition = $run->next_start_position + count($page['records']);
            $run->forceFill([
                'next_start_position' => $nextPosition,
                'processed_items' => $processed,
                'imported_items' => $run->imported_items + $importedItems,
            ])->save();

            $actor = $run->startedBy;

            if ($actor !== null
                && $run->total_items > 0
                && ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
                $this->steps->progress(
                    ApplicationSetupStep::INITIAL_QBO_IMPORT,
                    $actor,
                    $processed,
                    $run->total_items,
                );
            }

            if ($processed >= $run->total_items
                || ($page['total_count'] !== null && $nextPosition > $page['total_count'])) {
                $this->finish($run);

                return false;
            }

            return true;
        } finally {
            $lock->release();
        }
    }

    public function markUnexpectedJobFailure(int $syncId): void
    {
        $run = QuickBooksItemCatalogSync::query()->find($syncId);

        if ($run !== null && $run->status === QuickBooksItemCatalogSync::STATUS_IN_PROGRESS) {
            $this->failRun($run, 'item_catalog_sync_job_failed');
        }
    }

    private function assertCanSync(User $actor): void
    {
        $state = ApplicationSetupState::query()->whereKey(1)->firstOrFail();
        $claim = InstallationClaim::query()->whereKey(1)->first();
        $bws = BitwardenSecretManagerConnection::query()
            ->where('environment', app()->environment())
            ->first();

        abort_unless(
            $actor->status === 'active'
                && $actor->hasVerifiedEmail()
                && $actor->hasEnabledTwoFactorAuthentication()
                && $actor->can('integrations.manage'),
            403,
        );

        if ($state->onboarding_required && ! $state->quickbooks_sync_opted_in) {
            throw ValidationException::withMessages([
                'item_import' => 'Opt into QuickBooks synchronization in First-run setup before syncing the Item catalog.',
            ]);
        }

        if ($state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API) {
            throw ValidationException::withMessages([
                'item_import' => 'QuickBooks Item synchronization is unavailable in CSV-only Customer/Site mode.',
            ]);
        }

        if (! $this->syncControl->isEnabled()) {
            throw ValidationException::withMessages([
                'item_import' => 'QuickBooks synchronization is paused by an Admin. Resume it before syncing Items.',
            ]);
        }

        if ($state->onboarding_required
            && ($claim === null || (string) $claim->claimed_by_user_id !== (string) $actor->getKey())) {
            abort(403);
        }

        if (! ($bws?->isVerifiedForCurrentEnvironment() ?? false)
            || ! $this->api->isConnectedForCurrentEnvironment()) {
            throw ValidationException::withMessages([
                'item_import' => 'Verify BWS and connect the intended QuickBooks company before syncing the Item catalog.',
            ]);
        }
    }

    private function assertCustomerSitePhaseComplete(): void
    {
        if (! ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
            return;
        }

        $connection = $this->connection();
        $customerImport = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->first();

        if ($customerImport?->source !== QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API
            || $customerImport->realm_id !== $connection->realm_id
            || ! in_array($customerImport->status, [
                QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE,
                QuickBooksInitialCustomerImport::STATUS_COMPLETE,
            ], true)) {
            throw ValidationException::withMessages([
                'item_import' => 'Complete the QuickBooks Customer/Site import before syncing Items.',
            ]);
        }
    }

    private function connection(): IntegrationConnection
    {
        $connection = IntegrationConnection::query()
            ->where('provider', 'quickbooks')
            ->where('environment', config('services.quickbooks.environment'))
            ->first();

        if ($connection === null || ! $this->api->isConnectedForCurrentEnvironment()) {
            throw new QuickBooksApiException('connection_unavailable', 409);
        }

        return $connection;
    }

    private function finish(QuickBooksItemCatalogSync $run): void
    {
        $run->refresh();

        try {
            $connection = $this->connection();

            if ($connection->realm_id !== $run->realm_id) {
                throw new QuickBooksApiException('quickbooks_import_company_changed', 409);
            }

            $currentCount = $this->api->itemImportCount();
        } catch (Throwable $exception) {
            $this->failRun($run, $this->failureCode($exception));

            return;
        }

        if ($currentCount !== $run->total_items) {
            $this->failRun($run, 'qbo_item_catalog_changed');

            return;
        }

        $actor = $run->startedBy;

        if (ApplicationSetupState::query()->whereKey(1)->value('onboarding_required') && $actor === null) {
            $this->failRun($run, 'item_catalog_sync_actor_missing');

            return;
        }

        $run->forceFill([
            'status' => QuickBooksItemCatalogSync::STATUS_COMPLETE,
            'failure_code' => null,
            'completed_at' => now(),
        ])->save();

        if ($actor === null) {
            return;
        }

        $this->audit(
            $actor,
            $run,
            'quickbooks_item_catalog_sync_completed',
            'QuickBooks Item catalog sync completed.',
            $run->total_items,
            $run->imported_items,
        );

        if (! ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')) {
            return;
        }

        $customerImport = QuickBooksInitialCustomerImport::query()
            ->where('environment', app()->environment())
            ->where('source', QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API)
            ->first();

        if ($customerImport !== null
            && $customerImport->status === QuickBooksInitialCustomerImport::STATUS_CUSTOMER_SITES_COMPLETE) {
            $customerImport->forceFill([
                'status' => QuickBooksInitialCustomerImport::STATUS_COMPLETE,
                'failure_code' => null,
            ])->save();
        }

        $this->steps->pass(ApplicationSetupStep::INITIAL_QBO_IMPORT, $actor);
    }

    private function failRun(QuickBooksItemCatalogSync $run, string $code): void
    {
        $run->forceFill([
            'status' => QuickBooksItemCatalogSync::STATUS_FAILED,
            'failure_code' => $code,
        ])->save();

        if (ApplicationSetupState::query()->whereKey(1)->value('onboarding_required')
            && $run->startedBy !== null) {
            $this->steps->fail(ApplicationSetupStep::INITIAL_QBO_IMPORT, $run->startedBy, $code);
        }
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            return 'item_catalog_persistence_failed';
        }

        if ($exception instanceof QuickBooksApiException
            && preg_match('/\A[a-z0-9_]{1,80}\z/D', $exception->reason) === 1) {
            return $exception->reason;
        }

        return 'item_catalog_sync_failed';
    }

    private function audit(
        User $actor,
        QuickBooksItemCatalogSync $run,
        string $event,
        string $summary,
        int $totalItems,
        ?int $importedItems = null,
    ): void {
        AuditEvent::query()->create([
            'actor_user_id' => $actor->getKey(),
            'subject_type' => QuickBooksItemCatalogSync::class,
            'subject_id' => $run->getKey(),
            'event' => $event,
            'summary' => $summary,
            'metadata' => array_filter([
                'environment' => app()->environment(),
                'realm_id' => $run->realm_id,
                'total_items' => $totalItems,
                'imported_items' => $importedItems,
            ], fn (mixed $value): bool => $value !== null),
            'created_at' => now(),
        ]);
    }
}
