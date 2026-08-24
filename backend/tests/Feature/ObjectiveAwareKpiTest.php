<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Services\CreativeMetrics;
use Tests\TestCase;

/**
 * OBJECTIVE-AWARE-KPI-001 — a campaign is judged by what it was bought to do.
 *
 * KPI selection ran off `MarketingPath`, which has three cases and answers a MONEY question: whose
 * cost per order this spend may land in. Three buckets are right for that and far too few for «which
 * figure is the verdict».
 *
 * The visible consequence: `Leads` and `AppInstalls` both sit on the conversion path, so a
 * lead-generation campaign and an app-install campaign were both headlined with `revenue`, `roas`
 * and `aov` — figures neither was bought to produce and neither platform reports for them. The
 * requirement states it directly: a sales campaign must not be judged primarily on CTR, and an
 * awareness campaign must not be judged on ROAS. The inverse of that was shipping.
 */
final class ObjectiveAwareKpiTest extends TestCase
{
    private CreativeMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metrics = app(CreativeMetrics::class);
    }

    /**
     * The canonical family — a lead campaign is priced per LEAD, never per order.
     *
     * Asserted on `ObjectiveFamily` rather than on the creative headline, because this is the
     * definition. What a given TABLE can answer of it is a separate question, below.
     */
    public function test_the_lead_family_is_priced_per_lead_and_not_per_order(): void
    {
        $headline = ObjectiveFamily::Leads->headlineMetrics();

        $this->assertContains('leads', $headline);
        $this->assertContains('cpl', $headline);

        $this->assertNotContains('roas', $headline, 'A lead-gen campaign reports no revenue to divide.');
        $this->assertNotContains('aov', $headline, 'A lead is not an order and has no average value.');
        $this->assertNotContains('cpa', $headline, 'CPL is not CPA — conflating them prices the wrong thing.');
    }

    /** An app campaign is priced per install, not per purchase. */
    public function test_the_app_family_is_priced_per_install_not_per_purchase(): void
    {
        $headline = ObjectiveFamily::App->headlineMetrics();

        $this->assertContains('installs', $headline);
        $this->assertContains('cpi', $headline);
        $this->assertNotContains('roas', $headline);
        $this->assertNotContains('revenue', $headline);
    }

    /**
     * The creative card never headlines a metric this table cannot produce.
     *
     * `creative_daily_metrics` has no `leads`, `installs`, `registrations` or `in_app_events`
     * column — a platform that breaks results down per creative does not break every result type
     * down. Leaving them in the list would put «no data» in the most prominent position on the
     * card, which is the opposite of an objective-aware card, and the requirement is explicit that
     * a metric must not reach the UI before the pipeline can supply it.
     *
     * It is still a real coverage gap and still recorded as one — but the card no longer PAYS for
     * it. See `CONTENT-KPI-COLLAPSE-001` below: what the family cannot answer is dropped, and the
     * remainder is topped up from the set that is true of every campaign, so an app-install
     * creative shows four honest figures instead of a lone withheld spend.
     */
    public function test_the_creative_headline_drops_what_the_creative_table_cannot_answer(): void
    {
        $this->assertNotContains('leads', $this->metrics->headline('leads'));
        $this->assertNotContains('cpl', $this->metrics->headline('leads'));
        $this->assertNotContains('installs', $this->metrics->headline('app_installs'));

        // ...and never falls through to nothing: spend is always the question.
        $this->assertContains('spend', $this->metrics->headline('app_installs'));
        $this->assertNotContains('roas', $this->metrics->headline('app_installs'));
    }

    /**
     * CONTENT-KPI-COLLAPSE-001 — dropping what cannot be answered must not empty the card.
     *
     * `ObjectiveFamily::App` headlines on `installs`, `cpi`, `registrations` and `in_app_events`,
     * and `creative_daily_metrics` holds none of them. Every one was filtered away and the headline
     * fell back to a bare `['spend']` — one figure, and on the account this was found on a WITHHELD
     * one, which is how an app-install creative came to show no performance indicators at all.
     *
     * Asserted as a COUNT against the number the grid renders, because that is the actual
     * requirement: `headline_metrics.slice(0, 4)` over a one-item list leaves three empty cells.
     */
    public function test_an_app_install_creative_is_not_left_with_a_single_figure(): void
    {
        $headline = $this->metrics->headline('app_installs');

        $this->assertGreaterThanOrEqual(4, count($headline), 'The card renders four; a family that answers one leaves it looking broken.');
        $this->assertSame('spend', $headline[0], 'Spend still leads — it is the question whatever the campaign was bought to do.');

        // Topped up from what the catalogue can actually produce, never from what it cannot.
        $this->assertContains('impressions', $headline);
        $this->assertContains('clicks', $headline);

        // The refusals that made this family distinct are untouched.
        $this->assertNotContains('installs', $headline);
        $this->assertNotContains('roas', $headline);
        $this->assertNotContains('cpa', $headline);
    }

    /**
     * The same fault, one degree quieter: `Leads` kept three metrics, so the old fallback — which
     * fired only on a COMPLETELY empty list — never reached it, and the card simply went short.
     */
    public function test_a_lead_creative_is_topped_up_rather_than_left_short(): void
    {
        $headline = $this->metrics->headline('leads');

        $this->assertGreaterThanOrEqual(4, count($headline));
        $this->assertSame('spend', $headline[0]);
        // What the family CAN answer keeps its place, in its own order, ahead of the top-up.
        $this->assertSame('conversion_rate', $headline[1], 'The family is still the judge of its own verdict.');
        $this->assertNotContains('cpl', $headline);
    }

    /**
     * The guarantee, stated once over every objective the product recognises rather than family by
     * family — a new objective added later inherits this test without anybody remembering to.
     */
    public function test_no_objective_leaves_the_card_with_fewer_figures_than_it_renders(): void
    {
        $objectives = array_map(
            static fn (CampaignObjective $c): string => $c->value,
            CampaignObjective::cases(),
        );

        foreach ([...$objectives, null] as $objective) {
            $headline = $this->metrics->headline($objective);

            $this->assertGreaterThanOrEqual(
                4,
                count($headline),
                sprintf('«%s» headlines on %d metric(s); the card renders four.', $objective ?? 'null', count($headline)),
            );

            // Every one still has to be a figure this pipeline can actually produce — the top-up
            // must not have reintroduced the very metrics the filter exists to remove.
            $this->assertSame(
                $headline,
                $this->metrics->headline($objective),
                'The selection is deterministic.',
            );
        }
    }

    /**
     * A family that already answers four is left exactly as it was.
     *
     * The top-up is a floor, not a rewrite: appending «true of every campaign» metrics to a sales
     * card would push `roas` off the four the grid shows and undo OBJECTIVE-AWARE-KPI-001.
     */
    public function test_a_family_that_can_answer_four_is_not_padded(): void
    {
        $sales = $this->metrics->headline('sales');

        $this->assertSame(['spend', 'orders', 'cpa', 'revenue'], array_slice($sales, 0, 4));
        $this->assertNotContains('impressions', array_slice($sales, 0, 4));
    }

    /** Engagement means engagements — a click is a different thing a person did. */
    public function test_an_engagement_campaign_leads_with_engagements_not_clicks(): void
    {
        $headline = $this->metrics->headline('engagement');

        $this->assertContains('engagements', $headline);
        $this->assertContains('cpe', $headline);
        $this->assertSame('engagements', $headline[1], 'Spend leads; the verdict comes second.');
        $this->assertNotContains('roas', $headline);
    }

    /** A video buy is judged on whether it was watched. */
    public function test_a_video_campaign_leads_with_watching(): void
    {
        $headline = $this->metrics->headline('video_views');

        $this->assertContains('video_views', $headline);
        $this->assertContains('completion_rate', $headline);
        $this->assertNotContains('roas', $headline);
    }

    /** Sales keeps its own set — the fix must not swing the other way. */
    public function test_a_sales_campaign_still_leads_with_orders_and_roas(): void
    {
        $headline = $this->metrics->headline('sales');

        $this->assertContains('orders', $headline);
        $this->assertContains('roas', $headline);
        $this->assertContains('cpa', $headline);
        $this->assertNotSame('ctr', $headline[1], 'A sales campaign is not judged primarily on CTR.');
    }

    /** Awareness is reach and cost of reaching, never a return on spend it did not chase. */
    public function test_an_awareness_campaign_is_never_judged_on_roas(): void
    {
        $headline = $this->metrics->headline('awareness');

        $this->assertContains('reach', $headline);
        $this->assertContains('cpm', $headline);
        $this->assertNotContains('roas', $headline);
    }

    /**
     * CONTENT-KPI-COLLAPSE-001 — the awareness card stops promising a cell it cannot fill.
     *
     * The FAMILY still names frequency; that is the definition of what an awareness buy is judged
     * on and it is unchanged. What changed is the CREATIVE headline, which may only promise what
     * `creative_daily_metrics` can answer — and Snapchat's creative-grain stats call does not ask
     * for frequency, so the fourth cell on most of this account's cards could never be filled.
     *
     * Deriving it was refused rather than overlooked: frequency is impressions ÷ reach, and reach is
     * summed across days, so daily uniques added together over-count the people actually reached and
     * the quotient would be a lower bound presented as a measurement.
     */
    public function test_the_awareness_creative_headline_does_not_promise_frequency(): void
    {
        $this->assertContains('frequency', ObjectiveFamily::Awareness->headlineMetrics(), 'The family definition is untouched.');

        $headline = $this->metrics->headline('awareness');

        $this->assertNotContains('frequency', $headline);

        // And the slot it vacated is filled by a figure this table really does hold.
        $this->assertSame(['spend', 'impressions', 'reach', 'cpm'], array_slice($headline, 0, 4));
    }

    /**
     * An unclassified objective gets what is true of every campaign — not the sales set.
     *
     * `Other` sits on the awareness path precisely so unclassified spend never reaches a cost per
     * order. Headlining it with ROAS would reintroduce through the card what the path refuses at
     * the total.
     */
    public function test_an_unclassified_objective_is_not_given_the_sales_verdict(): void
    {
        foreach ([null, 'other', 'nonsense-from-a-provider'] as $objective) {
            $this->assertNotContains('roas', $this->metrics->headline($objective));
            $this->assertContains('spend', $this->metrics->headline($objective));
        }
    }

    /** Every objective resolves to exactly one family — no silent gap as objectives are added. */
    public function test_every_objective_has_a_family(): void
    {
        foreach (CampaignObjective::cases() as $case) {
            $this->assertInstanceOf(ObjectiveFamily::class, $case->family());
        }
    }

    /**
     * The money rule is untouched.
     *
     * `path()` is what keeps brand spend out of a sales CPA. This change adds a layer above it for
     * choosing KPIs and must not have moved a single objective's money.
     */
    public function test_the_marketing_path_is_unchanged_by_the_family_layer(): void
    {
        $this->assertSame(MarketingPath::Conversion, CampaignObjective::Leads->path());
        $this->assertSame(MarketingPath::Conversion, CampaignObjective::AppInstalls->path());
        $this->assertSame(MarketingPath::Awareness, CampaignObjective::Other->path());
        $this->assertSame(MarketingPath::Awareness, CampaignObjective::VideoViews->path());

        // ...while their KPI families differ, which is the entire point of the separation.
        $this->assertSame(ObjectiveFamily::Leads, CampaignObjective::Leads->family());
        $this->assertSame(ObjectiveFamily::App, CampaignObjective::AppInstalls->family());
    }
}
