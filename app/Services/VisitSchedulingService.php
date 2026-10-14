<?php

namespace App\Services;

use App\Models\User;
use App\Models\Visit;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class VisitSchedulingService
{
    public function conflictFor(
        User $user,
        Carbon $scheduledAt,
        ?WorkOrder $currentWorkOrder = null,
        int $durationMinutes = 60,
    ): ?string {
        $scheduledEnd = $scheduledAt->copy()->addMinutes($durationMinutes);
        $visits = Visit::query()
            ->where(fn ($query) => $query
                ->whereHas('assignedUsers', fn ($assigned) => $assigned->whereKey($user->getKey()))
                ->orWhereHas('workOrder.assignedUsers', fn ($assigned) => $assigned->whereKey($user->getKey())))
            ->when($currentWorkOrder, fn ($query) => $query->where('work_order_id', '!=', $currentWorkOrder->getKey()))
            ->with('workOrder')
            ->get();

        foreach ($visits as $visit) {
            $visitStart = $visit->scheduled_at;
            $visitEnd = $visitStart->copy()->addMinutes($visit->duration_minutes ?: 60);

            if ($scheduledAt->lt($visitEnd) && $scheduledEnd->gt($visitStart)) {
                $suggestedAt = $this->nextAvailableSlot($scheduledAt, $durationMinutes, $visits);

                return "This time overlaps {$visit->workOrder?->job_number} ({$visit->workOrder?->title}). Suggested time: {$suggestedAt->format('Y-m-d H:i')}.";
            }
        }

        return null;
    }

    /** @param Collection<int, Visit> $visits */
    private function nextAvailableSlot(Carbon $requestedAt, int $durationMinutes, $visits): Carbon
    {
        $candidate = $requestedAt->copy()->setSecond(0);
        $candidate->setMinute((int) (ceil($candidate->minute / 15) * 15));

        if ($candidate->minute === 60) {
            $candidate->addHour()->setMinute(0);
        }

        for ($attempt = 0; $attempt < 60; $attempt++) {
            if ($candidate->isWeekend() || $candidate->hour < 8 || ($candidate->hour === 17 && $candidate->minute > 0) || $candidate->hour > 17) {
                $candidate->addDay()->setTime(8, 0);

                continue;
            }

            $candidateEnd = $candidate->copy()->addMinutes($durationMinutes);
            if ($candidateEnd->hour > 17 || ($candidateEnd->hour === 17 && $candidateEnd->minute > 0)) {
                $candidate->addDay()->setTime(8, 0);

                continue;
            }

            $hasConflict = $visits->contains(function (Visit $visit) use ($candidate, $candidateEnd): bool {
                $visitStart = $visit->scheduled_at;
                $visitEnd = $visitStart->copy()->addMinutes($visit->duration_minutes ?: 60);

                return $candidate->lt($visitEnd) && $candidateEnd->gt($visitStart);
            });

            if (! $hasConflict) {
                return $candidate;
            }

            $candidate->addMinutes(15);
        }

        return $requestedAt->copy()->addDay()->setTime(8, 0);
    }
}
