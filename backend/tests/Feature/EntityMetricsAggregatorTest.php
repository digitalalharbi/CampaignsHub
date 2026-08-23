<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-DRILLDOWN-001 — the ad-squad and ad rungs answer on the same terms as every other.
 *
 * A reader must not be able to tell which table produced a figure: the same money-truth field
 * names, the same demo isolation, the same refusal to turn a withheld figure or an impossible
 * ratio into a zero.
 */
final class EntityMetricsAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private EntityMetricsAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $this->aggregator = app(EntityMetricsAggregator::class);
    }

    public function test_an_ad_squads_days_are_summed_and_its_ratios_derived(): void
    {
        $id = (string) Str::uuid();
        $this->row($id, '2026-08-01', ['impressions' => 10000, 'clicks' => 200, 'spend' => 100, 'conversions' => 10]);
        $this->row($id, '2026-08-02', ['impressions' => 30000, 'clicks' => 400, 'spend' => 300, 'conversions' => 10]);

        $out = $this->aggregate()[0];

        $this->assertEqualsWithDelta(40000, $out['impressions'], 0.01);
        $this->assertEqualsWithDelta(600, $out['clicks'], 0.01);
        $this->assertEqualsWithDelta(0.015, $out['ctr'], 0.0001);
        $this->assertEqualsWithDelta(20.0, $out['cpa'], 0.01);
        $this->assertSame(2, $out['active_days']);
    }

    /** A metric nobody reported is null, and every ratio that needs it is null too. */
    public function test_an_unreported_metric_yields_no_number_and_no_ratio(): void
    {
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 1000]);

        $out = $this->aggregate()[0];

        $this->assertNull($out['leads'], 'SUM over all-NULL is NULL, and that is the honest answer.');
        $this->assertNull($out['cpl'], 'A cost per lead with no leads is not zero — it cannot be stated.');
        $this->assertNull($out['roas']);
        $this->assertNull($out['spend']);
    }

    /**
     * Withheld money reaches the reader as an original plus its currency.
     *
     * These are the same field names `MetricsAggregator` and `CreativeMetrics` emit, so the one
     * frontend money reader renders an ad squad exactly as it renders a dashboard KPI.
     */
    public function test_withheld_money_carries_its_original_and_currency(): void
    {
        $id = (string) Str::uuid();
        $this->row($id, '2026-08-01', [
            'impressions' => 5000, 'spend' => null, 'spend_original' => 412.5,
            'original_currency' => 'USD', 'project_currency' => 'SAR',
        ]);

        $out = $this->aggregate()[0];

        $this->assertNull($out['spend']);
        $this->assertSame(1, $out['spend_withheld_rows']);
        $this->assertEqualsWithDelta(412.5, $out['spend_original'], 0.01);
        $this->assertSame('USD', $out['money_original_currency']);
        $this->assertSame(1, $out['money_original_currencies']);
        $this->assertNull($out['cpm'], 'A CPM derived from a withheld spend would read as free.');
    }

    /** Frequency is averaged, never summed — a summed frequency grows with the window. */
    public function test_frequency_is_averaged_across_days(): void
    {
        $id = (string) Str::uuid();
        $this->row($id, '2026-08-01', ['impressions' => 100, 'frequency' => 2.0]);
        $this->row($id, '2026-08-02', ['impressions' => 100, 'frequency' => 4.0]);

        $this->assertEqualsWithDelta(3.0, $this->aggregate()[0]['frequency'], 0.01);
    }

    /** Drill-down into one parent shows that parent's children only. */
    public function test_a_drilldown_is_narrowed_to_its_parent(): void
    {
        $campaign = (string) Str::uuid();
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 10], campaignId: $campaign);
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 20], campaignId: (string) Str::uuid());

        $out = $this->aggregate(parentIds: [$campaign]);

        $this->assertCount(1, $out);
        $this->assertEqualsWithDelta(10, $out[0]['impressions'], 0.01);
    }

    /** A parent with no children shows none — never every entity in the project. */
    public function test_an_empty_parent_set_matches_nothing(): void
    {
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 10]);

        $this->assertCount(0, $this->aggregate(parentIds: []));
    }

    /** Demo rows stay out of an operational total — the same rule as the campaign grain. */
    public function test_demo_rows_are_excluded_from_a_live_scope(): void
    {
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 100]);
        $this->row((string) Str::uuid(), '2026-08-01', ['impressions' => 900], demo: true);

        $total = array_sum(array_map(static fn (array $r): float => (float) $r['impressions'], $this->aggregate()));

        $this->assertEqualsWithDelta(100, $total, 0.01, 'A seeded row was added to a real total.');
    }

    /** Two attribution windows are two measurements and are never mixed into one figure. */
    public function test_one_attribution_window_can_be_asked_for_alone(): void
    {
        $id = (string) Str::uuid();
        $this->row($id, '2026-08-01', ['conversions' => 10], window: 'swipe_28d');
        $this->row($id, '2026-08-01', ['conversions' => 4], window: 'swipe_1d');

        $out = $this->aggregate(attributionWindow: 'swipe_1d');

        $this->assertEqualsWithDelta(4, $out[0]['conversions'], 0.01);
    }

    /** @return list<array<string,mixed>> */
    private function aggregate(?array $parentIds = null, ?string $attributionWindow = null): array
    {
        return $this->aggregator->byEntity(
            $this->project->id,
            EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'),
            Carbon::parse('2026-08-10'),
            $parentIds,
            $attributionWindow,
        );
    }

    /** @param array<string,mixed> $values */
    private function row(
        string $entityId,
        string $date,
        array $values,
        ?string $campaignId = null,
        bool $demo = false,
        string $window = 'default',
    ): void {
        $model = new EntityDailyMetric;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'entity_type' => EntityDailyMetric::AD_SET,
            'entity_id' => $entityId,
            'external_entity_id' => 'sq-'.substr($entityId, 0, 6),
            'external_campaign_id' => $campaignId,
            'metric_date' => $date,
            'attribution_window' => $window,
            // forceFill, because `is_demo` is deliberately not fillable: a demo flag that could be
            // mass-assigned is one an untrusted payload could clear.
            'is_demo' => $demo,
            ...$values,
        ])->save();
    }
}
