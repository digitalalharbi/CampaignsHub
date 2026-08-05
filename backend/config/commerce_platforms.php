<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Commerce platform integrations (PROVCFG-001, section 2 of the brief)
|--------------------------------------------------------------------------
|
| Salla and Zid. Kept apart from `ad_platforms.php` because they are not advertising platforms and
| never will be: connecting one discovers a STORE and its orders, not an ad account and its campaigns.
| One file holding both would invite a shared loop over "all platforms" that then has to branch on
| every line — which is exactly the flattening `ProviderCatalogue` exists to prevent.
|
| The env values here are a FALLBACK for local development and CI. The configuration a real install
| runs on is entered by the platform operator at `/admin/settings/integrations` and stored encrypted in
| `provider_configurations`, which always wins. See `ProviderConfigurationService::values()`.
|
| Nothing here is a claim. These are published endpoints; no install in this repository holds keys for
| either platform, so both read `not_configured` until an operator provisions them AND a real round
| trip succeeds.
*/

return [

    'platforms' => [

        'salla' => [
            'label' => 'Salla',
            'authorize_url' => 'https://accounts.salla.sa/oauth2/auth',
            'token_url' => 'https://accounts.salla.sa/oauth2/token',
            'api_base' => 'https://api.salla.dev/admin/v2',
            'scopes' => ['offline_access'],
            'client_id' => env('SALLA_CLIENT_ID'),
            'client_secret' => env('SALLA_CLIENT_SECRET'),
            'webhook_secret' => env('SALLA_WEBHOOK_SECRET'),
        ],

        'zid' => [
            'label' => 'Zid',
            'authorize_url' => 'https://oauth.zid.sa/oauth/authorize',
            'token_url' => 'https://oauth.zid.sa/oauth/token',
            'api_base' => 'https://api.zid.sa/v1',
            'scopes' => [],
            'client_id' => env('ZID_CLIENT_ID'),
            'client_secret' => env('ZID_CLIENT_SECRET'),
            'webhook_secret' => env('ZID_WEBHOOK_SECRET'),
        ],
    ],
];
