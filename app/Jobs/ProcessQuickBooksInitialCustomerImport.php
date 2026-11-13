<?php

namespace App\Jobs;

use App\Models\QuickBooksInitialCustomerImport;
use App\Services\QuickBooksInitialCustomerImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessQuickBooksInitialCustomerImport implements ShouldQueue
{
    public const QUEUE = 'quickbooks-initial-import';

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 75;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function middleware(): array
    {
        return [new HoldQuickBooksJobsWhenSyncPaused];
    }

    public function handle(QuickBooksInitialCustomerImportService $apiImport): void
    {
        try {
            $run = QuickBooksInitialCustomerImport::query()->find($this->importId);

            if ($run === null) {
                return;
            }

            if (! $apiImport->processNextCustomer($run)) {
                return;
            }

            self::dispatch($this->importId)->onQueue(self::QUEUE);
        } catch (\Throwable) {
            $apiImport->markUnexpectedJobFailure($this->importId);
        }
    }
}
