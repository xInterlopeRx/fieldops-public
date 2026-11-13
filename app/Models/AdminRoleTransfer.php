<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminRoleTransfer extends Model
{
    protected $fillable = [
        'from_user_id',
        'to_user_id',
        'expires_at',
        'accepted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function isPending(): bool
    {
        return $this->completed_at === null
            && $this->accepted_at === null
            && $this->expires_at->isFuture();
    }
}
