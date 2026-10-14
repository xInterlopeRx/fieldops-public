<?php

$workSchedulesGroupId = env('MICROSOFT_GRAPH_WORK_SCHEDULES_GROUP_ID');
$workSchedulesSiteId = env('MICROSOFT_GRAPH_WORK_SCHEDULES_SITE_ID');
$workSchedulesDriveId = env('MICROSOFT_GRAPH_WORK_SCHEDULES_DRIVE_ID');
$notificationMailboxId = env('MICROSOFT_GRAPH_NOTIFICATION_MAILBOX_ID');
$notificationCalendarId = env('MICROSOFT_GRAPH_NOTIFICATION_CALENDAR_ID');

return [

    'bitwarden' => [
        'api_url' => env('BWS_API_URL', 'https://api.bitwarden.com'),
        'identity_url' => env('BWS_IDENTITY_URL', 'https://identity.bitwarden.com'),
        'helper_command' => env('BWS_HELPER_COMMAND'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'quickbooks' => [
        'environment' => env('QUICKBOOKS_ENVIRONMENT', 'sandbox'),
        'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
        'authorization_url' => 'https://appcenter.intuit.com/connect/oauth2',
        'token_url' => 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',
        'accounting_scope' => 'com.intuit.quickbooks.accounting',
        'customer_sync_freshness_minutes' => (int) env('QUICKBOOKS_CUSTOMER_SYNC_FRESHNESS_MINUTES', 360),
        'customer_sync_failure_cooldown_minutes' => 5,
    ],

    'microsoft_graph' => [
        // Notification mail and notification-mailbox calendar writes are independent.
        'writes_enabled' => (bool) env('MICROSOFT_GRAPH_WRITES_ENABLED', false),
        'calendar_writes_enabled' => (bool) env('MICROSOFT_GRAPH_CALENDAR_WRITES_ENABLED', false),
        'contacts_sync_enabled' => (bool) env('MICROSOFT_GRAPH_CONTACTS_SYNC_ENABLED', false),
        'sharepoint_enabled' => (bool) env('MICROSOFT_GRAPH_SHAREPOINT_ENABLED', false),
        'onboarding_writes_enabled' => (bool) env('MICROSOFT_GRAPH_ONBOARDING_WRITES_ENABLED', false),
        'test_recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('MICROSOFT_GRAPH_TEST_RECIPIENTS', '')),
        ))),
        'tenant_id' => env('MICROSOFT_GRAPH_TENANT_ID'),
        'notification_mailbox_id' => $notificationMailboxId,
        'notification_mailbox_address' => env('MICROSOFT_GRAPH_NOTIFICATION_MAILBOX_ADDRESS'),
        'notification_calendar_id' => $notificationCalendarId,
        'notification_calendar_endpoint' => is_string($notificationMailboxId) && $notificationMailboxId !== ''
            ? 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($notificationMailboxId)
                .(is_string($notificationCalendarId) && $notificationCalendarId !== ''
                    ? '/calendars/'.rawurlencode($notificationCalendarId)
                    : '')
                .'/events'
            : null,
        'notification_sendmail_endpoint' => is_string($notificationMailboxId) && $notificationMailboxId !== ''
            ? 'https://graph.microsoft.com/v1.0/users/'.rawurlencode($notificationMailboxId).'/sendMail'
            : null,
        'work_schedules' => [
            'group_id' => $workSchedulesGroupId,
            'site_id' => $workSchedulesSiteId,
            'drive_id' => $workSchedulesDriveId,
            'calendar_endpoint' => is_string($workSchedulesGroupId) && $workSchedulesGroupId !== ''
                ? "https://graph.microsoft.com/v1.0/groups/{$workSchedulesGroupId}/events"
                : null,
            'mailbox_endpoint' => is_string($workSchedulesGroupId) && $workSchedulesGroupId !== ''
                ? "https://graph.microsoft.com/v1.0/groups/{$workSchedulesGroupId}/conversations"
                : null,
            'group_drive_endpoint' => is_string($workSchedulesGroupId) && $workSchedulesGroupId !== ''
                ? "https://graph.microsoft.com/v1.0/groups/{$workSchedulesGroupId}/drive"
                : null,
            'drive_endpoint' => is_string($workSchedulesDriveId) && $workSchedulesDriveId !== ''
                ? 'https://graph.microsoft.com/v1.0/drives/'.rawurlencode($workSchedulesDriveId)
                : null,
        ],
        'max_email_bytes' => (int) env('MICROSOFT_GRAPH_MAX_EMAIL_BYTES', 2621440),
    ],

];
