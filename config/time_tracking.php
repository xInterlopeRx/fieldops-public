<?php

return [
    'dashboard' => [
        'stale_timer_hours' => 12,
        'unapproved_entry_age_days' => 7,
    ],

    'payroll' => [
        'included_approval_status' => 'approved',
        'weekly_overtime_threshold_minutes' => 2400,
        // ISO weekday: Monday = 1, Sunday = 7.
        'workweek_starts_on' => 1,
        'max_date_range_days' => 366,
    ],

    'billing' => [
        'max_date_range_days' => 366,
    ],
];
