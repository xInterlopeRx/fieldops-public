<?php

namespace App\Enums;

enum TimeEntryApprovalStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Invoiced = 'invoiced';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Invoiced => 'Invoiced',
            self::Paid => 'Paid',
        };
    }
}
