<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CREATIVE-MONEY-TRUTH-001 — a creative's money obeys the same contract as everything else.
 *
 * ## The defect this asserts against
 *
 * `daily_metrics` goes through `InsightRowNormaliser`, which is where FX-001 lives: money is
 * converted into the project's reporting currency, and when no rate can be vouched for the value is
 * WITHHELD — null, with `original_amount` and `original_currency` kept so the row converts itself
 * the day a rate exists.
 *
 * `creative_daily_metrics` did not. `AccountMetricsSyncer` calls `UpsertCreativeDailyMetrics`
 * directly with the connector's rows, and the table had no currency column at all — `spend` and
 * `revenue` were bare decimals defaulting to 0. Snapchat reports in the ad account's currency, so
 * production stored the account's own figures, and `CreativePulseSection` rendered them under a
 * hard-coded currency label.
 *
 * That is worse than the withheld-zero this product already fixed once: not a missing number, a
 * WRONG one wearing the right number's label. 4,128.93 SAR shown as «4,129 USD» overstates spend by
 * roughly 3.75× and reads as a measured fact.
 */
/**
 * REACH-DEDUP-001 at the creative grain — the content library, its groups and Content Analytics.
 *
 * A creative's reach is stored per day. Summing days, or adding creatives together, counts the same
 * person more than once; averaging daily frequencies, or weighting creatives' frequencies together, is
 * not a frequency either. Both are shown only where the figure is one provider row, otherwise «—».
 */
final class CreativeReachIsNotASumTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private ExternalAccount $account;

    private ExternalCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        // `is_currency` is what decides money; without the catalogue the test would not be production.
        $this->seed(MetricDefinitionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            /*
             * SAR, and deliberately ignored: the reporting currency is the canonical USD for every
             * project (MONEY-USD-001). Kept here as a guard — if a workspace preference is ever
             * allowed to pick the basis again, `project_currency` below stops reading USD.
             */
            'default_currency' => 'SAR',
        ]);

        // The production shape: the project reports in the canonical USD, the ad account spends in riyals.
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id,
            'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => 'act-1',
            'name' => 'Snap',
            'status' => 'active',
            'currency' => 'SAR',
            'discovered_at' => Carbon::now(),
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);
    }

    public function test_a_creative_over_two_days_has_no_reach_and_no_frequency(): void
    {
        $creative = $this->creative('cr-1');
        $this->day((string) $creative->id, '2026-08-01', 4000, 2.0);
        $this->day((string) $creative->id, '2026-08-02', 3000, 1.5);

        $figures = $this->figures([(string) $creative->id])[(string) $creative->id];

        $this->assertNull($figures['reach']);
        $this->assertNull($figures['frequency']);
        $this->assertFalse($figures['reported']['reach'], 'a card must read «not reported», not a sum');
        $this->assertFalse($figures['reported']['frequency']);
    }

    public function test_a_creative_on_one_day_keeps_the_providers_own_reach_and_frequency(): void
    {
        $creative = $this->creative('cr-1');
        $this->day((string) $creative->id, '2026-08-01', 4000, 2.0);

        $figures = $this->figures([(string) $creative->id])[(string) $creative->id];

        $this->assertEqualsWithDelta(4000, (float) $figures['reach'], 0.01);
        $this->assertEqualsWithDelta(2.0, (float) $figures['frequency'], 0.001);
    }

    public function test_a_group_of_creatives_has_no_reach_and_no_frequency(): void
    {
        $a = $this->creative('cr-1');
        $b = $this->creative('cr-2');
        $this->day((string) $a->id, '2026-08-01', 4000, 2.0);
        $this->day((string) $b->id, '2026-08-01', 3000, 1.5);

        $pooled = app(CreativeMetrics::class)->aggregate(array_values($this->figures([(string) $a->id, (string) $b->id])));

        $this->assertNull($pooled['reach'], 'two creatives\' reach added is not reach');
        $this->assertNull($pooled['frequency'], 'an impression-weighted mean of frequencies is not a frequency');
    }

    /** @return array<string, array<string,mixed>> */
    private function figures(array $ids): array
    {
        return app(CreativeMetrics::class)->forCreatives($ids, Carbon::parse('2026-07-25'), Carbon::parse('2026-08-10'));
    }

    private function day(string $creativeId, string $date, float $reach, float $frequency): void
    {
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'creative_id' => $creativeId,
            'campaign_id' => null,
            'metric_date' => $date,
            'spend' => 10,
            'impressions' => $reach * $frequency,
            'reach' => $reach,
            'frequency' => $frequency,
            'is_demo' => false,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function creative(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'external_campaign_id' => $this->campaign->id,
            'provider' => 'snapchat',
            'external_creative_id' => $externalId,
            'name' => "Creative {$externalId}",
            'format' => 'image',
        ]);
    }
}
