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
            /*
             * ZID-WEBHOOK-001 — Zid does not sign. It authenticates with HTTP Basic.
             *
             * There was a `webhook_secret` here, and `WebhookSignature` used it to compute an HMAC and
             * compare it against an `x-zid-signature` header. Zid publishes no signature scheme at
             * all: its Create Webhook reference states that when a username and password are given at
             * subscription time, «Zid will include a Basic Authentication header when sending webhook
             * requests … This allows partners to verify that the webhook request is coming from Zid».
             *
             * So the operator was being asked for a signing secret they could never obtain, and every
             * genuine delivery was refused for want of a header Zid never sends. These two are the
             * credentials Zid actually accepts — the same pair given when the subscription is created.
             */
            'webhook_username' => env('ZID_WEBHOOK_USERNAME'),
            'webhook_password' => env('ZID_WEBHOOK_PASSWORD'),
        ],
    ],
];
