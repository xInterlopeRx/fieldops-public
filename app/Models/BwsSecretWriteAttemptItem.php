<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class BwsSecretWriteAttemptItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_NOT_WRITTEN = 'not_written';

    public const STATUS_WRITE_UNCONFIRMED = 'write_unconfirmed';

    public const STATUS_VERIFICATION_UNAVAILABLE = 'verification_unavailable';

    public const STATUS_MISMATCH = 'mismatch';

    protected $fillable = [
        'attempt_id',
        'secret_name',
        'expected_fingerprint',
        'status',
        'write_status',
        'failure_code',
        'verified_at',
    ];

    protected $hidden = [
        'expected_fingerprint',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('BWS secret write attempt items are durable reconciliation records.');
        });
    }

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(BwsSecretWriteAttempt::class, 'attempt_id');
    }
}
