<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Catalogue\ProviderKind;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Tests\TestCase;

/**
 * GA4-INTEGRATION-001 — Google Analytics 4 is a measurement source, and must never be counted as a
 * place anybody buys advertising.
 *
 * The owner's instruction is specific, and it is specific because the failure is expensive rather
 * than cosmetic: «Do NOT put it into AdvertisingConnectorRegistry as though it were
 * Meta/Snapchat/TikTok. Do NOT count GA4 as an advertising platform. Do not calculate a blended
 * ROAS from incompatible attribution sources.»
 *
 * GA4 measures the client's own site under its own attribution model. An ad platform reports what
 * IT believes its ads caused. Adding one to the other produces a number that is true of neither,
 * and the moment GA4 sits in the advertising family every «which platforms are connected» answer,
 * every platform breakdown and every blended return picks it up for free.
 *
 * So the separation is asserted at the place it is decided — the kind — and at the registry that
 * would otherwise hand it a connector.
 */
final class Ga4IsNotAnAdPlatformTest extends TestCase
{
    public function test_ga4_is_catalogued_as_a_measurement_source(): void
    {
        $this->assertTrue(ProviderCatalogue::has('ga4'), 'GA4 is not in the provider catalogue');
        $this->assertSame(ProviderKind::Measurement, ProviderCatalogue::get('ga4')->kind);
    }

    /** The registry that hands out advertising connectors must not know it. */
    public function test_the_advertising_registry_does_not_carry_ga4(): void
    {
        $this->assertNotContains(
            'ga4',
            array_map(
                static fn (object $d): string => $d->key,
                ProviderCatalogue::ofKind(ProviderKind::Advertising),
            ),
            'GA4 is listed among the advertising providers',
        );

        $this->assertNull(
            app(AdvertisingConnectorRegistry::class)->get('ga4'),
            'the advertising connector registry hands out a connector for GA4',
        );
    }

    /**
     * The scope is read-only, and only the one.
     *
     * GA4 also publishes `analytics.edit` and `analytics.manage.users`. Neither is needed to read a
     * property, and requesting one would put a consent screen in front of a customer stating that
     * this product may change their Analytics configuration — a claim it must not make.
     */
    public function test_it_asks_only_for_read_access(): void
    {
        $this->assertSame(
            ['https://www.googleapis.com/auth/analytics.readonly'],
            ProviderCatalogue::get('ga4')->scopes,
        );
    }

    /** Its callback family is its own, so a measurement consent can never land on an ads handler. */
    public function test_its_callback_family_is_separate(): void
    {
        $this->assertSame('measurement', ProviderKind::Measurement->routeSegment());
        $this->assertNotSame(
            ProviderKind::Advertising->routeSegment(),
            ProviderKind::Measurement->routeSegment(),
        );
    }

    /** The Connection Hub files it under measurement, not paid media — constitution §23. */
    public function test_the_hub_groups_it_away_from_paid_media(): void
    {
        $this->assertSame('analytics_measurement', ProviderKind::Measurement->group());
        $this->assertSame('paid_media', ProviderKind::Advertising->group());
    }
}
