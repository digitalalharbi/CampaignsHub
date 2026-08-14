<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Relax rate limits (LOCAL E2E ONLY)
    |---------------------------------------------------------------------------
    | When true, per-IP throttling is skipped so a single-IP local E2E suite does not hit abuse limits.
    | Defaults to false. It is HARD-IGNORED in production and staging (see ConditionalThrottle) — setting it
    | there can never disable the limits. Leave false everywhere except a local machine running the browser
    | suite.
    */
    'relax_rate_limits' => env('E2E_RELAX_RATE_LIMITS', false),

    /*
    |---------------------------------------------------------------------------
    | Data-subject requests per minute (LEGAL-THROTTLE-001)
    |---------------------------------------------------------------------------
    | `/data-deletion` and `/data-requests`. Keyed by the person NAMED in the request,
    | with the address kept only as an abuse ceiling — a literal per-IP throttle rationed a legal
    | right by whoever else happened to share the router, and `/data-deletion` is the URL handed to
    | Meta, TikTok, Snapchat and Google as this platform's deletion contact.
    |
    | The `_local` pair exists for the same reason `auth.login_throttle_local` does: the limiter has
    | to stay reachable by a test. `DataSubjectRequestThrottleTest` sets them to the production
    | figures below and drives the real endpoint, so what it measures is the live keying.
    */
    'data_request_throttle_per_subject' => (int) env('DATA_REQUEST_PER_SUBJECT', 3),
    'data_request_throttle_per_address' => (int) env('DATA_REQUEST_PER_ADDRESS', 12),
    'data_request_throttle_per_subject_local' => (int) env('DATA_REQUEST_PER_SUBJECT_LOCAL', 60),
    'data_request_throttle_per_address_local' => (int) env('DATA_REQUEST_PER_ADDRESS_LOCAL', 600),
];
