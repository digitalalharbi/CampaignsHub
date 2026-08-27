<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    private ?string $accountId = null;

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

    // ── REPORT-ADSET-001: the report scope reaches this grain too ────────────────────────────────

    /**
     * THE TRAP, and the reason this test exists at all.
     *
     * A report applies its scope ONCE, to one engine, and every section reads that bounded engine.
     * This aggregator is not that engine, so the ad-squad section has to be bounded by hand — and the
     * two sides speak different id spaces. `MetricsAggregator` filters
     * `daily_metrics.unified_campaign_id`, and `ReportScope::resolvedCampaignIds()` is built to match
     * it. But `entity_daily_metrics.external_campaign_id` holds an `external_campaigns` row id.
     *
     * Comparing them directly matches nothing, and nothing does not read as a bug here: the section
     * would render its honest «the platform reported no ad squads» empty state on every scoped
     * report — a false statement about the platform rather than a visible error. That is why the
     * translation is asserted rather than assumed.
     */
    public function test_a_campaign_bound_translates_from_unified_ids_to_this_tables_own(): void
    {
        [$unifiedId, $externalId] = $this->campaign('inside');
        [$otherUnified, $otherExternal] = $this->campaign('outside');

        $mine = (string) Str::uuid();
        $theirs = (string) Str::uuid();
        $this->row($mine, '2026-08-01', ['spend' => 100], $externalId);
        $this->row($theirs, '2026-08-01', ['spend' => 900], $otherExternal);

        // The scope names the UNIFIED id, which is what a report actually carries.
        $rows = $this->aggregator->forCampaigns([$unifiedId])->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );

        $this->assertCount(1, $rows, 'The bound matched nothing, so a scoped report would claim the platform sent no ad squads.');
        $this->assertSame($mine, $rows[0]['entity_id']);
        $this->assertEqualsWithDelta(100.0, (float) $rows[0]['spend'], 0.01);
        $this->assertNotSame($otherUnified, $externalId, 'Fixture error: the two id spaces must differ for this test to mean anything.');
    }

    /** A provider bound keeps the section consistent with the KPI cards above it. */
    public function test_a_provider_bound_narrows_this_grain(): void
    {
        $snap = (string) Str::uuid();
        $meta = (string) Str::uuid();
        $this->row($snap, '2026-08-01', ['spend' => 100]);
        $this->row($meta, '2026-08-01', ['spend' => 400], provider: 'meta');

        $rows = $this->aggregator->forProviders(['meta'])->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(400.0, (float) $rows[0]['spend'], 0.01);
    }

    /**
     * An unbounded aggregator answers for everything — the setters must not narrow by existing.
     *
     * `null` is the only thing that means unbounded, and it is what an empty argument produces. The
     * dangerous direction is treating «the scope named these and none exist» as «show everything»,
     * which is how a report scoped away from a platform ends up printing it.
     */
    public function test_an_empty_bound_is_unbounded_and_an_unmatched_bound_shows_nothing(): void
    {
        $id = (string) Str::uuid();
        $this->row($id, '2026-08-01', ['spend' => 100]);

        $unbounded = $this->aggregator->forProviders([])->forCampaigns([])->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );
        $this->assertCount(1, $unbounded, 'An empty bound narrowed the scope instead of leaving it open.');

        $unmatched = $this->aggregator->forProviders(['tiktok'])->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );
        $this->assertSame([], $unmatched, 'A bound that matches nothing must show nothing, never everything.');
    }

    /** Bounds return copies: narrowing one section must not narrow the report's other sections. */
    public function test_bounding_returns_a_copy_and_leaves_the_original_open(): void
    {
        $snap = (string) Str::uuid();
        $meta = (string) Str::uuid();
        $this->row($snap, '2026-08-01', ['spend' => 100]);
        $this->row($meta, '2026-08-01', ['spend' => 400], provider: 'meta');

        $bounded = $this->aggregator->forProviders(['meta']);
        $this->assertCount(1, $bounded->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        ));

        $this->assertCount(2, $this->aggregator->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        ), 'The setter mutated the shared instance, so one bounded section would silently bound the rest.');
    }

    /**
     * The ad-set bound means «these squads» at BOTH grains, and reads a different column to say so.
     *
     * At the squad grain the id IS the row's `entity_id`; at the ad grain it is the row's PARENT,
     * `external_ad_set_id`. One list, two columns — and getting it backwards fails silently in the
     * direction that looks fine: asking for two squads at the ad grain would match on `entity_id`,
     * find no ad whose own id is a squad id, and render «the platform reported no ads» over a campaign
     * that was running perfectly well.
     */
    public function test_the_ad_set_bound_reads_the_squads_own_id_at_the_squad_grain(): void
    {
        $mine = (string) Str::uuid();
        $theirs = (string) Str::uuid();
        $this->row($mine, '2026-08-01', ['spend' => 100]);
        $this->row($theirs, '2026-08-01', ['spend' => 900]);

        $rows = $this->aggregator->forAdSets([$mine])->byEntity(
            $this->project->id, EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );

        $this->assertCount(1, $rows);
        $this->assertSame($mine, $rows[0]['entity_id']);
    }

    /** …and the squad's id as the PARENT at the ad grain. */
    public function test_the_ad_set_bound_reads_the_parent_at_the_ad_grain(): void
    {
        $squad = (string) Str::uuid();
        $otherSquad = (string) Str::uuid();
        $adInside = (string) Str::uuid();
        $adOutside = (string) Str::uuid();

        $this->adRow($adInside, $squad, '2026-08-01', ['spend' => 120]);
        $this->adRow($adOutside, $otherSquad, '2026-08-01', ['spend' => 480]);

        $rows = $this->aggregator->forAdSets([$squad])->byEntity(
            $this->project->id, EntityDailyMetric::AD,
            Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'),
        );

        $this->assertCount(1, $rows, 'The bound matched on the ad’s own id, so a live squad renders as reporting nothing.');
        $this->assertSame($adInside, $rows[0]['entity_id']);
        $this->assertEqualsWithDelta(120.0, (float) $rows[0]['spend'], 0.01);
    }

    /** One ad row, under a named parent squad. */
    private function adRow(string $adId, string $adSetId, string $date, array $values): void
    {
        (new EntityDailyMetric)->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => 'snapchat',
            'entity_type' => EntityDailyMetric::AD,
            'entity_id' => $adId,
            'external_entity_id' => 'ad-'.substr($adId, 0, 6),
            'external_ad_set_id' => $adSetId,
            'metric_date' => $date,
            'attribution_window' => 'default',
            'is_demo' => false,
            ...$values,
        ])->save();
    }

    /** One ad account for the campaigns to hang off — the column is NOT NULL and carries a key. */
    private function account(): string
    {
        if ($this->accountId !== null) {
            return $this->accountId;
        }

        $credential = new IntegrationCredential([
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap', 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
        ])->save();

        return $this->accountId = (string) $account->id;
    }

    /**
     * A unified campaign and the external campaign behind it.
     *
     * @return array{0: string, 1: string} [unified id, external id]
     */
    private function campaign(string $label): array
    {
        $unifiedId = (string) Str::uuid();
        DB::table('unified_campaigns')->insert([
            'id' => $unifiedId,
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => "Campaign {$label}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $externalId = (string) Str::uuid();
        DB::table('external_campaigns')->insert([
            'id' => $externalId,
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'unified_campaign_id' => $unifiedId,
            'external_account_id' => $this->account(),
            'provider' => 'snapchat',
            'external_id' => "ext-{$label}",
            'name' => "Campaign {$label}",
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$unifiedId, $externalId];
    }

    /** @param array<string,mixed> $values */
    private function row(
        string $entityId,
        string $date,
        array $values,
        ?string $campaignId = null,
        bool $demo = false,
        string $window = 'default',
        string $provider = 'snapchat',
    ): void {
        $model = new EntityDailyMetric;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'provider' => $provider,
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
