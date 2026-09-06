<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AGGREGATION-TRUTH-001 §9 — a unified headline must be mathematically true, not merely present.
 *
 * ## Why this exists now
 *
 * Project 1 in production carries TWO providers for the first time: Snapchat (3,420 rows, 9,446.29
 * spend) and Meta (317 rows, 927.42 spend). Every question about combining them stopped being
 * hypothetical the moment the second one started reporting.
 *
 * The dangerous failure here is not a missing platform — `AggregationTruthTest` already holds that —
 * it is a total that LOOKS right. A CTR averaged from two providers' CTRs is a plausible number, it
 * renders without complaint, and it is wrong; nobody notices until a decision is made on it.
 *
 * ## The figures are chosen so a wrong method cannot pass by luck
 *
 * Snapchat: 1,000 impressions, 10 clicks, 100 spend, 500 revenue → CTR 1.00%, CPC 10.000, ROAS 5.000
 * Meta:     9,000 impressions, 630 clicks, 63 spend, 63 revenue  → CTR 7.00%, CPC 0.100, ROAS 1.000
 *
 * Averaging the two ratios gives CTR 4.00%, CPC 5.050, ROAS 3.000.
 * Recomputing from the sums gives CTR 6.40%, CPC 0.255, ROAS 3.454.
 *
 * No rounding, no coincidence, no shared value between the two methods. A test built on 100/100
 * would pass under either and prove nothing.
 */
final class CrossProviderTotalsAreTruthfulTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $this->write('snapchat', ['impressions' => 1000, 'clicks' => 10, 'spend' => 100, 'revenue' => 500, 'conversions' => 10, 'reach' => 800]);
        $this->write('meta', ['impressions' => 9000, 'clicks' => 630, 'spend' => 63, 'revenue' => 63, 'conversions' => 5, 'reach' => 7000]);
    }

    /** The additive metrics are the sum of the rows legitimately in scope, and nothing else. */
    public function test_additive_metrics_are_the_sum_of_both_providers(): void
    {
        $t = $this->totals();

        $this->assertSame(10000.0, (float) $t['impressions']);
        $this->assertSame(640.0, (float) $t['clicks']);
        $this->assertSame(163.0, (float) $t['spend']);
        $this->assertSame(563.0, (float) $t['revenue']);
    }

    /**
     * Every derived ratio is recomputed from the aggregate numerator and denominator.
     *
     * Asserted against the AVERAGE as well as the correct answer, because «not the average» is the
     * failure this rule exists to prevent and an assertion that only names the right number would
     * pass on a version that reached it some other wrong way.
     */
    public function test_derived_ratios_are_recomputed_and_never_averaged(): void
    {
        $t = $this->totals();

        // CTR: 640 / 10,000 = 6.4%, not the mean of 1% and 7%.
        $this->assertEqualsWithDelta(0.064, (float) $t['ctr'], 0.00001);
        $this->assertNotEqualsWithDelta(0.04, (float) $t['ctr'], 0.00001);

        // CPC: 163 / 640 = 0.2547, not the mean of 10.000 and 0.100.
        $this->assertEqualsWithDelta(0.255, (float) $t['cpc'], 0.001);
        $this->assertNotEqualsWithDelta(5.05, (float) $t['cpc'], 0.01);

        // ROAS: 563 / 163 = 3.454, not the mean of 5.000 and 1.000.
        $this->assertEqualsWithDelta(3.454, (float) $t['roas'], 0.001);
        $this->assertNotEqualsWithDelta(3.0, (float) $t['roas'], 0.001);

        // CPM: 163 / 10,000 × 1000 = 16.30.
        $this->assertEqualsWithDelta(16.30, (float) $t['cpm'], 0.01);

        // CPA: 163 / 15 = 10.87.
        $this->assertEqualsWithDelta(10.87, (float) $t['cpa'], 0.01);
    }

    /**
     * The per-provider rows carry each provider's OWN ratios, and they differ from the combined one.
     *
     * Both facts matter. If the breakdown recomputed from the total it would report the same CTR for
     * a platform at 1% and one at 7%; if the total came from the breakdown it would be an average.
     * They are computed the same way from different scopes, which is the only arrangement where both
     * are true at once.
     */
    public function test_each_provider_keeps_its_own_ratio_beside_the_combined_one(): void
    {
        $rows = collect($this->aggregator()->byProvider($this->windowStart(), $this->windowEnd()))->keyBy('provider');

        $this->assertEqualsWithDelta(0.01, (float) $rows['snapchat']['ctr'], 0.00001);
        $this->assertEqualsWithDelta(0.07, (float) $rows['meta']['ctr'], 0.00001);

        // ...and neither equals the combined 6.4%.
        $this->assertNotEqualsWithDelta(0.064, (float) $rows['snapchat']['ctr'], 0.00001);
        $this->assertNotEqualsWithDelta(0.064, (float) $rows['meta']['ctr'], 0.00001);
    }

    /**
     * A provider filter returns THAT provider, and the unfiltered scope returns both.
     *
     * «No provider may disappear because another provider also has data» — the filter narrows the
     * scope and never the truth, so the filtered figures equal that provider's own row exactly.
     */
    public function test_a_provider_filter_narrows_the_scope_and_nothing_else(): void
    {
        $snap = $this->aggregator()->forProviders(['snapchat'])->totals($this->windowStart(), $this->windowEnd());
        $meta = $this->aggregator()->forProviders(['meta'])->totals($this->windowStart(), $this->windowEnd());
        $both = $this->totals();

        $this->assertSame(1000.0, (float) $snap['impressions']);
        $this->assertSame(9000.0, (float) $meta['impressions']);

        // The parts add up to the whole — the promise «كل حملاتك الإعلانية المدفوعة في مكان واحد».
        $this->assertSame(
            (float) $both['impressions'],
            (float) $snap['impressions'] + (float) $meta['impressions'],
        );
        $this->assertSame((float) $both['spend'], (float) $snap['spend'] + (float) $meta['spend']);

        // And each filtered ratio is its own, not the combined one.
        $this->assertEqualsWithDelta(0.01, (float) $snap['ctr'], 0.00001);
        $this->assertEqualsWithDelta(0.07, (float) $meta['ctr'], 0.00001);
    }

    /**
     * Reach is summed, and it is NOT a count of unique people.
     *
     * 800 + 7,000 = 7,800 is «what the platforms each reported», and the same person on both
     * platforms is in it twice. There is no shared identifier that could deduplicate them, so the
     * figure is the only one available and the product may not call it unique. This holds the
     * ARITHMETIC; the labelling is held on the surfaces that render it.
     */
    public function test_reach_is_the_sum_the_platforms_reported_and_frequency_follows_it(): void
    {
        $t = $this->totals();

        $this->assertSame(7800.0, (float) $t['reach']);

        // Frequency is impressions ÷ reach on the same scope: 10,000 / 7,800 = 1.28.
        $this->assertEqualsWithDelta(1.28, (float) $t['frequency'], 0.01);
    }

    /** @return array<string,mixed> */
    private function totals(): array
    {
        return $this->aggregator()->totals($this->windowStart(), $this->windowEnd());
    }

    private function aggregator(): MetricsAggregator
    {
        return app(MetricsAggregator::class)->forProjects([$this->project->id]);
    }

    private function windowStart(): Carbon
    {
        return Carbon::parse('2026-08-01');
    }

    private function windowEnd(): Carbon
    {
        return Carbon::parse('2026-08-31');
    }

    /** @param array<string,float> $metrics */
    private function write(string $provider, array $metrics): void
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider' => $provider,
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = new ExternalAccount;
        $account->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => $provider.'-act',
            'name' => $provider,
            'status' => 'active',
        ])->save();

        $campaign = new ExternalCampaign;
        $campaign->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'external_id' => $provider.'-cmp',
            'name' => $provider.' campaign',
            'status' => 'active',
        ])->save();

        foreach ($metrics as $key => $value) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id,
                'project_id' => $this->project->id,
                'external_account_id' => $account->id,
                'external_campaign_id' => $campaign->id,
                'provider' => $provider,
                'metric_key' => $key,
                'metric_date' => '2026-08-10',
                'value' => $value,
                'original_amount' => $value,
                'original_currency' => 'USD',
                'project_currency' => 'USD',
                'exchange_rate' => 1,
            ]);
        }
    }
}
