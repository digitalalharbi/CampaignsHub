<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * EXTERNAL-PAGE-001 — the ad destination borrows the domain and belongs to nothing else.
 *
 * ## Why a PHP test holds a static file and an nginx rule
 *
 * Because nothing else can. The page is deliberately outside the application: it is not a route, not
 * a component and not a Blade view, so neither the frontend suite nor the HTTP suite can reach it.
 * What CAN be checked is what the two files say, and every claim below is a claim about their text.
 *
 * ## What it is defending
 *
 * The page went through two wrong addresses before this one. It was served AT a short link, which
 * filed it under a feature whose job is forwarding; then at `/videos`, a name anybody could guess.
 * It is now a single self-contained file at one short address, served by an exact-match rule above
 * the SPA fallback, read from a location nginx marks `internal`.
 *
 * Each assertion here is a way that could silently stop being true: a route re-added, the fallback
 * moved above the rule, the `internal` dropped, a product asset linked in, or a redirect introduced
 * that an ad reviewer would read as cloaking.
 */
final class ExternalAdPageTest extends TestCase
{
    private const PAGE = '../frontend/public/_ext/7kq3md.html';

    private const NGINX = '../infrastructure/nginx/frontend.conf';

    private function page(): string
    {
        $path = base_path(self::PAGE);
        $this->assertFileExists($path, 'the ad page is gone — the address it is advertised at now opens the product');

        return (string) file_get_contents($path);
    }

    private function nginx(): string
    {
        $path = base_path(self::NGINX);
        $this->assertFileExists($path, 'the served nginx config moved; this guard is reading a file that no longer exists');

        return (string) file_get_contents($path);
    }

    /** The one thing a visitor is there to do, as a plain link. */
    public function test_the_button_is_a_real_link_to_the_number(): void
    {
        $page = $this->page();

        $this->assertStringContainsString('href="https://wa.me/966553190369"', $page);

        // Not a scripted navigation: it has to work with scripting off, open in a new tab and
        // long-press to «copy link», all of which a reviewer expects of a destination.
        $this->assertStringNotContainsString('<script', $page, 'the page runs script it does not need');
    }

    /**
     * **It does not forward, and cannot be mistaken for a page that does.**
     *
     * An auto-forward, a timed redirect or a user-agent branch is what an ad platform calls
     * cloaking, and the difference between a landing page and a redirect is the whole reason this
     * page exists rather than a link straight to WhatsApp.
     */
    public function test_it_never_forwards_by_itself(): void
    {
        $page = $this->page();

        foreach (['http-equiv="refresh"', 'http-equiv=\'refresh\'', 'location.replace', 'location.href', 'setTimeout'] as $forward) {
            $this->assertStringNotContainsString($forward, $page, "the page forwards by itself via «{$forward}»");
        }
    }

    /** Given out, not found. */
    public function test_it_is_hidden_from_indexes(): void
    {
        $this->assertMatchesRegularExpression(
            '/<meta\s+name="robots"\s+content="[^"]*noindex/i',
            $this->page(),
            'the page invites indexing',
        );

        $this->assertStringContainsString('X-Robots-Tag "noindex, nofollow, noarchive" always', $this->nginx());
    }

    /**
     * **It depends on nothing the product serves.**
     *
     * One file. A linked stylesheet, bundle or font from the product would make an ad destination
     * fail whenever a deploy renamed a hashed asset — and would tell anybody reading the source what
     * is behind the domain.
     */
    public function test_it_borrows_the_domain_and_nothing_else(): void
    {
        $page = $this->page();

        foreach (['/assets/', 'index.html', '/api/', 'src=', 'rel="stylesheet"'] as $dependency) {
            $this->assertStringNotContainsString($dependency, $page, "the page depends on «{$dependency}»");
        }

        // And it says nothing about the system behind it.
        foreach (['CampaignsHub', 'كامبينز هب', 'campaignshub'] as $tell) {
            $this->assertStringNotContainsString($tell, $page, "the page names «{$tell}»");
        }
    }

    /**
     * The address resolves to the page rather than to the product — and only that address does.
     *
     * The exact-match rule must stay ABOVE the history fallback: the fallback answers every
     * unmatched path with `index.html`, so an ordering change alone would quietly turn the
     * advertised address back into the React app.
     */
    public function test_nginx_serves_it_at_one_address_and_hides_its_file(): void
    {
        $conf = $this->nginx();

        $this->assertStringContainsString('location = /7kq3md {', $conf, 'the address is no longer claimed');
        $this->assertStringContainsString('try_files /_ext/7kq3md.html =404;', $conf);

        // `internal` is what makes the file's own path unopenable from a browser.
        $this->assertMatchesRegularExpression('/location\s+\/_ext\/\s*\{\s*internal;/', $conf, 'the page\'s file is directly reachable');

        $page = strpos($conf, 'location = /7kq3md {');
        $fallback = strpos($conf, 'try_files $uri $uri/ /index.html;');

        $this->assertIsInt($page);
        $this->assertIsInt($fallback);
        $this->assertLessThan($fallback, $page, 'the SPA fallback now answers before the page does, so the address opens the product');
    }

    /** And the product no longer carries a route for it; the two must not both claim the address. */
    public function test_the_product_does_not_route_this_address(): void
    {
        $router = (string) file_get_contents(base_path('../frontend/src/app/router.tsx'));

        $this->assertStringNotContainsString('7kq3md', $router, 'the SPA claims the address too');
    }
}
