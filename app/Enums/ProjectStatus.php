<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Created = 'created';
    case Scheduled = 'scheduled';
    case Planning = 'planning';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Scheduled => 'Scheduled',
            self::Planning => 'Planning',
            self::Active => 'Active',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Created => 'gray',
            self::Scheduled => 'blue',
            self::Planning => 'purple',
            self::Active, self::Completed => 'green',
            self::OnHold => 'orange',
            self::Cancelled => 'red',
        };
    }

    /** @return list<array{value: string, label: string, color: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => [
                'value' => $status->value,
                'label' => $status->label(),
                'color' => $status->color(),
            ],
            self::cases(),
        );
    }
}
