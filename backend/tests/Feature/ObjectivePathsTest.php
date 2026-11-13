<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Support\PathObjective;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\ObjectivePerformance;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * OBJECTIVE-PATHS-001 — a coffee brand with an online store and physical branches.
 *
 * Shaped on the Production facts for 1KCOFFEE (2026-11-13, ids and counts only): Snapchat squads
 * optimised for PIXEL_PURCHASE (the store) beside squads optimised for SWIPES / LANDING_PAGE_VIEW
 * (the branches), and two Snapchat campaigns on the platform that carry both kinds of squad in ONE
 * campaign. Figures here are invented; no real data is in the fixture.
 */
final class ObjectivePathsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 'paths-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $workspace = ClientWorkspace::create(['name' => 'Coffee', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'Coffee', 'status' => 'active']);
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'snapchat',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'snap-coffee', 'name' => 'Snap', 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    /** One campaign, two paths: the store's ROAS is over the store's spend only. */
    public function test_a_campaign_with_a_store_path_and_a_branches_path_is_judged_per_path(): void
    {
        $this->campaign('Coffee — store + branches', 'sales', [
            ['goal' => 'pixel_purchase', 'spend' => 600, 'conversions' => 30, 'revenue' => 3000, 'clicks' => 1400, 'landing_page_views' => 500],
            ['goal' => 'landing_page_view', 'spend' => 400, 'conversions' => 0, 'revenue' => 0, 'clicks' => 8000, 'landing_page_views' => 2400],
        ]);

        $paths = $this->paths();

        $this->assertTrue($paths['mixed']);
        $this->assertSame(1, $paths['mixed_campaigns']);
        $sales = $this->path($paths, ObjectiveFamily::Sales);
        $traffic = $this->path($paths, ObjectiveFamily::Traffic);

        $this->assertSame(600.0, $sales['spend']);
        $this->assertSame(5.0, $sales['roas'], 'ROAS must be store revenue ÷ store-path spend (3000 ÷ 600), not ÷ 1000');
        $this->assertSame('conversions', $sales['result_key']);
        $this->assertSame(30.0, $sales['result']);
        $this->assertSame(20.0, $sales['cost_per_result']);

        $this->assertSame(400.0, $traffic['spend']);
        $this->assertNull($traffic['roas'], 'the branches path carries no ROAS');
        $this->assertNull($traffic['revenue']);
        $this->assertSame('landing_page_views', $traffic['result_key']);
        $this->assertSame(2400.0, $traffic['result']);
        $this->assertSame(round(400 / 2400, 2), $traffic['cost_per_result']);
    }

    /** ONE total spend, equal to the sum of the paths, stated once; the shares split it exactly. */
    public function test_total_spend_is_stated_once_and_equals_the_sum_of_the_paths(): void
    {
        $this->campaign('Coffee — store', 'sales', [
            ['goal' => 'pixel_purchase', 'spend' => 571.4, 'conversions' => 31, 'revenue' => 3112, 'clicks' => 1429, 'landing_page_views' => 495],
        ]);
        $this->campaign('Coffee — branches', 'traffic', [
            ['goal' => 'swipes', 'spend' => 470.2, 'conversions' => 0, 'revenue' => 0, 'clicks' => 8319, 'landing_page_views' => 1200],
            ['goal' => 'swipes', 'spend' => 465.1, 'conversions' => 0, 'revenue' => 0, 'clicks' => 8272, 'landing_page_views' => 1383],
        ]);
        $this->campaign('Coffee — store + branches', 'sales', [
            ['goal' => 'pixel_purchase', 'spend' => 242, 'conversions' => 7, 'revenue' => 472, 'clicks' => 380, 'landing_page_views' => 178],
            ['goal' => 'landing_page_view', 'spend' => 71, 'conversions' => 0, 'revenue' => 0, 'clicks' => 81, 'landing_page_views' => 19],
        ]);

        $block = $this->paths();
        $sum = array_sum(array_column($block['paths'], 'spend'));

        $this->assertEqualsWithDelta(1819.7, $block['total_spend'], 0.001);
        $this->assertEqualsWithDelta($block['total_spend'], $sum, 0.001, 'the paths must add up to the one total');
        $this->assertEqualsWithDelta(1.0, array_sum(array_column($block['paths'], 'spend_share')), 0.001);
        // Stated once: no path carries a «total» of its own.
        foreach ($block['paths'] as $p) {
            $this->assertArrayNotHasKey('total_spend', $p);
        }
        $sales = $this->path($block, ObjectiveFamily::Sales);
        $this->assertEqualsWithDelta(813.4, $sales['spend'], 0.001);
        $this->assertEqualsWithDelta((3112 + 472) / 813.4, (float) $sales['roas'], 0.01);
    }

    /**
     * Cents the ad sets do not account for go to the largest ad set, never to a path of their own:
     * a sales campaign whose only squad buys visits has no «sales» card of 0.38.
     */
    public function test_a_rounding_residual_never_opens_a_path_of_its_own(): void
    {
        $this->campaign('Coffee — branches under a sales campaign', 'sales', [
            ['goal' => 'landing_page_view', 'spend' => 999.62, 'clicks' => 8000, 'landing_page_views' => 2400],
        ], campaignOverride: ['spend' => 1000.0]);

        $block = $this->paths();

        $this->assertFalse($block['mixed']);
        $this->assertCount(1, $block['paths']);
        $this->assertSame('traffic', $block['paths'][0]['family']);
        $this->assertSame(1000.0, $block['paths'][0]['spend']);
        $this->assertSame(1000.0, $block['total_spend']);
    }

    /** A campaign without ad-set figures goes whole to its own family, so nothing is dropped. */
    public function test_a_campaign_without_ad_set_figures_goes_whole_to_its_family(): void
    {
        $this->campaign('Coffee — store', 'sales', [], campaignFigures: ['spend' => 300, 'conversions' => 6, 'revenue' => 900, 'clicks' => 200]);

        $block = $this->paths();

        $this->assertFalse($block['mixed']);
        $this->assertSame(300.0, $block['total_spend']);
        $this->assertSame(3.0, $this->path($block, ObjectiveFamily::Sales)['roas']);
    }

    /** The goal table: a decisive goal decides; an indecisive one, or an unclassified campaign, does not. */
    public function test_the_path_goal_rule(): void
    {
        $this->assertSame(ObjectiveFamily::Traffic, PathObjective::familyOf('snapchat', 'landing_page_view', ObjectiveFamily::Sales));
        $this->assertSame(ObjectiveFamily::Sales, PathObjective::familyOf('snapchat', 'PIXEL_PURCHASE', ObjectiveFamily::Traffic));
        // OFFSITE_CONVERSIONS optimises whichever pixel event was chosen — the campaign decides.
        $this->assertSame(ObjectiveFamily::Leads, PathObjective::familyOf('meta', 'offsite_conversions', ObjectiveFamily::Leads));
        $this->assertSame(ObjectiveFamily::Sales, PathObjective::familyOf('google', null, ObjectiveFamily::Sales));
        $this->assertSame(ObjectiveFamily::Unknown, PathObjective::familyOf('snapchat', 'pixel_purchase', ObjectiveFamily::Unknown));
    }

    /** The block rides on `build()`, so every surface reading the objective split receives it. */
    public function test_build_carries_the_block(): void
    {
        $this->campaign('Coffee — store', 'sales', [
            ['goal' => 'pixel_purchase', 'spend' => 100, 'conversions' => 2, 'revenue' => 400, 'clicks' => 50, 'landing_page_views' => 20],
        ]);

        $this->holdingTenant((string) $this->tenant->id);
        $built = (new ObjectivePerformance(projectIds: [(string) $this->project->id]))->build(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertSame(100.0, $built['objective_paths']['total_spend']);
    }

    // ── fixtures ───────────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function paths(): array
    {
        $this->holdingTenant((string) $this->tenant->id);

        return (new ObjectivePerformance(projectIds: [(string) $this->project->id]))
            ->objectivePaths(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
    }

    /** @param array<string, mixed> $block @return array<string, mixed> */
    private function path(array $block, ObjectiveFamily $family): array
    {
        foreach ($block['paths'] as $p) {
            if ($p['family'] === $family->value) {
                return $p;
            }
        }
        $this->fail("no {$family->value} path");
    }

    /**
     * A campaign, its squads and both grains of figures: the campaign row is the sum of its squads,
     * exactly as the platform reports them.
     *
     * @param  list<array<string, mixed>>  $squads
     * @param  array<string, float|int>  $campaignFigures  used when there are no squads
     * @param  array<string, float|int>  $campaignOverride  campaign-grain figures that differ from the squads' sum
     */
    private function campaign(string $name, string $objective, array $squads, array $campaignFigures = [], array $campaignOverride = []): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $unified = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective,
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $unified->id,
            'provider' => 'snapchat', 'external_id' => 'ext-'.uniqid(), 'name' => $name, 'status' => 'active',
        ]);

        $totals = $campaignFigures;
        foreach ($squads as $i => $squad) {
            $set = ExternalAdSet::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_campaign_id' => $external->id, 'unified_campaign_id' => $unified->id,
                'provider' => 'snapchat', 'external_id' => 'sq-'.uniqid(), 'name' => "Squad {$i}",
                'status' => 'active', 'optimization_goal' => $squad['goal'],
            ]);
            $figures = array_diff_key($squad, ['goal' => true]);
            EntityDailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $this->account->getKey(), 'provider' => 'snapchat',
                'entity_type' => EntityDailyMetric::AD_SET, 'entity_id' => $set->id, 'external_entity_id' => $set->external_id,
                'external_campaign_id' => $external->id, 'external_ad_set_id' => $set->id,
                'metric_date' => '2026-07-10', 'project_currency' => 'SAR',
            ] + $figures);
            foreach ($figures as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
        }

        $totals = $campaignOverride + $totals;
        $rows = [];
        foreach ($totals as $key => $value) {
            $rows[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id, provider: 'snapchat',
                externalAccountId: (string) $this->account->getKey(), externalCampaignId: (string) $external->id,
                unifiedCampaignId: (string) $unified->id, metricDate: Carbon::parse('2026-07-10'),
                metricKey: $key, value: (float) $value, projectCurrency: 'SAR',
            );
        }
        app(UpsertDailyMetrics::class)->handle($rows);
    }
}
