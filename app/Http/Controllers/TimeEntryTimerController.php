<?php

namespace App\Http\Controllers;

use App\Exceptions\TimeEntryTimerException;
use App\Http\Requests\StartTimeEntryTimerRequest;
use App\Http\Requests\StopTimeEntryTimerRequest;
use App\Models\TimeEntry;
use App\Services\TimeEntryTimerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TimeEntryTimerController extends Controller
{
    public function active(Request $request, TimeEntryTimerService $timers): JsonResponse
    {
        Gate::authorize('viewAny', TimeEntry::class);

        return response()->json([
            'data' => $this->present($timers->activeFor($request->user())),
        ]);
    }

    public function start(StartTimeEntryTimerRequest $request, TimeEntryTimerService $timers): JsonResponse
    {
        try {
            $timeEntry = $timers->start($request->user(), $request->validated());
        } catch (TimeEntryTimerException $exception) {
            return $this->timerError($exception);
        }

        return response()->json(['data' => $this->present($timeEntry)], 201);
    }

    public function stop(
        StopTimeEntryTimerRequest $request,
        TimeEntry $timeEntry,
        TimeEntryTimerService $timers,
    ): JsonResponse {
        $ownedTimeEntry = TimeEntry::query()
            ->whereKey($timeEntry->getKey())
            ->where('user_id', $request->user()->getKey())
            ->firstOrFail();

        if ($ownedTimeEntry->ended_at !== null) {
            return response()->json(['message' => 'This timer has already been stopped.'], 409);
        }

        Gate::authorize('stop', $ownedTimeEntry);

        try {
            $stoppedEntry = $timers->stop($request->user(), (int) $ownedTimeEntry->getKey());
        } catch (TimeEntryTimerException $exception) {
            return $this->timerError($exception);
        }

        return response()->json(['data' => $this->present($stoppedEntry)]);
    }

    /** @return array<string, mixed>|null */
    private function present(?TimeEntry $timeEntry): ?array
    {
        if ($timeEntry === null) {
            return null;
        }

        $timeEntry->loadMissing([
            'customer:id,name,company_name',
            'project:id,project_number,name',
            'job:id,job_number,title,site_id,project_id',
            'visit:id,visit_type,scheduled_at',
        ]);

        return [
            'id' => $timeEntry->getKey(),
            'userId' => $timeEntry->user_id,
            'customerId' => $timeEntry->customer_id,
            'customerName' => $timeEntry->customer?->company_name ?: $timeEntry->customer?->name,
            'projectId' => $timeEntry->project_id,
            'projectName' => $timeEntry->project?->name,
            'projectNumber' => $timeEntry->project?->project_number,
            'jobId' => $timeEntry->job_id,
            'jobNumber' => $timeEntry->job?->job_number,
            'jobTitle' => $timeEntry->job?->title,
            'visitId' => $timeEntry->visit_id,
            'visitType' => $timeEntry->visit?->visit_type,
            'visitScheduledAt' => $timeEntry->visit?->scheduled_at?->toIso8601String(),
            'activityType' => $timeEntry->activity_type->value,
            'startedAt' => $timeEntry->started_at?->toIso8601String(),
            'endedAt' => $timeEntry->ended_at?->toIso8601String(),
            'durationMinutes' => $timeEntry->duration_minutes,
            'billable' => $timeEntry->billable,
            'notes' => $timeEntry->notes,
            'approvalStatus' => $timeEntry->approval_status->value,
        ];
    }

    private function timerError(TimeEntryTimerException $exception): JsonResponse
    {
        return response()->json(['message' => $exception->getMessage()], $exception->statusCode);
    }
}
