<?php

declare(strict_types=1);

return [
    // Tenant that owns public-portal requests (the agency running CampaignsHub). Null until configured
    // for a single-portal deployment; internal dashboards still filter strictly by tenant_id.
    'portal_tenant_id' => env('REQUESTS_PORTAL_TENANT_ID'),

    // Default SLA window (hours) applied at intake; per-type SLA policies land with the dashboard phase.
    'default_sla_hours' => (int) env('REQUESTS_DEFAULT_SLA_HOURS', 48),

    // Public intake anti-abuse.
    'intake_throttle_per_minute' => (int) env('REQUESTS_INTAKE_THROTTLE', 6),
];
