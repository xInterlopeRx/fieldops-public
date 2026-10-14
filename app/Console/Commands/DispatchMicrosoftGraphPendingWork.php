<?php

namespace App\Console\Commands;

use App\Jobs\BuildWorkOrderCompletionReport;
use App\Jobs\PollVisitMicrosoftGraphRsvps;
use App\Jobs\SendGraphNotification;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\GraphNotificationOutbox;
use App\Models\Visit;
use App\Models\WorkOrder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('graph:dispatch-pending-work')]
#[Description('Dispatch pending Microsoft Graph event, RSVP, report, and email work.')]
class DispatchMicrosoftGraphPendingWork extends Command
{
    public function handle(): int
    {
        $calendarWritesEnabled = (bool) config('services.microsoft_graph.calendar_writes_enabled');
        $mailWritesEnabled = (bool) config('services.microsoft_graph.writes_enabled');

        if (! $mailWritesEnabled && ! $calendarWritesEnabled) {
            $this->comment('Graph event and mail writes are disabled; report generation recovery may still run.');
        }

        $eventCount = 0;
        if ($calendarWritesEnabled) {
            Visit::query()
                ->whereIn('graph_sync_status', ['pending', 'pending_configuration'])
                ->orderBy('id')
                ->limit(250)
                ->pluck('id')
                ->each(function (int $visitId) use (&$eventCount): void {
                    SyncVisitMicrosoftGraphEvent::dispatch($visitId);
                    $eventCount++;
                });
        }

        $outboxCount = 0;
        if ($mailWritesEnabled) {
            GraphNotificationOutbox::query()
                ->whereIn('status', ['pending', 'pending_configuration'])
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->orderBy('created_at')
                ->limit(250)
                ->pluck('id')
                ->each(function (string $outboxId) use (&$outboxCount): void {
                    SendGraphNotification::dispatch($outboxId);
                    $outboxCount++;
                });
        }

        $rsvpCount = 0;
        if ($calendarWritesEnabled) {
            Visit::query()
                ->where('graph_sync_status', 'synced')
                ->whereNotNull('microsoft_graph_event_id')
                ->where('scheduled_at', '>=', now()->subDay())
                ->where(fn ($query) => $query->whereNull('rsvp_polled_at')->orWhere('rsvp_polled_at', '<=', now()->subMinutes(15)))
                ->orderBy('scheduled_at')
                ->limit(250)
                ->pluck('id')
                ->each(function (int $visitId) use (&$rsvpCount): void {
                    PollVisitMicrosoftGraphRsvps::dispatch($visitId);
                    $rsvpCount++;
                });
        }

        $reportCount = 0;
        WorkOrder::query()
            ->where('status', config('work_orders.completion_status'))
            ->whereNotNull('completed_at')
            ->where(fn ($query) => $query
                ->whereNull('completion_report_generated_at')
                ->orWhereColumn('completion_report_generated_at', '<', 'completed_at'))
            ->orderBy('completed_at')
            ->limit(100)
            ->get(['id', 'completed_at'])
            ->each(function (WorkOrder $workOrder) use (&$reportCount): void {
                BuildWorkOrderCompletionReport::dispatch($workOrder->id, $workOrder->completed_at?->timestamp);
                $reportCount++;
            });

        $this->info("Dispatched {$eventCount} event syncs, {$rsvpCount} RSVP polls, {$reportCount} reports, and {$outboxCount} emails.");

        return self::SUCCESS;
    }
}
