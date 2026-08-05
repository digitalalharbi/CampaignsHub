<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Legal\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * IDENTITY-PROD — one domain, one address, spelled one way (IDENTITY-PROD-001…003).
 *
 * The platform's own identity is `campaignshub.io` and `info@campaignshub.io`, and it is printed
 * where a customer acts on it: the contact page, the privacy policy, the terms, the security page,
 * the header of an emailed report. Four different spellings had accumulated —
 * `info@CampaignsHub.io` in the database default and the marketing copy, `support@campaignshub.io`
 * in the brand config, `hello@example.com` as the address every outgoing mail would have been sent
 * FROM — and each of them reaches a customer.
 *
 * `support@campaignshub.io` is the one worth naming: it was not a typo but a second address that
 * nobody holds. Printing it on the contact page invites a customer to write somewhere no one is
 * listening, which is the same failure as claiming a delivery that did not happen.
 */
final class PlatformIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'info@campaignshub.io';

    private const DOMAIN = 'campaignshub.io';

    /** The address the platform publishes, before anybody has opened `/admin/settings`. */
    public function test_a_fresh_install_publishes_the_canonical_address(): void
    {
        $settings = PlatformSetting::current();

        $this->assertSame(self::EMAIL, $settings->contact_email);
    }

    /**
     * Lower case, deliberately. A mail domain is case-insensitive so nothing was undeliverable, but
     * this address is PRINTED on legal pages and compared as a string in code — and mixed case in a
     * published address reads as a typo, which is the last impression a privacy policy should give.
     */
    public function test_the_published_address_carries_no_stray_capitals(): void
    {
        $settings = PlatformSetting::current();

        $this->assertSame(strtolower($settings->contact_email), $settings->contact_email);
    }

    /**
     * Every brand URL FALLS BACK to the one domain — no leftover placeholder host.
     *
     * Asserted against the defaults written in `config/brand.php` rather than against the resolved
     * config, because the resolved values are whatever the machine running the tests has in its
     * `.env` — here `http://localhost:5173`, which is correct for a developer and proves nothing
     * about a deployment. The claim being pinned is the one that matters to an install that sets
     * none of these: it gets campaignshub.io, not somebody's placeholder.
     */
    public function test_every_brand_url_falls_back_to_the_production_domain(): void
    {
        $source = (string) file_get_contents(config_path('brand.php'));

        preg_match_all("/env\('[A-Z_]+', '(https?:\/\/[^']+)'\)/", $source, $matches);

        $this->assertNotEmpty($matches[1], 'No URL defaults found in config/brand.php — has it been restructured?');

        foreach ($matches[1] as $url) {
            $this->assertStringContainsString(self::DOMAIN, $url, "{$url} in config/brand.php does not belong to ".self::DOMAIN);
        }
    }

    /**
     * The support address is the same address. It defaulted to `support@campaignshub.io`, which is a
     * mailbox nobody holds — a customer writing to it would have been writing into nothing.
     */
    public function test_support_falls_to_the_one_address_the_platform_actually_reads(): void
    {
        $this->assertSame(self::EMAIL, config('brand.support_email'));
    }

    /**
     * An operator who has typed their own address keeps it. The identity is a DEFAULT, not a
     * constant — the whole point of putting it in `platform_settings` is that `/admin` governs it.
     */
    public function test_an_operator_chosen_address_is_not_overwritten(): void
    {
        $settings = PlatformSetting::current();
        $settings->update(['contact_email' => 'hello@another-operator.sa']);

        $this->assertSame('hello@another-operator.sa', PlatformSetting::current()->fresh()->contact_email);
    }
}
