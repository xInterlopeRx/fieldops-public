<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GraphOnboardingChallenge extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REPLACED = 'replaced';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'environment',
        'target_id',
        'user_id',
        'status',
        'token_hash',
        'recipient_email_hash',
        'expires_at',
        'sent_at',
        'verified_at',
        'failure_code',
    ];

    protected $hidden = [
        'token_hash',
        'recipient_email_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MicrosoftGraphOnboardingTarget::class, 'target_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
