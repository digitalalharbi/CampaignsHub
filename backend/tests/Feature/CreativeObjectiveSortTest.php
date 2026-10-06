<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CONTENT-OBJECTIVE-SORT-001 — the automatic order is the one the objective asks for.
 *
 * ## The owner's rule
 *
 * «Add filters — by highest spend, clicks, impressions, engagement rate or orders. And the automatic
 * filter should be: most orders if the objective is sales, most clicks if it is engagement, most
 * impressions if it is awareness, and so on for every objective.»
 *
 * Spend is the right order for «where is the money» and the wrong one for «what worked». An awareness
 * campaign that spent most is not the creative that was seen most, and ranking a sales library by
 * spend puts the expensive creative above the one that actually sold.
 *
 * ## It is not a second mapping
 *
 * `ObjectiveFamily::headlineMetrics()` already answers «which number is the verdict for this family»,
 * ordered most-important-first with `spend` leading every list because it is always the question. The
 * leading RESULT metric is therefore the second entry — which is how `ObjectivePerformance` already
 * reads it — and this sort reads the same place. A second table mapping objectives to metrics is
 * exactly the drift this codebase keeps writing guards against.
 *
 * ## And it never reorders silently
 *
 * The response states the metric it ordered by and the objective it read it from. An order a reader
 * cannot account for is indistinguishable from a bug, which is the whole reason this unit exists.
 */
