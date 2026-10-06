<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * SHARE-PREVIEW-IMAGE-REACHABLE-001 — the picture is not fetched by the crawler that read the tag.
 *
 * ## The production defect, measured
 *
 * The edge diverts `/r/...` to Laravel only for a crawler user-agent, which is right for the
 * metadata DOCUMENT: a human typing the address wants the report rather than an OpenGraph page.
 *
 * It is wrong for the image that document points at. `og:image` is fetched by a SEPARATE image
 * fetcher, by every recipient's own client when the message is opened, and by caches in between —
 * all of them sending ordinary user-agents. On Production, on 2026-10-05:
 *
 *     GET https://campaignshub.io/r/<token>/preview.png              → 200 text/html, 6512 bytes
 *     GET https://campaignshub.io/r/<token>/preview.png  (WhatsApp)   → 404 from Laravel, correctly
 *
 * The first is the SPA shell. A card whose picture is an HTML document renders as no card, so every
 * client link previewed blank in the chat it was pasted into — while the metadata above it was
 * perfectly correct. That is why it survived: the one request nobody makes from inside the product
 * is this one, and the suite's own fetch of `og:image` goes through Laravel's router, which has no
 * nginx in front of it.
 *
 * ## Why this reads the CONFIG as text
 *
 * The defect is in the edge, not in the application — Laravel's route has always been right. The
 * only artefact that decides it is the file the frontend image is built from, so that file is what
 * is asserted, the way `ExternalAdPageTest` asserts the rule for the external page.
 */
final class SharePreviewImageReachableTest extends TestCase
{
    private function conf(): string
    {
        $path = base_path('../infrastructure/nginx/frontend.conf');

        $this->assertFileExists($path, 'the nginx config the frontend image is built from has moved');

        return (string) file_get_contents($path);
    }

    /** The image path is routed to the application, for everybody. */
    public function test_the_preview_image_is_proxied_to_the_application(): void
    {
        $conf = $this->conf();

        $this->assertMatchesRegularExpression(
            '/location\s*~\s*\^\/r\/\[A-Za-z0-9\]\+\/preview\\\\\.png\$\s*\{[^}]*proxy_pass\s+http:\/\/backend:8000;[^}]*\}/',
            $conf,
            'nothing routes the preview image to Laravel, so a chat client receives index.html',
        );
    }

    /**
     * And it is NOT behind the user-agent test.
     *
     * This is the whole defect. The block that gates on a crawler user-agent is correct for the
     * metadata document and fatal for the picture, because the client fetching the picture is not
     * the crawler that read the tag.
     */
    public function test_the_preview_image_does_not_depend_on_a_crawler_user_agent(): void
    {
        $conf = $this->conf();

        preg_match(
            '/location\s*~\s*\^\/r\/\[A-Za-z0-9\]\+\/preview\\\\\.png\$\s*\{(.*?)\}/s',
            $conf,
            $match,
        );

        $this->assertNotEmpty($match[1] ?? '', 'the preview image location is missing');
        $this->assertStringNotContainsString(
            'http_user_agent',
            $match[1],
            'the picture was gated on a user-agent that the client fetching it does not send',
        );
    }

    /**
     * It is matched BEFORE the generic rule, which would otherwise swallow it.
     *
     * nginx takes the first matching regular-expression location, so order is the whole behaviour
     * here: placed after `^/(r|reports/share)/` this block would never run, and the file would read
     * as though the defect were fixed.
     */
    public function test_the_preview_image_is_matched_before_the_generic_report_rule(): void
    {
        $conf = $this->conf();

        $image = strpos($conf, 'preview\\.png$');
        $generic = strpos($conf, '^/(r|reports/share)/');

        $this->assertIsInt($image, 'the preview image location is missing');
        $this->assertIsInt($generic, 'the crawler diversion has moved');
        $this->assertLessThan(
            $generic,
            $image,
            'nginx takes the FIRST matching regex location, so this one never runs',
        );
    }

    /**
     * NGINX-LOCATION-REGEX-001 — a brace in a location regex takes the whole site down.
     *
     * The first version of the block above read `[A-Za-z0-9]{16,64}`, mirroring the route's own token
     * shape. nginx treats `{` and `}` as BLOCK DELIMITERS: a location regular expression containing
     * them must be quoted, or the file does not parse. Unquoted, nginx refused to start, the frontend
     * container never came up, and every address on the domain answered 502 — the homepage, the
     * login page and the API alike. A routing nicety took the product down.
     *
     * The deploy reported success, because it builds and starts containers rather than asking
     * whether nginx accepted its configuration.
     *
     * So: no unquoted brace in any location regex in this file. The length bound was never this
     * file's job — `routes/web.php` constrains the token and the controller refuses one that does not
     * resolve.
     */
    public function test_no_location_regex_carries_an_unquoted_brace(): void
    {
        $offenders = [];

        foreach (explode("\n", $this->conf()) as $line) {
            $trimmed = trim($line);

            if (! str_starts_with($trimmed, 'location ~')) {
                continue;
            }

            // The pattern is everything up to the opening block brace.
            $pattern = trim((string) preg_replace('/\s*\{\s*$/', '', $trimmed));
            $quoted = str_contains($pattern, '"') || str_contains($pattern, "'");

            if (! $quoted && preg_match('/\{|\}/', substr($pattern, strlen('location ~')))) {
                $offenders[] = $trimmed;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'an unquoted { or } in a location regex — nginx will refuse to start and the whole site 502s',
        );
    }

    /**
     * The REPORT itself is still the SPA for a human, and still the metadata for a crawler.
     *
     * A fix that routed all of `/r/` to Laravel would hand every client opening their own report an
     * OpenGraph document instead of the page.
     */
    public function test_the_report_page_itself_is_untouched(): void
    {
        $conf = $this->conf();

        $this->assertStringContainsString('location ~ ^/(r|reports/share)/ {', $conf);
        $this->assertStringContainsString('facebookexternalhit', $conf);
        $this->assertStringContainsString('try_files $uri $uri/ /index.html;', $conf);
    }
}
