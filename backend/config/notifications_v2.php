<?php

declare(strict_types=1);

/*
 * NOTIF-V2-ENGINE-001 — engine settings (Owner spec §1, §3, §4). Platform defaults only; every value here is the
 * fallback below an admin row (plan features, event catalog) or a workspace setting.
 */
return [
    'entitlements' => [
        // §1: every key ON for every plan and workspace type, except removing the platform footprint.
        'defaults' => [
            'notifications.internal' => true,
            'notifications.push' => true,
            'notifications.sound' => true,
            'notifications.external' => true,
            'notifications.external.charts' => true,
            'notifications.external.cc_bcc' => true,
            'notifications.external.custom_subject' => true,
            'notifications.external.review_mode' => true,
            'followup.morning_brief' => true,
            'followup.tasks' => true,
            'branding.remove_platform_footprint' => false,
        ],

        // §1: generous and editable per plan (notification_plan_features.limits) and per workspace (tenant_limit_overrides,
        // metric `notif.<limit>`). null = unlimited.
        'limits' => [
            'external_recipients' => 500,
            'monthly_messages' => 20000,
            'cc_per_message' => 10,
            'devices_per_user' => 10,
            'monthly_push' => 50000,
            'monthly_charts' => 50000,
        ],

        // Which key each limit belongs to (shown beside it in admin and on the usage page).
        'limit_keys' => [
            'external_recipients' => 'notifications.external',
            'monthly_messages' => 'notifications.external',
            'cc_per_message' => 'notifications.external.cc_bcc',
            'devices_per_user' => 'notifications.push',
            'monthly_push' => 'notifications.push',
            'monthly_charts' => 'notifications.external.charts',
        ],

        'grace_days' => 14,
        'heads_up_days_before_grace_end' => 3,
    ],

    'routing' => [
        'grouping_window_minutes' => 10,
        'default_timezone' => 'Asia/Riyadh',
        // Workspace quiet hours when the workspace has not chosen (external deliveries wait them out; no bypass).
        'default_quiet_hours' => ['enabled' => true, 'start' => '21:00', 'end' => '08:00'],
    ],

    'sender' => [
        'batch_size' => 200,          // outbox rows claimed per run
        'per_tenant_per_run' => 50,   // spread: one workspace never takes the whole run
        'max_attempts' => 3,          // §4: three attempts
        'backoff_minutes' => [5, 30], // growing back-off before attempt 2 and attempt 3
        'claim_timeout_minutes' => 15,
    ],

    'review' => [
        'window_hours' => 4,          // §9: no decision in the window → skipped
        'first_messages' => 3,        // §9: first N messages of a new subscription are reviewed
    ],

    'consent' => [
        'unconfirmed_notice_days' => 7,
    ],
];
