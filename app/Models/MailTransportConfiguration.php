<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailTransportConfiguration extends Model
{
    public const string PRIMARY = 'primary';

    protected $fillable = [
        'singleton_key',
        'host',
        'port',
        'security',
        'username',
        'password',
        'from_address',
        'from_name',
        'configured_by_user_id',
        'tested_at',
    ];

    protected $hidden = [
        'username',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'username' => 'encrypted',
            'password' => 'encrypted',
            'tested_at' => 'datetime',
        ];
    }
}
