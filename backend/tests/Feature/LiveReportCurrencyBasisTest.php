<?php

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ReportingCurrency;
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
 * REPORT-CURRENCY-TRUTH-001 — the live page labels its figures with the currency its rows are
 * normalised to, not with the stamp the report row was created with.
 *
 * Both report-creating controllers write `ReportingCurrency::DEFAULT` (USD); the aggregator
 * normalises every monetary row to the project's basis (SAR here). The snapshot path already let the
 * rows win (`ReportCurrencyBasisTest`); the live page printed «45.9K USD» over the same figures its
 * budget block called 45.9K SAR.
 */
class LiveReportCurrencyBasisTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 'ccy-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'A campaign', 'status' => 'active', 'objective' => 'sales',
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param list<array{string, float, string|null}> $rows metric key, value, project currency */
    private function rows(array $rows): void
    {
        $this->holdingTenant((string) $this->tenant->getKey());
        foreach ($rows as [$key, $value, $currency]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta',
                'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value,
                'project_currency' => $currency,
            ]);
        }
        app(TenantContext::class)->forget();
    }

    /** @return array<string, mixed> */
    private function livePayloadOfAUsdStampedReport(): array
    {
        $this->holdingTenant((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'currency' => ReportingCurrency::DEFAULT,
            'period_start' => now()->subDays(29)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => ['kpis' => ['spend' => 400]],
        ]);
        [, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(29)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    public function test_rows_normalised_to_sar_are_labelled_sar_whatever_the_report_row_says(): void
    {
        $this->rows([['spend', 400.0, 'SAR'], ['clicks', 90.0, null], ['revenue', 1600.0, 'SAR']]);

        $payload = $this->livePayloadOfAUsdStampedReport();

        $this->assertSame('SAR', $payload['currency'], 'the live page published USD over figures its own rows say are SAR');
    }

    public function test_two_bases_state_no_currency_rather_than_choosing_one(): void
    {
        $this->rows([['spend', 400.0, 'SAR'], ['revenue', 1600.0, 'USD']]);

        $payload = $this->livePayloadOfAUsdStampedReport();

        $this->assertArrayHasKey('currency', $payload);
        $this->assertNull($payload['currency']);
    }

    public function test_with_no_monetary_rows_the_stamp_stands(): void
    {
        $this->rows([['clicks', 90.0, null]]);

        $payload = $this->livePayloadOfAUsdStampedReport();

        $this->assertSame('USD', $payload['currency']);
    }
}
