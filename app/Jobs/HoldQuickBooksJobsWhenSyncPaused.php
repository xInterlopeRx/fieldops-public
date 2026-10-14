<?php

namespace App\Jobs;

use App\Services\QuickBooksSyncControl;
use Closure;

class HoldQuickBooksJobsWhenSyncPaused
{
    public function handle(object $job, Closure $next): void
    {
        $syncControl = app(QuickBooksSyncControl::class);

        if ($syncControl->shouldPause($job)) {
            $syncControl->holdCurrentJob($job);

            return;
        }

        $next($job);
    }
}
