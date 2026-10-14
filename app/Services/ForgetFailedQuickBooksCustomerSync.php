<?php

namespace App\Services;

use App\Jobs\SyncQuickBooksCustomer;
use App\Models\ApplicationSetupState;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ForgetFailedQuickBooksCustomerSync
{
    public function forget(User $actor, int $failedJobId): void
    {
        $failedConfig = config('queue.failed', []);
        $driver = $failedConfig['driver'] ?? null;
        $database = is_string($failedConfig['database'] ?? null)
            ? $failedConfig['database']
            : config('database.default');
        $table = is_string($failedConfig['table'] ?? null) ? $failedConfig['table'] : 'failed_jobs';

        if (! in_array($driver, ['database', 'database-uuids'], true)
            || ! is_string($database)
            || $database !== config('database.default')) {
            throw new ConflictHttpException('Forgetting this failure is unavailable for the configured queue storage.');
        }

        $schema = Schema::connection($database);
        if (! $schema->hasTable($table)
            || ! $schema->hasColumns($table, ['id', 'queue', 'payload', 'failed_at'])) {
            throw new ConflictHttpException('The failed queue record is no longer available. Refresh and review the current list.');
        }

        /** @var Connection $connection */
        $connection = DB::connection($database);

        $connection->transaction(function () use ($actor, $failedJobId, $table, $connection): void {
            $state = ApplicationSetupState::query()->whereKey(1)->lockForUpdate()->firstOrFail();
            $failedJob = $connection->table($table)
                ->where('id', $failedJobId)
                ->lockForUpdate()
                ->first(['id', 'queue', 'payload', 'failed_at']);

            if ($failedJob === null) {
                throw new NotFoundHttpException('The failed queue record no longer exists. Refresh and review the current list.');
            }

            $payload = is_string($failedJob->payload) ? json_decode($failedJob->payload, true) : null;
            $displayName = is_array($payload) ? ($payload['displayName'] ?? null) : null;

            if ($displayName !== SyncQuickBooksCustomer::class) {
                throw new ConflictHttpException('Only a failed QuickBooks customer sync record can be forgotten from this action.');
            }

            $deleted = $connection->table($table)->where('id', $failedJobId)->delete();
            if ($deleted !== 1) {
                throw new ConflictHttpException('The failed queue record changed before it could be forgotten. Refresh and review the current list.');
            }

            AuditEvent::query()->create([
                'actor_user_id' => $actor->getKey(),
                'subject_type' => $state->getMorphClass(),
                'subject_id' => $state->getKey(),
                'event' => 'failed_queue_record_forgotten',
                'summary' => $actor->name.' permanently forgot failed QuickBooks customer sync queue record #'.$failedJobId.' without retrying it.',
                'metadata' => [
                    'failed_job_id' => $failedJobId,
                    'queue' => (string) $failedJob->queue,
                    'job_type' => 'quickbooks_customer_sync',
                    'failed_at' => $failedJob->failed_at === null ? null : (string) $failedJob->failed_at,
                    'retry_performed' => false,
                    'provider_call_performed' => false,
                    'serialized_payload_exposed' => false,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
