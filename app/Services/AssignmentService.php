<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\BusinessAssignmentChanged;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(private readonly ActivityLogService $activityLog) {}

    /** @param list<int> $requestedUserIds
     * @return array{added: list<int>, removed: list<int>}
     */
    public function sync(Model $record, array $requestedUserIds, User $actor): array
    {
        abort_unless($record instanceof Customer || $record instanceof Project || $record instanceof WorkOrder, 404);

        $requestedIds = collect($requestedUserIds)->map(fn ($id): int => (int) $id)->unique()->values();

        return DB::transaction(function () use ($record, $requestedIds, $actor): array {
            $activeIds = User::query()
                ->where('status', 'active')
                ->whereKey($requestedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id);

            if ($activeIds->count() !== $requestedIds->count()) {
                throw ValidationException::withMessages([
                    'assigned_user_ids' => 'Choose only active FieldOps staff members.',
                ]);
            }

            $currentIds = $record->assignedUsers()->orderBy('users.id')->pluck('users.id')->map(fn ($id): int => (int) $id);
            $addedIds = $activeIds->diff($currentIds)->values();
            $removedIds = $currentIds->diff($activeIds)->values();
            $relation = $record->assignedUsers();

            if ($removedIds->isNotEmpty()) {
                $relation->detach($removedIds->all());
            }

            foreach ($addedIds as $userId) {
                $relation->attach($userId, ['assigned_by_user_id' => $actor->getKey()]);
            }

            if ($addedIds->isEmpty() && $removedIds->isEmpty()) {
                return ['added' => [], 'removed' => []];
            }

            $admins = User::query()
                ->permission('admin.access')
                ->where('status', 'active')
                ->orderBy('id')
                ->get();

            foreach ([['assigned', $addedIds], ['unassigned', $removedIds]] as [$change, $ids]) {
                if ($ids->isEmpty()) {
                    continue;
                }

                $affectedUsers = User::query()->whereKey($ids)->orderBy('id')->get();

                foreach ($affectedUsers as $affectedUser) {
                    $this->activityLog->record($record, $change, [
                        'assigned_user_id' => $affectedUser->getKey(),
                        'assigned_user_name' => $affectedUser->name,
                    ], $actor);

                    $notification = (new BusinessAssignmentChanged(
                        change: $change,
                        affectedUserId: (int) $affectedUser->getKey(),
                        affectedUserName: $affectedUser->name,
                        actorName: $actor->name,
                        recordLabel: $this->recordLabel($record),
                        recordUrl: $this->recordUrl($record, $change),
                        adminRecordUrl: $this->adminRecordUrl($record),
                    ))->afterCommit();

                    Notification::send(
                        $admins->concat([$affectedUser])->unique('id')->values(),
                        $notification,
                    );
                }
            }

            return [
                'added' => $addedIds->all(),
                'removed' => $removedIds->all(),
            ];
        });
    }

    private function recordLabel(Model $record): string
    {
        return match (true) {
            $record instanceof Customer => 'customer '.($record->company_name ?: $record->name),
            $record instanceof Project => 'project '.($record->project_number ?: $record->name),
            $record instanceof WorkOrder => 'job '.($record->job_number ?: $record->title),
            default => 'record #'.$record->getKey(),
        };
    }

    private function recordUrl(Model $record, string $change): string
    {
        if ($change === 'unassigned') {
            return route('calendar');
        }

        return route('calendar', match (true) {
            $record instanceof Customer => ['open_customer' => $record->getKey()],
            $record instanceof Project => ['open_project' => $record->getKey()],
            $record instanceof WorkOrder => ['open_job' => $record->getKey()],
        });
    }

    private function adminRecordUrl(Model $record): string
    {
        return match (true) {
            $record instanceof Customer => route('admin.customers'),
            $record instanceof Project => route('admin.projects'),
            $record instanceof WorkOrder => route('admin.jobs'),
        };
    }
}
