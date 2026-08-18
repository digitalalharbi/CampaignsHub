<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SYNC-001 — the sync pipeline's contract. What matters is not that a sync "works", but that every
 * outcome is recorded truthfully: a credential-less provider is never run and never marked failed,
 * an unmappable insight makes the run partial rather than silently disappearing, and metrics land
 * through the same idempotent upsert the rest of the system uses.
 */
final class MetricsSyncPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Sandbox Awareness', 'objective' => 'awareness', 'status' => 'active',
        ]);
    }

    private function account(string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential(['provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'sandbox-act-1',
            'name' => 'Acct', 'status' => 'active',
        ]);

        /*
         * Assigned, because that is what every account this pipeline runs against is.
         *
         * RUNTIME-100 §15 — the syncer now refuses an account nobody attached to a project, before it
         * looks at credentials at all: there is no instruction to fetch it, so the question of whether
         * we COULD does not arise. These tests are about the credential and connector rules, so the
         * fixture has to satisfy the outer one to reach them. Without this they were asserting the
         * credential refusal against an account the pipeline would never have been given.
         */
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }

    public function test_a_connected_provider_writes_normalized_metrics_and_records_a_run(): void
    {
        $account = $this->account('sandbox');

        // The sandbox connector reports insights for "sbx-cmp-1"; only a discovered campaign can be mapped.
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id, 'external_account_id' => $account->id,
            'provider' => 'sandbox', 'external_id' => 'sbx-cmp-1', 'name' => 'Sandbox Awareness', 'status' => 'active',
        ]);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        /*
         * One row was mappable, the other ("sbx-cmp-2") was not.
         *
         * INTEG-RUNTIME §8: that is `partial_mapping` — rows arrived and some could not be placed —
         * and it is now told apart from `no_data`, which the single word `partial` used to cover too.
         */
        $this->assertSame('partial_mapping', $run->status);
        $this->assertSame(2, (int) $run->parsed_rows);
        $this->assertSame(1, (int) $run->mapped_campaign_rows);
        $this->assertGreaterThan(0, $run->metrics_upserted);
        $this->assertNotNull($run->finished_at);

        $spend = DailyMetric::withoutGlobalScopes()->where('metric_key', 'spend')->where('unified_campaign_id', $this->campaign->id)->first();
        $this->assertNotNull($spend, 'the mapped insight must land as a normalized metric');
        $this->assertEquals(100.0, (float) $spend->value);
    }

    /**
     * PIPELINE-METRICS-001 — a metric a connector maps is a metric that reaches storage.
     *
     * `ingest()` used to carry a literal list of seven keys while `MetricsAggregator` read eighteen,
     * so a connector could map `add_to_cart` correctly, from the platform's own correct field, and
     * the figure was dropped on that line before it ever became a row. Nothing failed and nothing was
     * logged — the funnel simply had no add-to-cart stage, on every platform, for as long as that
     * list stayed shorter than the engine's.
     *
     * The assertion walks `MetricsAggregator::readKeys()` rather than naming keys, so a metric added
     * to the engine later cannot quietly stop being carried here.
     */
    public function test_every_canonical_metric_a_connector_reports_is_stored_not_only_the_first_seven(): void
    {
        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        // A real authorised connection: this path reads a token, unlike the sandbox connector.
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'snapchat',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'snapchat',
        );
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => 'snapchat', 'account_type' => 'ad_account', 'external_id' => 'act-1',
            'name' => 'Snap acct', 'status' => 'active',
            // SNAP-WINDOW-001 — Snapchat's DAY range must sit on this account's day boundary, so the
            // connector needs the timezone discovery recorded rather than a default.
            'timezone' => 'Asia/Riyadh',
        ]);

        // Assigned, because that is what every account this pipeline runs against is (RUNTIME-100 §15).
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id, 'external_account_id' => $account->id,
            'provider' => 'snapchat', 'external_id' => 'camp-1', 'name' => 'Snap', 'status' => 'active',
        ]);

        Http::fake(['adsapi.snapchat.com/*' => Http::response([
            'timeseries_stats' => [[
                'timeseries_stat' => [
                    'id' => 'camp-1',
                    'timeseries' => [[
                        'start_time' => '2026-06-01T00:00:00.000-00:00',
                        'stats' => [
                            'spend' => 10_000_000, 'impressions' => 5000, 'swipes' => 200,
                            'uniques' => 4000, 'landing_page_views' => 150, 'video_views' => 900,
                            'view_completion' => 300, 'conversion_add_cart' => 60,
                            'conversion_start_checkout' => 30, 'conversion_purchases' => 12,
                            'conversion_purchases_value' => 48_000_000,
                        ],
                    ]],
                ],
            ]],
        ])]);

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertSame('success', $run->status);

        $stored = DailyMetric::withoutGlobalScopes()
            ->where('unified_campaign_id', $this->campaign->id)
            ->pluck('value', 'metric_key');

        // The five that always worked, and the six that were being thrown away.
        $expected = [
            'spend' => 10.0, 'impressions' => 5000.0, 'clicks' => 200.0,
            'conversions' => 12.0, 'revenue' => 48.0, 'reach' => 4000.0,
            'landing_page_views' => 150.0, 'video_views' => 900.0, 'video_completions' => 300.0,
            'add_to_cart' => 60.0, 'checkout' => 30.0, 'purchases' => 12.0,
        ];

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $stored->all(), "{$key} was mapped by the connector and never stored");
            $this->assertEqualsWithDelta($value, (float) $stored[$key], 0.001, $key);
        }

        // Nothing DERIVED was stored: a daily frequency summed over a month has no meaning, and the
        // engine computes it — null on a zero denominator — from the sums above.
        foreach (['frequency', 'roas', 'ctr', 'cpa', 'cpc', 'cpm'] as $derived) {
            $this->assertArrayNotHasKey($derived, $stored->all(), "{$derived} is derived and must never be stored");
        }

        // And every key the pipeline carried is one the engine actually reads.
        foreach ($stored->keys() as $key) {
            $this->assertContains($key, MetricsAggregator::readKeys(), "{$key} is stored but nothing reads it");
        }
    }

    /**
     * A platform with no credentials is not CALLED, and the refusal keeps its own category.
     *
     * The run is `failed`, because §8 gives the sync six words and «awaiting credentials» is not one
     * of them. The distinction that mattered — «we never tried» against «we tried and it broke» —
     * survives where it is actually used: `last_sync_error_category`, which is what decides whether
     * an operator adds keys or a platform is simply having a bad minute.
     */
    public function test_a_provider_without_credentials_is_not_called_and_keeps_its_own_category(): void
    {
        $account = $this->account('meta');   // no real Meta credentials in this environment

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('awaiting_credentials', $account->refresh()->last_sync_error_category);
        $this->assertNull($run->provider_raw_rows, 'nothing was measured, so nothing is claimed');
        $this->assertSame(0, (int) $run->metrics_upserted);
        $this->assertStringContainsString('No credentials', (string) $run->error);
        $this->assertSame(0, DailyMetric::withoutGlobalScopes()->count(), 'nothing may be written for an unauthenticated provider');
    }

    public function test_syncing_twice_does_not_duplicate_metrics(): void
    {
        $account = $this->account('sandbox');
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'unified_campaign_id' => $this->campaign->id, 'external_account_id' => $account->id,
            'provider' => 'sandbox', 'external_id' => 'sbx-cmp-1', 'name' => 'C', 'status' => 'active',
        ]);

        $syncer = app(AccountMetricsSyncer::class);
        $syncer->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));
        $first = DailyMetric::withoutGlobalScopes()->count();
        $syncer->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-02'));

        $this->assertSame($first, DailyMetric::withoutGlobalScopes()->count(), 'a re-sync updates in place, it does not duplicate');
    }

    /**
     * Regression: stored rows use the provider key "google" while the connector registers itself as
     * "google_ads". Without an alias a Google account resolved to no connector at all and its sync was
     * recorded as "no connector registered" — a misleading failure instead of the truthful
     * awaiting-credentials state.
     */
    public function test_google_accounts_resolve_to_the_google_ads_connector(): void
    {
        $account = $this->account('google');

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07'));

        $this->assertSame('failed', $run->status);
        $this->assertSame('awaiting_credentials', $account->refresh()->last_sync_error_category);
        $this->assertStringNotContainsString('No connector is registered', (string) $run->error);
        $this->assertStringContainsString('Google Ads API', (string) $run->error);
    }
}
