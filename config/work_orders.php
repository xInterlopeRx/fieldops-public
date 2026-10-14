<?php

return [
    // Canonical Job statuses, display colors, and validation semantics are
    // shared with Inertia. Keep timer rules keyed by configured statuses, not
    // in service status-name branches.
    'default_status' => 'created',

    'completion_status' => 'completed',

    'pending_status' => 'pending',

    'statuses' => [
        'created' => [
            'label' => 'Created',
            'status_color' => 'gray',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => true,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => false,
        ],
        'scheduled' => [
            'label' => 'Scheduled',
            'status_color' => 'blue',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => true,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => false,
        ],
        'pending' => [
            'label' => 'Pending',
            'status_color' => 'orange',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => true,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => true,
        ],
        'on_my_way' => [
            'label' => 'On The Way',
            'status_color' => 'purple',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => true,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => false,
        ],
        'in_progress' => [
            'label' => 'In Progress',
            'status_color' => 'magenta',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => true,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => false,
        ],
        'completed' => [
            'label' => 'Completed',
            'status_color' => 'green',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => false,
            'job_notes_required' => false,
            'client_completion_summary_required' => true,
            'pending_reason_required' => false,
        ],
        'cancelled' => [
            'label' => 'Cancelled',
            'status_color' => 'red',
            'calendar_selectable' => true,
            'active_for_upcoming_jobs' => false,
            'job_notes_required' => false,
            'client_completion_summary_required' => false,
            'pending_reason_required' => false,
        ],
    ],

    'timer_automations' => [
        'created' => ['stop_active_timer_for_job' => true],
        'scheduled' => ['stop_active_timer_for_job' => true],
        'pending' => [
            'stop_active_timer_for_job' => true,
        ],
        'on_my_way' => [
            'start_activity_type' => 'travel',
        ],
        'in_progress' => [
            'start_activity_type' => 'labor',
        ],
        'cancelled' => ['stop_active_timer_for_job' => true],
        'completed' => [
            'stop_active_timer_for_job' => true,
        ],
    ],
];