final class CreativeObjectiveSortTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Carbon $to;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-objsort-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $client = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-objsort-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->to = Carbon::parse('2026-08-30');
    }

    public function test_sales_orders_by_orders_rather_than_by_spend(): void
    {
        $this->creative('expensive, few orders', spend: 9_000.0, conversions: 2);
        $this->creative('cheap, many orders', spend: 100.0, conversions: 90);

        $this->assertSame(['cheap, many orders', 'expensive, few orders'], $this->ordered('sales'));
    }

    public function test_awareness_orders_by_impressions(): void
    {
        $this->creative('expensive, unseen', spend: 9_000.0, impressions: 10);
        $this->creative('cheap, seen everywhere', spend: 100.0, impressions: 900_000);

        $this->assertSame(['cheap, seen everywhere', 'expensive, unseen'], $this->ordered('awareness'));
    }

    public function test_traffic_orders_by_clicks(): void
    {
        $this->creative('few clicks', spend: 9_000.0, clicks: 3);
        $this->creative('many clicks', spend: 100.0, clicks: 4_000);

        $this->assertSame(['many clicks', 'few clicks'], $this->ordered('traffic'));
    }

    public function test_engagement_orders_by_engagements(): void
    {
        $this->creative('quiet', spend: 9_000.0, engagements: 5);
        $this->creative('loud', spend: 100.0, engagements: 5_000);

        $this->assertSame(['loud', 'quiet'], $this->ordered('engagement'));
    }

    public function test_video_orders_by_views(): void
    {
        $this->creative('unwatched', spend: 9_000.0, videoViews: 4);
        $this->creative('watched', spend: 100.0, videoViews: 40_000);

        $this->assertSame(['watched', 'unwatched'], $this->ordered('video'));
    }

    /** An objective whose result the creative grain cannot hold falls back to spend, never to nothing. */
    public function test_an_objective_with_no_creative_grain_result_falls_back_to_spend(): void
    {
        $this->creative('big spend', spend: 9_000.0);
        $this->creative('small spend', spend: 100.0);

        $this->assertSame(['big spend', 'small spend'], $this->ordered('app'));
    }

    /** Measurement still leads: the owner's first rule is not undone by his second. */
    public function test_an_unmeasured_creative_stays_below_every_measured_one(): void
    {
        $this->creative('nothing at all');
        $this->creative('one order', spend: 1.0, conversions: 1);

        $this->assertSame(['one order', 'nothing at all'], $this->ordered('sales'));
    }

    /** Ties on the result fall to spend, so the order is total rather than arbitrary. */
    public function test_a_tie_on_the_result_is_broken_by_spend(): void
    {
        $this->creative('same orders, less spend', spend: 10.0, conversions: 5);
        $this->creative('same orders, more spend', spend: 900.0, conversions: 5);

        $this->assertSame(['same orders, more spend', 'same orders, less spend'], $this->ordered('sales'));
    }

    /** The metric is NAMED, so a reader can account for the order they are looking at. */
    public function test_the_rule_states_which_metric_an_objective_leads_with(): void
    {
        $rows = app(CreativeRows::class);

        $this->assertSame('conversions', $rows->objectiveSortMetric('sales'));
        $this->assertSame('impressions', $rows->objectiveSortMetric('awareness'));
        $this->assertSame('clicks', $rows->objectiveSortMetric('traffic'));
        $this->assertSame('engagements', $rows->objectiveSortMetric('engagement'));
        $this->assertSame('video_views', $rows->objectiveSortMetric('video'));
        $this->assertSame('spend', $rows->objectiveSortMetric(null));
        $this->assertSame('spend', $rows->objectiveSortMetric('a objective nobody has heard of'));
    }

    /**
     * CONTENT-OBJECTIVE-SORT-001 — «engagement rate» is a RATE, and a rate is not a sum.
     *
     * The owner asked for it by name among the filters. It is the one he listed that no column holds:
     * it is `engagements ÷ impressions`, computed over the window's totals rather than averaged over
     * days, because an average of daily rates weights a day with ten impressions the same as one with
     * ten thousand.
     */
    public function test_engagement_rate_orders_by_the_ratio_rather_than_by_the_count(): void
    {
        /*
         * The names are deliberately in the WRONG alphabetical order for the expected result.
         *
         * An unrecognised sort key falls through to the default arm, which breaks its ties on name —
         * so a fixture whose names happen to agree with the expected order passes whether or not the
         * sort exists. The first draft of this test did exactly that and went green before a line of
         * it was implemented.
         */
        $this->creative('a — many engagements, enormous reach', spend: 1.0, impressions: 1_000_000, engagements: 10_000);
        $this->creative('z — fewer engagements, tiny reach', spend: 1.0, impressions: 1_000, engagements: 500);

        // 1% against 50%: the smaller count is the higher rate, which is the whole point of the sort.
        $this->assertSame(
            ['z — fewer engagements, tiny reach', 'a — many engagements, enormous reach'],
            $this->orderedBy('engagement_rate'),
        );
    }

    /** No impressions is no rate — never a zero one, which would read as «nobody engaged». */
    public function test_a_creative_with_no_impressions_has_no_rate_and_sorts_last(): void
    {
        $this->creative('a — no impressions', spend: 5.0, impressions: 0, engagements: 0);
        $this->creative('z — has a rate', spend: 1.0, impressions: 100, engagements: 1);

        $this->assertSame(['z — has a rate', 'a — no impressions'], $this->orderedBy('engagement_rate'));
    }

    /**
     * CONTENT-MEASURED-FIRST-001 — an explicit metric sort obeys the same rule the automatic one does.
     *
     * Observed on a real library, ordering by `engagements` where no creative had any: every total
     * was null, so every row tied and fell to `id` — and three creatives with NO figures of any kind
     * came out above ones that had spent thousands. That is the owner's original complaint arriving
     * through a different door, so it is closed the same way: measured first, whatever the chosen
     * metric reports.
     */
    public function test_a_metric_sort_still_puts_measured_content_above_unmeasured(): void
    {
        // Neither has the metric; one of them has figures, and that is the whole difference.
        $this->creative('a — nothing at all');
        $this->creative('z — spent, but no engagements', spend: 500.0, impressions: 9_000);

        $this->assertSame(
            ['z — spent, but no engagements', 'a — nothing at all'],
            $this->orderedBy('engagements'),
        );
    }

    /** And spend breaks the tie when the chosen metric is silent for everyone. */
    public function test_a_silent_metric_falls_to_spend_rather_than_to_an_identifier(): void
    {
        $this->creative('a — small spend', spend: 10.0, impressions: 100);
        $this->creative('z — large spend', spend: 5_000.0, impressions: 100);

        $this->assertSame(['z — large spend', 'a — small spend'], $this->orderedBy('engagements'));
    }

    /** @return list<string> */
    private function orderedBy(string $sort): array
    {
        $query = ExternalCreative::query()->where('project_id', $this->project->id);

        return app(CreativeRows::class)
            ->applySort($query, $sort, $this->to->copy()->subDays(30), $this->to)
            ->get()->pluck('name')->map(strval(...))->all();
    }

    /** @return list<string> */
    private function ordered(?string $objective): array
    {
        $query = ExternalCreative::query()->where('project_id', $this->project->id);
        $sorted = app(CreativeRows::class)
            ->applySort($query, 'auto', $this->to->copy()->subDays(30), $this->to, $objective);

        return $sorted->get()->pluck('name')->map(strval(...))->all();
    }

    private function creative(
        string $name,
        ?float $spend = null,
        int $impressions = 0,
        int $clicks = 0,
        int $conversions = 0,
        int $engagements = 0,
        int $videoViews = 0,
    ): ExternalCreative {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->project->tenant_id,
            'project_id' => $this->project->id,
            'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(8),
            'name' => $name,
            'format' => 'image',
            'status' => 'active',
            'last_active_at' => $this->to->copy()->subDay(),
        ]);

        if ($spend !== null) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->project->tenant_id,
                'project_id' => $this->project->id,
                'creative_id' => $creative->id,
                'metric_date' => $this->to->copy()->subDay()->toDateString(),
                'spend' => $spend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'conversions' => $conversions,
                'engagements' => $engagements,
                'video_views' => $videoViews,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $creative;
    }
}
