<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
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
 * LEAD-EVIDENCE-001 — a client link never prints a platform's optimised-for «conversions» as Leads.
 *
 * NASEL Riwaq on TikTok: 701 `conversion` events (landing-page and lead-form campaigns together) and no lead metric at all. A report that
 * reads «Leads 701» claims 701 people left their details. The payload therefore says which base metrics the platform actually SENT, and the
 * page prints «غير متاح» for Leads where it sent none.
 */
final class LiveReportLeadEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'F', 'slug' => 'form-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $tenant->getKey());

        $ws = ClientWorkspace::create(['tenant_id' => $tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'A campaign', 'status' => 'active', 'objective' => 'leads',
        ]);

        foreach ([['spend', 400.0], ['clicks', 90.0], ['impressions', 9000.0], ['conversions', 701.0]] as [$key, $value]) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'tenant_id' => $tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'tiktok',
                'metric_key' => $key,
                'metric_date' => now()->subDays(2)->toDateString(),
                'value' => $value,
            ]);
        }

        /*
         * Created the way `LiveReportBuilderController` creates one: NO form on the report row.
         *
         * `reports.form` therefore falls to its column default, which is `detailed`. Writing
         * `'form' => 'executive_summary'` here instead would make every case below pass for the
         * wrong reason — it is the disagreement between the two rows that is under test.
         */
        $this->report = Report::create([
            'tenant_id' => $tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'live', 'status' => 'completed', 'currency' => 'SAR',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    private function payload(): array
    {
        [, $raw] = app(ShareService::class)->create($this->report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['tiktok'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);

        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    public function test_raw_conversions_are_reported_and_leads_are_not_when_the_platform_sent_no_lead_metric(): void
    {
        $payload = $this->payload();

        $this->assertTrue($payload['reported']['conversions']);
        $this->assertFalse($payload['reported']['leads'], 'no lead metric was sent, so the page must not print a measured zero or borrow conversions');
        $this->assertEquals(701, $payload['totals']['conversions']);
    }
}
