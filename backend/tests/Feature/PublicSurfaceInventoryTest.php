<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Routing\Router;
use Tests\TestCase;

/**
 * IP-AUDIT-001 — the public API surface is a list somebody decided, not a list that grew.
 *
 * Every `/api` route with no authentication middleware is reachable by a stranger. Some are meant to be:
 * sign-in and registration, the public report and its files (token-gated), payment and platform webhooks
 * (signature-gated), the client portal (its own session token, checked in the controller), the public
 * catalogue and legal pages, health. This test records the set as it stands, so adding a route that skips
 * authentication — by forgetting a middleware group, which is the usual way — fails here until somebody
 * adds it with a reason in the pull request that adds it. It does not judge a route; it forces the
 * question to be asked.
 *
 * The three routes registered only outside production (the dev status page and the payment sandbox) are
 * not part of the surface a customer can reach and are left out of the comparison.
 */
final class PublicSurfaceInventoryTest extends TestCase
{
    private const OUTSIDE_PRODUCTION_ONLY = ['GET api/v1/dev/status', 'GET api/v1/payments/sandbox', 'POST api/v1/payments/sandbox/confirm'];

    /** @var list<string> */
    private const PUBLIC = [
        'DELETE api/v1/client/team/{membership}',
        'DELETE api/v1/requests/uploads/{file}',
        // AUTH-LOGIN-UX-002 — which ways in the sign-in page may offer; booleans only.
        'GET api/v1/auth/methods',
        'GET api/v1/auth/oauth/providers',
        'GET api/v1/auth/oauth/{provider}/callback',
        'GET api/v1/auth/registration/{registration}',
        'GET api/v1/brand',
        'GET api/v1/client/branding',
        'GET api/v1/client/branding/logo',
        'GET api/v1/client/campaigns',
        'GET api/v1/client/files',
        'GET api/v1/client/invoices',
        'GET api/v1/client/invoices/{invoice}',
        'GET api/v1/client/invoices/{invoice}/pdf',
        'GET api/v1/client/messages',
        'GET api/v1/client/messages/{thread}',
        'GET api/v1/client/overview',
        'GET api/v1/client/quotes',
        'GET api/v1/client/quotes/{quote}',
        'GET api/v1/client/reports',
        'GET api/v1/client/requests',
        'GET api/v1/client/requests/{reference}',
        'GET api/v1/client/requests/{reference}/files/{file}',
        'GET api/v1/client/requests/{reference}/journey',
        'GET api/v1/client/session',
        'GET api/v1/client/spaces',
        'GET api/v1/client/team',
        'GET api/v1/client/visibility',
        'GET api/v1/commerce/store-installs/{claim}',
        'GET api/v1/health',
        'GET api/v1/invitations/{token}',
        'GET api/v1/legal',
        'GET api/v1/oauth/ads/{provider}/callback',
        'GET api/v1/oauth/commerce/{provider}/callback',
        'GET api/v1/oauth/measurement/{provider}/callback',
        'GET api/v1/payments/providers',
        'GET api/v1/plans',
        'GET api/v1/plans/{code}/quote',
        'GET api/v1/public/catalog/paid-media-services',
        // FORMS-HUB-001 — a published lead form as a stranger meets it: the slug is unguessable, only a PUBLISHED form of a workspace
        // with the module on answers, and the three are throttled per form and address (`public-form`).
        'GET api/v1/public/forms/{slug}',
        'GET api/v1/public/pages/{page}',
        'GET api/v1/ready',
        'GET api/v1/reports/download/{token}',
        'GET api/v1/reports/print/{token}',
        'GET api/v1/reports/print/{token}/evidence/{evidence}',
        'GET api/v1/reports/print/{token}/logo',
        'GET api/v1/reports/print/{token}/logo/{role}',
        'GET api/v1/reports/shared/{token}',
        'GET api/v1/reports/shared/{token}/attribution',
        'GET api/v1/reports/shared/{token}/widgets',
        'GET api/v1/reports/shared/{token}/branding',
        'GET api/v1/reports/shared/{token}/branding/logo',
        'GET api/v1/reports/shared/{token}/branding/logo/{role}',
        'GET api/v1/reports/shared/{token}/creatives',
        'GET api/v1/reports/shared/{token}/creatives/comparison',
        'GET api/v1/reports/shared/{token}/creatives/summary',
        'GET api/v1/reports/shared/{token}/creatives/{creative}',
        'GET api/v1/reports/shared/{token}/download/{format}',
        'GET api/v1/reports/shared/{token}/live',
        'GET api/v1/reports/shared/{token}/live/content/{key}',
        'GET api/v1/reports/shared/{token}/live/platform/{provider}',
        'GET api/v1/requests/meta',
        'GET api/v1/requests/track/{token}',
        'GET api/v1/requests/track/{token}/files/{file}',
        'GET api/v1/salla/enter',
        'GET api/v1/salla/open',
        'GET api/v1/subscriptions/invoices/shared/{token}',
        'GET api/v1/webhooks/{kind}/{provider}',
        'POST api/v1/auth/email-code/start',
        'POST api/v1/auth/email-code/verify',
        'POST api/v1/auth/email/verify',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/login',
        'POST api/v1/auth/method',
        'POST api/v1/auth/oauth/{provider}/callback',
        'POST api/v1/auth/oauth/{provider}/start',
        'POST api/v1/auth/phone/start',
        'POST api/v1/auth/phone/verify',
        'POST api/v1/auth/register',
        'POST api/v1/auth/registration/verify-email',
        'POST api/v1/auth/registration/{registration}/checkout',
        'POST api/v1/auth/registration/{registration}/resend',
        'POST api/v1/auth/registration/{registration}/verify-mobile',
        'POST api/v1/auth/reset-password',
        'POST api/v1/auth/tokens',
        'POST api/v1/billing/webhook/{provider}',
        'POST api/v1/client/invoices/{invoice}/pay',
        'POST api/v1/client/login/start',
        'POST api/v1/client/login/verify',
        'POST api/v1/client/logout',
        'POST api/v1/client/messages',
        'POST api/v1/client/messages/{thread}',
        'POST api/v1/client/quotes/{quote}/approve',
        'POST api/v1/client/quotes/{quote}/reject',
        'POST api/v1/client/requests',
        'POST api/v1/client/requests/{reference}/reply',
        'POST api/v1/client/team',
        'POST api/v1/contact',
        'POST api/v1/data-deletion',
        'POST api/v1/data-deletion/status',
        'POST api/v1/data-deletion/verify',
        'POST api/v1/data-requests',
        'POST api/v1/invitations/accept',
        'POST api/v1/payments/webhook/{provider}',
        'POST api/v1/public/forms/{slug}/events',
        'POST api/v1/public/forms/{slug}/submissions',
        'POST api/v1/requests',
        'POST api/v1/requests/track/{token}/reply',
        'POST api/v1/requests/uploads',
        'POST api/v1/requests/uploads/start',
        'POST api/v1/requests/verify/check',
        'POST api/v1/requests/verify/start',
        'POST api/v1/support/tickets',
        'POST api/v1/webhooks/data-deletion/{provider}',
        'POST api/v1/webhooks/{kind}/{provider}',
    ];

