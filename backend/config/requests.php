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
];
