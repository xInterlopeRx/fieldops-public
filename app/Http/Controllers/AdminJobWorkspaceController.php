<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAdminJobWorkspaceRequest;
use App\Http\Requests\UploadWorkOrderAttachmentsRequest;
use App\Jobs\SyncVisitMicrosoftGraphEvent;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Services\ActivityLogService;
use App\Services\AssignmentService;
use App\Services\AttachmentLifecycleService;
use App\Services\AttachmentListService;
use App\Services\GraphNotificationService;
use App\Services\SharePointAttachmentUploadService;
use App\Services\VisitSchedulingService;
use App\Services\WorkOrderStatusHistoryService;
use App\Services\WorkOrderTimerAutomationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AdminJobWorkspaceController extends Controller
{
    public function show(WorkOrder $workOrder, AttachmentListService $attachmentLists): JsonResponse
    {
        $workOrder->load([
            'site:id,customer_id,name,address',
            'site.customer:id,name,company_name',
            'project:id,project_number,name',
            'clientContact:id,customer_id,site_id,contact_type,first_name,last_name,email,phone',
            'assignedUsers:id,name',
            'contacts:id,customer_id,site_id,contact_type,first_name,last_name,email,phone',
            'visits' => fn ($query) => $query->orderBy('scheduled_at')->orderBy('id')->limit(100),
            'visits.jobItems.item:id,type',
            'statusHistory.actor:id,name',
        ]);
        $attachments = $attachmentLists->forJobWorkspace($workOrder);
        $customerId = $workOrder->site?->customer_id;
        abort_if($customerId === null, 404);

        $activity = AuditEvent::query()
            ->where(function (Builder $query) use ($workOrder): void {
                $query->where(function (Builder $subjectQuery) use ($workOrder): void {
                    $subjectQuery->where('subject_type', $workOrder->getMorphClass())
                        ->where('subject_id', $workOrder->getKey());
                })->orWhere(function (Builder $subjectQuery) use ($workOrder): void {
                    $subjectQuery->where('subject_type', (new Visit)->getMorphClass())
                        ->whereIn('subject_id', Visit::withArchived()->where('work_order_id', $workOrder->getKey())->select('id'));
                })->orWhere(function (Builder $subjectQuery) use ($workOrder): void {
                    $subjectQuery->where('subject_type', (new WorkOrderAttachment)->getMorphClass())
                        ->whereIn('subject_id', WorkOrderAttachment::withArchived()
                            ->where('work_order_id', $workOrder->getKey())
                            ->orWhereIn('visit_id', Visit::withArchived()->where('work_order_id', $workOrder->getKey())->select('id'))
                            ->select('id'));
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'actor_user_id', 'subject_type', 'subject_id', 'event', 'summary', 'created_at']);

        return response()->json([
            'data' => [
                'job' => [
                    'id' => $workOrder->id,
                    'number' => $workOrder->job_number,
                    'title' => $workOrder->title,
                    'subtitle' => $workOrder->subtitle,
                    'description' => $workOrder->description,
                    'status' => $workOrder->status,
                    'scope' => $workOrder->scope,
                    'jobNotes' => $workOrder->job_notes,
                    'fieldNotes' => $workOrder->field_notes,
                    'clientCompletionSummary' => $workOrder->client_completion_summary,
                    'siteId' => $workOrder->site_id,
                    'customerId' => $customerId,
                    'customerName' => $workOrder->site?->customer?->company_name ?: $workOrder->site?->customer?->name,
                    'projectName' => $workOrder->project?->name,
                    'projectNumber' => $workOrder->project?->project_number,
                    'contactIds' => [
                        'client' => $workOrder->client_contact_id,
                        'technical' => $workOrder->technical_contact_id,
                        'projectManager' => $workOrder->project_manager_contact_id,
                        'contractor' => $workOrder->contractor_check_in_contact_id,
                    ],
                ],
                'sites' => Site::query()
                    ->where('customer_id', $customerId)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->limit(100)
                    ->get(['id', 'name', 'address'])
                    ->map(fn (Site $site): array => ['id' => $site->id, 'name' => $site->name, 'address' => $site->address])
                    ->values(),
                'contacts' => Contact::query()
                    ->where('customer_id', $customerId)
                    ->with('site:id,name')
                    ->orderBy('contact_type')
                    ->orderBy('first_name')
                    ->orderBy('last_name')
                    ->orderBy('id')
                    ->limit(100)
                    ->get(['id', 'customer_id', 'site_id', 'contact_type', 'first_name', 'last_name', 'email', 'phone'])
                    ->map(fn (Contact $contact): array => [
                        'id' => $contact->id,
                        'siteId' => $contact->site_id,
                        'siteName' => $contact->site?->name,
                        'type' => $contact->contact_type,
                        'name' => trim(implode(' ', array_filter([$contact->first_name, $contact->last_name]))) ?: 'Unnamed contact',
                        'email' => $contact->email,
                        'phone' => $contact->phone,
                    ])
                    ->values(),
                'additionalContactIds' => $workOrder->contacts->modelKeys(),
                'assignedUserIds' => $workOrder->assignedUsers->modelKeys(),
                'staff' => User::query()
                    ->with('roles:id,name')
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->limit(250)
                    ->get(['id', 'name', 'email'])
                    ->map(fn (User $user): array => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'roles' => $user->getRoleNames()->values()->all(),
                    ])
                    ->values(),
                'visits' => $workOrder->visits->map(fn (Visit $visit): array => [
                    'id' => $visit->id,
                    'type' => $visit->visit_type,
                    'scheduledAt' => $visit->scheduled_at?->toIso8601String(),
                    'scope' => $visit->scope,
                    'notes' => $visit->notes,
                    'durationMinutes' => $visit->duration_minutes,
                    'items' => $visit->jobItems->map(fn ($jobItem): array => [
                        'id' => (int) $jobItem->getKey(),
                        'name' => $jobItem->item_name_snapshot,
                        'description' => $jobItem->item_description_snapshot,
                        'type' => $jobItem->item?->type,
                        'quantity' => (string) $jobItem->quantity,
                    ])->values(),
                ])->values(),
                'attachments' => $attachments,
                'activity' => $activity->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary,
                    'actor' => $event->actor?->name ?? 'Former user',
                    'createdAt' => $event->created_at?->toIso8601String(),
                ])->values(),
                'statusHistory' => $workOrder->statusHistory->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'fromStatus' => $entry->from_status,
                    'toStatus' => $entry->to_status,
                    'reason' => $entry->reason,
                    'actor' => $entry->actor?->name ?? 'Former user',
                    'createdAt' => $entry->created_at?->toIso8601String(),
                ])->values(),
            ],
        ]);
    }

    public function update(
        UpdateAdminJobWorkspaceRequest $request,
        WorkOrder $workOrder,
        AssignmentService $assignments,
        ActivityLogService $activityLog,
        GraphNotificationService $notifications,
        WorkOrderTimerAutomationService $timerAutomations,
        WorkOrderStatusHistoryService $statusHistory,
        VisitSchedulingService $scheduling,
    ): JsonResponse {
        $data = $request->validated();
        $customerId = $workOrder->site()->value('customer_id');
        $site = Site::query()->where('customer_id', $customerId)->find($data['site_id']);

        if ($site === null) {
            return response()->json([
                'message' => 'Choose a site belonging to this job’s customer.',
                'errors' => ['site_id' => ['Choose a site belonging to this job’s customer.']],
            ], 422);
        }

        if ($data['status'] === 'scheduled' && isset($data['scheduled_at'])) {
            $conflict = $scheduling->conflictFor(
                $request->user(),
                $request->date('scheduled_at'),
                $workOrder,
            );

            if ($conflict !== null) {
                throw ValidationException::withMessages(['scheduled_at' => $conflict]);
            }
        }

        $contactIds = collect([
            $data['client_contact_id'] ?? null,
            $data['technical_contact_id'] ?? null,
            $data['project_manager_contact_id'] ?? null,
            $data['contractor_check_in_contact_id'] ?? null,
            ...$data['additional_contact_ids'],
        ])->filter()->unique()->values();

        if (Contact::query()->where('customer_id', $customerId)->whereKey($contactIds)->count() !== $contactIds->count()) {
            throw ValidationException::withMessages([
                'additional_contact_ids' => 'Choose only contacts belonging to this job’s customer.',
            ]);
        }

        $oldAdditionalContactIds = $workOrder->contacts()->orderBy('contacts.id')->pluck('contacts.id')->map(fn ($id): int => (int) $id);
        $newAdditionalContactIds = collect($data['additional_contact_ids'])->map(fn ($id): int => (int) $id)->unique()->values();

        [$oldStatus, $history] = DB::transaction(function () use ($workOrder, $data, $site, $oldAdditionalContactIds, $newAdditionalContactIds, $assignments, $activityLog, $request, $timerAutomations, $statusHistory): array {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->getKey());
            $lockedWorkOrder = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            $oldStatus = $lockedWorkOrder->status;
            $status = $data['status'];
            $clientContact = isset($data['client_contact_id']) ? Contact::query()->find($data['client_contact_id']) : null;
            $technicalContact = isset($data['technical_contact_id']) ? Contact::query()->find($data['technical_contact_id']) : null;
            $projectManagerContact = isset($data['project_manager_contact_id']) ? Contact::query()->find($data['project_manager_contact_id']) : null;
            $contractorContact = isset($data['contractor_check_in_contact_id']) ? Contact::query()->find($data['contractor_check_in_contact_id']) : null;

            $lockedWorkOrder->update([
                'site_id' => $site->getKey(),
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'description' => $data['description'] ?? null,
                'scope' => $data['scope'],
                'job_notes' => $data['job_notes'] ?? null,
                'field_notes' => $data['field_notes'] ?? null,
                'client_completion_summary' => $data['client_completion_summary'] ?? null,
                'status' => $status,
                'completed_at' => $status === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_at : now())
                    : $lockedWorkOrder->completed_at,
                'completed_by' => $status === config('work_orders.completion_status')
                    ? ($oldStatus === config('work_orders.completion_status') ? $lockedWorkOrder->completed_by : $request->user()->getKey())
                    : $lockedWorkOrder->completed_by,
                'client_contact_id' => $clientContact?->getKey(),
                'client_contact_name' => $this->contactName($clientContact),
                'client_contact_email' => $clientContact?->email,
                'client_contact_phone' => $clientContact?->phone,
                'technical_contact_id' => $technicalContact?->getKey(),
                'technical_contact_name' => $this->contactName($technicalContact),
                'technical_contact_email' => $technicalContact?->email,
                'technical_contact_phone' => $technicalContact?->phone,
                'project_manager_contact_id' => $projectManagerContact?->getKey(),
                'project_manager_name' => $this->contactName($projectManagerContact),
                'project_manager_email' => $projectManagerContact?->email,
                'project_manager_phone' => $projectManagerContact?->phone,
                'contractor_check_in_contact_id' => $contractorContact?->getKey(),
                'contractor_check_in_name' => $this->contactName($contractorContact),
                'contractor_check_in_email' => $contractorContact?->email,
                'contractor_check_in_phone' => $contractorContact?->phone,
            ]);

            if ($oldStatus !== $status) {
                $timerAutomations->applyTransition(
                    $lockedWorkOrder,
                    $actor,
                    $oldStatus,
                    $status,
                    $data,
                );
            }

            $history = $statusHistory->record(
                $lockedWorkOrder,
                $oldStatus,
                $status,
                $actor,
                $data['pending_reason'] ?? null,
            );

            $workOrder->contacts()->sync($newAdditionalContactIds->all());

            if ($oldAdditionalContactIds->diff($newAdditionalContactIds)->isNotEmpty()
                || $newAdditionalContactIds->diff($oldAdditionalContactIds)->isNotEmpty()) {
                $activityLog->record($lockedWorkOrder, 'edited', ['changed_fields' => ['additional_contacts']], $request->user());
            }

            $assignments->sync($lockedWorkOrder, array_map('intval', $data['assigned_user_ids']), $request->user());

            if ($status === 'scheduled') {
                $visit = $lockedWorkOrder->visits()->oldest('id')->first();
                if ($visit === null && isset($data['scheduled_at'])) {
                    $visit = $lockedWorkOrder->visits()->create([
                        'visit_type' => 'initial',
                        'scheduled_at' => $data['scheduled_at'],
                        'scope' => $lockedWorkOrder->scope,
                        'notes' => $lockedWorkOrder->field_notes,
                        'graph_sync_status' => 'pending',
                        'metadata' => ['created_from' => 'job_scheduling'],
                    ]);
                } elseif ($visit !== null && isset($data['scheduled_at'])) {
                    $visit->update([
                        'scheduled_at' => $data['scheduled_at'],
                        'graph_sync_status' => 'pending',
                    ]);
                }

                if ($visit !== null) {
                    $visit->assignedUsers()->syncWithoutDetaching(array_map('intval', $data['assigned_user_ids']));
                }
            }

            return [$oldStatus, $history];
        });

        $workOrder->refresh();

        if ($workOrder->status === 'scheduled' && isset($data['scheduled_at']) && config('services.microsoft_graph.calendar_writes_enabled')) {
            $visit = $workOrder->visits()->oldest('id')->first();
            if ($visit !== null) {
                SyncVisitMicrosoftGraphEvent::dispatch($visit->id)->afterCommit();
            }
        }

        if ($history !== null && $workOrder->status === 'scheduled') {
            $notifications->jobScheduled($workOrder, $history);
        } elseif ($oldStatus !== $workOrder->status) {
            $notifications->statusChanged($workOrder, $oldStatus, $workOrder->status);
        }

        return response()->json(['message' => 'Job saved.']);
    }

    public function uploadAttachment(
        UploadWorkOrderAttachmentsRequest $request,
        WorkOrder $workOrder,
        SharePointAttachmentUploadService $attachments,
        AttachmentListService $attachmentLists,
        AttachmentLifecycleService $attachmentEvents,
    ): JsonResponse {
        $validated = $request->validated();

        try {
            $uploadedAttachments = $attachments->uploadManyForWorkOrder(
                $validated['files'],
                $workOrder,
                $request->user(),
                $validated['client_upload_id'] ?? null,
            );
        } catch (\Throwable $exception) {
            $attachmentEvents->failed($workOrder, $request->user());

            throw $exception;
        }

        return response()->json([
            'data' => collect($uploadedAttachments)
                ->map(fn ($attachment): array => $attachmentLists->workOrderUploadItem($attachment->load('uploader:id,name')))
                ->values(),
        ], 201);
    }

    public function attachment(WorkOrderAttachment $attachment): mixed
    {
        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function contactName(?Contact $contact): ?string
    {
        if ($contact === null) {
            return null;
        }

        $name = trim(implode(' ', array_filter([$contact->first_name, $contact->last_name])));

        return $name !== '' ? $name : null;
    }
}
