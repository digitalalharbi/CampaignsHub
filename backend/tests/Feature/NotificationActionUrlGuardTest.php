<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * NOTIF-LINK-001 — a notification's link lands the reader somewhere that exists, in their portal.
 *
 * ADR 0002 draws the rule this guards, and `router.tsx` states it in as many words: the agency
 * portal mounts the SAME engines the advertiser portal uses, and «their internal links resolve
 * through `usePortalPath()`, so following one keeps the operator inside /agency instead of dropping
 * them into /app mid-journey». `usePortalPath()` honours that by passing a path that already names a
 * portal straight through, untouched.
 *
 * Which is exactly why a hard-coded `/app/…` minted on the server defeats it. Five of the six
 * `action_url` values in this application were hard-coded that way, and four of them named a route
 * that does not exist under `/app` at all — `/app/clients/…`, `/app/requests/…`,
 * `/app/requests/…/journey/…` and `/app/messages/…`. Every one of those notifications ended on «الصفحة
 * غير موجودة», for every reader, in both portals.
 *
 * So the guard is on the SHAPE, not on any one string: a minted `action_url` is portal-relative.
 * The one deliberate exception is named below and has to stay named — the doc on `usePortalPath()`
 * allows a cross-portal link that is «rare, and always explicit», and this is what explicit means.
 */
final class NotificationActionUrlGuardTest extends TestCase
{
    /**
     * The links that name a portal on purpose, each because its page exists in ONE portal only.
     *
     * This list has been wrong in both directions, and the rule it now encodes is what was missing.
     *
     * A portal-relative link resolves against the portal the reader is LOOKING at — `usePortalPath()`
     * reads the current URL — not the portal their membership belongs to. That is right for a page
     * both portals mount, and wrong for a page only one does: an operator who happens to be in /app
     * when the bell rings gets `/app/clients/…`, which is not a route.
     *
     * So: relative when every portal has the page, explicit when only one does. `clients` and
     * `requests` are mounted under /agency and nowhere else — checked in `router.tsx`, where the
     * `/app` block contains neither.
     *
     * `/app/subscriptions` was here for a REASON THAT WAS FALSE — «billing has no agency-side
     * surface» — and `subscriptionsRoutes` is spread into both portals. It is relative now.
     *
     * @var list<string>
     */
    private const SINGLE_PORTAL_PAGES = ['/agency/clients/', '/agency/requests/'];

    public function test_no_notification_links_a_reader_out_of_their_own_portal(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $file) {
            $contents = (string) file_get_contents($file);
            $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file);

            if (! preg_match_all("/'action_url' *=> *['\"]([^'\"]*)/", $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $url) {
                foreach (['/app/', '/agency/', '/portal/', '/influencers/'] as $portal) {
                    if (! str_starts_with($url, $portal)) {
                        continue;
                    }

                    $named = false;
                    foreach (self::SINGLE_PORTAL_PAGES as $allowed) {
                        if (str_starts_with($url, $allowed)) {
                            $named = true;
                        }
                    }

                    if ($named) {
                        continue 2;
                    }

                    $offenders[] = "{$relative} mints {$url}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A notification action_url names a portal, which pins the reader to that portal:', ''],
            $offenders,
            ['', 'Write it portal-relative — "/alerts" — when every portal mounts that page, so',
                'usePortalPath() resolves it where the reader is. Name the portal only when ONE portal',
                'has the page, and add its prefix to SINGLE_PORTAL_PAGES above with the route that',
                'proves it.'],
        )));
    }

    /** @return list<string> */
    private function phpFilesUnderApp(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->getExtension() === 'php') {
                $files[] = $entry->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
