<?php

namespace Database\Factories;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use App\Models\Customer;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterMaking(function (TimeEntry $timeEntry): void {
            $timeEntry->created_by ??= $timeEntry->user_id;
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endedAt = now()->startOfMinute();

        return [
            'user_id' => User::factory(),
            'customer_id' => Customer::factory(),
            'project_id' => null,
            'job_id' => null,
            'visit_id' => null,
            'activity_type' => TimeEntryActivityType::Administrative,
            'started_at' => $endedAt->copy()->subHour(),
            'ended_at' => $endedAt,
            'duration_minutes' => 60,
            'billable' => false,
            'notes' => null,
            'approval_status' => TimeEntryApprovalStatus::Draft,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
