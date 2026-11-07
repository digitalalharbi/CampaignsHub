<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Measurement\Ga4AttributionMatcher;
use App\Domains\Integrations\Measurement\Ga4PlatformGap;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use PHPUnit\Framework\TestCase;

/**
 * GA4-ATTRIBUTION-MATCH-001 — the canonical campaign-level matcher, on constructed fixtures.
 */
final class Ga4AttributionMatcherTest extends TestCase
{
    private function matcher(): Ga4AttributionMatcher
    {
        return new Ga4AttributionMatcher(new Ga4PlatformGap(new Ga4SiteAnalytics));
    }

    /** @return array<string, mixed> */
    private function campaign(string $platform, string $id, string $name, array $claim = []): array
    {
        return ['platform' => $platform, 'external_id' => $id, 'name' => $name, 'claimed' => $claim + ['conversions' => 10.0, 'revenue_usd' => 1000.0, 'spend_usd' => 200.0, 'clicks' => 500.0]];
    }

    /** @return array<string, mixed> */
    private function unit(string $name, string $source, string $medium, float $sessions = 100, float $purchases = 5, float $usd = 500): array
    {
        return ['name' => $name, 'source' => $source, 'medium' => $medium, 'sessions' => $sessions, 'purchases' => $purchases, 'revenue' => $usd * 3.75, 'revenue_usd' => $usd];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function find(array $out, string $platform, string $name): array
    {
        foreach ($out['platforms'] as $p) {
            if ($p['platform'] === $platform) {
                foreach ($p['campaigns'] as $c) {
                    if ($c['name'] === $name) {
                        return $c;
                    }
                }
            }
        }
        $this->fail("no $platform / $name");
    }

    public function test_id_beats_utm_beats_channel(): void
    {
        $out = $this->matcher()->build(
            [$this->campaign('meta', '1201', 'Ramadan Sale'), $this->campaign('meta', '1202', 'eid_push'), $this->campaign('meta', '1203', 'never_named')],
            [$this->unit('1201', 'facebook', 'paid_social'), $this->unit('eid_push', 'facebook', 'paid_social'), $this->unit('stray', 'facebook', 'paid_social', 40, 1, 50)],
            ['ids' => [], 'clicks' => []],
        );

        $this->assertSame('id', $this->find($out, 'meta', 'Ramadan Sale')['confidence']);
        $this->assertSame('utm', $this->find($out, 'meta', 'eid_push')['confidence']);
        $this->assertSame('unmatched', $this->find($out, 'meta', 'never_named')['confidence']);

        $meta = collect($out['platforms'])->firstWhere('platform', 'meta');
        // The stray paid-social visit names no campaign: it is the platform's CHANNEL bucket, not a campaign's.
        $this->assertSame('channel', $meta['channel']['confidence']);
        $this->assertSame(40.0, $meta['channel']['ga4']['sessions']);
    }

    public function test_click_id_corroboration_upgrades_a_name_match_to_id(): void
    {
        $out = $this->matcher()->build(
            [$this->campaign('meta', '9', 'spring')],
            [$this->unit('spring', 'facebook', 'paid_social')],
            ['ids' => [], 'clicks' => [['family' => 'meta', 'campaign' => 'spring', 'sessions' => 80.0]]],
        );
        $this->assertSame('id', $this->find($out, 'meta', 'spring')['confidence']);
    }

    public function test_unmatched_both_ways(): void
    {
        $out = $this->matcher()->build(
            [$this->campaign('snapchat', '77', 'ghost', ['spend_usd' => 300.0, 'clicks' => 900.0])],
            [$this->unit('(organic)', 'google', 'organic', 300, 9, 900), $this->unit('(direct)', '(direct)', '(none)', 200, 4, 400)],
            ['ids' => [], 'clicks' => []],
        );

        // Platform spend with no GA4 trace.
        $this->assertSame('unmatched', $this->find($out, 'snapchat', 'ghost')['confidence']);
        $this->assertSame(1, $out['unmatched']['campaigns']);
        $this->assertSame(300.0, $out['unmatched']['spend_usd']);
        // GA4 traffic that matches no platform.
        $this->assertSame(500.0, $out['unattributed']['sessions']);
        $classes = array_column($out['unattributed']['classes'], 'class');
        $this->assertContains('organic', $classes);
        $this->assertContains('direct', $classes);
    }

    public function test_two_platforms_claiming_the_same_name_do_not_double_count(): void
    {
        // Same utm_campaign on two platforms, and a source GA4 cannot tie to either: ambiguous, assigned to neither.
        $out = $this->matcher()->build(
            [$this->campaign('meta', '1', 'ramadan'), $this->campaign('snapchat', '2', 'ramadan')],
            [$this->unit('ramadan', 'newsletter', 'cpc', 100, 5, 500)],
            ['ids' => [], 'clicks' => []],
        );
        $this->assertSame('unmatched', $this->find($out, 'meta', 'ramadan')['confidence']);
        $this->assertSame('unmatched', $this->find($out, 'snapchat', 'ramadan')['confidence']);
        $this->assertSame(100.0, $out['other_paid']['ga4']['sessions']);

        // When the source names the platform, only that platform claims the session.
        $out = $this->matcher()->build(
            [$this->campaign('meta', '1', 'ramadan'), $this->campaign('snapchat', '2', 'ramadan')],
            [$this->unit('ramadan', 'snapchat', 'cpc', 100, 5, 500)],
            ['ids' => [], 'clicks' => []],
        );
        $this->assertSame('utm', $this->find($out, 'snapchat', 'ramadan')['confidence']);
        $this->assertSame('unmatched', $this->find($out, 'meta', 'ramadan')['confidence']);
        $total = 0.0;
        foreach ($out['platforms'] as $p) {
            foreach ($p['campaigns'] as $c) {
                $total += $c['ga4']['sessions'] ?? 0;
            }
            $total += $p['channel']['ga4']['sessions'] ?? 0;
        }
        $this->assertSame(100.0, $total, 'one session is counted once across the whole table');
    }

    public function test_gap_and_zero_denominators_are_withheld_not_guessed(): void
    {
        $out = $this->matcher()->build(
            [$this->campaign('meta', '1', 'a', ['conversions' => 20.0, 'revenue_usd' => 2000.0, 'spend_usd' => 0.0]), $this->campaign('meta', '2', 'b', ['conversions' => 0.0, 'revenue_usd' => null, 'spend_usd' => 50.0])],
            [$this->unit('a', 'facebook', 'paid_social', 100, 10, 800), $this->unit('b', 'facebook', 'paid_social', 0, 0, 0)],
            ['ids' => [], 'clicks' => []],
        );
        $a = $this->find($out, 'meta', 'a');
        $this->assertSame(10.0, $a['gap']['conversions']);
        $this->assertSame(0.5, $a['gap']['conversions_share']);
        $this->assertSame(1200.0, $a['gap']['revenue_usd']);
        $this->assertSame(80.0, $a['ga4']['aov_usd']);
        $this->assertSame(0.1, $a['ga4']['conversion_rate']);
        $this->assertNull($a['roas_ga4'], 'zero spend: no ROAS');

        $b = $this->find($out, 'meta', 'b');
        $this->assertNull($b['ga4']['aov_usd']);
        $this->assertNull($b['cost_per_purchase_usd'], 'zero GA4 purchases: no cost per purchase');
        $this->assertNull($b['gap']['conversions_share'], 'platform claimed nothing: no share');
        $this->assertNull($b['gap']['revenue_usd']);
    }

    public function test_missing_usd_rate_withholds_the_money_figures(): void
    {
        $u = $this->unit('a', 'facebook', 'paid_social');
        $u['revenue_usd'] = null;
        $out = $this->matcher()->build([$this->campaign('meta', '1', 'a')], [$u], ['ids' => [], 'clicks' => []]);
        $a = $this->find($out, 'meta', 'a');
        $this->assertNull($a['ga4']['revenue_usd']);
        $this->assertNull($a['roas_ga4']);
        $this->assertNull($a['gap']['revenue_usd']);
        $this->assertNotNull($a['ga4']['revenue'], 'the original-currency figure stays');
    }

    public function test_the_payload_states_what_it_is_and_never_sums(): void
    {
        $out = $this->matcher()->build([], [], ['ids' => [], 'clicks' => []]);
        $this->assertFalse($out['summed']);
        $this->assertFalse($out['order_level']);
        $this->assertSame('ga4', $out['basis']);
    }

    public function test_client_view_carries_no_spend_and_no_campaign_names(): void
    {
        $out = $this->matcher()->build([$this->campaign('meta', '1', 'secret_name')], [$this->unit('secret_name', 'facebook', 'paid_social')], ['ids' => [], 'clicks' => []]);
        $client = $this->matcher()->forClient($out);
        $json = json_encode($client, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('secret_name', $json);
        $this->assertStringNotContainsString('spend', $json);
        $this->assertStringNotContainsString('cost_per', $json);
        $this->assertStringNotContainsString('roas', $json);
    }
}
