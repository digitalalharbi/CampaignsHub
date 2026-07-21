<?php

declare(strict_types=1);

// Central brand + domain identity. Change here (or via env) — never hard-code the name in code.
return [
    'name' => env('APP_BRAND_NAME', 'CampaignsHub'),
    'domain' => env('APP_DOMAIN', 'campaignshub.io'),
    'frontend_url' => env('FRONTEND_URL', 'https://campaignshub.io'),
    'app_url' => env('APP_MARKETING_URL', 'https://campaignshub.io'),
    'application_url' => env('APPLICATION_URL', 'https://app.campaignshub.io'),
    'api_url' => env('API_URL', 'https://api.campaignshub.io'),
    'docs_url' => env('DOCS_URL', 'https://docs.campaignshub.io'),
    'status_url' => env('STATUS_URL', 'https://status.campaignshub.io'),
    'support_email' => env('SUPPORT_EMAIL', 'support@campaignshub.io'),
    'tagline' => 'Run every client, project, and campaign from one place.',
];
