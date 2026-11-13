<?php

namespace App\Jobs;

use App\Models\QuickBooksInitialCustomerImport;
use App\Services\QuickBooks\QuickBooksXlsxCustomerImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Imports the uploaded QuickBooks Customer Contact List workbook in one pass.
 */
class ProcessQuickBooksXlsxImport implements ShouldQueue
{
    public const QUEUE = 'default';

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function handle(QuickBooksXlsxCustomerImportService $import): void
    {
        try {
            if (QuickBooksInitialCustomerImport::query()->whereKey($this->importId)->doesntExist()) {
                return;
            }

            $import->import($this->importId);
        } catch (Throwable) {
            $import->markUnexpectedJobFailure($this->importId);
        }
    }
}
