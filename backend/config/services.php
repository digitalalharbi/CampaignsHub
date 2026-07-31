<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Social sign-in (LOGIN-004)
    |--------------------------------------------------------------------------
    |
    | Authorization Code + PKCE. A provider counts as configured only when it has BOTH a client id
    | and a client secret — `OAuthProviderRegistry` reports anything else as Awaiting Credentials,
    | and the sign-in page renders it as unavailable rather than as a button that cannot work.
    |
    | Apple's "secret" is a short-lived JWT signed with a private key rather than a static string,
    | so its own fields are listed separately; the registry treats the key as the secret for the
    | purpose of deciding whether the provider is usable.
    |
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Payment gateways (PAY-001)
    |---------------------------------------------------------------------------
    | Moyasar is the official, primary gateway; Stripe is the alternative. Neither
    | has credentials on this install, so both report Awaiting Credentials: no
    | session can be opened and no webhook can verify.
    |
    | BOTH values are required per provider. A secret key with no webhook secret
    | could take money that nothing is able to confirm — the customer would be
    | charged and no account would ever activate.
    */
    'moyasar' => [
        'secret_key' => env('MOYASAR_SECRET_KEY'),
        'publishable_key' => env('MOYASAR_PUBLISHABLE_KEY'),
        'webhook_token' => env('MOYASAR_WEBHOOK_TOKEN'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

];
