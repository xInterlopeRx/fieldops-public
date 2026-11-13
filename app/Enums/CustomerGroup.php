<?php

namespace App\Enums;

enum CustomerGroup: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case Industrial = 'industrial';
    case NonProfit = 'non_profit';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::Commercial => 'Commercial',
            self::Industrial => 'Industrial',
            self::NonProfit => 'Non-profit',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $group): array => ['value' => $group->value, 'label' => $group->label()],
            self::cases(),
        );
    }
}
