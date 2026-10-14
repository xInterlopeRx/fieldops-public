<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrosoftGraphOnboardingCanary extends Model
{
    public const RESOURCE_CALENDAR_EVENT = 'calendar_event';

    public const RESOURCE_CONTACT = 'outlook_contact';

    public const STATUS_CREATING = 'creating';

    public const STATUS_CREATE_UNKNOWN = 'create_unknown';

    public const STATUS_CREATED = 'created';

    public const STATUS_CLEANUP_PENDING = 'cleanup_pending';

    public const STATUS_CLEANED = 'cleaned';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CLEANUP_FAILED = 'cleanup_failed';

    public const STATUS_CLEANUP_UNKNOWN = 'cleanup_unknown';

    public const STATUS_CLEANUP_CONFLICT = 'cleanup_conflict';

    public const STATUS_MANUALLY_RECONCILED = 'manually_reconciled';

    /** @var list<string> */
    public const ACTIVE_STATUSES = [
        self::STATUS_CREATING,
        self::STATUS_CREATE_UNKNOWN,
        self::STATUS_CREATED,
        self::STATUS_CLEANUP_PENDING,
        self::STATUS_CLEANUP_FAILED,
        self::STATUS_CLEANUP_UNKNOWN,
        self::STATUS_CLEANUP_CONFLICT,
    ];

    protected $hidden = [
        'client_id',
        'tenant_id',
        'remote_etag',
        'transaction_id',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'target_id',
        'actor_user_id',
        'environment',
        'resource_type',
        'status',
        'transaction_id',
        'tenant_id',
        'client_id',
        'mailbox_id',
        'calendar_id',
        'subject',
        'remote_resource_id',
        'remote_etag',
        'attempted_at',
        'created_remotely_at',
        'cleaned_at',
        'reconciled_at',
        'reconciled_by_user_id',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'created_remotely_at' => 'datetime',
            'cleaned_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MicrosoftGraphOnboardingTarget::class, 'target_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }
}
