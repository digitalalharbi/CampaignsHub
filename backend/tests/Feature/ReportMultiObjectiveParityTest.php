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
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportTemplateEngine;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * REPORT-MULTI-OBJECTIVE-001 — master task 10, its acceptance in the Owner's words: «نفس التقرير بهدف "زيارات + مبيعات" يظهر بنفس المؤشرات
 * والأرقام في الرابط والمحفوظ وPDF».
 *
 * Before: the builder's objective never left the browser (every live link ranked under «custom» and showed every KPI), the saved report folded
 * several objectives into the first, and a live link ignored the report's own KPI list while its PDF was cut by it.
 */
final class ReportMultiObjectiveParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    private UnifiedCampaign $campaign;

    private const FIGURES = ['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0, 'add_to_cart' => 40.0, 'purchases' => 12.0, 'revenue' => 3000.0];

    /** Traffic (spend · impressions · clicks · ctr · cpc) ∪ sales (… · add_to_cart · purchases · revenue · roas), in that order. */
    // KPI-SET-PER-OBJECTIVE-001 — the traffic preset gained CPM and frequency (Owner 2026-11-13); the union keeps traffic's order, then sales'.
    private const UNION = ['spend', 'impressions', 'clicks', 'ctr', 'cpm', 'frequency', 'cpc', 'add_to_cart', 'purchases', 'revenue', 'roas'];

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

    /** @return array{page: array<string, mixed>, pdf: array<string, mixed>, file: array<string, mixed>, created: array<string, mixed>} */
    private function surfaces(array $extra = [], array $metrics = ['spend', 'impressions', 'clicks']): array
    {
        $this->report(self::FIGURES);
        $created = $this->live($metrics, $extra + ['allow_download' => true, 'hide_spend' => false]);
        $raw = (string) $created['token'];
        $report = Report::withoutGlobalScopes()->findOrFail($created['report_id']);
        $share = ReportShare::withoutGlobalScopes()->where('report_id', $report->getKey())->firstOrFail();

        $page = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        config(['reports.chromium.enabled' => true, 'reports.chromium.node_bin' => '/nonexistent/node-that-cannot-run']);
        $minted = null;
        Cache::spy();
        $this->get("/api/v1/reports/shared/{$raw}/download/pdf");
        Cache::shouldHaveReceived('put')->withArgs(function ($key, $value) use (&$minted): bool {
            if (is_string($key) && str_starts_with($key, 'report-print:') && is_array($value)) {
                $minted = $value;

                return true;
            }

            return false;
        });
        $this->assertIsArray($minted);
        Cache::clearResolvedInstance('cache');
        Cache::swap(app('cache'));
        Cache::put('report-print:'.hash('sha256', 'live-captured'), $minted, 300);
        $pdf = $this->getJson('/api/v1/reports/print/live-captured')->assertOk()->json('data');

        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $file = app(ReportExporter::class)->exportData(app(ShareService::class)->documentReport($report, $share), $share);

        return ['page' => $page, 'pdf' => $pdf, 'file' => $file, 'created' => $created];
    }

    public function test_a_live_link_for_visits_and_sales_shows_both_objectives_kpis_on_its_page_pdf_and_file(): void
    {
        $s = $this->surfaces(['objectives' => ['traffic', 'sales']], []);

        $report = Report::withoutGlobalScopes()->findOrFail($s['created']['report_id']);
        $this->assertSame('custom', $report->campaign_objective, 'several objectives are the mixed lens, never the first of them');
        $this->assertSame(['traffic', 'sales'], $report->config['objectives'] ?? null);

        $this->assertSame(self::UNION, $s['page']['metrics'], 'the link states both objectives\' KPIs');
        $this->assertSame($s['page']['metrics'], $s['pdf']['data']['metric_set'] ?? null, 'PDF ≠ link');
        $this->assertSame($s['page']['metrics'], $s['file']['metric_set'] ?? null, 'file ≠ link');
        foreach (['spend', 'clicks', 'purchases', 'revenue'] as $metric) {
            $this->assertEqualsWithDelta(self::FIGURES[$metric], (float) $s['page']['totals'][$metric], 0.001, "link: {$metric}");
            $this->assertEqualsWithDelta(self::FIGURES[$metric], (float) ($s['pdf']['data']['kpis'][$metric] ?? -1), 0.001, "PDF: {$metric}");
        }
    }

    public function test_one_objective_is_the_reports_objective_and_its_preset(): void
    {
        $s = $this->surfaces(['objectives' => ['sales']], []);

        $report = Report::withoutGlobalScopes()->findOrFail($s['created']['report_id']);
        $this->assertSame('sales', $report->campaign_objective);
        $this->assertArrayNotHasKey('objectives', (array) $report->config);
        $this->assertSame(['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], $s['page']['metrics']);
    }

    public function test_a_link_with_no_objective_and_no_kpis_is_unchanged(): void
    {
        $s = $this->surfaces([], []);

        $this->assertSame([], $s['page']['metrics'], 'a link made as before still means «all of them»');
        $this->assertNull(Report::withoutGlobalScopes()->findOrFail($s['created']['report_id'])->campaign_objective);
    }

    public function test_the_objectives_kpis_drop_the_money_the_link_hides(): void
    {
        $s = $this->surfaces(['objectives' => ['traffic', 'sales'], 'hide_spend' => true], []);

        $this->assertNotContains('spend', $s['page']['metrics']);
        $this->assertNotContains('cpc', $s['page']['metrics']);
        $this->assertContains('clicks', $s['page']['metrics']);
        $this->assertSame($s['page']['metrics'], $s['pdf']['data']['metric_set'] ?? null);
    }

    public function test_a_saved_report_for_visits_and_sales_states_the_same_kpis_and_figures_as_the_link(): void
    {
        $live = $this->surfaces(['objectives' => ['traffic', 'sales']], []);

        $saved = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'Saved',
            'type' => 'performance', 'status' => 'completed', 'audience' => 'client', 'form' => 'detailed',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'SAR', 'created_by' => $this->operator->getKey(),
            'scope' => ['objectives' => ['traffic', 'sales']],
        ]);
        $data = app(ReportGenerator::class)->generate($saved->fresh());

        $this->assertSame('custom', $saved->fresh()->campaign_objective);
        $this->assertSame(['traffic', 'sales'], $data['objectives']);
        $this->assertSame($live['page']['metrics'], $data['metric_set'], 'saved ≠ link');
        foreach (['spend', 'clicks', 'purchases', 'revenue'] as $metric) {
            $this->assertEqualsWithDelta((float) $live['page']['totals'][$metric], (float) ($data['kpis'][$metric] ?? -1), 0.001, "saved: {$metric}");
        }
    }

    public function test_a_live_link_is_cut_by_the_reports_own_kpi_list_as_its_pdf_is(): void
    {
        $this->report(self::FIGURES);
        $created = $this->live([], ['hide_spend' => false]);
        $report = Report::withoutGlobalScopes()->findOrFail($created['report_id']);
        $report->forceFill(['scope' => ['metrics' => ['clicks', 'impressions']]])->saveQuietly();

        $page = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');

        $this->assertSame(['clicks', 'impressions'], $page['metrics'], 'the link ignored the report\'s own KPI list');
    }

    public function test_a_live_link_of_a_report_whose_objective_was_inferred_still_shows_every_kpi(): void
    {
        $this->report(self::FIGURES);
        $created = $this->live([], ['hide_spend' => false]);
        $report = Report::withoutGlobalScopes()->findOrFail($created['report_id']);
        // A saved report's generator writes its inferred objective; that is not a choice made for its link.
        $report->forceFill(['campaign_objective' => 'sales', 'config' => [...(array) $report->config, 'live' => false]])->saveQuietly();

        $page = $this->getJson("/api/v1/reports/shared/{$created['token']}/live")->assertOk()->json('data');

        $this->assertSame([], $page['metrics'], 'an existing link changed the KPIs it shows');
    }

    public function test_a_report_stored_as_custom_keeps_the_custom_kpis(): void
    {
        $this->report(self::FIGURES);
        $saved = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'Custom',
            'type' => 'performance', 'status' => 'completed', 'audience' => 'client', 'form' => 'detailed', 'campaign_objective' => 'custom',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency' => 'SAR', 'created_by' => $this->operator->getKey(),
        ]);
        $data = app(ReportGenerator::class)->generate($saved->fresh());

        $this->assertSame(app(ReportTemplateEngine::class)->metricSet('custom'), $data['metric_set']);
        $this->assertSame([], $data['objectives']);
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
