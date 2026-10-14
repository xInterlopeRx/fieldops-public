<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MicrosoftGraphOnboardingPermissionVerification extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'target_id',
        'environment',
        'tenant_id',
        'client_id',
        'mailbox_id',
        'non_target_mailbox_email',
        'target_is_dedicated_shared_mailbox',
        'non_target_is_user_mailbox',
        'business_site_id',
        'business_drive_id',
        'm365_admin_upn',
        'verification_reference',
        'mail_send_target_verified',
        'mail_send_non_target_denied',
        'calendar_write_target_verified',
        'calendar_write_non_target_denied',
        'contacts_boundary_verified',
        'sharepoint_boundary_verified',
        'sharepoint_retention_reviewed',
        'unscoped_app_roles_reviewed',
        'verified_by_user_id',
        'verified_at',
    ];

    protected $hidden = [
        'tenant_id',
        'client_id',
        'mailbox_id',
        'non_target_mailbox_email',
        'business_site_id',
        'business_drive_id',
    ];

    protected function casts(): array
    {
        return [
            'target_is_dedicated_shared_mailbox' => 'boolean',
            'non_target_is_user_mailbox' => 'boolean',
            'mail_send_target_verified' => 'boolean',
            'mail_send_non_target_denied' => 'boolean',
            'calendar_write_target_verified' => 'boolean',
            'calendar_write_non_target_denied' => 'boolean',
            'contacts_boundary_verified' => 'boolean',
            'sharepoint_boundary_verified' => 'boolean',
            'sharepoint_retention_reviewed' => 'boolean',
            'unscoped_app_roles_reviewed' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Microsoft Graph permission verification evidence is append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Microsoft Graph permission verification evidence is append-only.');
        });
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MicrosoftGraphOnboardingTarget::class, 'target_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function matchesTarget(MicrosoftGraphOnboardingTarget $target): bool
    {
        return $this->environment === $target->environment
            && $this->tenant_id === $target->tenant_id
            && $this->client_id === $target->client_id
            && $this->mailbox_id === $target->notification_mailbox_id
            && $this->non_target_mailbox_email === $target->non_target_mailbox_email
            && $this->target_is_dedicated_shared_mailbox === $target->target_is_dedicated_shared_mailbox
            && $this->non_target_is_user_mailbox === $target->non_target_is_user_mailbox
            && $this->target_is_dedicated_shared_mailbox
            && $this->non_target_is_user_mailbox
            && $this->business_site_id === $target->business_attachments_site_id
            && $this->business_drive_id === $target->business_attachments_drive_id
            && $this->mail_send_target_verified
            && $this->mail_send_non_target_denied
            && $this->calendar_write_target_verified
            && $this->calendar_write_non_target_denied
            && $this->contacts_boundary_verified
            && $this->sharepoint_boundary_verified
            && $this->sharepoint_retention_reviewed
            && $this->unscoped_app_roles_reviewed;
    }
}
