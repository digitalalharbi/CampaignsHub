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

    // Feature flags (platform defaults; a tenant may override in its settings later).
    'features' => [
        // Generic sales CRM (leads/opportunities/proposals) — OFF by default; the media-buying
        // operational nav is the primary experience.
        'sales_crm_enabled' => (bool) env('FEATURE_SALES_CRM', false),

        /*
         * Influencer & UGC — OFF (INFL-OFF-001).
         *
         * The service is not being offered in this release and will return later as its own
         * sub-system. It is switched off rather than deleted: every table, row, model, service,
         * controller and test stays exactly where it is, so turning this back on restores a portal
         * rather than starting one.
         *
         * What the flag governs is what the product OFFERS — the portal gate, the doors, the
         * registration options, the rails, the marketing page, the demo logins and new requests for
         * the module. It governs nothing about what already exists: an influencer request already in
         * the system keeps its service type, its history and its files, and stays readable.
         */
        'influencers_ugc_enabled' => (bool) env('FEATURE_INFLUENCERS_UGC', false),
    ],
];
