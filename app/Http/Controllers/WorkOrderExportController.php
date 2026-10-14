<?php

namespace App\Http\Controllers;

use App\Models\WorkOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WorkOrderExportController extends Controller
{
    public function __invoke(Request $request, WorkOrder $workOrder): Response
    {
        $workOrder->load([
            'site.customer',
            'visits.assignedUsers',
            'visits.attachments',
            'assignedUsers',
            'attachments',
        ]);

        $userId = $request->user()->id;
        $canExport = $request->user()->can('work_orders.view')
            && ($workOrder->assignedUsers->contains('id', $userId)
            || $workOrder->visits->contains(
                fn ($visit): bool => $visit->assignedUsers->contains('id', $userId),
            ));

        abort_unless($canExport, 403);

        return Pdf::loadView('exports.work-order', [
            'workOrder' => $workOrder,
        ])
            ->setPaper('letter')
            ->download("work-order-{$workOrder->id}.pdf");
    }
}
