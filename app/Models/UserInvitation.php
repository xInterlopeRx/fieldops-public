<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInvitation extends Model
{
    use HasFactory;

    protected $fillable = ['invited_by', 'name', 'email', 'roles', 'token_hash', 'expires_at', 'accepted_at'];

    protected $casts = ['roles' => 'array', 'expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
