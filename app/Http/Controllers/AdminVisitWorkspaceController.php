<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAdminVisitWorkspaceRequest;
use App\Models\AuditEvent;
use App\Models\Visit;
use App\Models\WorkOrderAttachment;
use App\Services\VisitJobItemService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminVisitWorkspaceController extends Controller
{
    public function show(Visit $visit, Request $request, VisitJobItemService $itemUsage): JsonResponse
    {
        $visit->load([
            'workOrder:id,site_id,job_number,title',
            'workOrder.site:id,customer_id,name,address',
            'workOrder.site.customer:id,name,company_name',
            'assignedUsers:id,name',
            'attachments' => fn ($query) => $query->select('id', 'visit_id', 'original_name', 'mime_type', 'size', 'created_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(100),
            'jobItems.item:id,type',
        ]);
        $activity = AuditEvent::query()
            ->where(function (Builder $query) use ($visit): void {
                $query->where(function (Builder $subjectQuery) use ($visit): void {
                    $subjectQuery->where('subject_type', $visit->getMorphClass())
                        ->where('subject_id', $visit->getKey());
                })->orWhere(function (Builder $subjectQuery) use ($visit): void {
                    $subjectQuery->where('subject_type', (new WorkOrderAttachment)->getMorphClass())
                        ->whereIn('subject_id', WorkOrderAttachment::withArchived()->where('visit_id', $visit->getKey())->select('id'));
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get(['id', 'actor_user_id', 'subject_type', 'subject_id', 'event', 'summary', 'created_at']);

        return response()->json([
            'data' => [
                'visit' => [
                    'id' => $visit->id,
                    'type' => $visit->visit_type,
                    'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
                    'durationMinutes' => $visit->duration_minutes,
                    'scope' => $visit->scope,
                    'notes' => $visit->notes,
                    'jobId' => $visit->workOrder?->id,
                    'jobNumber' => $visit->workOrder?->job_number,
                    'jobTitle' => $visit->workOrder?->title,
                    'siteName' => $visit->workOrder?->site?->name,
                    'siteAddress' => $visit->workOrder?->site?->address,
                    'customerName' => $visit->workOrder?->site?->customer?->company_name ?: $visit->workOrder?->site?->customer?->name,
                    'itemsEditable' => $itemUsage->canEditItems(
                        $request->user(),
                        $itemUsage->catalogReady(),
                        $visit->workOrder?->status !== config('work_orders.completion_status'),
                        true,
                    ),
                ],
                'attachments' => $visit->attachments->map(fn (WorkOrderAttachment $attachment): array => [
                    'id' => $attachment->id,
                    'name' => $attachment->original_name,
                    'mimeType' => $attachment->mime_type,
                    'size' => $attachment->size,
                    'url' => route('admin.jobs.attachments.show', $attachment),
                ])->values(),
                'items' => $visit->jobItems->map(fn ($jobItem): array => [
                    'id' => (int) $jobItem->getKey(),
                    'itemId' => (int) $jobItem->item_id,
                    'name' => $jobItem->item_name_snapshot,
                    'description' => $jobItem->item_description_snapshot,
                    'type' => $jobItem->item?->type,
                    'quantity' => (string) $jobItem->quantity,
                ])->values(),
                'activity' => $activity->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary,
                    'actor' => $event->actor?->name ?? 'Former user',
                    'createdAt' => $event->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function update(UpdateAdminVisitWorkspaceRequest $request, Visit $visit): JsonResponse
    {
        $visit->update($request->validated());

        return response()->json(['message' => 'Visit saved.']);
    }
}
