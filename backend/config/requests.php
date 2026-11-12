<?php

declare(strict_types=1);

return [
    // Tenant that owns public-portal requests (the agency running CampaignsHub). Null until configured
    // for a single-portal deployment; internal dashboards still filter strictly by tenant_id.
    'portal_tenant_id' => env('REQUESTS_PORTAL_TENANT_ID'),

    /*
     * PLATFORM-SERVICE-INBOX-001 — the hosts that ARE CampaignsHub. A service request arriving on one of them
     * belongs to the platform owner and to no subscriber. Comma-separated; left unset, the public site, the
     * application and the API as `config/brand.php` publishes them are used.
     */
    'platform_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('REQUESTS_PLATFORM_HOSTS', ''))))),

    'platform_inbox' => [
        // Tell the platform's administrators, through the mail ledger, when a service request arrives.
        'notify' => (bool) env('REQUESTS_PLATFORM_INBOX_NOTIFY', true),
    ],

    // Default SLA window (hours) applied at intake; per-type SLA policies land with the dashboard phase.
    'default_sla_hours' => (int) env('REQUESTS_DEFAULT_SLA_HOURS', 48),

    // Warn when the running SLA has fewer than this many hours remaining (evaluated by the scheduler).
    'sla_warning_hours' => (int) env('REQUESTS_SLA_WARNING_HOURS', 4),

    // EMAIL-GAPS — request mail, to the client contact and to the team. OFF until the Owner approves it:
    // switching it on mails real people. While off, `client_notifications` is written exactly as before.
    'client_mail_enabled' => (bool) env('REQUEST_CLIENT_MAIL_ENABLED', false),

    // Public intake anti-abuse.
    'intake_throttle_per_minute' => (int) env('REQUESTS_INTAKE_THROTTLE', 6),

    // Secure temporary uploads.
    'uploads' => [
        'disk' => env('REQUESTS_UPLOAD_DISK', 'local'), // PRIVATE disk — never 'public'
        'max_size_kb' => (int) env('REQUESTS_UPLOAD_MAX_KB', 10240), // 10 MB per file
        'max_files' => (int) env('REQUESTS_UPLOAD_MAX_FILES', 8),
        'session_ttl_hours' => (int) env('REQUESTS_UPLOAD_TTL_HOURS', 24),
        // Real MIME allowlist (validated against the file's detected type, not just the extension).
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg', 'image/png', 'image/webp', 'image/gif',
            'text/csv', 'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
        // Malware scanning is OFF (no scanner wired) — do not claim it runs. Feature-flag when available.
        'malware_scan' => (bool) env('REQUESTS_UPLOAD_MALWARE_SCAN', false),
    ],

    // Client-portal contact verification (OTP / magic-code). Providers are OFF until real credentials
    // are wired — delivery is recorded as "awaiting_provider_credentials" and never claimed as sent.
    'verification' => [
        /*
         * LOGIN-SINGLE-DOOR-001 — the email code as a way in, OFF unless an operator turns it on.
         *
         * Off: `/login` shows no code control and `/client/login/*` answers 404, so nothing mints a portal session from a code.
         * Sessions opened before it was turned off keep working until they expire. A contact with no password uses recovery.
         */
        'portal_code_on_login' => (bool) env('PORTAL_CODE_ON_LOGIN', false),
        'code_ttl_minutes' => (int) env('REQUESTS_OTP_TTL_MINUTES', 10),
        'max_attempts' => (int) env('REQUESTS_OTP_MAX_ATTEMPTS', 5),
        'token_ttl_minutes' => (int) env('REQUESTS_VERIFIED_TTL_MINUTES', 30), // verified→submit window
        'portal_session_days' => (int) env('REQUESTS_PORTAL_SESSION_DAYS', 14),
        'providers' => [
            'sms' => (bool) env('REQUESTS_SMS_ENABLED', false),
            'whatsapp' => (bool) env('REQUESTS_WHATSAPP_ENABLED', false),
            'email' => (bool) env('REQUESTS_MAIL_ENABLED', false),
        ],
        // DEV/TEST ONLY: expose the code so the flow is usable without a provider. NEVER in production.
        'expose_dev_code' => (bool) env('REQUESTS_OTP_EXPOSE_DEV_CODE', env('APP_ENV', 'production') !== 'production'),
    ],
];
