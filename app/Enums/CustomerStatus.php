<?php

namespace App\Enums;

enum CustomerStatus: string
{
    case Opportunity = 'opportunity';
    case CurrentClient = 'current_client';
    case TransferredClient = 'transferred_client';
    case LostOpportunity = 'lost_opportunity';

    public function label(): string
    {
        return match ($this) {
            self::Opportunity => 'Opportunity',
            self::CurrentClient => 'Current Customer',
            self::TransferredClient => 'Transferred Customer',
            self::LostOpportunity => 'Lost Opportunity',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
