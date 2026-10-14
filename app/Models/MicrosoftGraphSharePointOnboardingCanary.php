<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrosoftGraphSharePointOnboardingCanary extends Model
{
    protected $table = 'microsoft_graph_sharepoint_onboarding_canaries';

    public $incrementing = false;

    protected $keyType = 'string';

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

    protected $fillable = [
        'id',
        'target_id',
        'actor_user_id',
        'environment',
        'tenant_id',
        'client_id',
        'site_id',
        'drive_id',
        'status',
        'operation_id',
        'folder_name',
        'remote_item_id',
        'remote_etag',
        'attempted_at',
        'created_remotely_at',
        'cleaned_at',
        'reconciled_at',
        'reconciled_by_user_id',
        'failure_code',
    ];

    protected $hidden = [
        'tenant_id',
        'client_id',
        'site_id',
        'drive_id',
        'operation_id',
        'remote_etag',
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

    public function matchesTarget(MicrosoftGraphOnboardingTarget $target): bool
    {
        return $this->environment === $target->environment
            && $this->tenant_id === $target->tenant_id
            && $this->client_id === $target->client_id
            && $this->site_id === $target->business_attachments_site_id
            && $this->drive_id === $target->business_attachments_drive_id;
    }
}
