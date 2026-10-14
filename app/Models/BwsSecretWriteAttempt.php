<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BwsSecretWriteAttempt extends Model
{
    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RECONCILIATION_REQUIRED = 'reconciliation_required';

    public const STATUS_MANUALLY_RECONCILED = 'manually_reconciled';

    protected $fillable = [
        'id',
        'connection_id',
        'actor_user_id',
        'environment',
        'organization_id',
        'project_id',
        'status',
        'fingerprint_key_id',
        'failure_code',
        'started_at',
        'completed_at',
        'reconciled_at',
        'reconciled_by_user_id',
        'reconciliation_reference',
    ];

    protected $hidden = [
        'fingerprint_key_id',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('BWS secret write attempts are durable reconciliation records.');
        });
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(BitwardenSecretManagerConnection::class, 'connection_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function secretItems(): HasMany
    {
        return $this->hasMany(BwsSecretWriteAttemptItem::class, 'attempt_id')->orderBy('id');
    }
}
