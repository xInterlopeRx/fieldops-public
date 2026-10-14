<?php

namespace App\Enums;

enum ContactMethodLabel: string
{
    case Office = 'office';
    case Mobile = 'mobile';
    case Dispatch = 'dispatch';
    case AfterHours = 'after_hours';
    case Operations = 'operations';
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Office => 'Office',
            self::Mobile => 'Mobile',
            self::Dispatch => 'Dispatch',
            self::AfterHours => 'After Hours',
            self::Operations => 'Operations',
            self::Personal => 'Personal',
        };
    }
}
