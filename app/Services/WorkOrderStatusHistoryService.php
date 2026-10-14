<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderStatusHistory;
use Illuminate\Validation\ValidationException;

class WorkOrderStatusHistoryService
{
    public function record(
        WorkOrder $workOrder,
        ?string $oldStatus,
        string $newStatus,
        User $actor,
        ?string $reason = null,
    ): ?WorkOrderStatusHistory {
        if ($oldStatus === $newStatus) {
            return null;
        }

        if ($newStatus === config('work_orders.pending_status')) {
            $isAssigned = $workOrder->assignedUsers()->whereKey($actor->getKey())->exists()
                || $workOrder->visits()
                    ->whereHas('assignedUsers', fn ($query) => $query->whereKey($actor->getKey()))
                    ->exists();

            if (! $isAssigned) {
                throw ValidationException::withMessages([
                    'status' => 'Only a staff member assigned to this Job can move it to Pending.',
                ]);
            }
        }

        return $workOrder->statusHistory()->create([
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'reason' => $newStatus === config('work_orders.pending_status') ? trim((string) $reason) : null,
            'changed_by' => $actor->getKey(),
        ]);
    }
}
