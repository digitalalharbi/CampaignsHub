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
     * Empty, and that is the point.
     *
     * It held one entry — `/app/subscriptions`, excused on the grounds that «billing has no
     * agency-side surface». That was wrong: `router.tsx` spreads `subscriptionsRoutes` into the
     * agency portal as well as the advertiser one, under a comment naming it «the agency's own plan
     * with CampaignsHub». So the exception pinned an agency operator to /app for a page their own
     * portal has, which is the exact defect this guard exists to prevent.
     *
     * Kept as a mechanism rather than deleted: a genuine cross-portal link is still possible, and
     * `usePortalPath()` documents one as «rare, and always explicit». This is where explicit lives.
     * A new entry should be hard to add without saying why, which an empty list makes it.
     *
     * @var array<string, string>
     */
    private const CROSS_PORTAL_BY_DESIGN = [];

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

                    if ((self::CROSS_PORTAL_BY_DESIGN[$relative] ?? null) === $url) {
                        continue 2;
                    }

                    $offenders[] = "{$relative} mints {$url}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['A notification action_url names a portal, which pins the reader to that portal:', ''],
            $offenders,
            ['', 'Write it portal-relative — "/clients/{id}" — so usePortalPath() resolves it in the',
                'portal the reader is actually in. If leaving the portal is the intent, name the file',
                'and the url in CROSS_PORTAL_BY_DESIGN above and say why.'],
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
