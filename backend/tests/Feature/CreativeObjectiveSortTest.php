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