    /** @return list<string> */
    private function unauthenticated(): array
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $found = [];

        foreach ($router->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = array_map(static fn ($m): string => is_string($m) ? $m : 'closure', $router->gatherRouteMiddleware($route));
            $authenticated = collect($middleware)->contains(
                static fn (string $m): bool => str_starts_with($m, 'auth') || str_contains($m, 'Authenticate') || str_contains($m, 'platform.admin') || $m === 'tenant',
            );

            if ($authenticated) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if ($method !== 'HEAD') {
                    $found[] = $method.' '.$route->uri();
                }
            }
        }

        $found = array_values(array_diff($found, self::OUTSIDE_PRODUCTION_ONLY));
        sort($found);

        return $found;
    }

    public function test_the_unauthenticated_api_surface_is_exactly_the_recorded_one(): void
    {
        $found = $this->unauthenticated();

        $this->assertSame(
            [],
            array_values(array_diff($found, self::PUBLIC)),
            "These routes can be reached with no authentication middleware and are not on the recorded list.\n"
            .'Add the middleware, or record the route here with the reason it is public.',
        );
        $this->assertSame(
            [],
            array_values(array_diff(self::PUBLIC, $found)),
            'These recorded public routes no longer exist or are now authenticated; remove them from the list.',
        );
    }

    public function test_nothing_that_writes_or_reads_the_operators_own_data_is_on_the_list_by_accident(): void
    {
        // The operator's own workspaces live behind these prefixes; none may appear without authentication.
        foreach (self::PUBLIC as $route) {
            $this->assertDoesNotMatchRegularExpression('#api/v1/(projects|admin|app|agency|settings|team|integrations|campaigns|automation|agents|reports/\{report\})#', $route, $route);
        }
    }
}
