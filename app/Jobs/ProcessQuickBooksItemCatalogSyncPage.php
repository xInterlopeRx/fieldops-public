<?php

namespace App\Jobs;

use App\Models\QuickBooksItemCatalogSync;
use App\Services\QuickBooksItemCatalogSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessQuickBooksItemCatalogSyncPage implements ShouldQueue
{
    public const QUEUE = 'quickbooks-initial-import';

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 75;

    public int $tries = 1;

    public function __construct(public readonly int $syncId) {}

    public function middleware(): array
    {
        return [new HoldQuickBooksJobsWhenSyncPaused];
    }

    public function handle(QuickBooksItemCatalogSyncService $sync): void
    {
        try {
            $run = QuickBooksItemCatalogSync::query()->find($this->syncId);

            if ($run === null || ! $sync->processNextPage($run)) {
                return;
            }

            self::dispatch($this->syncId)->onQueue(self::QUEUE);
        } catch (\Throwable) {
            $sync->markUnexpectedJobFailure($this->syncId);
        }
    }
}
