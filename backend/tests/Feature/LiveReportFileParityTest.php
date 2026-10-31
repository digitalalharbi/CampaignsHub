<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A live report's FILES carry the figures its page shows (NASEL, Production 2026-10-29).
 *
 * A live link's report is created with no `data` snapshot on purpose — the report IS the live definition. Every file path read
 * that snapshot, so the CSV exported from the NASEL live report printed « → » for its period, an empty KPI block and a platform
 * header naming revenue / conversions / ROAS / CPA the report never chose. The file must be rendered from the same live builder
 * as the page, over the window the page shows, under the report's own KPI list.
 */
final class LiveReportFileParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Stages', 'slug' => 'st-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create([
            'name' => 'Op', 'email' => 'st-'.uniqid().'@a.test', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(), provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account', 'external_id' => 'acc-'.uniqid(),
            'name' => 'Shop', 'currency' => 'SAR', 'status' => 'active',
        ]);
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Sales', 'status' => 'active', 'objective' => 'sales', 'platforms' => ['meta'],
        ]);
    }

    public function test_a_live_reports_csv_carries_the_pages_period_and_chosen_kpis(): void
    {
        $this->report(['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0]);
        $created = $this->live(['spend', 'impressions', 'clicks']);
        $page = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');

        $csv = app(ReportExporter::class)->render(Report::withoutGlobalScopes()->findOrFail($created['report_id']), 'csv');

        $this->assertStringContainsString((string) $page['period']['from'], $csv, 'the file lost the period the page shows');
        $this->assertStringContainsString((string) $page['period']['to'], $csv);
        foreach (['spend', 'impressions', 'clicks'] as $key) {
            $this->assertMatchesRegularExpression('/^'.$key.',/m', $csv, "the chosen KPI {$key} has no row in the file");
        }
        $this->assertMatchesRegularExpression('/^spend,500(\\.0+)?$/m', $csv, 'the file does not carry the page\'s spend');
        $this->assertStringContainsString('Currency,'.$page['currency'], $csv, 'the file names a currency the page does not');
        $this->assertStringContainsString('Live', $csv, 'a live report\'s file calls itself a snapshot');
        // KPIs the report did not choose are not rows in its KPI block.
        $kpiBlock = explode("\n\n", substr($csv, strpos($csv, "KPIs\n")))[0];
        foreach (['revenue', 'conversions', 'roas', 'cpa', 'results'] as $unchosen) {
            $this->assertDoesNotMatchRegularExpression('/^'.$unchosen.',/m', $kpiBlock, "{$unchosen} was not chosen and is in the file");
        }
    }

    public function test_a_live_links_downloaded_csv_carries_the_pages_figures(): void
    {
        $this->report(['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0]);
        $created = $this->live(['spend', 'impressions', 'clicks'], ['allow_download' => true, 'hide_spend' => false]);

        $csv = $this->get("/api/v1/reports/shared/{$created['token']}/download/csv")->assertOk()->streamedContent();

        $this->assertMatchesRegularExpression('/^impressions,90000(\\.0+)?$/m', $csv, 'the link\'s file is empty where its page is not');
    }

    /** @param array<string, float> $metrics */
    private function report(array $metrics): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $this->campaign->getKey(),
            'provider' => 'meta', 'external_id' => 'e-'.uniqid(), 'name' => 'Sales', 'status' => 'active',
        ]);

        $rows = [];
        foreach ($metrics as $key => $value) {
            $rows[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->getKey(),
                projectId: (string) $this->project->getKey(),
                provider: 'meta',
                externalAccountId: (string) $this->account->getKey(),
                externalCampaignId: (string) $external->getKey(),
                unifiedCampaignId: (string) $this->campaign->getKey(),
                metricDate: Carbon::parse('2026-09-15'),
                metricKey: $key,
                value: $value,
                projectCurrency: 'SAR',
            );
        }

        app(UpsertDailyMetrics::class)->handle($rows);
    }

    /**
     * @param  list<string>  $metrics
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function test_a_live_link_that_hides_spend_ships_no_spend_in_its_file(): void
    {
        $this->report(['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0]);
        $created = $this->live(['impressions', 'clicks'], ['allow_download' => true, 'hide_spend' => true]);

        $csv = $this->get("/api/v1/reports/shared/{$created['token']}/download/csv")->assertOk()->streamedContent();

        $this->assertMatchesRegularExpression('/^clicks,1200(\\.0+)?$/m', $csv);
        $this->assertStringNotContainsString(',500', $csv, 'the hidden spend reached the file');
    }

    private function live(array $metrics, array $extra = []): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/live", [
                'name' => 'Live file',
                'account_ids' => [(string) $this->account->getKey()],
                'from' => '2026-09-01',
                'to' => '2026-09-30',
                'form' => 'detailed',
                'metrics' => $metrics,
            ] + $extra)
            ->assertCreated()
            ->json('data');
    }
}
