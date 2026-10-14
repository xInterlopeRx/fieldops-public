<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAdminProjectWorkspaceRequest;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Models\ProjectAttachment;
use App\Models\Site;
use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use App\Services\ActivityLogService;
use App\Services\AssignmentService;
use App\Services\AttachmentLifecycleService;
use App\Services\AttachmentListService;
use App\Services\SharePointAttachmentUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminProjectWorkspaceController extends Controller
{
    public function show(Project $project, AttachmentListService $attachmentLists): JsonResponse
    {
        $project->load(['customer:id,name,company_name', 'sites:id,name,address']);
        $attachments = $attachmentLists->forProjectWorkspace($project);
        $jobs = $project->workOrders()
            ->with('site:id,name')
            ->withCount('visits')
            ->orderBy('job_number')
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'site_id', 'job_number', 'title', 'status']);
        $activity = AuditEvent::query()
            ->where(function (Builder $query) use ($project): void {
                $query->where(function (Builder $subjectQuery) use ($project): void {
                    $subjectQuery->where('subject_type', $project->getMorphClass())
                        ->where('subject_id', $project->getKey());
                });

                $query->orWhere(function (Builder $subjectQuery) use ($project): void {
                    $subjectQuery->where('subject_type', (new WorkOrder)->getMorphClass())
                        ->whereIn('subject_id', WorkOrder::withArchived()->where('project_id', $project->getKey())->select('id'));
                });
                $query->orWhere(function (Builder $subjectQuery) use ($project): void {
                    $subjectQuery->where('subject_type', (new Visit)->getMorphClass())
                        ->whereIn('subject_id', Visit::withArchived()->whereIn('work_order_id', WorkOrder::withArchived()->where('project_id', $project->getKey())->select('id'))->select('id'));
                });
                $query->orWhere(function (Builder $subjectQuery) use ($project): void {
                    $subjectQuery->where('subject_type', (new ProjectAttachment)->getMorphClass())
                        ->whereIn('subject_id', ProjectAttachment::withArchived()->where('project_id', $project->getKey())->select('id'));
                });
            })
            ->with('actor:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'actor_user_id', 'subject_type', 'subject_id', 'event', 'summary', 'created_at']);

        return response()->json([
            'data' => [
                'project' => [
                    'id' => $project->id,
                    'number' => $project->project_number,
                    'name' => $project->name,
                    'notes' => $project->notes,
                    'status' => $project->status,
                    'customerName' => $project->customer?->company_name ?: $project->customer?->name,
                ],
                'sites' => $project->sites->map(fn (Site $site): array => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'address' => $site->address,
                ])->values(),
                'attachments' => $attachments,
                'availableSites' => Site::query()
                    ->where('customer_id', $project->customer_id)
                    ->orderBy('name')
                    ->orderBy('id')
                    ->limit(100)
                    ->get(['id', 'name', 'address'])
                    ->map(fn (Site $site): array => [
                        'id' => $site->id,
                        'name' => $site->name,
                        'address' => $site->address,
                    ])
                    ->values(),
                'jobs' => $jobs->map(fn (WorkOrder $job): array => [
                    'id' => $job->id,
                    'number' => $job->job_number,
                    'title' => $job->title,
                    'status' => $job->status,
                    'site' => $job->site?->name,
                    'visitsCount' => $job->visits_count,
                ])->values(),
                'assignedUserIds' => $project->assignedUsers()->pluck('users.id')->map(fn ($id): int => (int) $id)->values(),
                'activity' => $activity->map(fn (AuditEvent $event): array => [
                    'id' => $event->id,
                    'summary' => $event->summary,
                    'actor' => $event->actor?->name ?? 'Former user',
                    'createdAt' => $event->created_at?->toIso8601String(),
                ])->values(),
            ],
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
        ]);
    }

    public function update(
        UpdateAdminProjectWorkspaceRequest $request,
        Project $project,
        AssignmentService $assignments,
        ActivityLogService $activityLog,
    ): JsonResponse {
        $data = $request->validated();
        $validSiteIds = Site::query()
            ->where('customer_id', $project->customer_id)
            ->whereKey($data['site_ids'])
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);

        if ($validSiteIds->count() !== count($data['site_ids'])) {
            return response()->json([
                'message' => 'Choose only sites belonging to this project’s customer.',
                'errors' => ['site_ids' => ['Choose only sites belonging to this project’s customer.']],
            ], 422);
        }

        DB::transaction(function () use ($project, $data, $validSiteIds, $assignments, $activityLog, $request): void {
            $currentSiteIds = $project->sites()->orderBy('sites.id')->pluck('sites.id')->map(fn ($id): int => (int) $id);
            $addedSiteIds = $validSiteIds->diff($currentSiteIds)->values();
            $removedSiteIds = $currentSiteIds->diff($validSiteIds)->values();

            $project->fill([
                'name' => $data['name'],
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'],
            ])->save();
            $project->sites()->sync($validSiteIds->all());

            if ($addedSiteIds->isNotEmpty() || $removedSiteIds->isNotEmpty()) {
                $activityLog->record($project, 'edited', [
                    'changed_fields' => ['sites'],
                    'added_site_ids' => $addedSiteIds->all(),
                    'removed_site_ids' => $removedSiteIds->all(),
                ], $request->user());
            }

            $assignments->sync($project, array_map('intval', $data['assigned_user_ids']), $request->user());
        });

        return response()->json(['message' => 'Project saved.']);
    }

    public function uploadAttachment(
        Request $request,
        Project $project,
        SharePointAttachmentUploadService $attachments,
        AttachmentListService $attachmentLists,
        AttachmentLifecycleService $attachmentEvents,
    ): JsonResponse {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm,pdf,txt,csv,doc,docx,xls,xlsx,ppt,pptx'],
        ]);
        try {
            $attachment = $attachments->uploadForProject($validated['file'], $project, $request->user());
        } catch (\Throwable $exception) {
            $attachmentEvents->failed($project, $request->user());

            throw $exception;
        }

        return response()->json([
            'data' => $attachmentLists->projectUploadItem($attachment->load('uploader:id,name')),
        ], 201);
    }

    public function attachment(ProjectAttachment $attachment): mixed
    {
        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type, 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
