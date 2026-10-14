<?php

namespace App\Jobs;

use App\Exceptions\QuickBooksApiException;
use App\Models\Customer;
use App\Services\QuickBooksCustomerSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SyncQuickBooksCustomer implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 70;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $customerId) {}

    public function uniqueId(): string
    {
        return 'quickbooks-customer-'.$this->customerId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120, 300];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        return [
            new HoldQuickBooksJobsWhenSyncPaused,
            (new WithoutOverlapping('quickbooks-customer-data-'.$this->customerId))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
    }

    public function handle(QuickBooksCustomerSyncService $sync): void
    {
        try {
            $sync->sync($this->customerId);
        } catch (QuickBooksApiException $exception) {
            if ($exception->statusCode === 429) {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            if ($exception->isRetryable()) {
                throw $exception;
            }

            $this->markFailed($exception->reason);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception instanceof QuickBooksApiException
            ? $exception->reason
            : 'sync_failed';

        $this->markFailed($error);
    }

    private function markFailed(string $error): void
    {
        Customer::query()
            ->whereKey($this->customerId)
            ->whereNull('quickbooks_write_request_id')
            ->update([
                'quickbooks_sync_status' => 'failed',
                'quickbooks_sync_error' => substr($error, 0, 64),
            ]);
    }
}
