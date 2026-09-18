<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Notifications\Services\DigestPresenter;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REACH-DEDUP-001 — a sum of reach is not reach.
 *
 * Providers deduplicate reach for the grain they were asked about, and what is stored is one figure
 * per campaign, per platform, per day. Adding two of those counts a person who came back on Tuesday
 * twice, or a person reached on Meta and on Snapchat twice. The KPI cards summed them and derived
 * frequency from the sum — overstating reach and understating frequency on every surface that reads
 * the aggregator.
 *
 * The rule: reach is shown only where the displayed grain and period ARE one provider grain carrying
 * one reach row; otherwise it is null («—», not reported) and frequency is null with it.
 */
final class ReachIsNotASumTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private string $account;

    /** @var array<string,string> one external campaign per platform */
    private array $external = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'reach-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $this->account = (string) Str::uuid();
        $this->campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'Brand', 'status' => 'active', 'objective' => 'reach']);

        // Meta: two days of the same campaign. Snapchat: one day.
        $this->day('meta', '2026-07-10', ['impressions' => 5_000, 'reach' => 3_000, 'spend' => 10]);
        $this->day('meta', '2026-07-11', ['impressions' => 4_000, 'reach' => 2_500, 'spend' => 10]);
        $this->day('snapchat', '2026-07-10', ['impressions' => 6_000, 'reach' => 4_000, 'spend' => 10]);
    }

    /** @param array<string,float> $figures */
    private function day(string $provider, string $date, array $figures): void
    {
        $external = $this->external[$provider] ??= (string) Str::uuid();
        foreach ($figures as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => $this->account,
                'external_campaign_id' => $external, 'unified_campaign_id' => $this->campaign->id,
                'provider' => $provider, 'metric_key' => $key, 'metric_date' => $date, 'value' => $value,
            ]);
        }
    }

    private function engine(): MetricsAggregator
    {
        return app(MetricsAggregator::class);
    }

    public function test_the_period_total_across_days_and_platforms_has_no_reach_and_no_frequency(): void
    {
        $totals = $this->engine()->totals(Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11'));

        $this->assertNull($totals['reach'], '9,500 is three deduplicated figures added together, not reach');
        $this->assertNull($totals['frequency']);
        $this->assertSame(15_000.0, (float) $totals['impressions']);
        $this->assertFalse($this->engine()->reportedKeys(Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11'))['reach']);
    }

    public function test_one_platform_over_two_days_has_no_reach(): void
    {
        $meta = collect($this->engine()->byProvider(Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11')))->firstWhere('provider', 'meta');

        $this->assertNull($meta['reach']);
        $this->assertNull($meta['frequency']);
        $this->assertFalse($this->engine()->reportedKeysByProvider(Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11'))['meta']['reach']);
    }

    public function test_one_platform_one_campaign_one_day_is_the_providers_own_reach(): void
    {
        $day = Carbon::parse('2026-07-11');
        $meta = collect($this->engine()->byProvider($day, $day))->firstWhere('provider', 'meta');

        $this->assertSame(2_500.0, (float) $meta['reach']);
        $this->assertSame(1.6, (float) $meta['frequency']);
        $this->assertTrue($this->engine()->reportedKeysByProvider($day, $day)['meta']['reach']);

        // The whole scope that day is ONE grain too (only Meta ran on the 11th).
        $totals = $this->engine()->totals($day, $day);
        $this->assertSame(2_500.0, (float) $totals['reach']);
        $this->assertTrue($this->engine()->reportedKeys($day, $day)['reach']);
    }

    public function test_a_daily_series_point_spanning_two_platforms_has_no_reach(): void
    {
        $series = collect($this->engine()->timeseries(Carbon::parse('2026-07-10'), Carbon::parse('2026-07-11')))->keyBy('date');

        $this->assertNull($series['2026-07-10']['reach'], 'Meta and Snapchat on one day: two platforms\' reach added');
        $this->assertSame(2_500.0, (float) $series['2026-07-11']['reach']);
    }

    public function test_the_live_client_link_prints_no_summed_reach(): void
    {
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => ['kpis' => ['spend' => 1]],
        ]);
        [, $raw] = app(ShareService::class)->create($report, ['scope' => [
            'project_id' => $this->project->id, 'campaign_ids' => [$this->campaign->id], 'providers' => ['meta', 'snapchat'],
            'earliest' => '2026-07-10', 'latest' => '2026-07-11',
        ]], null);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        $res = $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-10&to=2026-07-11")->assertOk();

        $this->assertNull($res->json('data.totals.reach'));
        $this->assertNull($res->json('data.totals.frequency'));
    }

    public function test_the_generated_report_and_its_pdf_snapshot_carry_no_summed_reach_and_no_frequency_note(): void
    {
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly', 'status' => 'processing',
            'currency' => 'SAR', 'period_start' => '2026-07-10', 'period_end' => '2026-07-11',
        ]);

        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));
        $data = $report->refresh()->data;

        $this->assertNull($data['kpis']['reach']);
        $this->assertNull($data['kpis']['frequency']);
        $this->assertFalse($data['reported']['reach'], 'the card reads «not reported», never a summed figure');
        $this->assertNotContains('frequency_saturation', array_column($data['observations'] ?? [], 'kind'));
    }

    public function test_the_digest_card_says_not_reported_instead_of_a_sum(): void
    {
        $from = Carbon::parse('2026-07-10');
        $to = Carbon::parse('2026-07-11');
        $presenter = app(DigestPresenter::class);

        $shown = $presenter->count($this->engine()->totals($from, $to), $this->engine()->reportedKeys($from, $to), 'reach');

        $this->assertStringNotContainsString('9,500', $shown);
        $this->assertSame($presenter->count([], ['reach' => false], 'reach'), $shown);
    }
}
