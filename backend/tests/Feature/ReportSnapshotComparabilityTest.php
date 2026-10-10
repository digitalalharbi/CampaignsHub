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
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-SNAPSHOT-COMPARABILITY-001 — a generated report compares two windows only when both are whole.
 */
class ReportSnapshotComparabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private string $accountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Snap', 'slug' => 'snap-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);
        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token-meta');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'meta', 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::create(['tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->id, 'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'act_1', 'name' => 'Ad account', 'status' => 'active']);
        $this->accountId = (string) $account->id;
        ExternalCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'client_workspace_id' => $client->getKey(),
            'unified_campaign_id' => $this->campaign->getKey(), 'external_account_id' => $this->accountId, 'provider' => 'meta',
            'external_id' => 'ext-1', 'name' => 'Meta campaign', 'status' => 'active', 'starts_at' => now()->subDays(60)->toDateString(), 'ends_at' => null,
        ]);
        foreach ([2, 3, 12, 13] as $ago) {
            foreach ([['spend', 100.0], ['clicks', 20.0], ['impressions', 2000.0], ['conversions', 3.0], ['revenue', 400.0]] as [$key, $value]) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                    'external_account_id' => $this->accountId, 'external_campaign_id' => (string) Str::uuid(),
                    'unified_campaign_id' => $this->campaign->getKey(), 'provider' => 'meta', 'metric_key' => $key,
                    'metric_date' => now()->subDays($ago)->toDateString(), 'value' => $value * ($ago > 10 ? 1 : 2),
                    'project_currency' => 'SAR',
                ]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function generate(): array
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Ten days', 'type' => 'monthly', 'form' => 'detailed', 'status' => 'processing',
            'period_start' => now()->subDays(9)->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'SAR',
        ]);
        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));

        return (array) $report->refresh()->data;
    }

    public function test_with_no_sync_checkpoint_the_windows_compare_and_the_deltas_are_frozen(): void
    {
        $data = $this->generate();

        $this->assertTrue($data['comparison']['comparable']);
        $this->assertEqualsWithDelta(1.0, $data['delta']['spend'], 0.0001);
    }

    public function test_a_platform_that_stopped_short_withholds_every_delta_and_names_the_date(): void
    {
        $through = now()->subDays(5)->toDateString();
        MetricSyncRun::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->accountId, 'provider' => 'meta', 'status' => 'success',
            'window_start' => now()->subDays(40)->toDateString(), 'window_end' => $through, 'finished_at' => now()->subDays(5),
        ]);

        $data = $this->generate();

        $this->assertFalse($data['comparison']['comparable']);
        $this->assertSame('current', $data['comparison']['window']);
        $this->assertSame(['meta'], $data['comparison']['contributors']);
        $this->assertSame($through, $data['comparison']['through']);
        $this->assertSame([], array_filter($data['delta'], static fn ($v) => $v !== null), 'every delta is withheld');
        // The figures themselves are still frozen.
        $this->assertEqualsWithDelta(400.0, $data['kpis']['spend'], 0.01);
    }
}
