<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Enums\LeadDestination;
use App\Domains\Reports\Support\ResultEvents;
use PHPUnit\Framework\TestCase;

/**
 * RESULT-EVENTS-001 — Results are configured, per platform and acquisition path, and never inferred.
 */
final class ResultEventsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(string $campaign, string $provider, array $figures, array $reported): array
    {
        return ['campaign_id' => $campaign, 'provider' => $provider, 'spend' => 10.0, 'spend_withheld_rows' => 0, 'reported' => $reported] + $figures;
    }

    public function test_nothing_configured_means_no_results_even_when_the_platform_reported_conversions(): void
    {
        $out = ResultEvents::fromArray(null)->over([$this->row('c1', 'tiktok', ['conversions' => 700], ['conversions' => true])], []);

        $this->assertFalse($out['configured']);
        $this->assertNull($out['total'], 'a generic provider conversion is never promoted to Results');
        $this->assertNull($out['cost']);
    }

    public function test_the_two_acquisition_paths_stay_separate_and_unmapped_ones_contribute_nothing(): void
    {
        $events = ResultEvents::fromArray(['tiktok' => ['lead_form' => 'leads', 'landing_page' => 'registrations']]);
        $rows = [
            $this->row('form', 'tiktok', ['leads' => 3, 'conversions' => 900], ['leads' => true, 'conversions' => true]),
            $this->row('page', 'tiktok', ['registrations' => 5, 'conversions' => 700], ['registrations' => true, 'conversions' => true]),
            $this->row('unstated', 'tiktok', ['conversions' => 50], ['conversions' => true]),
        ];

        $out = $events->over($rows, ['form' => LeadDestination::LeadForm, 'page' => LeadDestination::Website]);

        $this->assertSame(8.0, $out['total']);
        $this->assertSame(['lead_form' => 3.0, 'landing_page' => 5.0], $out['by_path']);
        $this->assertSame(2.5, $out['cost'], 'cost per result is the contributing spend over the results');
    }

    public function test_a_mapped_metric_the_platform_never_reported_is_absence_not_zero(): void
    {
        $out = ResultEvents::fromArray(['snapchat' => ['all' => 'leads']])->over(
            [$this->row('c', 'snapchat', ['leads' => 0], ['leads' => false])],
            [],
        );

        $this->assertTrue($out['configured']);
        $this->assertNull($out['total']);
        $this->assertNull($out['cost']);
    }

    public function test_a_cost_per_result_over_withheld_money_is_absent(): void
    {
        $row = $this->row('c', 'meta', ['leads' => 4], ['leads' => true]);
        $row['spend_withheld_rows'] = 1;

        $this->assertNull(ResultEvents::fromArray(['meta' => ['all' => 'leads']])->over([$row], [])['cost']);
    }

    public function test_a_report_override_replaces_the_project_mapping_for_the_platforms_it_names_only(): void
    {
        $events = ResultEvents::merged(
            ['tiktok' => ['all' => 'conversions'], 'meta' => ['all' => 'leads']],
            ['tiktok' => ['all' => 'registrations']],
        );

        $this->assertSame('registrations', $events->metricFor('tiktok', null));
        $this->assertSame('leads', $events->metricFor('meta', null));
    }

    public function test_unknown_platforms_paths_and_metrics_are_dropped(): void
    {
        $events = ResultEvents::fromArray(['tiktok' => ['everywhere' => 'leads', 'all' => 'made_up'], 'nope' => ['all' => 'leads']]);

        $this->assertFalse($events->configured());
    }

    /** The NASEL definitions: a landing lead is the platform's Purchase event, kept on its own path by the project's naming convention. */
    public function test_landing_leads_are_the_mapped_purchase_event_and_the_pixel_lead_beside_it_is_never_added(): void
    {
        $events = ResultEvents::merged(
            ['meta' => ['landing_page' => 'purchases'], 'tiktok' => ['landing_page' => 'purchases'], 'snapchat' => ['landing_page' => 'purchases', 'lead_form' => 'leads']],
            null,
            ['LandingPage' => 'landing_page', 'LeadForm' => 'lead_form'],
        );
        $rows = [
            // Meta reports the Purchase AND the pixel Lead for one submission: only the mapped Purchase counts.
            $this->row('m1', 'meta', ['purchases' => 4, 'leads' => 4, 'conversions' => 4] + ['campaign_name' => 'Riwaq — LandingPage — Meta'], ['purchases' => true, 'leads' => true]),
            // A landing-page load counted as TikTok's generic conversion is not a lead; its Complete Payment is.
            $this->row('t1', 'tiktok', ['purchases' => 0, 'conversions' => 653] + ['campaign_name' => 'Riwaq — LandingPage — TikTok'], ['purchases' => true, 'conversions' => true]),
            // The native-form campaign carries no landing-page lead even though it reports a Purchase.
            $this->row('t2', 'tiktok', ['purchases' => 9, 'conversions' => 1] + ['campaign_name' => 'Riwaq — LeadForm — TikTok'], ['purchases' => true, 'conversions' => true]),
            // Native leads are mapped for Snapchat but the platform has not reported them: absence, not zero.
            $this->row('s1', 'snapchat', ['leads' => 0] + ['campaign_name' => 'Riwaq — LeadForm — Snap'], ['leads' => false]),
        ];

        $out = $events->leadBreakdown($rows, []);

        $this->assertSame(4.0, $out['landing'], 'Meta 4 + TikTok 0; the pixel Lead, the 653 conversions and the form campaign\'s purchases are not landing leads');
        $this->assertNull($out['native'], 'a native lead metric nobody reported is unavailable, never a zero');
        $this->assertSame(4.0, $out['total']);
        $this->assertSame('platform_attributed_not_unique_people', $out['basis']);
    }

    public function test_a_google_ads_platform_with_no_event_configured_contributes_nothing(): void
    {
        $events = ResultEvents::merged(['meta' => ['all' => 'purchases']], null);

        $this->assertSame(0.0, $events->leadBreakdown([$this->row('g', 'google', ['purchases' => 50], ['purchases' => true])], [])['total'] ?? 0.0);
    }

    /** NASEL 2026-10-28 — TikTok LandingPage: 0 configured Results beside 1,113 optimisation events printed a cost of 0.09. */
    public function test_zero_results_never_produce_a_cost_and_each_platform_costs_its_own_event_over_its_own_eligible_spend(): void
    {
        $events = ResultEvents::merged(['tiktok' => ['landing_page' => 'purchases', 'lead_form' => 'form_leads'], 'meta' => ['lead_form' => 'form_leads']], null, ['LandingPage' => 'landing_page', 'LeadForm' => 'lead_form']);
        $rows = [
            ['campaign_id' => 't1', 'campaign_name' => 'Riwaq LandingPage', 'provider' => 'tiktok', 'spend' => 102.97, 'spend_withheld_rows' => 0, 'purchases' => 0, 'conversions' => 1113, 'reported' => ['purchases' => true, 'conversions' => true]],
            ['campaign_id' => 't2', 'campaign_name' => 'Riwaq LeadForm', 'provider' => 'tiktok', 'spend' => 7.16, 'spend_withheld_rows' => 0, 'form_leads' => 1, 'reported' => ['form_leads' => true]],
            // A campaign whose configured event was not reported spends nothing toward the cost.
            ['campaign_id' => 't3', 'campaign_name' => 'Riwaq LandingPage 2', 'provider' => 'tiktok', 'spend' => 50.0, 'spend_withheld_rows' => 0, 'conversions' => 9, 'reported' => ['conversions' => true]],
            ['campaign_id' => 'm1', 'campaign_name' => 'Riwaq LeadForm', 'provider' => 'meta', 'spend' => 134.21, 'spend_withheld_rows' => 0, 'form_leads' => 0, 'leads' => 2, 'reported' => ['form_leads' => true, 'leads' => true]],
        ];

        $out = $events->over($rows, []);

        $this->assertSame(1.0, $out['by_platform']['tiktok']['results']);
        $this->assertSame(110.13, $out['by_platform']['tiktok']['spend'], 'eligible spend: the two campaigns whose configured event was reported');
        $this->assertSame(110.13, $out['by_platform']['tiktok']['cost']);
        $this->assertSame(0.0, $out['by_platform']['meta']['results'], 'messaging leads are not native form leads');
        $this->assertNull($out['by_platform']['meta']['cost'], 'zero results have no cost');
        $this->assertSame(['landing_page' => 0.0, 'lead_form' => 1.0], $out['by_path']);
    }

    /** NASEL 2026-10-29 — the platform cards read native leads per platform from this one resolver; they add up to `native`, landing kept apart. */
    public function test_native_and_landing_leads_per_platform_add_up_to_their_totals_and_never_mix(): void
    {
        $ev = ['lead_form' => 'form_leads', 'landing_page' => 'purchases'];
        $events = ResultEvents::merged(['meta' => $ev, 'tiktok' => $ev, 'snapchat' => $ev], null, ['LeadForm' => 'lead_form', 'LandingPage' => 'landing_page']);
        $rows = [
            $this->row('m1', 'meta', ['form_leads' => 39, 'leads' => 2, 'campaign_name' => 'Riwaq — LeadForm — Meta'], ['form_leads' => true, 'leads' => true]),
            $this->row('m2', 'meta', ['purchases' => 1, 'leads' => 2, 'campaign_name' => 'Riwaq — LandingPage — Meta'], ['purchases' => true, 'leads' => true]),
            $this->row('t1', 'tiktok', ['form_leads' => 11, 'campaign_name' => 'Riwaq — LeadForm — TikTok'], ['form_leads' => true]),
            $this->row('t2', 'tiktok', ['purchases' => 0, 'conversions' => 1110, 'campaign_name' => 'Riwaq — LandingPage — TikTok'], ['purchases' => true, 'conversions' => true]),
            $this->row('s1', 'snapchat', ['form_leads' => 44, 'campaign_name' => 'Riwaq — LeadForm — Snap'], ['form_leads' => true]),
            $this->row('s2', 'snapchat', ['purchases' => 1, 'campaign_name' => 'Riwaq — LandingPage — Snap'], ['purchases' => true]),
        ];

        $out = $events->leadBreakdown($rows, []);

        $this->assertSame(['meta' => 39.0, 'tiktok' => 11.0, 'snapchat' => 44.0], $out['native_by_platform']);
        $this->assertSame(94.0, $out['native']);
        $this->assertSame(94.0, array_sum($out['native_by_platform']), 'the platform cards add up to the native total');
        $this->assertSame(['meta' => 1.0, 'tiktok' => 0.0, 'snapchat' => 1.0], $out['landing_by_platform']);
        $this->assertSame(2.0, $out['landing']);
        $this->assertSame(96.0, $out['total'], 'total interest = native + landing completions; the pixel Lead and generic conversions are not in it');
    }

    /** NASEL 2026-10-29 — the landing-page funnel reads landing campaigns only; a native form campaign's checkout or pixel events never enter it. */
    public function test_the_landing_funnel_counts_landing_campaigns_only_and_keeps_unreported_steps_absent(): void
    {
        $ev = ['lead_form' => 'form_leads', 'landing_page' => 'purchases'];
        $events = ResultEvents::merged(['meta' => $ev, 'tiktok' => $ev, 'snapchat' => $ev], null, ['LeadForm' => 'lead_form', 'LandingPage' => 'landing_page']);
        $rows = [
            $this->row('m1', 'meta', ['form_leads' => 39, 'checkout' => 1, 'campaign_name' => 'Riwaq — LeadForm — Meta'], ['form_leads' => true, 'checkout' => true]),
            $this->row('m2', 'meta', ['purchases' => 1, 'checkout' => 3, 'landing_page_views' => 13, 'leads' => 2, 'campaign_name' => 'Riwaq — LandingPage — Meta'], ['purchases' => true, 'checkout' => true, 'landing_page_views' => true, 'leads' => true]),
            $this->row('t2', 'tiktok', ['purchases' => 0, 'checkout' => 117, 'conversions' => 1110, 'campaign_name' => 'Riwaq — LandingPage — TikTok'], ['purchases' => true, 'checkout' => true, 'conversions' => true]),
            // Snapchat receives the same form_start as START_CHECKOUT; X receives none, so its checkout would not count.
            $this->row('x2', 'x', ['purchases' => 0, 'checkout' => 9, 'campaign_name' => 'Riwaq — LandingPage — X'], ['purchases' => true, 'checkout' => true]),
            $this->row('s2', 'snapchat', ['purchases' => 1, 'checkout' => 53, 'landing_page_views' => 30, 'campaign_name' => 'Riwaq — LandingPage — Snap'], ['purchases' => true, 'checkout' => true, 'landing_page_views' => true]),
        ];

        $funnel = $events->leadBreakdown($rows, [])['landing_funnel'];

        $this->assertSame(4, $funnel['campaigns']);
        $this->assertSame(43.0, $funnel['page_views']);
        $this->assertSame(173.0, $funnel['form_starts'], 'Meta 3 + TikTok 117 + Snapchat 53; the LeadForm campaign\'s checkout and X\'s are not landing form starts');
        $this->assertSame(2.0, $funnel['completions']);
        $this->assertSame(round(2 / 173, 4), $funnel['completion_rate']);
        $this->assertSame(40.0, $funnel['spend'], 'the four landing campaigns\' spend only');
        $this->assertSame(20.0, $funnel['cost_per_completion']);
        $this->assertNull($funnel['contacts'], 'contact clicks are not ingested — absent, never zero');

        $none = $events->leadBreakdown([$this->row('m1', 'meta', ['form_leads' => 3, 'campaign_name' => 'Riwaq — LeadForm — Meta'], ['form_leads' => true])], [])['landing_funnel'];
        $this->assertSame(0, $none['campaigns']);
        $this->assertNull($none['completions']);
        $this->assertNull($none['cost_per_completion']);
    }

    /** NASEL 2026-10-29 — a content tile printed generic `conversions`: 0 on a Snapchat lead-form ad with 27 native leads. */
    public function test_a_content_result_is_the_configured_event_at_ad_grain_never_generic_conversions(): void
    {
        $ev = ['lead_form' => 'form_leads', 'landing_page' => 'purchases'];
        $events = ResultEvents::merged(['meta' => $ev, 'tiktok' => $ev, 'snapchat' => $ev], null, ['LeadForm' => 'lead_form', 'LandingPage' => 'landing_page']);

        // Snapchat reads native submissions into `leads` at ad grain.
        $snap = $events->contentResult(['provider' => 'snapchat', 'campaign_name' => 'Riwaq — LeadForm', 'conversions' => 0, 'leads' => 27]);
        $this->assertSame(['metric' => 'form_leads', 'path' => 'lead_form', 'value' => 27.0], $snap);

        // Meta stores no ad-level native submission: not available, and its pixel `leads` is not borrowed.
        $meta = $events->contentResult(['provider' => 'meta', 'campaign_name' => 'Riwaq — LeadForm', 'leads' => 2, 'metrics' => ['leads' => 2, 'conversions' => 3, 'reported' => ['leads' => true, 'form_leads' => false]]]);
        $this->assertNull($meta['value']);

        // A landing ad's result is its configured completion event; 1,110 generic conversions are not it.
        $tiktok = $events->contentResult(['provider' => 'tiktok', 'campaign_name' => 'Riwaq — LandingPage', 'metrics' => ['conversions' => 1110, 'purchases' => 0, 'reported' => ['purchases' => true, 'conversions' => true]]]);
        $this->assertSame(0.0, $tiktok['value']);

        // A campaign outside the naming convention has no configured result at all.
        $this->assertNull($events->contentResult(['provider' => 'snapchat', 'campaign_name' => '22 ad 1', 'conversions' => 5]));
    }

    /** Owner 2026-10-29 — clicks may be chosen as the landing path's result, and they are read like any other reported metric. */
    public function test_clicks_can_be_the_result_of_a_path(): void
    {
        $this->assertSame([], ResultEvents::problems(['tiktok' => ['landing_page' => 'clicks']]));
        $events = ResultEvents::merged(['tiktok' => ['landing_page' => 'clicks']], null, ['LandingPage' => 'landing_page']);
        $out = $events->over([$this->row('t1', 'tiktok', ['clicks' => 5525, 'conversions' => 1126, 'campaign_name' => 'Riwaq — LandingPage'], ['clicks' => true, 'conversions' => true])], []);

        $this->assertSame(5525.0, $out['total']);
    }

    public function test_native_form_is_accepted_as_the_lead_form_path_and_stored_under_its_existing_key(): void
    {
        $events = ResultEvents::merged(['snapchat' => ['native_form' => 'form_leads']], null, ['LeadForm' => 'native_form']);

        $this->assertSame(['snapchat' => ['lead_form' => 'form_leads']], $events->toArray());
        $this->assertSame('lead_form', $events->pathOfName('Riwaq — LeadForm'));
    }
}
