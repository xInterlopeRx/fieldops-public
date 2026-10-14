<?php

namespace App\Http\Controllers;

use App\Http\Requests\RescheduleVisitRequest;
use App\Http\Requests\StoreRevisitRequest;
use App\Http\Requests\StoreWorkOrderRequest;
use App\Http\Requests\UpdateVisitScopeRequest;
use App\Http\Requests\UpdateWorkOrderRequest;
use App\Http\Requests\UpdateWorkOrderStatusRequest;
use App\Http\Requests\UploadWorkOrderAttachmentsRequest;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\AssignmentService;
use App\Services\AttachmentLifecycleService;
use App\Services\AttachmentListService;
use App\Services\GraphNotificationService;
use App\Services\QuickBooksCustomerWriteService;
use App\Services\SharePointAttachmentUploadService;
use App\Services\VisitJobItemService;
use App\Services\VisitSchedulingService;
use App\Services\WorkOrderStatusHistoryService;
use App\Services\WorkOrderTimerAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class WorkOrderController extends Controller
{
    public function show(
        Request $request,
        WorkOrder $workOrder,
        AttachmentListService $attachmentLists,
        VisitJobItemService $visitItems,
    ): JsonResponse {
        $this->authorizeAccess($request, $workOrder);

        $workOrder->load([
            'site.customer.sites',
            'site.customer.contacts',
            'project',
            'clientContact',
            'assignedUsers:id',
            'visits' => fn ($query) => $query
                ->orderByRaw("CASE WHEN visit_type = 'initial' THEN 0 ELSE 1 END")
                ->orderBy('scheduled_at')
                ->orderBy('id'),
            'visits.assignedUsers',
            'statusHistory.actor:id,name',
            'visits.attachments',
            'visits.jobItems.item:id,type',
        ]);

        $catalogReady = $visitItems->catalogReady();
        $assignedToJob = $workOrder->assignedUsers->contains('id', $request->user()->getKey());
        $workOrder->setRelation('visits', $workOrder->visits->map(function (Visit $visit) use ($request, $workOrder, $visitItems, $catalogReady, $assignedToJob): array {
            $assignedToVisit = $visit->assignedUsers->contains('id', $request->user()->getKey());

            return [
                'id' => $visit->id,
                'visit_type' => $visit->visit_type,
                'initial_visit_id' => $visit->initial_visit_id,
                'scheduled_at' => $visit->scheduled_at?->toIso8601String(),
                'duration_minutes' => $visit->duration_minutes,
                'scope' => $visit->scope,
                'notes' => $visit->notes,
                'items_editable' => $visitItems->canEditItems(
                    $request->user(),
                    $catalogReady,
                    $workOrder->status !== config('work_orders.completion_status'),
                    $assignedToJob || $assignedToVisit,
                ),
                'items' => $visit->jobItems->map(fn ($jobItem): array => [
                    'id' => (int) $jobItem->getKey(),
                    'itemId' => (int) $jobItem->item_id,
                    'name' => $jobItem->item_name_snapshot,
                    'description' => $jobItem->item_description_snapshot,
                    'type' => $jobItem->item?->type,
                    'quantity' => (string) $jobItem->quantity,
                ])->values()->all(),
            ];
        })->values());

        $workOrder->setRelation('attachments', collect($attachmentLists->forCalendarJob($workOrder)));
        $statusHistory = $workOrder->statusHistory->map(fn ($entry): array => [
            'id' => $entry->id,
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'reason' => $entry->reason,
            'changed_by' => $entry->actor?->name,
            'created_at' => $entry->created_at,
        ])->values();
        $workOrder->unsetRelation('statusHistory');
        $workOrder->setAttribute('status_history', $statusHistory);

        return response()->json(['data' => $workOrder]);
    }

    public function store(
        StoreWorkOrderRequest $request,
        QuickBooksCustomerWriteService $quickBooksWrites,
        GraphNotificationService $notifications,
        AssignmentService $assignments,
        WorkOrderStatusHistoryService $statusHistory,
        VisitSchedulingService $scheduling,
        SharePointAttachmentUploadService $attachmentUploads,
        AttachmentLifecycleService $attachmentEvents,
    ): RedirectResponse {
        $scheduledAt = $request->string('status')->toString() === 'scheduled'
            ? $request->date('scheduled_at')
            : null;
        $conflict = $scheduledAt === null ? null : $scheduling->conflictFor($request->user(), $scheduledAt);

        if ($conflict !== null && ! $request->boolean('scheduling_override')) {
            return back()->withErrors(['scheduled_at' => $conflict]);
        }

        $attachmentUploadStarted = false;

        try {
            [$workOrder, $history] = DB::transaction(function () use ($request, $assignments, $statusHistory, $attachmentUploads, &$attachmentUploadStarted): array {
                $customer = $this->resolveCustomer($request);
                $site = $this->resolveSite($request, $customer);
                $customer->loadMissing('contacts');
                $contactFields = $this->contactFields($request, $customer);
                $workOrder = WorkOrder::create([
                    'site_id' => $site->id,
                    'job_number' => $this->nextJobNumber(),
                    'title' => $request->string('title')->toString(),
                    'subtitle' => $request->input('subtitle'),
                    'description' => $request->input('description'),
                    'scope' => $request->string('scope')->toString(),
                    'client_completion_summary' => $request->input('client_completion_summary'),
                    ...$contactFields,
                    'job_notes' => $request->input('job_notes'),
                    'field_notes' => $request->input('field_notes'),
                    'status' => $request->string('status')->toString(),
                    'completed_at' => $request->string('status')->toString() === config('work_orders.completion_status') ? now() : null,
                    'completed_by' => $request->string('status')->toString() === config('work_orders.completion_status') ? $request->user()->id : null,
                ]);

                $assignments->sync($workOrder, [$request->user()->id], $request->user());
                $history = $statusHistory->record(
                    $workOrder,
                    null,
                    $workOrder->status,
                    $request->user(),
                    $request->input('pending_reason'),
                );
                if ($workOrder->status === 'scheduled' && $request->date('scheduled_at') !== null) {
                    $visit = $workOrder->visits()->create([
                        'scheduled_at' => $request->date('scheduled_at'),
                        'notes' => $request->input('field_notes'),
                        'scope' => $workOrder->scope,
                        'graph_sync_status' => 'pending',
                        'metadata' => ['created_from' => 'work_order_form'],
                    ]);
                    $visit->assignedUsers()->syncWithoutDetaching([$request->user()->id]);
                }
                if (($request->file('files', []) ?? []) !== []) {
                    $attachmentUploadStarted = true;
                    $this->storeAttachments($request, $workOrder, $attachmentUploads);
                }

                return [$workOrder, $history];
            });
        } catch (Throwable $exception) {
            if ($attachmentUploadStarted) {
                $attachmentEvents->failed($request->user(), $request->user(), 'attachment_operation_failed', 'job_creation');
            }

            throw $exception;
        }

        $visit = $workOrder->visits()->oldest('id')->first();
        if ($visit !== null && config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
        }
        if ($history !== null) {
            $notifications->jobCreated($workOrder, $history);
        }
        if ($history !== null && $workOrder->status === 'scheduled') {
            $notifications->jobScheduled($workOrder, $history);
        }
        if ($workOrder->status === config('work_orders.completion_status')) {
            $notifications->statusChanged(
                $workOrder,
                config('work_orders.default_status'),
                $workOrder->status,
            );
        }

        $workOrder->load('site.customer');
        $site = $workOrder->site;
        $siteWriteStatus = null;

        if (
            $site !== null &&
            $site->quickbooks_id === null &&
            ($site->metadata['created_from'] ?? null) === 'work_order_form'
        ) {
            $siteWriteStatus = $quickBooksWrites->queueSite($site);
        }

        $message = "Job {$workOrder->job_number} created.";

        if ($siteWriteStatus === 'pending') {
            $message .= ' New site sync to QuickBooks queued.';
        } elseif ($siteWriteStatus === 'paused') {
            $message .= ' QuickBooks sync is paused; the new Site is queued and held until sync resumes.';
        }

        return redirect()->route('calendar')->with('success', $message);
    }

    public function update(
        UpdateWorkOrderRequest $request,
        WorkOrder $workOrder,
        GraphNotificationService $notifications,
        WorkOrderTimerAutomationService $timerAutomations,
        WorkOrderStatusHistoryService $statusHistory,
        VisitSchedulingService $scheduling,
        SharePointAttachmentUploadService $attachmentUploads,
        AttachmentLifecycleService $attachmentEvents,
    ): RedirectResponse {
        $this->authorizeAccess($request, $workOrder, manage: true);
        $visit = $workOrder->visits()->oldest('id')->first();
        $visitId = $visit?->getKey();
        $scheduledAt = $request->date('scheduled_at');
        $conflict = $scheduledAt === null ? null : $scheduling->conflictFor(
            $request->user(),
            $scheduledAt,
            $workOrder,
            $visit?->duration_minutes,
        );

        if ($conflict !== null && ! $request->boolean('scheduling_override')) {
            return back()->withErrors(['scheduled_at' => $conflict]);
        }

        [$oldStatus, $history] = DB::transaction(function () use ($request, $workOrder, $visitId, $timerAutomations, $statusHistory): array {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $oldStatus = $lockedWorkOrder->status;
            $newStatus = $request->string('status')->toString();
            $data = $request->validated();

            $lockedWorkOrder->update([
                'site_id' => $request->integer('site_id'),
                'title' => $request->string('title')->toString(),
                'subtitle' => $request->input('subtitle'),
                'description' => $request->input('description'),
                'scope' => $request->string('scope')->toString(),
                'client_completion_summary' => $request->input('client_completion_summary'),
                ...$this->contactFields($request, $lockedWorkOrder->site()->with('customer.contacts')->firstOrFail()->customer),
                'job_notes' => $request->input('job_notes'),
                'field_notes' => $request->input('field_notes'),
                'status' => $newStatus,
                'completed_at' => $newStatus === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_at : now())
                    : $lockedWorkOrder->completed_at,
                'completed_by' => $newStatus === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_by : $request->user()->id)
                    : $lockedWorkOrder->completed_by,
            ]);

            if ($oldStatus !== $newStatus) {
                $timerAutomations->applyTransition(
                    $lockedWorkOrder,
                    $actor,
                    $oldStatus,
                    $newStatus,
                    $data,
                );
            }

            $history = $statusHistory->record(
                $lockedWorkOrder,
                $oldStatus,
                $newStatus,
                $actor,
                $data['pending_reason'] ?? null,
            );

            if ($visitId !== null) {
                $visit = $lockedWorkOrder->visits()->whereKey($visitId)->firstOrFail();
                $visit->update([
                    'scheduled_at' => $request->date('scheduled_at') ?? $visit->scheduled_at,
                    'notes' => $request->input('field_notes'),
                    'graph_sync_status' => 'pending',
                ]);
            } elseif ($newStatus === 'scheduled' && $request->date('scheduled_at') !== null) {
                $visit = $lockedWorkOrder->visits()->create([
                    'visit_type' => 'initial',
                    'scheduled_at' => $request->date('scheduled_at'),
                    'notes' => $request->input('field_notes'),
                    'scope' => $lockedWorkOrder->scope,
                    'graph_sync_status' => 'pending',
                    'metadata' => ['created_from' => 'job_scheduling'],
                ]);
                $visit->assignedUsers()->syncWithoutDetaching([$actor->getKey()]);
            }

            return [$oldStatus, $history];
        });

        $workOrder->refresh();
        $visit = $visitId === null
            ? $workOrder->visits()->oldest('id')->first()
            : $workOrder->visits()->find($visitId);
        $this->storeAttachmentsSafely($request, $workOrder, $attachmentUploads, $attachmentEvents);

        if ($visit !== null && config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
        }

        if ($history !== null && $workOrder->status === 'scheduled') {
            $notifications->jobScheduled($workOrder, $history);
        } elseif ($oldStatus !== $workOrder->status) {
            $notifications->statusChanged($workOrder, $oldStatus, $workOrder->status);
        }

        return back()->with('success', "Job {$workOrder->job_number} updated.");
    }

    public function attachment(Request $request, WorkOrderAttachment $attachment): mixed
    {
        $this->authorizeAccess($request, $attachment->workOrder);

        $headers = ['Content-Type' => $attachment->mime_type];

        if ($request->boolean('download')) {
            return Storage::disk($attachment->disk)->download(
                $attachment->path,
                $attachment->original_name,
                $headers,
            );
        }

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->original_name,
            $headers,
            'inline',
        );
    }

    public function updateStatus(
        UpdateWorkOrderStatusRequest $request,
        WorkOrder $workOrder,
        GraphNotificationService $notifications,
        WorkOrderTimerAutomationService $timerAutomations,
        WorkOrderStatusHistoryService $statusHistory,
        VisitSchedulingService $scheduling,
    ): RedirectResponse {
        $this->authorizeAccess($request, $workOrder, manage: true);
        $newStatus = $request->string('status')->toString();
        $requestedSchedule = $request->date('scheduled_at');
        $scheduleConflict = $newStatus === 'scheduled' && $requestedSchedule !== null
            ? $scheduling->conflictFor($request->user(), $requestedSchedule, $workOrder)
            : null;
        if ($scheduleConflict !== null && ! $request->boolean('scheduling_override')) {
            return back()->withErrors(['scheduled_at' => $scheduleConflict]);
        }
        [$oldStatus, $history, $scheduledVisit] = DB::transaction(function () use ($request, $workOrder, $newStatus, $timerAutomations, $statusHistory): array {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $oldStatus = $lockedWorkOrder->status;
            $data = $request->validated();

            $lockedWorkOrder->update([
                'status' => $newStatus,
                'client_completion_summary' => $newStatus === config('work_orders.completion_status')
                    ? $request->string('client_completion_summary')->toString()
                    : $lockedWorkOrder->client_completion_summary,
                'completed_at' => $newStatus === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_at : now())
                    : $lockedWorkOrder->completed_at,
                'completed_by' => $newStatus === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_by : $request->user()->id)
                    : $lockedWorkOrder->completed_by,
            ]);

            if ($oldStatus !== $newStatus) {
                $timerAutomations->applyTransition(
                    $lockedWorkOrder,
                    $actor,
                    $oldStatus,
                    $newStatus,
                    $data,
                );
            }

            $history = $statusHistory->record(
                $lockedWorkOrder,
                $oldStatus,
                $newStatus,
                $actor,
                $data['pending_reason'] ?? null,
            );
            $scheduledVisit = null;
            if ($newStatus === 'scheduled') {
                $scheduledVisit = $lockedWorkOrder->visits()->oldest('id')->first();
                if ($scheduledVisit === null) {
                    $scheduledVisit = $lockedWorkOrder->visits()->create([
                        'visit_type' => 'initial',
                        'scheduled_at' => $request->date('scheduled_at'),
                        'scope' => $lockedWorkOrder->scope,
                        'notes' => $lockedWorkOrder->field_notes,
                        'graph_sync_status' => 'pending',
                        'metadata' => ['created_from' => 'job_scheduling'],
                    ]);
                } elseif ($request->date('scheduled_at') !== null) {
                    $scheduledVisit->update([
                        'scheduled_at' => $request->date('scheduled_at'),
                        'graph_sync_status' => 'pending',
                    ]);
                }
                $scheduledVisit->assignedUsers()->syncWithoutDetaching([$actor->getKey()]);
            }

            return [$oldStatus, $history, $scheduledVisit];
        });
        $workOrder->refresh();

        if ($scheduledVisit !== null && config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($scheduledVisit->id)->afterCommit();
        }

        if ($history !== null && $newStatus === 'scheduled') {
            $notifications->jobScheduled($workOrder, $history);
        } elseif ($oldStatus !== $newStatus) {
            $notifications->statusChanged($workOrder, $oldStatus, $newStatus);
        }

        return back()->with('success', "{$workOrder->job_number} status updated.");
    }

    public function uploadAttachments(
        UploadWorkOrderAttachmentsRequest $request,
        WorkOrder $workOrder,
        SharePointAttachmentUploadService $attachmentUploads,
        AttachmentLifecycleService $attachmentEvents,
    ): RedirectResponse {
        $this->authorizeAccess($request, $workOrder, manage: true);
        $this->storeAttachmentsSafely($request, $workOrder, $attachmentUploads, $attachmentEvents);

        return back()->with('success', "Files added to {$workOrder->job_number}.");
    }

    public function storeRevisit(
        StoreRevisitRequest $request,
        WorkOrder $workOrder,
        SharePointAttachmentUploadService $attachmentUploads,
        AttachmentLifecycleService $attachmentEvents,
        VisitSchedulingService $scheduling,
    ): RedirectResponse {
        $this->authorizeAccess($request, $workOrder, manage: true);
        $initialVisit = $workOrder->visits()->where('visit_type', 'initial')->oldest('id')->firstOrFail();
        $durationMinutes = $request->integer('duration_minutes') ?: 60;
        $conflict = $scheduling->conflictFor(
            $request->user(),
            $request->date('scheduled_at'),
            $workOrder,
            $durationMinutes,
        );

        if ($conflict !== null && ! $request->boolean('scheduling_override')) {
            return back()->withErrors(['scheduled_at' => $conflict]);
        }

        $visit = $workOrder->visits()->create([
            'visit_type' => 'revisit',
            'initial_visit_id' => $initialVisit->id,
            'scheduled_at' => $request->date('scheduled_at'),
            'notes' => $request->input('notes'),
            'scope' => $workOrder->scope,
            'graph_sync_status' => 'pending',
            'duration_minutes' => $durationMinutes,
            'metadata' => ['created_from' => 'revisit_form'],
        ]);
        $visit->assignedUsers()->syncWithoutDetaching([$request->user()->id]);
        $this->storeAttachmentsSafely($request, $workOrder, $attachmentUploads, $attachmentEvents);
        if (config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
        }

        return back()->with('success', "Revisit added to {$workOrder->job_number}.");
    }

    public function rescheduleVisit(RescheduleVisitRequest $request, Visit $visit, VisitSchedulingService $scheduling): RedirectResponse
    {
        $workOrder = $visit->workOrder;
        $this->authorizeAccess($request, $workOrder, manage: true);
        $durationMinutes = $request->integer('duration_minutes') ?: ($visit->duration_minutes ?: 60);
        $conflict = $scheduling->conflictFor(
            $request->user(),
            $request->date('scheduled_at'),
            $workOrder,
            $durationMinutes,
        );

        if ($conflict !== null && ! $request->boolean('scheduling_override')) {
            return back()->withErrors(['scheduled_at' => $conflict]);
        }

        $visit->update([
            'scheduled_at' => $request->date('scheduled_at'),
            'duration_minutes' => $durationMinutes,
            'graph_sync_status' => 'pending',
        ]);
        if (config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
        }

        return back()->with('success', "{$workOrder->job_number} rescheduled.");
    }

    public function updateVisitScope(UpdateVisitScopeRequest $request, Visit $visit): RedirectResponse
    {
        $this->authorizeAccess($request, $visit->workOrder, manage: true);
        $visit->update([
            'scope' => $request->string('scope')->toString(),
            'graph_sync_status' => 'pending',
        ]);

        if (config('services.microsoft_graph.calendar_writes_enabled')) {
            SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
        }

        return back()->with('success', 'Visit scope updated.');
    }

    private function resolveCustomer(StoreWorkOrderRequest $request): Customer
    {
        if ($request->filled('customer_id')) {
            return Customer::findOrFail($request->integer('customer_id'));
        }

        return Customer::create([
            'client_id' => null,
            'name' => $request->string('customer_name')->toString(),
            'email' => $request->input('customer_email'),
            'phone' => $request->input('customer_phone'),
            'address' => $request->input('customer_address'),
            'metadata' => ['created_from' => 'work_order_form'],
        ]);
    }

    private function contactFields(Request $request, Customer $customer): array
    {
        $customer->loadMissing('contacts');
        $fields = [];

        foreach ([
            'client_contact' => 'client_contact_id',
            'technical_contact' => 'technical_contact_id',
            'project_manager' => 'project_manager_contact_id',
            'contractor_check_in' => 'contractor_check_in_contact_id',
        ] as $prefix => $idField) {
            $contactId = $request->integer($idField) ?: null;
            $contact = $contactId === null ? null : $customer->contacts->firstWhere('id', $contactId);

            abort_unless($contactId === null || $contact !== null, 422, "Selected {$prefix} does not belong to this customer.");

            $fields[$idField] = $contact?->id;
            $fields["{$prefix}_name"] = $contact
                ? trim(implode(' ', array_filter([$contact->first_name, $contact->last_name])))
                : $request->input("{$prefix}_name");
            $fields["{$prefix}_phone"] = $contact?->phone ?? $request->input("{$prefix}_phone");
            $fields["{$prefix}_email"] = $contact?->email ?? $request->input("{$prefix}_email");
        }

        return $fields;
    }

    private function resolveSite(StoreWorkOrderRequest $request, Customer $customer): Site
    {
        if ($request->filled('site_id')) {
            return $customer->sites()->findOrFail($request->integer('site_id'));
        }

        return $customer->sites()->create([
            'name' => $request->string('site_name')->toString(),
            'address' => $request->input('site_address'),
            'metadata' => ['created_from' => 'work_order_form'],
        ]);
    }

    private function nextJobNumber(): string
    {
        $lastJobNumber = WorkOrder::query()
            ->whereNotNull('job_number')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->value('job_number');
        $nextNumber = $lastJobNumber === null ? 1001 : ((int) substr($lastJobNumber, 1)) + 1;

        return 'J'.str_pad((string) $nextNumber, 5, '0', STR_PAD_LEFT);
    }

    private function storeAttachments(
        Request $request,
        WorkOrder $workOrder,
        SharePointAttachmentUploadService $attachmentUploads,
    ): void {
        $files = $request->file('files', []) ?? [];

        if ($files !== []) {
            $attachmentUploads->uploadManyForWorkOrder($files, $workOrder, $request->user());
        }
    }

    private function storeAttachmentsSafely(
        Request $request,
        WorkOrder $workOrder,
        SharePointAttachmentUploadService $attachmentUploads,
        AttachmentLifecycleService $attachmentEvents,
    ): void {
        try {
            $this->storeAttachments($request, $workOrder, $attachmentUploads);
        } catch (Throwable $exception) {
            $attachmentEvents->failed($workOrder, $request->user());

            throw $exception;
        }
    }

    private function authorizeAccess(Request $request, WorkOrder $workOrder, bool $manage = false): void
    {
        abort_unless($request->user()->can($manage ? 'work_orders.manage' : 'work_orders.view'), 403);

        abort_unless(
            $workOrder->assignedUsers()->whereKey($request->user()->id)->exists()
                || $workOrder->visits()->whereHas('assignedUsers', fn ($query) => $query->whereKey($request->user()->id))->exists(),
            403,
        );
    }
}
