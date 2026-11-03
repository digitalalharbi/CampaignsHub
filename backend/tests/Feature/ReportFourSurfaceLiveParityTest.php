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
 * REPORT-PARITY-FOUR-SURFACES-001 — a LIVE link: its page, its PDF and its file are one answer.
 *
 * The page recomputes on every open; the PDF and the files are rendered from `LiveReportService::materialise()`, a snapshot of that
 * same builder taken at print time. What one saved live configuration (window, KPIs chosen, a section off, a currency) shows and
 * hides must not depend on which of the three the client opens.
 */
final class ReportFourSurfaceLiveParityTest extends TestCase
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

    /** @return array{page: array<string, mixed>, pdf: array<string, mixed>, file: array<string, mixed>, created: array<string, mixed>} */
    private function surfaces(array $extra = [], array $metrics = ['spend', 'impressions', 'clicks']): array
    {
        $this->report(['spend' => 500.0, 'impressions' => 90000.0, 'clicks' => 1200.0]);
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

    public function test_a_live_links_page_pdf_and_file_state_the_same_window_currency_and_figures(): void
    {
        $s = $this->surfaces();

        $this->assertSame('2026-09-01', $s['page']['period']['from']);
        $this->assertSame('2026-09-30', $s['page']['period']['to']);
        foreach (['pdf' => $s['pdf']['data'], 'file' => $s['file']] as $name => $doc) {
            $this->assertSame($s['page']['period']['from'], $doc['period']['from'] ?? null, "{$name}: another window than the page");
            $this->assertSame($s['page']['period']['to'], $doc['period']['to'] ?? null, "{$name}: another window than the page");
            $this->assertSame($s['page']['currency'], $doc['currency'] ?? null, "{$name}: another currency than the page");
            $this->assertSame($s['page']['metrics'], $doc['metric_set'] ?? null, "{$name}: other KPIs than the page");
            foreach (['spend', 'impressions', 'clicks'] as $metric) {
                $this->assertEqualsWithDelta((float) $s['page']['totals'][$metric], (float) ($doc['kpis'][$metric] ?? -1), 0.001, "{$name}: {$metric} is not the page's");
            }
        }
        $this->assertSame($s['page']['currency'], $s['pdf']['currency']);
    }

    public function test_a_live_links_pdf_and_file_show_and_hide_the_sections_its_page_does(): void
    {
        $s = $this->surfaces();

        $this->assertSame($s['page']['report_sections'], $s['pdf']['data']['report_sections'] ?? null, 'PDF ≠ page');
        $this->assertSame($s['page']['report_sections'], $s['file']['report_sections'] ?? null, 'file ≠ page');
    }

    public function test_a_live_links_pdf_and_file_honour_the_links_own_switches_like_its_page(): void
    {
        // Spend is not one of the KPIs this link chose, so its «hide spend» switch holds (a link that SELECTS spend shows it — REPORT-SPEND-VISIBILITY-001).
        $s = $this->surfaces(['hide_spend' => true], ['impressions', 'clicks']);

        $this->assertNull($s['page']['totals']['spend'] ?? null, 'the page still shows the hidden spend');
        $this->assertNull($s['pdf']['data']['kpis']['spend'] ?? null, 'the PDF prints the spend the page hides');
        $this->assertNull($s['file']['kpis']['spend'] ?? null, 'the file carries the spend the page hides');
        $this->assertEqualsWithDelta(1200.0, (float) $s['pdf']['data']['kpis']['clicks'], 0.001);
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
