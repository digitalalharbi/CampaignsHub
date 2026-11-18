<?php

declare(strict_types=1);

/**
 * NOTIF-PUSH-001 — Web Push (VAPID). Free, standards-based, no vendor account.
 *
 * The key pair is generated locally with `php artisan webpush:vapid` (it prints to the console and writes nothing), and lives ONLY
 * in the environment of the server that sends. Nothing here has a default key: with no pair set the feature is simply off — the
 * settings page says so and nothing is sent — rather than falling back to a key that is committed somewhere.
 */
return [
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // The contact the push services may use if we misbehave. A mailto: or an https: URL.
        'subject' => env('VAPID_SUBJECT', 'mailto:info@campaignshub.io'),
    ],

    /*
     * The only hosts a subscription endpoint may point at. The server POSTs to the endpoint the browser hands over, so an
     * unchecked endpoint would be a way to make this server call any address (SSRF). These are the push services of Chrome / Edge /
     * Android (FCM), Firefox, Safari / iOS (Apple) and Windows (WNS). Suffix match on a dot boundary; https only; port 443 only.
     */
    'allowed_hosts' => [
        'fcm.googleapis.com',
        'android.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ],

    // Most pushes one person gets in an hour; the rest wait for one summary (see FlushDeferredPush).
    'ceiling_per_hour' => 6,

    // Seconds a push service keeps an undelivered message (a closed phone), and how long we wait for it to answer.
    'ttl' => 4 * 3600,
    'timeout' => 10,
];
