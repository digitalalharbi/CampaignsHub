<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

        return ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'sandbox-act-1',
            'name' => 'Acct', 'status' => 'active',
        ]);
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

        // One row was mappable, the other ("sbx-cmp-2") was not — that is a partial run, not a success.
        $this->assertSame('partial', $run->status);
        $this->assertStringContainsString('could not be mapped', (string) $run->error);
        $this->assertGreaterThan(0, $run->metrics_upserted);
        $this->assertNotNull($run->finished_at);

        $spend = DailyMetric::withoutGlobalScopes()->where('metric_key', 'spend')->where('unified_campaign_id', $this->campaign->id)->first();
        $this->assertNotNull($spend, 'the mapped insight must land as a normalized metric');
        $this->assertEquals(100.0, (float) $spend->value);
    }

    public function test_a_provider_without_credentials_is_not_run_and_is_not_reported_as_failed(): void
    {
        $account = $this->account('meta');   // no real Meta credentials in this environment

        $run = app(AccountMetricsSyncer::class)->sync($account, Carbon::parse('2026-06-01'), Carbon::parse('2026-06-07'));

        $this->assertSame('awaiting_credentials', $run->status, '"we never tried" must be distinguishable from "we tried and it broke"');
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

        $this->assertSame('awaiting_credentials', $run->status);
        $this->assertStringNotContainsString('No connector is registered', (string) $run->error);
        $this->assertStringContainsString('Google Ads API', (string) $run->error);
    }
}
