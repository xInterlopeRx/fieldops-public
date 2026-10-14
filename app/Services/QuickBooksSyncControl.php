<?php

namespace App\Services;

use App\Jobs\ProcessQuickBooksInitialCustomerImport;
use App\Models\ApplicationSetupState;
use App\Models\BitwardenSecretManagerConnection;
use App\Models\QuickBooksInitialCustomerImport;
use App\Models\User;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class QuickBooksSyncControl
{
    public const SYNC_QUEUE = 'quickbooks-sync';

    public const CUSTOMER_IMPORT_QUEUE = 'quickbooks-initial-import';

    private const PAUSED_QUEUE_PREFIX = 'quickbooks-paused:';

    public function isEnabled(): bool
    {
        return (bool) (ApplicationSetupState::query()->whereKey(1)->value('quickbooks_sync_enabled') ?? false);
    }

    public function queueForDispatch(): string
    {
        return $this->isEnabled() ? self::SYNC_QUEUE : self::pausedQueue(self::SYNC_QUEUE);
    }

    public function canControlQueue(): bool
    {
        if (config('queue.default') !== 'database') {
            return false;
        }

        $connection = config('queue.connections.database.connection') ?? config('database.default');
        $databaseConnection = config('database.default');
        $table = config('queue.connections.database.table', 'jobs');

        return $connection === $databaseConnection
            && Schema::connection($databaseConnection)->hasTable($table);
    }

    public function setEnabled(
        bool $enabled,
        User $actor,
        ActivityLogService $activityLog,
        QuickBooksApiClient $quickBooks,
    ): bool {
        if (! $this->canControlQueue()) {
            throw ValidationException::withMessages([
                'quickbooks_sync' => 'QuickBooks sync pause is unavailable for the configured queue. Ask an administrator to use the managed database queue.',
            ]);
        }

        return DB::transaction(function () use ($enabled, $actor, $activityLog, $quickBooks): bool {
            $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            if ($enabled && $state->onboarding_required) {
                $bwsConnection = BitwardenSecretManagerConnection::query()
                    ->where('environment', app()->environment())
                    ->first();

                if (! $state->quickbooks_sync_opted_in
                    || $state->quickbooks_import_mode !== ApplicationSetupState::QUICKBOOKS_IMPORT_MODE_API
                    || ! ($bwsConnection?->isVerifiedForCurrentEnvironment() ?? false)
                    || ! $quickBooks->isConnectedForCurrentEnvironment()) {
                    throw ValidationException::withMessages([
                        'quickbooks_sync' => 'Opt into QuickBooks, verify both Intuit app credentials in BWS, and connect the intended QuickBooks company before enabling synchronization.',
                    ]);
                }
            }

            if ((bool) $state->quickbooks_sync_enabled === $enabled) {
                return false;
            }

            $state->forceFill(['quickbooks_sync_enabled' => $enabled])->save();

            if ($enabled) {
                $this->resumeHeldJobs();
                $event = 'quickbooks_sync_resumed';
            } else {
                $this->holdReadySyncJobs();
                $event = 'quickbooks_sync_paused';
            }

            $activityLog->record($state, $event, [], $actor);

            return true;
        });
    }

    public function shouldPause(object $job): bool
    {
        if ($this->isEnabled()) {
            return false;
        }

        if ($job instanceof ProcessQuickBooksInitialCustomerImport) {
            return QuickBooksInitialCustomerImport::query()
                ->whereKey($job->importId)
                ->value('source') === QuickBooksInitialCustomerImport::SOURCE_QUICKBOOKS_API;
        }

        return true;
    }

    public function holdCurrentJob(object $job): void
    {
        $queueJob = $job->job ?? null;

        if (! $queueJob instanceof DatabaseJob || ! $this->canControlQueue()) {
            if ($queueJob !== null && method_exists($queueJob, 'release')) {
                $queueJob->release(300);
            }

            return;
        }

        $originalQueue = $queueJob->getQueue();

        if (str_starts_with($originalQueue, self::PAUSED_QUEUE_PREFIX)) {
            $queueJob->release(300);

            return;
        }

        $connectionName = $queueJob->getConnectionName();
        $databaseQueue = Queue::connection($connectionName);
        $databaseConnectionName = config('queue.connections.'.$connectionName.'.connection')
            ?? config('database.default');
        $pausedQueue = self::PAUSED_QUEUE_PREFIX.$originalQueue;

        DB::connection($databaseConnectionName)->transaction(function () use ($databaseQueue, $queueJob, $pausedQueue): void {
            $databaseQueue->pushRaw($queueJob->getRawBody(), $pausedQueue);
            $queueJob->delete();
        });
    }

    /** @return array{count: int, truncated: bool} */
    public function heldJobsSummary(): array
    {
        if (! $this->canControlQueue()) {
            return ['count' => 0, 'truncated' => false];
        }

        $connection = config('database.default');
        $table = config('queue.connections.database.table', 'jobs');
        $ids = DB::connection($connection)->table($table)
            ->whereIn('queue', $this->pausedQueueNames())
            ->limit(101)
            ->pluck('id');

        return ['count' => min(100, $ids->count()), 'truncated' => $ids->count() > 100];
    }

    private function holdReadySyncJobs(): void
    {
        $connection = config('database.default');
        $table = config('queue.connections.database.table', 'jobs');

        DB::connection($connection)->table($table)
            ->where('queue', self::SYNC_QUEUE)
            ->whereNull('reserved_at')
            ->update(['queue' => self::pausedQueue(self::SYNC_QUEUE)]);
    }

    private function resumeHeldJobs(): void
    {
        $connection = config('database.default');
        $table = config('queue.connections.database.table', 'jobs');

        foreach (['default', self::SYNC_QUEUE, self::CUSTOMER_IMPORT_QUEUE] as $queue) {
            DB::connection($connection)->table($table)
                ->where('queue', self::pausedQueue($queue))
                ->update(['queue' => $queue]);
        }
    }

    private function pausedQueueNames(): array
    {
        return array_map(
            self::pausedQueue(...),
            ['default', self::SYNC_QUEUE, self::CUSTOMER_IMPORT_QUEUE],
        );
    }

    private static function pausedQueue(string $queue): string
    {
        return self::PAUSED_QUEUE_PREFIX.$queue;
    }
}
