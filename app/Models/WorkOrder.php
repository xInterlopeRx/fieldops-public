<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrder extends Model
{
    use Archiveable, HasFactory;

    protected $attributes = [
        'status' => 'created',
    ];

    protected $fillable = [
        'site_id',
        'project_id',
        'job_number',
        'title',
        'subtitle',
        'description',
        'scope',
        'client_completion_summary',
        'completed_at',
        'completion_report_generated_at',
        'completed_by',
        'client_contact_name', 'client_contact_phone', 'client_contact_email',
        'client_contact_id',
        'technical_contact_name', 'technical_contact_phone', 'technical_contact_email',
        'technical_contact_id',
        'project_manager_name', 'project_manager_phone', 'project_manager_email',
        'project_manager_contact_id',
        'contractor_check_in_name', 'contractor_check_in_phone', 'contractor_check_in_email',
        'contractor_check_in_contact_id',
        'job_notes',
        'field_notes',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'status' => 'string', // Could be an Enum in the future.
        'completed_at' => 'datetime',
        'completion_report_generated_at' => 'datetime',
    ];

    /**
     * Get the site associated with this work order.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function clientContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_contact_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Get the visits scheduled for this work order.
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class, 'job_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(WorkOrderStatusHistory::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Get the users assigned to this work order.
     */
    public function assignedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'work_order_user')
            ->withPivot('assigned_by_user_id');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_work_order')->withTimestamps();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }

    public function sharePointAttachments(): HasMany
    {
        return $this->hasMany(SharePointAttachment::class, 'job_id');
    }
}
