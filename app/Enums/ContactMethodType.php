<?php

namespace App\Enums;

enum ContactMethodType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Phone => 'Phone',
            self::Teams => 'Teams',
        };
    }
}
