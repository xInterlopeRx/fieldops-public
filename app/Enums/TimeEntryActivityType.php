<?php

namespace App\Enums;

enum TimeEntryActivityType: string
{
    case Travel = 'travel';
    case Labor = 'labor';
    case RemoteSupport = 'remote_support';
    case Documentation = 'documentation';
    case Research = 'research';
    case Meeting = 'meeting';
    case Administrative = 'administrative';
    case ProjectWork = 'project_work';

    public function label(): string
    {
        return match ($this) {
            self::Travel => 'Travel',
            self::Labor => 'Labor',
            self::RemoteSupport => 'Remote Support',
            self::Documentation => 'Documentation',
            self::Research => 'Research',
            self::Meeting => 'Meeting',
            self::Administrative => 'Administrative',
            self::ProjectWork => 'Project Work',
        };
    }
}
