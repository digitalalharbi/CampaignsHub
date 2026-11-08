<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Measurement platform integrations (GA4-INTEGRATION-001)
|--------------------------------------------------------------------------
|
| Google Analytics 4, and kept apart from `ad_platforms.php` for the same reason
| `commerce_platforms.php` is: it is not an advertising platform and never will be. Connecting it
| discovers PROPERTIES on the client's own site, not an ad account and its campaigns, and its
| revenue is measured under a different attribution model from any ad platform's.
|
| One file holding both would invite a shared loop over «all platforms» that then has to branch on
| every line — which is exactly the flattening `ProviderCatalogue` exists to prevent, and which
| would end with GA4 counted as a connected advertising platform and folded into a blended return.
|
*/

return [
    'platforms' => [
        'ga4' => [
            'label' => 'Google Analytics',
            'authorize_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            /*
             * The DATA API is the base, because that is what reports. Discovery talks to the Admin
             * API, whose host is different and is named where it is called — a single `api_base`
             * cannot stand for both, and pretending it could is how a discovery call ends up pointed
             * at the reporting host.
             */
            'api_base' => 'https://analyticsdata.googleapis.com/v1beta',
            /*
             * Read-only, and only this one. GA4 also publishes `analytics.edit` and
             * `analytics.manage.users`; neither is needed to READ a property, and requesting one
             * would put a consent screen in front of a customer claiming this product may change
             * their Analytics configuration.
             */
            'scopes' => ['https://www.googleapis.com/auth/analytics.readonly'],
            /*
             * GA4-PLATFORM-GOOGLE-CLIENT-001 — GA4 reads the platform's Google OAuth client (the sign-in one).
             *
             * Only the SCOPE keeps the consents apart: this flow asks for `analytics.readonly` and nothing else, never
             * `include_granted_scopes`, so a customer connecting Analytics is not asked for sign-in or advertising access
             * and the grant is revoked on its own. The callback above must be registered on that same client.
             *
             * The pair is chosen TOGETHER: `GA4_CLIENT_ID` set means both GA4 keys are used (a separate client stays
             * possible), absent means both sign-in keys. Mixing one of each is an `invalid_client` at Google that names
             * nothing. A value stored on the provider's admin row wins over both, as for every provider — clearing that
             * row is what makes this take effect.
             */
            'client_id' => env('GA4_CLIENT_ID') ?: env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GA4_CLIENT_ID') ? env('GA4_CLIENT_SECRET') : env('GOOGLE_CLIENT_SECRET'),
        ],
    ],
];
