<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BRAND-CANONICAL-001 — one configuration key holds the product's name.
 *
 * ## Why a sweep and not a code review
 *
 * The frontend already refuses a second identity: `oneCanonicalIdentity.test.ts` reads the source
 * tree and fails on any surface that paints its own wordmark. The backend had no such rule, and it
 * had two keys — `brand.name`, whose own docblock says «change here (or via env) — never hard-code
 * the name in code», and `app.name`, which is Laravel's, defaults to «Laravel», and is what
 * `MAIL_FROM_NAME` and `VITE_APP_NAME` interpolate.
 *
 * Two of them were being read for the product's identity: the Open Graph `site_name` on a client's
 * shared report — the card WhatsApp draws — and the title of the page served at `/` on the API host.
 * Nothing a reader saw was wrong, because every env template we ship sets `APP_NAME=CampaignsHub`.
 * That is exactly what makes it worth a guard: a wrong source that lands on the right value produces
 * no complaint, and the install that never set it sends «Laravel» to a client's chat.
 *
 * ## What this does NOT forbid
 *
 * `config('app.name')` is legitimate where the FRAMEWORK is the subject — a queue name, a log
 * channel, a cache prefix. The sweep looks at the surfaces that render an identity to a person:
 * controllers and views. A framework value that never reaches a reader is not a second brand.
 */
final class BrandIdentitySourceTest extends TestCase
{
    #[Test]
    public function the_brand_config_can_name_the_product(): void
    {
        $this->assertNotSame('', trim((string) config('brand.name')), 'brand.name is empty, so nothing can resolve the product’s name');
    }

    /**
     * No controller and no view reads `app.name`.
     *
     * The message names the replacement rather than only the offence, because the fix is one word and
     * a reader who has just been failed should not have to go and find it.
     */
    #[Test]
    public function no_rendered_surface_reads_the_framework_name(): void
    {
        $roots = [base_path('app'), base_path('resources/views')];
        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if (! $file->isFile() || ! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());

                /*
                 * The CALL, not the words. A docblock explaining why `app.name` is the wrong key —
                 * there are two, and they are the record of this fix — must not fail the guard that
                 * the fix installed.
                 */
                if (preg_match("/config\(\s*['\"]app\.name['\"]/", $source) === 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "A rendered surface reads `app.name` — Laravel's key, which defaults to «Laravel».\n"
            ."The product's identity is `config('brand.name')`:\n  "
            .implode("\n  ", $offenders),
        );
    }
}
