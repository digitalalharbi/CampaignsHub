<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GADS-TOKEN-SUNSET-001 — the developer token stopped being the source of access truth.
 *
 * Google sunset developer tokens on **2026-09-09**. The access levels that had been attached to an
 * approved token were transferred to the Google Cloud projects that had been calling with it, and
 * production access is now governed by the Cloud project owning the OAuth credentials. A new project
 * applies for Explorer or Basic from its own Google Ads API page.
 *
 * This build still declared `developer_token` a REQUIRED credential, with copy stating that it «is
 * approved separately from the OAuth client» and that «every Google Ads call is refused without it».
 * Both halves are now false, and the second one is the harmful half: it sends an operator to obtain a
 * credential that no longer gates anything, while the real gate — which Cloud project owns the OAuth
 * client — goes unmentioned.
 *
 * ## What is NOT done here, deliberately
 *
 * The header is still SENT. Google describes the token as ignored rather than rejected, and removing a
 * header that costs nothing on the strength of a sunset note would be the same guesswork in the
 * opposite direction. What changes is what the product REQUIRES and what it tells the reader, not what
 * it transmits.
 */
final class GoogleAdsDeveloperTokenContractTest extends TestCase
{
    #[Test]
    public function the_developer_token_is_no_longer_required_to_be_configured(): void
    {
        $required = ProviderCatalogue::get('google')->requiredKeys();

        $this->assertNotContains(
            'developer_token',
            $required,
            'a credential Google sunset on 2026-09-09 still blocks readiness, so a correctly configured install reads as «awaiting credentials»',
        );
    }

    /** The OAuth pair is what access now follows, so those stay required. */
    #[Test]
    public function the_oauth_credentials_remain_required(): void
    {
        $required = ProviderCatalogue::get('google')->requiredKeys();

        $this->assertContains('client_id', $required);
        $this->assertContains('client_secret', $required);
    }

    /**
     * The field survives as optional, because an install that already holds one keeps sending it.
     *
     * Deleting it outright would drop a header Google still accepts and would erase the one place the
     * product can explain what happened to it.
     */
    #[Test]
    public function the_developer_token_is_still_offered_and_explained(): void
    {
        $keys = array_map(
            static fn (array $f): string => (string) $f['key'],
            ProviderCatalogue::get('google')->toArray()['fields'] ?? [],
        );

        $this->assertContains('developer_token', $keys, 'the field vanished, so an install holding one has nowhere to put it');
    }

    /** No surface may still claim the token is what grants access. */
    #[Test]
    public function nothing_claims_that_calls_are_refused_without_the_token(): void
    {
        foreach ([
            base_path('config/ad_platforms.php'),
            app_path('Domains/Integrations/Catalogue/ProviderCatalogue.php'),
            app_path('Domains/Integrations/Providers/ApiAdvertisingConnector.php'),
        ] as $file) {
            $said = (string) file_get_contents($file);

            $this->assertStringNotContainsString(
                'every Google Ads call is refused without it',
                $said,
                basename($file).' still states that the developer token gates every call',
            );
            $this->assertStringNotContainsString(
                'approved separately from the OAuth client',
                $said,
                basename($file).' still states the token is approved separately from the OAuth client',
            );
        }
    }
}
