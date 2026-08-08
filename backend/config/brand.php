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
    'support_email' => env('SUPPORT_EMAIL', 'info@campaignshub.io'),
    /*
     * The OFFICIAL tagline, in both languages — BRAND-001.
     *
     * «كل حملاتك الإعلانية المدفوعة في مكان واحد» is the sentence the product is sold on, and until
     * now it lived almost entirely in code comments: eight files quoted it to explain a decision,
     * one marketing page used it as a heading, and the value actually configured here was a
     * different English sentence that no Arabic surface could use.
     *
     * Both languages sit together because every surface that needs one needs the other — the title
     * tag, the Open Graph card, the structured data, the sign-in panel, the email header and the
     * footer are each rendered in whichever language the reader chose.
     */
    'tagline' => [
        'ar' => 'كل حملاتك الإعلانية المدفوعة في مكان واحد',
        'en' => 'All your paid campaigns in one place',
    ],

    /*
     * What the product IS, for a description field rather than a headline.
     *
     * Deliberately plain and checkable: it names what the platform does and claims nothing about
     * results. A description that promises performance is a description that has to be defended.
     */
    'description' => [
        'ar' => 'منصة موحدة لإدارة ومتابعة وتحليل الحملات الإعلانية المدفوعة عبر جميع المنصات من مكان واحد.',
        'en' => 'One platform to run, monitor and analyse paid advertising across every ad platform.',
    ],

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
