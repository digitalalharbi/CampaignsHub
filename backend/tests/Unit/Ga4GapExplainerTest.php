<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Measurement\Ga4AttributionMatcher;
use App\Domains\Integrations\Measurement\Ga4GapExplainer;
use App\Domains\Integrations\Measurement\Ga4PlatformGap;
use App\Domains\Integrations\Measurement\Ga4SiteAnalytics;
use App\Domains\Integrations\Measurement\Ga4Visibility;
use PHPUnit\Framework\TestCase;

/**
 * GA4-GAP-EXPLAINED-001 — the decomposition is known from the fixture: the terms sum to the gap to the cent.
 */
final class Ga4GapExplainerTest extends TestCase
{
    /** @return array<string, mixed> */
    private function match(): array
    {
        $c = static fn (string $p, string $id, string $n, float $conv, float $usd): array => ['platform' => $p, 'external_id' => $id, 'name' => $n, 'claimed' => ['conversions' => $conv, 'revenue_usd' => $usd, 'spend_usd' => 100.0, 'clicks' => 1000.0]];
        $u = static fn (string $n, string $s, string $m, float $sess, float $p, float $usd): array => ['name' => $n, 'source' => $s, 'medium' => $m, 'sessions' => $sess, 'purchases' => $p, 'revenue' => $usd * 3.75, 'revenue_usd' => $usd];

        return (new Ga4AttributionMatcher(new Ga4PlatformGap(new Ga4SiteAnalytics)))->build(
            [$c('meta', '1', 'spring', 40.0, 4000.0), $c('meta', '2', 'ghost', 10.0, 900.0), $c('snapchat', '3', 'eid', 30.0, 3000.0)],
            [
                $u('spring', 'facebook', 'paid_social', 400, 25.0, 2500.0),   // matched meta
                $u('stray', 'facebook', 'paid_social', 100, 5.0, 500.0),      // meta, no campaign named
                $u('eid', 'snapchat', 'cpc', 300, 12.0, 1200.0),              // matched snapchat
                $u('(organic)', 'google', 'organic', 500, 8.0, 800.0),        // no platform
                $u('(direct)', '(direct)', '(none)', 200, 2.0, 200.0),        // no platform
            ],
            ['ids' => [], 'clicks' => []],
        );
    }

    public function test_the_terms_sum_exactly_to_the_gap(): void
    {
        $out = (new Ga4GapExplainer)->explain($this->match(), []);

        // C = 40 + 10 + 30 = 80; T = 25 + 5 + 12 + 8 + 2 = 52; gap = 28.
        $this->assertSame(80.0, $out['headline']['claimed']);
        $this->assertSame(52.0, $out['headline']['measured']);
        $this->assertSame(28.0, $out['headline']['gap']);
        $by = array_column($out['reasons'], 'value', 'key');
        $this->assertSame(10.0, $by['no_trace'], 'the ghost campaign claimed 10 with no GA4 trace');
        $this->assertSame(33.0, $by['attribution_rule'], '(40-25) + (30-12)');
        $this->assertSame(-5.0, $by['untagged_channel']);
        $this->assertSame(-10.0, $by['no_platform_trace'], 'organic 8 + direct 2');
        $this->assertSame(0.0, $out['unexplained']['value']);
        $this->assertSame($out['headline']['gap'], $out['check']['sum']);
        $this->assertSame(28.0, round(array_sum(array_column($out['bar'], 'value')), 2));
        $this->assertEqualsWithDelta(1.0, array_sum(array_filter(array_column($out['bar'], 'share'), 'is_numeric')), 0.0002);
    }

    public function test_revenue_terms_use_the_same_identity_and_are_withheld_when_any_rate_is_missing(): void
    {
        $out = (new Ga4GapExplainer)->explain($this->match(), []);
        // Claimed 7900 USD, measured 5200 USD.
        $this->assertSame(2700.0, $out['headline']['gap_usd']);
        $this->assertSame(2700.0, round(array_sum(array_column($out['reasons'], 'value_usd')), 2));

        $m = $this->match();
        $m['platforms'][0]['campaigns'][0]['ga4']['revenue_usd'] = null;
        $out = (new Ga4GapExplainer)->explain($m, []);
        $this->assertNull($out['headline']['gap_usd']);
        $this->assertNull($out['reasons'][0]['value_usd']);
        $this->assertSame(28.0, $out['headline']['gap'], 'the count gap stands');
    }

    public function test_unmeasurable_reasons_carry_no_number_and_the_context_never_claims_a_share(): void
    {
        $out = (new Ga4GapExplainer)->explain($this->match(), []);
        $keys = array_column($out['unmeasured'], 'key');
        $this->assertSame(['view_through', 'window_difference', 'double_claim', 'timing'], $keys);
        foreach ($out['unmeasured'] as $u) {
            $this->assertFalse($u['measured']);
            $this->assertNull($u['value']);
            $this->assertArrayNotHasKey('share', $u);
        }
        $ctx = array_column($out['context'], null, 'key');
        $this->assertFalse($ctx['store_total']['measured'], 'no store total in the system: not measured, and the source is GA4');
        $this->assertNull($ctx['store_total']['value']);
        foreach ($out['context'] as $c) {
            $this->assertArrayNotHasKey('share_of_gap', $c);
        }
        // With a store total it is a number.
        $out = (new Ga4GapExplainer)->explain($this->match(), ['store' => ['orders' => 50, 'revenue' => 1.0]]);
        $this->assertSame(-2.0, array_column($out['context'], null, 'key')['store_total']['value']);
    }

    public function test_one_purchase_is_in_one_term_only(): void
    {
        $m = $this->match();
        $out = (new Ga4GapExplainer)->explain($m, []);
        $ga4InTerms = 0.0;
        foreach ($out['reasons'] as $r) {
            foreach ($r['rows'] as $row) {
                $ga4InTerms += (float) ($row['ga4'] ?? 0);
            }
        }
        $this->assertSame($out['headline']['measured'], round($ga4InTerms, 2), 'every GA4 purchase appears once across the four terms');
    }

    public function test_client_framing_has_no_campaign_names_and_no_spend(): void
    {
        $out = (new Ga4GapExplainer)->explain($this->match(), [], client: true);
        $json = json_encode($out, JSON_THROW_ON_ERROR);
        foreach (['spring', 'ghost', 'eid', 'spend'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_a_hidden_reason_is_absent_from_every_output(): void
    {
        $out = (new Ga4GapExplainer)->explain($this->match(), []);
        $cut = Ga4Visibility::applyToSections(['ga4_gap_reasons' => $out], ['gap.reason.no_trace']);
        $json = json_encode($cut, JSON_THROW_ON_ERROR);
        $this->assertSame(['attribution_rule', 'untagged_channel', 'no_platform_trace'], array_column($cut['ga4_gap_reasons']['reasons'], 'key'));
        $this->assertStringNotContainsString('"ghost"', $json, 'the hidden reason took its drill rows with it');

        $cut = Ga4Visibility::applyToSections(['ga4_gap_reasons' => $out], ['gap.headline']);
        $this->assertNull($cut['ga4_gap_reasons']['headline']['gap']);
    }
}
