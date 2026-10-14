<?php

namespace App\Jobs;

use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\GraphNotificationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class BuildWorkOrderCompletionReport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $workOrderId,
        public readonly ?int $completionVersion = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->workOrderId.':'.($this->completionVersion ?? 'latest');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(GraphNotificationService $notifications): void
    {
        $workOrder = WorkOrder::query()
            ->with([
                'site.customer',
                'clientContact',
                'assignedUsers',
                'visits.assignedUsers',
                'visits.attachments',
                'visits.jobItems.item',
                'attachments',
            ])
            ->findOrFail($this->workOrderId);

        if ($workOrder->status !== config('work_orders.completion_status') || $workOrder->completed_at === null) {
            return;
        }

        if ($this->completionVersion !== null && $workOrder->completed_at->timestamp !== $this->completionVersion) {
            return;
        }

        $initialVisit = $workOrder->visits->firstWhere('visit_type', 'initial')
            ?? $workOrder->visits->sortBy('scheduled_at')->first();

        if (! $initialVisit instanceof Visit || ! $workOrder->completed_by) {
            $notifications->deliveryBlocked($workOrder, 'report_context_unavailable');

            return;
        }

        $reportBytes = Pdf::loadView('exports.work-order', [
            'workOrder' => $workOrder,
            'completionReport' => true,
        ])->setPaper('letter')->output();
        $billingReportBytes = Pdf::loadView('exports.billing-work-order', [
            'workOrder' => $workOrder,
        ])->setPaper('letter')->output();

        $filename = 'Completion Report - '.$workOrder->job_number.' - '.$workOrder->completed_at->format('Ymd-His').'.pdf';
        $path = 'work-orders/reports/'.sha1($workOrder->id.'|'.$workOrder->completed_at->timestamp).'.pdf';
        Storage::disk('local')->put($path, $reportBytes);

        $report = WorkOrderAttachment::query()->updateOrCreate(
            ['disk' => 'local', 'path' => $path],
            [
                'work_order_id' => $workOrder->id,
                'visit_id' => $initialVisit->id,
                'uploaded_by' => $workOrder->completed_by,
                'kind' => 'documents',
                'original_name' => $filename,
                'mime_type' => 'application/pdf',
                'size' => strlen($reportBytes),
            ],
        );

        $billingFilename = 'Billing Job Report - '.$workOrder->job_number.' - '.$workOrder->completed_at->format('Ymd-His').'.pdf';
        $billingPath = 'work-orders/reports/billing/'.sha1($workOrder->id.'|'.$workOrder->completed_at->timestamp).'.pdf';
        Storage::disk('local')->put($billingPath, $billingReportBytes);

        $billingReport = WorkOrderAttachment::query()->updateOrCreate(
            ['disk' => 'local', 'path' => $billingPath],
            [
                'work_order_id' => $workOrder->id,
                'visit_id' => $initialVisit->id,
                'uploaded_by' => $workOrder->completed_by,
                'kind' => 'documents',
                'original_name' => $billingFilename,
                'mime_type' => 'application/pdf',
                'size' => strlen($billingReportBytes),
            ],
        );

        $notifications->completionReportReady($workOrder, $report, $billingReport);
        $workOrder->update(['completion_report_generated_at' => now()]);
    }
}
