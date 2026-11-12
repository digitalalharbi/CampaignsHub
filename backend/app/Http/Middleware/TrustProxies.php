<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

/**
 * EDGE-CLIENT-IP-001 — the visitor's address is the visitor's, not the proxy's.
 *
 * Replaces the framework's global `TrustProxies`, which trusted nothing here: every request reached the
 * application from Caddy or the frontend nginx container, so `request()->ip()` was one of three private
 * addresses for the whole internet, every access and audit row carried it, and every per-address throttle was a
 * single bucket the first busy visitor emptied for everybody else.
 *
 * Two decisions, both deliberate:
 *
 * - **Which peers.** `security.trusted_proxies`: the private ranges the Docker networks in front of the backend
 *   are drawn from, or the exact subnet from `TRUSTED_PROXIES`. Never `*` — see `config/security.php`. Symfony
 *   walks `X-Forwarded-For` from the right and stops at the first address that is not a trusted proxy, so a
 *   visitor who writes their own `X-Forwarded-For` only adds entries to the left of the one Caddy appended.
 * - **Which header.** `X-Forwarded-For` alone. The forwarded host, port, prefix and scheme stay untrusted, so
 *   nothing that decides an owner from the Host (`RequestOwnerResolver`), builds a URL or validates a signature
 *   changes behaviour with this.
 */
final class TrustProxies extends Middleware
{
    /** @return list<string> */
    protected function proxies(): array
    {
        /** @var list<string> $list */
        $list = (array) config('security.trusted_proxies', []);

        return array_values(array_filter($list, static fn ($p): bool => is_string($p) && $p !== '' && $p !== '*' && $p !== '**'));
    }

    protected function headers(): int
    {
        return Request::HEADER_X_FORWARDED_FOR;
    }
}
