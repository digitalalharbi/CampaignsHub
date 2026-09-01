<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ADSET-METRICS-TRUTH-001 — a Meta account's ad sets had no numbers, and Meta reports them.
 *
 * ## The defect, as an operator met it
 *
 * Spend, CPC, CPM and cost per result all read «—» on every ad-set row of a Meta account. Nothing
 * was broken: `AccountMetricsSyncer` asked for these grains behind `if ($connector instanceof
 * SnapchatConnector)`, so of eight platforms exactly one was ever asked. The product printed our own
 * silence in the place where the platform's figures go, which reads as a platform that reports less
 * than it does.
 *
 * ## What this proves, and what it cannot
 *
 * It proves the whole path with Meta's own response shape: the account is asked at `level=adset` and
 * `level=ad`, the rows are mapped by the SAME mapper the campaign grain uses, and they arrive in
 * `entity_daily_metrics` against the entities the structure sweep discovered. It cannot prove Meta
 * answers this way, because this install holds no Meta credential — that is
 * `PROVIDER-LIVE-VERIFICATION-001`, and the response below is built from the documented shape.
 */
final class MetaEntityGrainsTest extends TestCase
{
    use RefreshDatabase;

    public function test_meta_ad_set_and_ad_metrics_reach_the_table(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        $account = $this->liveMetaAccount();

        $this->fakeMetaInsights();

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $adSet = DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->first();
        $ad = DB::table('entity_daily_metrics')->where('entity_type', 'ad')->first();

        $this->assertNotNull($adSet, 'The ad-set grain is empty — Meta was never asked.');
        $this->assertNotNull($ad, 'The ad grain is empty — Meta was never asked.');

        $this->assertEqualsWithDelta(40000, (float) $adSet->impressions, 0.01);
        $this->assertEqualsWithDelta(800, (float) $adSet->clicks, 0.01);
        // Spend is what CPC, CPM and cost-per-result are all computed from; «—» downstream starts here.
        $this->assertEqualsWithDelta(1200, (float) $adSet->spend_original, 0.01);
        $this->assertSame('USD', $adSet->original_currency);
        // A purchase is the conversion for this connector, and it is the same number under both names.
        $this->assertEqualsWithDelta(24, (float) $adSet->conversions, 0.01);

        $this->assertEqualsWithDelta(25000, (float) $ad->impressions, 0.01);
    }

    /**
     * A measure Meta did not report is ABSENT, not zero.
     *
     * The row below carries no `actions` at all, which is what an account with no pixel looks like.
     * Storing 0 conversions would say «this ad set sold nothing», and an operator would pause it.
     */
    public function test_a_measure_meta_did_not_report_is_not_stored_as_zero(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        $account = $this->liveMetaAccount();

        $this->fakeMetaInsights(withActions: false);

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $adSet = DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->first();

        $this->assertNotNull($adSet);
        $this->assertNull($adSet->conversions, 'An unreported conversion count was stored as a measured zero.');
        $this->assertEqualsWithDelta(40000, (float) $adSet->impressions, 0.01, 'The measures it DID report are still there.');
    }

    /**
     * A row naming an ad set we have never discovered is skipped, never invented.
     *
     * The structure sweep owns identity and this owns numbers. Creating the entity here would put a
     * row with a provider id and nothing else into the drill-down, where it renders as a real ad set.
     */
    public function test_an_undiscovered_entity_is_skipped_rather_than_created(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        $account = $this->liveMetaAccount();

        $this->fakeMetaInsights(adSetId: 'adset-nobody-has-seen');

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $this->assertSame(0, DB::table('entity_daily_metrics')->where('entity_type', 'ad_set')->count());
        $this->assertSame(1, ExternalAdSet::withoutGlobalScopes()->count(), 'A stats row created an ad set.');
    }

    /** A refusal at these grains costs the new rows and nothing else — the run still completes. */
    public function test_a_refusal_at_the_grains_does_not_fail_the_run(): void
    {
        $this->seed(MetricDefinitionSeeder::class);
        $account = $this->liveMetaAccount();

        Http::fake(function ($request) {
            return str_contains($request->url(), 'level=adset') || str_contains($request->url(), 'level=ad&')
                ? Http::response(['error' => ['message' => 'rate limited']], 429)
                : Http::response(['data' => []], 200);
        });

        app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));

        $this->assertSame(0, DB::table('entity_daily_metrics')->count());
        $this->assertGreaterThan(0, DB::table('metric_sync_runs')->count());
    }

    /**
     * Meta's documented insights shape, at whichever level was asked for.
     *
     * The fake reads the `level` parameter rather than the path, because all three grains are the
     * same endpoint and two URL patterns would both match — the first would then answer for both,
     * and the test would pass while asking for one thing twice.
     */
    private function fakeMetaInsights(bool $withActions = true, string $adSetId = 'adset-1'): void
    {
        $actions = $withActions
            ? [
                'actions' => [['action_type' => 'purchase', 'value' => '24']],
                'action_values' => [['action_type' => 'purchase', 'value' => '5400']],
            ]
            : [];

        Http::fake(function ($request) use ($actions, $adSetId) {
            $url = $request->url();

            if (! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }

            $row = [
                'campaign_id' => 'cmp-1',
                'date_start' => '2026-08-01',
                'spend' => '1200',
                'impressions' => '40000',
                'clicks' => '800',
                'reach' => '31000',
                ...$actions,
            ];

            if (str_contains($url, 'level=adset')) {
                return Http::response(['data' => [[...$row, 'adset_id' => $adSetId]]], 200);
            }

            if (str_contains($url, 'level=ad&') || str_contains($url, 'level=ad%')) {
                return Http::response(['data' => [[
                    ...$row, 'ad_id' => 'ad-1', 'adset_id' => $adSetId, 'impressions' => '25000',
                ]]], 200);
            }

            // The campaign grain, which must keep working exactly as it did.
            return Http::response(['data' => [[...$row]]], 200);
        });
    }

    private function liveMetaAccount(): ExternalAccount
    {
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            'default_currency' => 'USD',
        ]);
        $project = Project::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)),
            connectionName: 'meta',
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta',
            'account_type' => 'ad_account',
            'external_id' => 'act_1',
            'name' => 'Meta',
            'status' => 'active',
            'currency' => 'USD',
            'timezone' => 'Asia/Riyadh',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'meta',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);

        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'meta',
            'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);

        $adSet = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_campaign_id' => $campaign->id, 'provider' => 'meta',
            'external_id' => 'adset-1', 'name' => 'Riyadh 25-44', 'status' => 'active',
        ]);

        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id,
            'external_ad_set_id' => $adSet->id, 'external_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ad-1', 'name' => 'Carousel', 'status' => 'active',
        ]);

        return $account;
    }
}
