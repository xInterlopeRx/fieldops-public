<?php

namespace App\Jobs;

use App\Models\FieldPulseImportRun;
use App\Services\FieldPulse\FieldPulseCustomerImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Processes one bounded FieldPulse import page, then re-dispatches itself until
 * the run completes. Read-only against FieldPulse.
 */
class ProcessFieldPulseCustomerImport implements ShouldQueue
{
    // Uses the default queue so no worker/compose change is required; each job is
    // a single bounded provider page.
    public const QUEUE = 'default';

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 75;

    public int $tries = 1;

    public function __construct(public readonly int $importId) {}

    public function handle(FieldPulseCustomerImportService $import): void
    {
        try {
            $run = FieldPulseImportRun::query()->find($this->importId);

            if ($run === null) {
                return;
            }

            if (! $import->processNextPage($this->importId)) {
                return;
            }

            self::dispatch($this->importId)->onQueue(self::QUEUE);
        } catch (Throwable) {
            $import->markUnexpectedJobFailure($this->importId);
        }
    }
}
