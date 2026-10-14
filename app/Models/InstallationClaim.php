<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationClaim extends Model
{
    public $incrementing = false;

    protected $fillable = [
        'id',
        'claimed_at',
        'claimed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }

    public function claimedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }

    public function isAvailable(): bool
    {
        return $this->claimed_at === null
            && ! User::query()->exists();
    }
}
