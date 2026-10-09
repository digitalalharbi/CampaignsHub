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
            'label' => 'Google Analytics 4',
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
             * Its OWN client, not Google Ads'. They are different consent screens asking for
             * different scopes, and sharing one would mean a customer connecting Analytics is asked
             * for advertising access — or worse, that revoking one silently breaks the other.
             */
            'client_id' => env('GA4_CLIENT_ID'),
            'client_secret' => env('GA4_CLIENT_SECRET'),
        ],
    ],
];
