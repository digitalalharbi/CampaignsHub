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
 * AGGREGATION-TRUTH-001 — a share of nothing is not zero.
 *
 * `byProvider()` divided each platform's spend by `array_sum(...) ?: 1`. When no spend was recorded —
 * a window before the campaigns started, a sync that has not landed, spend the money contract
 * withheld — the denominator became one riyal and every platform reported a share of exactly 0%.
 *
 * That is a definite claim: «this platform contributed nothing to the spend». The truth is that
 * there is no spend to take a share OF, which the reader has to be told instead of shown a bar at
 * zero. It is the same fabricated denominator as the printed budget table's `Math.max(1, budget)`,
 * and the same lie: an absence dressed as a measurement.
 */
final class SpendShareTruthTest extends TestCase
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
    }

    /** @param array<string, float|int> $metrics */
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
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $provider.'-act', 'name' => $provider, 'status' => 'active',
        ])->save();

        $campaign = new ExternalCampaign;
        $campaign->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider,
            'external_id' => $provider.'-cmp', 'name' => $provider.' campaign', 'status' => 'active',
        ])->save();

        foreach ($metrics as $key => $value) {
            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $account->id, 'external_campaign_id' => $campaign->id,
                'provider' => $provider, 'metric_key' => $key, 'metric_date' => '2026-08-10',
                'value' => $value, 'original_amount' => $value, 'original_currency' => 'USD',
                'project_currency' => 'USD', 'exchange_rate' => 1,
            ]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function byProvider(): array
    {
        return collect(app(MetricsAggregator::class)->forProjects([$this->project->id])
            ->byProvider(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-30')))
            ->keyBy('provider')->all();
    }

    public function test_a_platform_with_no_spend_anywhere_reports_no_share_rather_than_zero(): void
    {
        // Impressions landed; spend did not. The window has activity but nothing to take a share of.
        $this->write('meta', ['impressions' => 9000, 'clicks' => 630]);
        $this->write('snapchat', ['impressions' => 1000, 'clicks' => 10]);

        $rows = $this->byProvider();

        $this->assertNull($rows['meta']['spend_share'], 'a share of nothing is not zero');
        $this->assertNull($rows['snapchat']['spend_share']);
    }

    /** With real spend the share is a real proportion, and the shares add to one. */
    public function test_real_spend_still_divides_into_real_shares(): void
    {
        $this->write('meta', ['impressions' => 100, 'spend' => 75]);
        $this->write('snapchat', ['impressions' => 100, 'spend' => 25]);

        $rows = $this->byProvider();

        $this->assertEqualsWithDelta(0.75, (float) $rows['meta']['spend_share'], 0.0001);
        $this->assertEqualsWithDelta(0.25, (float) $rows['snapchat']['spend_share'], 0.0001);
        $this->assertEqualsWithDelta(
            1.0,
            (float) $rows['meta']['spend_share'] + (float) $rows['snapchat']['spend_share'],
            0.0001,
        );
    }
}
