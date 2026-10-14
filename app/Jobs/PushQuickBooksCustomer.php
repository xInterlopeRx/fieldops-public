<?php

namespace App\Jobs;

use App\Exceptions\QuickBooksApiException;
use App\Models\Customer;
use App\Services\QuickBooksCustomerWriteService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class PushQuickBooksCustomer implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 70;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $customerId,
        public readonly string $requestId,
    ) {}

    public function uniqueId(): string
    {
        return 'quickbooks-customer-write-'.$this->customerId.'-'.$this->requestId;
    }

    public function middleware(): array
    {
        return [
            new HoldQuickBooksJobsWhenSyncPaused,
            (new WithoutOverlapping('quickbooks-customer-data-'.$this->customerId))
                ->releaseAfter(5)
                ->expireAfter(120),
            (new WithoutOverlapping('quickbooks-name-list-writes'))
                ->releaseAfter(5)
                ->expireAfter(120),
        ];
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

    public function handle(QuickBooksCustomerWriteService $writes): void
    {
        try {
            $writes->pushCustomer($this->customerId, $this->requestId);
        } catch (QuickBooksApiException $exception) {
            if ($exception->statusCode === 429) {
                $this->release($exception->retryAfterSeconds ?? 60);

                return;
            }

            if ($exception->isRetryable()) {
                throw $exception;
            }

            $this->markFailed($exception->reason, retainRequest: false);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->markFailed($exception instanceof QuickBooksApiException
            ? $exception->reason
            : 'sync_failed');
    }

    private function markFailed(string $reason, bool $retainRequest = true): void
    {
        $updates = [
            'quickbooks_sync_status' => 'failed',
            'quickbooks_sync_error' => substr($reason, 0, 64),
        ];

        if (! $retainRequest) {
            $updates['quickbooks_write_request_id'] = null;
            $updates['quickbooks_write_fields'] = null;
            $updates['quickbooks_write_payload'] = null;
        }

        Customer::query()
            ->whereKey($this->customerId)
            ->where('quickbooks_write_request_id', $this->requestId)
            ->update($updates);
    }
}
