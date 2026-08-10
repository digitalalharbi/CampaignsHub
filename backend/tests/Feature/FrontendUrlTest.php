<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Frontend;
use Tests\TestCase;

/**
 * FRONTEND-URL-001 — one reader for the SPA's origin.
 *
 * Every case sets the two origins to DIFFERENT hosts, because that is the only configuration in
 * which a wrong answer would be visible at all. The product read two different config paths for one
 * fact — `app.frontend_url` at eight sites and `brand.frontend_url` at seven. Both resolve from
 * FRONTEND_URL today, so this is a consolidation onto one reader, not a behaviour change.
 */
final class FrontendUrlTest extends TestCase
{
    private function split(): void
    {
        config([
            'app.url' => 'https://api.campaignshub.io',
            'brand.frontend_url' => 'https://campaignshub.io',
        ]);
    }

    public function test_the_origin_is_the_front_end_not_the_api(): void
    {
        $this->split();

        $this->assertSame('https://campaignshub.io', Frontend::origin());
    }

    public function test_a_path_is_joined_without_a_double_slash(): void
    {
        $this->split();

        $this->assertSame('https://campaignshub.io/signup/status', Frontend::url('/signup/status'));
        $this->assertSame('https://campaignshub.io/signup/status', Frontend::url('signup/status'));
    }

    /** A trailing slash in the environment must not produce `//signup/status`. */
    public function test_a_trailing_slash_in_configuration_is_absorbed(): void
    {
        config(['brand.frontend_url' => 'https://campaignshub.io/']);

        $this->assertSame('https://campaignshub.io/signup/status', Frontend::url('/signup/status'));
    }

    /**
     * Single-origin installs still work.
     *
     * `app.url` stays the fallback deliberately: where the API and the SPA genuinely share a host,
     * failing to a working same-origin link beats failing to nothing.
     */
    public function test_it_falls_back_to_the_app_url_when_no_front_end_is_configured(): void
    {
        config(['app.url' => 'https://campaignshub.io', 'brand.frontend_url' => null]);

        $this->assertSame('https://campaignshub.io', Frontend::origin());
    }

    public function test_an_empty_front_end_url_is_treated_as_unset(): void
    {
        config(['app.url' => 'https://campaignshub.io', 'brand.frontend_url' => '']);

        $this->assertSame('https://campaignshub.io', Frontend::origin());
    }

    /**
     * The one that cost money: the payment callback.
     *
     * Moyasar returns the customer to whatever `callback_url` the invoice carried. Pointed at the
     * API host, somebody who had just paid landed on a 404 — with the payment taken and no way back
     * into the product.
     */
    public function test_the_payment_callback_returns_the_customer_to_the_front_end(): void
    {
        $this->split();

        $callback = Frontend::url('/signup/status');

        $this->assertStringStartsWith('https://campaignshub.io/', $callback);
        $this->assertStringNotContainsString('api.campaignshub.io', $callback);
    }

    /** One reader: no call site outside this helper names a front-end config path of its own. */
    public function test_only_the_helper_reads_a_front_end_config_path(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || $file->getFilename() === 'Frontend.php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            // ProductionReadiness legitimately CHECKS the declared key, so it reads config by name.
            if ($file->getFilename() === 'ProductionReadiness.php') {
                continue;
            }

            if (str_contains($source, "config('app.frontend_url'") || str_contains($source, "config('brand.frontend_url'")) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }

        $this->assertSame([], $offenders, "these files still read a front-end config path directly:\n".implode("\n", $offenders));
    }
}
