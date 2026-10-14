<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MicrosoftGraphOnboardingTarget extends Model
{
    protected $fillable = [
        'environment',
        'tenant_id',
        'client_id',
        'notification_mailbox_id',
        'notification_mailbox_address',
        'target_is_dedicated_shared_mailbox',
        'notification_calendar_id',
        'work_schedules_group_id',
        'work_schedules_site_id',
        'work_schedules_drive_id',
        'business_attachments_site_id',
        'business_attachments_drive_id',
        'non_target_mailbox_email',
        'non_target_is_user_mailbox',
        'non_target_site_id',
        'non_target_drive_id',
        'runtime_activated_at',
        'runtime_activated_by_user_id',
    ];

    protected $hidden = [
        'configured_by_user_id',
        'non_target_mailbox_id',
        'runtime_activated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'target_is_dedicated_shared_mailbox' => 'boolean',
            'non_target_is_user_mailbox' => 'boolean',
            'configured_at' => 'datetime',
            'last_validation_attempted_at' => 'datetime',
            'read_access_verified_at' => 'datetime',
            'read_access_check_count' => 'integer',
            'boundary_validation_attempted_at' => 'datetime',
            'boundary_verified_at' => 'datetime',
            'contacts_boundary_validation_attempted_at' => 'datetime',
            'contacts_boundary_verified_at' => 'datetime',
            'runtime_activated_at' => 'datetime',
        ];
    }

    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }

    public function linkbackChallenges(): HasMany
    {
        return $this->hasMany(GraphOnboardingChallenge::class, 'target_id');
    }

    public function onboardingCanaries(): HasMany
    {
        return $this->hasMany(MicrosoftGraphOnboardingCanary::class, 'target_id');
    }

    public function permissionVerifications(): HasMany
    {
        return $this->hasMany(MicrosoftGraphOnboardingPermissionVerification::class, 'target_id');
    }

    public function sharePointCanaries(): HasMany
    {
        return $this->hasMany(MicrosoftGraphSharePointOnboardingCanary::class, 'target_id');
    }
}
