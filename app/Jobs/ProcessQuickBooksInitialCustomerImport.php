<?php

namespace App\Jobs;

use App\Models\QuickBooksInitialCustomerImport;
use App\Services\CsvCustomerSiteImportService;
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

    public function handle(
        QuickBooksInitialCustomerImportService $apiImport,
        CsvCustomerSiteImportService $csvImport,
    ): void {
        try {
            $run = QuickBooksInitialCustomerImport::query()->find($this->importId);

            if ($run === null) {
                return;
            }

            $hasMore = $run->source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD
                ? $csvImport->processNextBatch($run)
                : $apiImport->processNextCustomer($run);

            if (! $hasMore) {
                return;
            }

            self::dispatch($this->importId)->onQueue(self::QUEUE);
        } catch (\Throwable) {
            $run = QuickBooksInitialCustomerImport::query()->find($this->importId);

            if ($run?->source === QuickBooksInitialCustomerImport::SOURCE_CSV_UPLOAD) {
                $csvImport->markUnexpectedJobFailure($this->importId);
            } else {
                $apiImport->markUnexpectedJobFailure($this->importId);
            }
        }
    }
}
