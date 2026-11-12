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
    | Trusted proxies — EDGE-CLIENT-IP-001
    |---------------------------------------------------------------------------
    | The addresses whose `X-Forwarded-For` the application believes. Nothing else.
    |
    | Production's chain, from `deploy/`: the host's Caddy terminates TLS (`Caddyfile.campaignshub`) and
    | proxies `api.campaignshub.io` straight to `campaignshub-backend:8000`, and `campaignshub.io` to the
    | frontend nginx, which proxies `/api/`, `/l/`, `/media/covers/`, `/demo/` and `/sanctum/` on to the same
    | backend (`infrastructure/nginx/frontend.conf`). Both hops are containers on the Docker networks
    | `web_proxy` and `internal` (`docker-compose.production.yml`), and the backend publishes no port, so the
    | peer the application sees is always one of them — a private address. Before this setting it trusted
    | no proxy, so `request()->ip()` was that container's address for every visitor and every per-address
    | throttle was one bucket shared by the whole internet.
    |
    | The default is the private ranges Docker assigns networks from, plus loopback — never `*`. The exact
    | subnet of `web_proxy` is the host's, not the repository's: set `TRUSTED_PROXIES` to it (comma
    | separated CIDRs) to narrow this further. A value of `*` or `**` is refused and the default used, so a
    | typo cannot make every peer a proxy.
    |
    | KNOWN LIMIT of the default: another product's container on the shared `web_proxy` network is also a
    | private address, so it could connect to the backend directly and write its own `X-Forwarded-For`. Its
    | own subnet does not close that; the two proxies' exact addresses do. After deploy, read them on the host
    | (`docker inspect` of the Caddy and `campaignshub-frontend` containers) and set them as /32s here. Each
    | entry must be an address or a CIDR no wider than /8 (IPv4) or /7 (IPv6); anything else is dropped.
    |
    | Only `X-Forwarded-For` is trusted. `X-Forwarded-Host` never is: the owner of a public request is
    | decided from the Host header (`RequestOwnerResolver`), and a forwarded host must not be able to pick it.
    */
    'trusted_proxies' => (static function (): array {
        $default = ['127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'];
        $raw = trim((string) env('TRUSTED_PROXIES', ''));
        $list = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $p): bool => $p !== ''));

        // `*`/`**` are dropped, never widened to: `172.18.0.2/32,*` keeps the pinned address and nothing more.
        $list = array_values(array_filter($list, static fn (string $p): bool => $p !== '*' && $p !== '**'));

        return $list === [] && $raw === '' ? $default : $list;
    })(),

    /*
    | EMAIL-GAPS — the «new sign-in» email. OFF until the Owner approves it, because switching it on mails
    | real people. Devices are recorded either way, so the day it is switched on nobody is told that a
    | device they have used all along is new.
    */
    'new_sign_in_alert' => (bool) env('NEW_SIGN_IN_ALERT_ENABLED', false),

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
