<?php

namespace App\Http\Controllers;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Http\Requests\BulkApproveTimeEntriesRequest;
use App\Http\Requests\ReviewTimeEntriesRequest;
use App\Http\Requests\UpdateReviewedTimeEntryRequest;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\TimeEntryReviewService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AdminTimeEntryReviewController extends Controller
{
    public function index(ReviewTimeEntriesRequest $request): Response
    {
        Gate::authorize('reviewAny', TimeEntry::class);

        $filters = $request->validated();
        $query = TimeEntry::query()
            ->select([
                'id',
                'user_id',
                'customer_id',
                'project_id',
                'job_id',
                'visit_id',
                'activity_type',
                'started_at',
                'ended_at',
                'duration_minutes',
                'billable',
                'approval_status',
            ])
            ->with([
                'user:id,name',
                'customer:id,name,company_name',
                'project:id,project_number,name',
                'job:id,job_number,title',
            ]);

        if (isset($filters['date_from'])) {
            $query->where(
                'started_at',
                '>=',
                Carbon::parse($filters['date_from'], config('app.timezone'))->startOfDay()->utc(),
            );
        }

        if (isset($filters['date_to'])) {
            $query->where(
                'started_at',
                '<',
                Carbon::parse($filters['date_to'], config('app.timezone'))->addDay()->startOfDay()->utc(),
            );
        }

        if (filled($filters['technician'] ?? null)) {
            $pattern = '%'.mb_strtolower($filters['technician']).'%';
            $query->whereHas('user', fn (Builder $userQuery) => $userQuery
                ->whereRaw('LOWER(users.name) LIKE ?', [$pattern]));
        }

        if (filled($filters['customer'] ?? null)) {
            $pattern = '%'.mb_strtolower($filters['customer']).'%';
            $query->whereHas('customer', fn (Builder $customerQuery) => $customerQuery
                ->where(fn (Builder $nameQuery) => $nameQuery
                    ->whereRaw('LOWER(customers.name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(customers.company_name, '')) LIKE ?", [$pattern])));
        }

        if (filled($filters['project'] ?? null)) {
            $pattern = '%'.mb_strtolower($filters['project']).'%';
            $query->whereHas('project', fn (Builder $projectQuery) => $projectQuery
                ->where(fn (Builder $nameQuery) => $nameQuery
                    ->whereRaw('LOWER(projects.name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(projects.project_number, '')) LIKE ?", [$pattern])));
        }

        $query
            ->when(isset($filters['activity_type']), fn (Builder $builder) => $builder->where('activity_type', $filters['activity_type']))
            ->when(isset($filters['billable']), fn (Builder $builder) => $builder->where('billable', $filters['billable'] === '1'))
            ->when(isset($filters['status']), fn (Builder $builder) => $builder->where('approval_status', $filters['status']))
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        $actor = $request->user();
        $entries = $query
            ->paginate(50)
            ->withQueryString()
            ->through(fn (TimeEntry $entry): array => $this->presentRow($entry, $actor));

        return Inertia::render('Admin/TimeTracking/Review', [
            'entries' => $entries,
            'filters' => [
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'technician' => $filters['technician'] ?? '',
                'customer' => $filters['customer'] ?? '',
                'project' => $filters['project'] ?? '',
                'activity_type' => $filters['activity_type'] ?? '',
                'billable' => $filters['billable'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'activityTypes' => array_map(
                fn (TimeEntryActivityType $type): array => ['value' => $type->value, 'label' => $type->label()],
                TimeEntryActivityType::cases(),
            ),
            'approvalStatuses' => array_map(
                fn (TimeEntryApprovalStatus $status): array => ['value' => $status->value, 'label' => $status->label()],
                TimeEntryApprovalStatus::cases(),
            ),
            'status' => session('status'),
        ]);
    }

    public function edit(TimeEntry $timeEntry): JsonResponse
    {
        Gate::authorize('update', $timeEntry);

        return response()->json([
            'data' => [
                'id' => $timeEntry->getKey(),
                'startedAt' => $timeEntry->started_at?->toIso8601String(),
                'endedAt' => $timeEntry->ended_at?->toIso8601String(),
                'durationMinutes' => $timeEntry->duration_minutes,
                'activityType' => $timeEntry->activity_type->value,
                'billable' => $timeEntry->billable,
                'notes' => $timeEntry->notes,
            ],
        ]);
    }

    public function update(
        UpdateReviewedTimeEntryRequest $request,
        TimeEntry $timeEntry,
        TimeEntryReviewService $review,
    ): RedirectResponse {
        $review->update($request->user(), $timeEntry, $request->validated());

        return back()->with('status', 'Time entry updated. Changes to an approved entry returned it to Draft.');
    }

    public function approve(Request $request, TimeEntry $timeEntry, TimeEntryReviewService $review): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $review->approve($actor, $timeEntry);

        return back()->with('status', 'Time entry approved.');
    }

    public function unapprove(Request $request, TimeEntry $timeEntry, TimeEntryReviewService $review): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $review->unapprove($actor, $timeEntry);

        return back()->with('status', 'Time entry returned to Draft.');
    }

    public function bulkApprove(
        BulkApproveTimeEntriesRequest $request,
        TimeEntryReviewService $review,
    ): RedirectResponse {
        $review->bulkApprove($request->user(), array_map('intval', $request->validated('ids')));

        return back()->with('status', 'Selected time entries approved.');
    }

    public function destroy(Request $request, TimeEntry $timeEntry, TimeEntryReviewService $review): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $review->delete($actor, $timeEntry);

        return back()->with('status', 'Time entry permanently deleted.');
    }

    /** @return array<string, mixed> */
    private function presentRow(TimeEntry $entry, User $actor): array
    {
        $isRunning = $entry->ended_at === null;

        return [
            'id' => $entry->getKey(),
            'startedAt' => $entry->started_at?->toIso8601String(),
            'durationMinutes' => $isRunning
                ? (int) $entry->started_at?->diffInMinutes(now())
                : $entry->duration_minutes,
            'isRunning' => $isRunning,
            'technician' => $entry->user?->name ?? 'Former user',
            'customer' => $entry->customer?->company_name ?: ($entry->customer?->name ?? 'Unavailable customer'),
            'project' => $entry->project === null
                ? null
                : trim(($entry->project->project_number ? $entry->project->project_number.' · ' : '').$entry->project->name),
            'job' => $entry->job === null
                ? null
                : trim(($entry->job->job_number ? $entry->job->job_number.' · ' : '').$entry->job->title),
            'activityType' => $entry->activity_type->value,
            'activityLabel' => $entry->activity_type->label(),
            'billable' => $entry->billable,
            'status' => $entry->approval_status->value,
            'statusLabel' => $entry->approval_status->label(),
            'canEdit' => $actor->can('update', $entry),
            'canApprove' => $actor->can('approve', $entry),
            'canUnapprove' => $actor->can('unapprove', $entry),
            'canDelete' => $actor->can('delete', $entry),
        ];
    }
}
