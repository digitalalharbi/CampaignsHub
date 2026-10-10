<?php

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-COVERAGE-001 — a client's live page compares two windows only when both are whole.
 *
 * The page carried a trend pill on every KPI computed from `totals` against `previous` and read
 * neither window's coverage: a platform that had reported through the 27th of a window ending on
 * the 10th put «+18 %» beside a spend covering seventeen days against thirty.
 */
class LiveReportComparabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Report $report;

    private UnifiedCampaign $campaign;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 'cmp-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'A campaign', 'status' => 'active', 'objective' => 'sales',
        ]);
        // A real account chain — the FK on external_campaigns and the sync run both point at it.
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token-meta');
        $credential->save();
        $connection = ProviderConnection::create([
            'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'meta connection', 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Ad account', 'status' => 'active',
        ]);
        $this->accountId = (string) $account->id;
        // Meta is EXPECTED because the project holds an active Meta campaign across both windows.
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $ws->getKey(), 'unified_campaign_id' => $this->campaign->getKey(),
            'external_account_id' => $this->accountId, 'provider' => 'meta',
            'external_id' => 'ext-1', 'name' => 'Meta campaign', 'status' => 'active',
            'starts_at' => now()->subDays(60)->toDateString(), 'ends_at' => null,
        ]);
        // Rows in both the current window (days 2..5 ago) and the previous one (days 35..38 ago).
        foreach ([2, 3, 4, 5, 35, 36, 37, 38] as $ago) {
            foreach ([['spend', 100.0], ['clicks', 20.0], ['impressions', 2000.0], ['conversions', 3.0], ['revenue', 400.0]] as [$key, $value]) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => $this->tenant->getKey(),
                    'project_id' => $this->project->getKey(),
                    'external_account_id' => $this->accountId,
                    'external_campaign_id' => (string) Str::uuid(),
                    'unified_campaign_id' => $this->campaign->getKey(),
                    'provider' => 'meta',
                    'metric_key' => $key,
                    'metric_date' => now()->subDays($ago)->toDateString(),
                    'value' => $value * ($ago > 30 ? 1 : 2),
                ]);
            }
        }
        $this->report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(29)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => ['kpis' => ['spend' => 800]],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @return array<string, mixed> */
    private function livePayload(): array
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(29)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    public function test_with_no_sync_checkpoint_the_windows_compare_and_the_deltas_are_stated(): void
    {
        $payload = $this->livePayload();

        $this->assertTrue($payload['comparison']['comparable']);
        $this->assertNull($payload['comparison']['window']);
        $this->assertEqualsWithDelta(1.0, $payload['deltas']['spend'], 0.0001);
    }

    public function test_a_platform_that_stopped_short_withholds_every_delta_and_says_through_when(): void
    {
        $through = now()->subDays(10)->toDateString();
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->accountId, 'provider' => 'meta',
            'status' => 'success', 'window_start' => now()->subDays(40)->toDateString(), 'window_end' => $through,
            'finished_at' => now()->subDays(10),
        ]);

        $payload = $this->livePayload();

        $this->assertFalse($payload['comparison']['comparable'], json_encode($payload['totals']['coverage'] ?? null));
        $this->assertSame('current', $payload['comparison']['window']);
        $this->assertSame(['meta'], $payload['comparison']['contributors']);
        $this->assertSame($through, $payload['comparison']['through']);
        $this->assertSame([], array_filter($payload['deltas'], static fn ($v) => $v !== null), 'every delta is withheld');
        foreach ($payload['platforms'] as $row) {
            $this->assertSame([], $row['movement']);
        }
        // The boundary still blanks the operator's sentence; the client gets the date, not the reason.
        $this->assertSame([], $payload['totals']['coverage']['reasons']);
    }
}
