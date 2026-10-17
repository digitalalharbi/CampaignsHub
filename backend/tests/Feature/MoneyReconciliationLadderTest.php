<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * MONEY-RECON-RUNGS-001 — the same money, read at every rung, adds up to the same total.
 *
 * Two platforms, three ad accounts, four campaigns, spend in SAR and USD (both converted into the reporting
 * unit) plus a EUR day with no rate. The ladder is account → campaign → platform → project: each rung's
 * converted spend must sum to the project's, ROAS and cost per result must be recomputed from the rung's
 * own bases (never averaged from its children), and the unconverted EUR money must be counted as withheld
 * on EVERY rung it belongs to — never silently dropped from one and kept in another.
 */
final class MoneyReconciliationLadderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Carbon $day;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-ladder', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-ladder', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        $this->day = Carbon::parse('2026-10-10');
    }

    private function campaign(string $name): UnifiedCampaign
    {
        return UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => $name, 'objective' => 'sales', 'status' => 'active']);
    }

    /** One money row: converted into the reporting unit when a rate existed, withheld (value null) when not. */
    private function money(string $key, ?float $converted, float $original, string $currency, string $provider, string $account, UnifiedCampaign $c): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id,
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, $account)->toString(),
            externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'ext-'.$c->name)->toString(),
            provider: $provider, metricKey: $key, metricDate: $this->day, value: $converted,
            unifiedCampaignId: (string) $c->id,
            originalCurrency: $currency, projectCurrency: 'USD', originalAmount: $original,
            convertedAmount: $converted, exchangeRate: ($converted === null || $original == 0.0) ? null : $converted / $original,
        );
    }

    private function tally(string $key, float $v, string $provider, string $account, UnifiedCampaign $c): NormalizedMetric
    {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id, projectId: (string) $this->project->id,
            externalAccountId: Uuid::uuid5(Uuid::NAMESPACE_URL, $account)->toString(),
            externalCampaignId: Uuid::uuid5(Uuid::NAMESPACE_URL, 'ext-'.$c->name)->toString(),
            provider: $provider, metricKey: $key, metricDate: $this->day, value: $v, unifiedCampaignId: (string) $c->id,
        );
    }

    private function seedEstate(bool $withEur): void
    {
        [$a, $b, $c, $d] = [$this->campaign('A'), $this->campaign('B'), $this->campaign('C'), $this->campaign('D')];
        $rows = [
            // Meta, account m1: SAR converted at the 3.75 peg.
            $this->money('spend', 100.0, 375.0, 'SAR', 'meta', 'm1', $a), $this->money('revenue', 400.0, 1500.0, 'SAR', 'meta', 'm1', $a), $this->tally('conversions', 10, 'meta', 'm1', $a),
            $this->money('spend', 60.0, 225.0, 'SAR', 'meta', 'm1', $b), $this->money('revenue', 90.0, 337.5, 'SAR', 'meta', 'm1', $b), $this->tally('conversions', 3, 'meta', 'm1', $b),
            // Snapchat, account s1 (USD native) and s2 (SAR).
            $this->money('spend', 40.0, 40.0, 'USD', 'snapchat', 's1', $c), $this->money('revenue', 200.0, 200.0, 'USD', 'snapchat', 's1', $c), $this->tally('conversions', 5, 'snapchat', 's1', $c),
            $this->money('spend', 20.0, 75.0, 'SAR', 'snapchat', 's2', $d), $this->money('revenue', 0.0, 0.0, 'SAR', 'snapchat', 's2', $d), $this->tally('conversions', 0, 'snapchat', 's2', $d),
        ];
        if ($withEur) {
            // No rate for EUR: the money exists, the conversion does not. Withheld, never zero.
            $rows[] = $this->money('spend', null, 50.0, 'EUR', 'snapchat', 's3', $this->campaign('E'));
        }
        app(UpsertDailyMetrics::class)->handle($rows);
    }

    private function agg(): MetricsAggregator
    {
        return app(MetricsAggregator::class)->forProjects([(string) $this->project->id]);
    }

    public function test_converted_spend_and_revenue_add_up_at_every_rung(): void
    {
        $this->seedEstate(false);
        $from = $this->day->copy()->startOfDay();
        $to = $this->day->copy()->endOfDay();

        $project = $this->agg()->totals($from, $to);
        $this->assertEquals(220.0, $project['spend']);
        $this->assertEquals(690.0, $project['revenue']);

        foreach (['platform' => $this->agg()->byProvider($from, $to), 'account' => $this->agg()->byAccount($from, $to), 'campaign' => $this->agg()->byCampaign($from, $to)] as $rung => $rows) {
            $this->assertEqualsWithDelta(220.0, array_sum(array_column($rows, 'spend')), 0.001, "{$rung} spend does not add up to the project's");
            $this->assertEqualsWithDelta(690.0, array_sum(array_column($rows, 'revenue')), 0.001, "{$rung} revenue does not add up to the project's");
            $this->assertEqualsWithDelta(18.0, array_sum(array_column($rows, 'conversions')), 0.001, "{$rung} results do not add up to the project's");
        }
    }

    public function test_ratios_are_recomputed_from_each_rungs_own_bases_not_averaged(): void
    {
        $this->seedEstate(false);
        $from = $this->day->copy()->startOfDay();
        $to = $this->day->copy()->endOfDay();

        $project = $this->agg()->totals($from, $to);
        $this->assertEqualsWithDelta(690.0 / 220.0, (float) $project['roas'], 0.001);

        foreach ($this->agg()->byProvider($from, $to) as $row) {
            $spend = (float) $row['spend'];
            $this->assertEqualsWithDelta($spend > 0 ? (float) $row['revenue'] / $spend : 0.0, (float) ($row['roas'] ?? 0), 0.001, "{$row['provider']} ROAS is not revenue over spend");
        }

        // The mean of the platforms' ROAS is NOT the project's — which is exactly why averaging is forbidden.
        $mean = array_sum(array_map(fn ($r) => (float) $r['roas'], $this->agg()->byProvider($from, $to))) / 2;
        $this->assertNotEqualsWithDelta((float) $project['roas'], $mean, 0.01);
    }

    public function test_unconverted_money_is_withheld_at_every_rung_it_belongs_to_and_never_dropped(): void
    {
        $this->seedEstate(true);
        $from = $this->day->copy()->startOfDay();
        $to = $this->day->copy()->endOfDay();

        $project = $this->agg()->totals($from, $to);
        $this->assertSame(1, (int) $project['spend_withheld_rows'], 'the project must count the unconverted day');

        $platform = collect($this->agg()->byProvider($from, $to))->firstWhere('provider', 'snapchat');
        $account = collect($this->agg()->byAccount($from, $to))->first(fn ($r) => (int) $r['spend_withheld_rows'] > 0);
        $campaign = collect($this->agg()->byCampaign($from, $to))->firstWhere('campaign_name', 'E');

        $this->assertSame(1, (int) $platform['spend_withheld_rows'], 'the platform rung lost the withheld day');
        $this->assertNotNull($account, 'the account rung lost the withheld day');
        $this->assertSame(1, (int) ($campaign['spend_withheld_rows'] ?? 0), 'the campaign rung lost the withheld day');

        // The converted part still reconciles; the withheld part is carried beside it, not folded in as zero.
        $this->assertEqualsWithDelta(50.0, (float) $project['spend_original'], 0.001);
        $this->assertSame('EUR', $project['money_original_currency']);
    }
}
