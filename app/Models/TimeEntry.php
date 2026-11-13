<?php

namespace App\Models;

use App\Enums\TimeEntryActivityType;
use App\Enums\TimeEntryApprovalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEntry extends Model
{
    use HasFactory;

    protected $fillable = [
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
        'notes',
        'approval_status',
    ];

    protected $casts = [
        'activity_type' => TimeEntryActivityType::class,
        'approval_status' => TimeEntryApprovalStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_minutes' => 'integer',
        'billable' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'job_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        if ($viewer->can('time_entries.view_all')) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($viewer): void {
            $visible->where('user_id', $viewer->getKey());

            if (! $viewer->can('time_entries.view_team')) {
                return;
            }

            $visible->orWhere(function (Builder $team) use ($viewer): void {
                $team->whereHas('job.assignedUsers', fn (Builder $users) => $users->whereKey($viewer->getKey()))
                    ->orWhereHas('visit.assignedUsers', fn (Builder $users) => $users->whereKey($viewer->getKey()))
                    ->orWhereHas('project.assignedUsers', fn (Builder $users) => $users->whereKey($viewer->getKey()));
            });
        });
    }
}
