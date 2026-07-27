<?php

declare(strict_types=1);

use App\Domains\Integrations\Connectors\NullConnector;
use App\Domains\Integrations\Connectors\SandboxConnector;

/*
|--------------------------------------------------------------------------
| Connection Center connectors
|--------------------------------------------------------------------------
| The unified connector framework registers ONE adapter per provider. Each entry declares the real
| capabilities that provider's integration has (the adapter surface) — NOT a claim that it is live.
|
| Honesty rule: only the Sandbox is fully functional here. Every external platform below is backed by
| the config-driven NullConnector and is therefore "Awaiting External Dependency" until real
| credentials / app approval are provisioned. None of them can report `production_verified` without
| real credentials AND a real successful sync — that guarantee is enforced in ConnectionCenterService.
|
| Capability keys (see App\Domains\Integrations\Connectors\Enums\Capability):
|   oauth, select_account, campaign_sync, adgroup_sync, ads_sync, creative_sync, metrics_sync, token_refresh
*/

$adsPlatform = [
    'oauth', 'select_account', 'campaign_sync', 'adgroup_sync',
    'ads_sync', 'creative_sync', 'metrics_sync', 'token_refresh',
];

return [

    // Default class used when an entry does not name a dedicated connector.
    'default' => NullConnector::class,

    'connectors' => [

        // The one fully-functional adapter: deterministic, offline, clearly-labelled demo data.
        'sandbox' => [
            'connector' => SandboxConnector::class,
            'label' => 'Sandbox (demo data)',
        ],

        // ---- Advertising platforms (full campaign → ad → creative → metrics surface) ----
        'meta_ads' => ['label' => 'Meta Ads', 'capabilities' => $adsPlatform],
        'google_ads' => ['label' => 'Google Ads', 'capabilities' => $adsPlatform],
        'tiktok_ads' => ['label' => 'TikTok Ads', 'capabilities' => $adsPlatform],
        'snapchat_ads' => ['label' => 'Snapchat Ads', 'capabilities' => $adsPlatform],
        'linkedin_ads' => ['label' => 'LinkedIn Ads', 'capabilities' => $adsPlatform],
        'x_ads' => ['label' => 'X Ads', 'capabilities' => $adsPlatform],
        'microsoft_ads' => ['label' => 'Microsoft Ads', 'capabilities' => $adsPlatform],
        'pinterest_ads' => ['label' => 'Pinterest Ads', 'capabilities' => $adsPlatform],

        // ---- Analytics / tagging ----
        'ga4' => ['label' => 'Google Analytics 4', 'capabilities' => ['oauth', 'select_account', 'metrics_sync', 'token_refresh']],
        'google_tag_manager' => ['label' => 'Google Tag Manager', 'capabilities' => ['oauth', 'select_account', 'token_refresh']],

        // ---- E-commerce ----
        'salla' => ['label' => 'Salla', 'capabilities' => ['oauth', 'select_account', 'metrics_sync', 'token_refresh']],
        'zid' => ['label' => 'Zid', 'capabilities' => ['oauth', 'select_account', 'metrics_sync', 'token_refresh']],
        'shopify' => ['label' => 'Shopify', 'capabilities' => ['oauth', 'select_account', 'metrics_sync', 'token_refresh']],
        'woocommerce' => ['label' => 'WooCommerce', 'capabilities' => ['select_account', 'metrics_sync']],

        // ---- Internal CRM + storage/export ----
        'crm' => ['label' => 'CRM', 'capabilities' => ['select_account', 'metrics_sync']],
        'google_drive' => ['label' => 'Google Drive', 'capabilities' => ['oauth', 'select_account', 'token_refresh']],
    ],
];
