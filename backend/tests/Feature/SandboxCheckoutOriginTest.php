<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Billing\Providers\SandboxPaymentProvider;
use Tests\TestCase;

/**
 * AUTH-SESSION-RACE-OBS — the checkout a customer is sent to must not move them to another HOST.
 *
 * The observation's second signature reported a session cookie held for TWO hosts after one
 * registration journey: «XSRF-TOKEN@127.0.0.1/, campaignshub-session@127.0.0.1/, XSRF-TOKEN@
 * localhost/, campaignshub-session@localhost/», with `/auth/me` answering 401 after a login that
 * answered 200. `SESSION_DOMAIN` is host-scoped, so those are two SESSIONS, and whichever `/auth/me`
 * carried had never been authenticated. The row's open question was «which generated link still uses
 * `APP_URL`».
 *
 * This is the link. `SandboxPaymentProvider` builds its checkout on `config('app.url')`, and the
 * registration walk NAVIGATES there — the «Pay now (sandbox)» button is server-rendered by
 * `SandboxCheckoutController`, not by the SPA. Where the API and the SPA are different hosts, which
 * is the deployed shape and the gate's shape, that navigation hands the browser a second session on
 * the API's host and brings it back.
 *
 * `Frontend::origin()` is the same correction `ShortLinkHops::shareUrl()` already carries, for the
 * same reason: a URL a PERSON follows belongs on the origin the person is already on. The SPA proxies
 * `/api`, so the endpoint is reached either way — what changes is whose cookie jar is written.
 *
 * The two hosts are configured DIFFERENTLY here on purpose. With one origin configured for both, a
 * URL built from the wrong one is indistinguishable from a URL built from the right one, and this
 * test would pass against the defect it exists to catch.
 */
final class SandboxCheckoutOriginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'https://api.example.test',
            'brand.frontend_url' => 'https://app.example.test',
            'subscriptions.sandbox_secret' => 'sandbox-secret-for-tests',
        ]);
    }

    public function test_the_checkout_url_keeps_the_customer_on_the_origin_they_are_already_on(): void
    {
        $session = app(SandboxPaymentProvider::class)->createSession([
            'reference' => 'subscription:abc:growth:monthly',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $url = (string) ($session['checkout_url'] ?? '');

        $this->assertStringStartsWith('https://app.example.test/', $url, 'the checkout sends the browser to the API host, which is a second cookie jar');
        $this->assertStringNotContainsString('api.example.test', $url);
    }

    /** And the endpoint is still the one that renders the page — only the origin changed. */
    public function test_the_checkout_url_still_points_at_the_sandbox_endpoint_with_its_reference(): void
    {
        $session = app(SandboxPaymentProvider::class)->createSession([
            'reference' => 'subscription:abc:growth:monthly',
            'amount' => 1000,
            'currency' => 'USD',
        ]);

        $url = (string) ($session['checkout_url'] ?? '');

        $this->assertStringContainsString('/api/v1/payments/sandbox?ref=', $url);
        /*
         * The reference stays percent-encoded in the QUERY. An idempotency key contains colons, and
         * a percent-encoded colon inside a path segment is not decoded back into a route parameter —
         * the charge was then looked up by a key that did not exist and answered 404 for a real
         * payment. That is recorded at the call site and asserted here so a tidy-up cannot undo it.
         */
        $this->assertStringContainsString(rawurlencode('subscription:abc:growth:monthly'), $url);
    }
}
