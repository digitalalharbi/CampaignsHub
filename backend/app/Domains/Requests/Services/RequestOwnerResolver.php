<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Support\RequestOwner;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Http\Request;

/**
 * PLATFORM-SERVICE-INBOX-001 — who a public service request belongs to, decided here and nowhere else.
 *
 * The answer comes from the host the request ARRIVED on, in this order, and from nothing the visitor typed:
 *
 *   1. a subscriber's own `portal_domain` (and `portal_enabled`) → that subscriber. Unchanged.
 *   2. one of the platform's own hosts → the PLATFORM. No tenant is involved at all.
 *      (`localhost` is one only when the configuration names it.)
 *   3. outside production only: the single `is_default_portal` tenant, then `REQUESTS_PORTAL_TENANT_ID` —
 *      the local and test convenience that has always been there.
 *   4. otherwise nobody → the caller refuses, and says why in the log.
 *
 * What changed is rule 2 and the reach of rule 3. A request from the official site used to be handed to
 * whichever subscriber was flagged «default», and so did a request on a host nobody recognised: a visitor's
 * name, phone and brief delivered to an agency that never asked for them. In production there is no default
 * owner any more. A forwarded-host header is not consulted: no proxy is trusted to supply one.
 *
 * Neither `tenant_id`, `Origin` nor `Referer` is read. A browser can send any of them.
 */
final class RequestOwnerResolver
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '::1', '[::1]'];

    public function __construct(private readonly PortalTenantResolver $tenants) {}

    public function resolve(Request $request): ?RequestOwner
    {
        $host = strtolower($request->getHost());

        $byDomain = Tenant::query()->where('portal_enabled', true)
            ->whereRaw('lower(portal_domain) = ?', [$host])->first();
        if ($byDomain !== null) {
            return new RequestOwner(ExternalRequest::OWNER_TENANT, $byDomain);
        }

        if (in_array($host, self::platformHosts(), true)) {
            return new RequestOwner(ExternalRequest::OWNER_PLATFORM, null);
        }

        if (! app()->environment('production')) {
            $fallback = $this->tenants->resolve($request);
            if ($fallback !== null) {
                return new RequestOwner(ExternalRequest::OWNER_TENANT, $fallback);
            }
        }

        return null;
    }

    /**
     * The hosts that ARE CampaignsHub: the ones named in `REQUESTS_PLATFORM_HOSTS`, or — when that is unset —
     * the public site, the application and the API as this install already publishes them.
     *
     * @return list<string>
     */
    public static function platformHosts(): array
    {
        $configured = config('requests.platform_hosts');

        if (is_array($configured) && $configured !== []) {
            $hosts = $configured;
        } else {
            $domain = (string) config('brand.domain');
            $hosts = [$domain, $domain === '' ? '' : 'www.'.$domain];

            foreach (['brand.frontend_url', 'brand.app_url', 'brand.application_url', 'brand.api_url'] as $key) {
                $hosts[] = (string) parse_url((string) config($key), PHP_URL_HOST);
            }

            // A developer's machine publishes itself as `localhost`. That is nobody's official site, so it is
            // never a platform host by inference — only when `REQUESTS_PLATFORM_HOSTS` names it outright.
            $hosts = array_diff($hosts, self::LOOPBACK);
        }

        $clean = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '') {
                $clean[$host] = $host;
            }
        }

        return array_values($clean);
    }
}
