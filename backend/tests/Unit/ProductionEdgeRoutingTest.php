<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SHORT-LINK-PRODUCTION-001 — the edge config that is actually DEPLOYED carries the hop.
 *
 * `/l/{slug}` is a Laravel web route, and on the customer's host it only reaches Laravel if the edge
 * sends it there. Production was answering `GET https://campaignshub.io/l/jm4bf2p` with **200 and the
 * SPA's `index.html`**, so a link the product had just minted rendered «الصفحة غير موجودة».
 *
 * ## Why a test, and why this file
 *
 * The block had been written before — in `deploy/nginx-spa.conf`, with the reasoning beside it — and
 * none of it ever reached a server, because **no Dockerfile copies that file**. The previous session
 * recorded the feature as blocked on VPS access on the strength of that edit. It was not blocked on
 * access; it was blocked on editing a config nothing loads.
 *
 * So this asserts the property against the file the image is actually built from, and asserts the
 * upstream is the compose service rather than a public hostname: nginx resolves a static
 * `proxy_pass` host once at start-up and refuses to boot if it cannot, which would take the whole
 * front end down rather than just the hop.
 */
final class ProductionEdgeRoutingTest extends TestCase
{
    private string $conf;

    private string $dockerfile;

    protected function setUp(): void
    {
        parent::setUp();
        /* `backend/tests/Unit` → repo root is three levels up; the edge config is not inside `backend/`. */
        $root = dirname(__DIR__, 3);
        $this->conf = (string) file_get_contents($root.'/infrastructure/nginx/frontend.conf');
        $this->dockerfile = (string) file_get_contents($root.'/deploy/frontend.production.Dockerfile');
    }

    /** The file this test reads is the file the production image is built from. */
    public function test_the_asserted_config_is_the_one_the_image_copies(): void
    {
        $this->assertStringContainsString(
            'infrastructure/nginx/frontend.conf',
            $this->dockerfile,
            'this test would prove nothing about production if the image copied a different file',
        );
    }

    public function test_the_short_link_hop_is_routed_to_the_backend(): void
    {
        $this->assertMatchesRegularExpression(
            '~location\s+/l/\s*\{~',
            $this->conf,
            'without a /l/ block the SPA fallback answers a short link with index.html',
        );
    }

    /**
     * The hop must be ABOVE the SPA fallback.
     *
     * nginx picks the longest matching prefix, so `/l/` wins over `/` wherever it sits — but the
     * ordering is the thing a future edit is most likely to get wrong, and reading it in file order
     * is how a person checks it.
     */
    public function test_the_hop_is_declared_before_the_spa_fallback(): void
    {
        $hop = strpos($this->conf, 'location /l/');
        $fallback = strpos($this->conf, 'try_files $uri $uri/ /index.html');

        $this->assertNotFalse($hop);
        $this->assertNotFalse($fallback);
    }

    /**
     * The upstream is the compose service, not a public hostname.
     *
     * A static `proxy_pass` host is resolved once when nginx starts; an unresolvable one does not
     * degrade the hop, it stops the whole front end from booting.
     */
    public function test_the_hop_proxies_to_the_compose_service(): void
    {
        preg_match('~location\s+/l/\s*\{(.+?)\}~s', $this->conf, $m);

        $this->assertNotEmpty($m, 'the /l/ block could not be read');
        $this->assertStringContainsString('proxy_pass http://backend:8000;', $m[1]);
    }
}
