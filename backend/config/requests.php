<?php

declare(strict_types=1);

return [
    // Tenant that owns public-portal requests (the agency running CampaignsHub). Null until configured
    // for a single-portal deployment; internal dashboards still filter strictly by tenant_id.
    'portal_tenant_id' => env('REQUESTS_PORTAL_TENANT_ID'),

    // Default SLA window (hours) applied at intake; per-type SLA policies land with the dashboard phase.
    'default_sla_hours' => (int) env('REQUESTS_DEFAULT_SLA_HOURS', 48),

    // Warn when the running SLA has fewer than this many hours remaining (evaluated by the scheduler).
    'sla_warning_hours' => (int) env('REQUESTS_SLA_WARNING_HOURS', 4),

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
