<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ad-platform integrations (INTEG-OAUTH-001)
|--------------------------------------------------------------------------
|
| One entry per advertising platform, in the products order — Snapchat, TikTok, Meta, Google Ads, X,
| LinkedIn (`App\Support\AdPlatforms::ORDER`). Each entry is everything the connector needs to talk to
| that platform for real: where to send somebody to authorise, where to exchange and refresh the code,
| which API host to call, and which extra secret that platform demands beyond a client id and secret.
|
| ## Where the credential values actually come from now (PROVCFG-001)
|
| The `env()` reads below are a FALLBACK for local development and CI. A real install is configured by
| the platform operator at `/admin/settings/integrations`, stored encrypted in
| `provider_configurations`, and that always wins — see `ProviderConfigurationService::values()`.
|
| ## What "configured" means, and why it is strict
|
| A platform counts as configured only when EVERY required value is present. The required list lives in
| `ProviderCatalogue`, not here: it used to be in both files, and two lists of required keys is one
| list that is wrong. Anything less than complete and the connector reports `awaiting_credentials` and
| refuses to call out — it does not attempt a request that would fail, and it never renders as
| connected.
|
| That strictness is not decoration. Google Ads without a developer token can authenticate and then be
| refused by every single API call; Snapchat without an organisation id can authenticate and then have
| nothing to list. Both would look "connected" to a check that only asked for a token, and the customer
| would be told their account was linked while no figure would ever arrive.
|
| ## Nothing in this file is a claim
|
| These are the published endpoints of each platform's marketing API. Their presence says the adapter
| knows where to go — not that anybody has been there. No install in this repository holds credentials
| for any of the six, so every one of them is `Awaiting Credentials` until an operator provisions keys
| AND a real round trip succeeds. See `docs/AD_PLATFORM_INTEGRATIONS_AUDIT.md`.
*/

return [

    /*
     * Where a platform sends the browser back after the customer authorises.
     *
     * One route for all six, with the provider in the path, because a callback URL has to be
     * registered by hand in each platform's developer console and six near-identical URLs is six
     * chances to register the wrong one.
     */
    'redirect_base' => rtrim((string) env('AD_PLATFORM_REDIRECT_BASE', (string) env('APP_URL', 'http://localhost:8000')), '/'),

    /*
     * How long an authorisation attempt stays valid.
     *
     * The `state` we mint is signed AND recorded; this bounds the recorded half, so a callback replayed
     * from a browser history days later cannot open a connection.
     */
    'state_ttl_minutes' => 15,

    /*
     * Refresh a token this long before it actually expires.
     *
     * A token that expires mid-sync fails the whole window and leaves a run that has to be re-driven,
     * so the vault treats "expires within the hour" as "expired" and refreshes ahead of use.
     */
    'refresh_skew_minutes' => 60,

    'platforms' => [

        'snapchat' => [
            'label' => 'Snapchat Marketing API',
            'authorize_url' => 'https://accounts.snapchat.com/login/oauth2/authorize',
            'token_url' => 'https://accounts.snapchat.com/login/oauth2/access_token',
            'api_base' => 'https://adsapi.snapchat.com/v1',
            'scopes' => ['snapchat-marketing-api'],
            'client_id' => env('SNAPCHAT_ADS_CLIENT_ID'),
            'client_secret' => env('SNAPCHAT_ADS_CLIENT_SECRET'),
            // Snapchat hangs ad accounts off an organisation; without one there is nothing to list.
            'organization_id' => env('SNAPCHAT_ADS_ORGANIZATION_ID'),
        ],

        'tiktok' => [
            'label' => 'TikTok Marketing API',
            'authorize_url' => 'https://business-api.tiktok.com/portal/auth',
            'token_url' => 'https://business-api.tiktok.com/open_api/v1.3/oauth2/access_token/',
            'api_base' => 'https://business-api.tiktok.com/open_api/v1.3',
            'scopes' => [],
            // TikTok calls the client id an "app id" and the secret a "secret"; the OAuth call uses
            // `app_id`/`secret` rather than the standard names, which the connector handles.
            'client_id' => env('TIKTOK_ADS_APP_ID'),
            'client_secret' => env('TIKTOK_ADS_APP_SECRET'),
        ],

        'meta' => [
            'label' => 'Meta Marketing API',
            /*
             * META-VERSION-001 — the Marketing API keeps its OWN version table, and on it v21.0
             * expired on 9 September 2025. This was pinned to v21.0.
             *
             * It never showed up as a failure because Meta does not answer an expired version with an
             * error: the platform versioning guide states that once a version is no longer usable,
             * calls to it default to the next-oldest usable version. So the figures kept arriving,
             * from a version nobody chose, and no log anywhere said which.
             *
             * v25.0 was released 18 February 2026 and is the current stable — the only one in the
             * table with no expiration date set. Marketing API expirations for the neighbours, so the
             * next person can see how much runway this has: v22.0 → 19 Feb 2026, v23.0 → 9 Jun 2026,
             * v24.0 → 6 Oct 2026. `MetaAttributionWindowTest` asserts the floor rather than the
             * literal, so an upgrade passes and only standing still eventually fails.
             */
            'authorize_url' => 'https://www.facebook.com/v25.0/dialog/oauth',
            'token_url' => 'https://graph.facebook.com/v25.0/oauth/access_token',
            'api_base' => 'https://graph.facebook.com/v25.0',
            'scopes' => ['ads_read', 'ads_management', 'business_management'],
            'client_id' => env('META_ADS_APP_ID'),
            'client_secret' => env('META_ADS_APP_SECRET'),
        ],

        'google' => [
            'label' => 'Google Ads API',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'api_base' => 'https://googleads.googleapis.com/v18',
            'scopes' => ['https://www.googleapis.com/auth/adwords'],
            'client_id' => env('GOOGLE_ADS_CLIENT_ID'),
            'client_secret' => env('GOOGLE_ADS_CLIENT_SECRET'),
            /*
             * The developer token is approved separately from the OAuth client, and every Google Ads
             * call is refused without it. Listing it as REQUIRED in the catalogue is the difference between an
             * account that says "awaiting credentials" and one that says "connected" and then returns
             * nothing but errors.
             */
            'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
            'login_customer_id' => env('GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        ],

        'x' => [
            'label' => 'X Ads API',
            'authorize_url' => 'https://x.com/i/oauth2/authorize',
            'token_url' => 'https://api.x.com/2/oauth2/token',
            'api_base' => 'https://ads-api.x.com/12',
            'scopes' => ['tweet.read', 'users.read', 'offline.access'],
            'client_id' => env('X_ADS_CLIENT_ID'),
            'client_secret' => env('X_ADS_CLIENT_SECRET'),
        ],

        'linkedin' => [
            'label' => 'LinkedIn Marketing API',
            'authorize_url' => 'https://www.linkedin.com/oauth/v2/authorization',
            'token_url' => 'https://www.linkedin.com/oauth/v2/accessToken',
            'api_base' => 'https://api.linkedin.com/rest',
            'scopes' => ['r_ads', 'r_ads_reporting', 'r_basicprofile'],
            'client_id' => env('LINKEDIN_ADS_CLIENT_ID'),
            'client_secret' => env('LINKEDIN_ADS_CLIENT_SECRET'),
            // LinkedIn pins every REST call to a monthly version; an unpinned call is rejected.
            'version' => env('LINKEDIN_ADS_VERSION', '202411'),
        ],
    ],
];
