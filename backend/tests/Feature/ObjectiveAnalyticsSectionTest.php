<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Analytics\ObjectiveAnalyticsSection;
use App\Domains\Reports\Analytics\ObjectiveAnalyticsInput;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — the objective section, read from the database, on every surface.
 *
 * The fixture is a mixed programme in two ad accounts:
 *
 *   awareness  meta    spend 500, impressions 100k — reach NOT reported
 *   leads      meta    spend 300, leads reported as 0
 *   sales      meta    spend 1000, 50 orders, revenue 10k       (account inside the link)
 *   sales      tiktok  spend 999, 9 orders                       (account OUTSIDE the link)
 *
 * so unavailable, zero, inapplicable, the family split and the account ceiling are each asserted on
 * figures that cannot be confused with one another.
 */
final class ObjectiveAnalyticsSectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Report $report;

    private string $accountInside;

    private string $accountOutside;

    /** @var list<string> */
    private array $campaigns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'objective-analytics-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        $this->accountInside = (string) Str::uuid();
        $this->accountOutside = (string) Str::uuid();

        $this->campaign('Brand push', 'awareness', $this->accountInside, 'meta', ['spend' => 500, 'impressions' => 100_000]);
        $this->campaign('Lead form', 'leads', $this->accountInside, 'meta', ['spend' => 300, 'leads' => 0]);
        $this->campaign('Summer sale', 'sales', $this->accountInside, 'meta', ['spend' => 1_000, 'purchases' => 50, 'revenue' => 10_000]);
        $this->campaign('Other account sale', 'sales', $this->accountOutside, 'tiktok', ['spend' => 999, 'purchases' => 9]);

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly', 'status' => 'processing',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['kpis' => ['spend' => 1]],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string,float> $figures */
    private function campaign(string $name, string $objective, string $account, string $provider, array $figures): void
    {
        $campaign = UnifiedCampaign::create([
            'project_id' => $this->project->id, 'name' => $name, 'status' => 'active', 'objective' => $objective,
        ]);
        $this->campaigns[] = (string) $campaign->id;

        foreach ($figures as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(),
                'project_id' => $this->project->id,
                'external_account_id' => $account,
                'external_campaign_id' => (string) Str::uuid(),
                'unified_campaign_id' => $campaign->id,
                'provider' => $provider,
                'metric_key' => $key,
                'metric_date' => '2026-07-10',
                'value' => $value,
            ]);
        }
    }

    /** @param array<string,mixed> $opts */
    private function link(array $opts = []): string
    {
        [, $raw] = app(ShareService::class)->create($this->report, $opts + [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => $this->campaigns,
                'account_ids' => [$this->accountInside],
                'providers' => ['meta', 'tiktok'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        return $raw;
    }

    /** @return array<string,mixed> */
    private function family(?array $section, string $family): array
    {
        foreach ($section['families'] ?? [] as $block) {
            if ($block['family'] === $family) {
                return $block;
            }
        }

        $this->fail("no {$family} block in ".json_encode($section));
    }

    /** @return array<string,mixed>|null */
    private function kpi(array $block, string $key): ?array
    {
        return collect($block['kpis'])->firstWhere('key', $key);
    }

    public function test_the_section_reads_each_family_with_its_own_states_from_the_database(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $section = (new ObjectiveAnalyticsSection)->build(new ObjectiveAnalyticsInput(
            from: Carbon::parse('2026-07-01'),
            to: Carbon::parse('2026-07-31'),
            projectIds: [(string) $this->project->id],
            accountIds: [$this->accountInside],
        ));

        $this->assertSame(['awareness', 'leads', 'sales'], array_column($section['families'], 'family'));
        $this->assertFalse($section['cross_family_blend']);

        $awareness = $this->family($section, 'awareness');
        $this->assertSame('unavailable', $this->kpi($awareness, 'frequency')['state']);
        $this->assertSame('unavailable', $this->kpi($awareness, 'reach')['state'], 'reach was never sent');
        $this->assertSame('not_reported', $this->kpi($awareness, 'reach')['reason']);
        $this->assertSame(5.0, $this->kpi($awareness, 'cpm')['value']);
        $this->assertNull($this->kpi($awareness, 'roas'), 'ROAS is inapplicable to awareness');

        $leads = $this->family($section, 'leads');
        $this->assertSame(['value' => 0.0, 'state' => 'reported'], array_intersect_key($this->kpi($leads, 'leads'), array_flip(['value', 'state'])));
        $this->assertSame('zero_denominator', $this->kpi($leads, 'cpl')['reason']);

        $sales = $this->family($section, 'sales');
        // Sales spend only: 1000 ÷ 50. The awareness 500 and the leads 300 never reach it.
        $this->assertSame(20.0, $this->kpi($sales, 'cpa')['value']);
        $this->assertEquals(10.0, $this->kpi($sales, 'roas')['value']);
    }

    public function test_daily_reach_rows_are_not_summed_into_a_period_reach(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => 'Reach', 'status' => 'active', 'objective' => 'reach']);
        foreach (['2026-07-11', '2026-07-12'] as $day) {
            foreach (['spend' => 10, 'impressions' => 5_000, 'reach' => 3_000] as $key => $value) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => $this->accountInside,
                    'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'meta',
                    'metric_key' => $key, 'metric_date' => $day, 'value' => $value,
                ]);
            }
        }

        $read = fn (string $from, string $to) => $this->family((new ObjectiveAnalyticsSection)->build(new ObjectiveAnalyticsInput(
            from: Carbon::parse($from), to: Carbon::parse($to), projectIds: [(string) $this->project->id], accountIds: [$this->accountInside],
        )), 'awareness');

        // Two days: 6,000 would count a returning person twice. Not reach.
        $this->assertSame('not_reported', $this->kpi($read('2026-07-11', '2026-07-12'), 'reach')['reason']);
        // One campaign, one platform, one day: the provider's own deduplicated figure.
        $oneDay = $read('2026-07-11', '2026-07-11');
        $this->assertEquals(3_000, $this->kpi($oneDay, 'reach')['value']);
        $this->assertEquals(round(5_000 / 3_000, 2), $this->kpi($oneDay, 'frequency')['value']);
    }

    public function test_the_live_link_carries_the_section_inside_its_account_ceiling(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link()}/live")->assertOk();

        $sales = $this->family($res->json('data.objective_analytics'), 'sales');

        $this->assertSame(1_000.0, (float) $this->kpi($sales, 'spend')['value'], 'spend from an account outside the link reached the section');
        $this->assertSame(['meta'], array_column($sales['platforms'], 'provider'));
        $this->assertStringNotContainsString('Summer sale', $res->getContent());
        $this->assertStringNotContainsString('Other account sale', $res->getContent());
    }

    public function test_a_link_hiding_spend_publishes_no_cost_figure_in_the_section(): void
    {
        $res = $this->getJson("/api/v1/reports/shared/{$this->link(['hide_spend' => true])}/live")->assertOk();

        $sales = $this->family($res->json('data.objective_analytics'), 'sales');
        $keys = array_column($sales['kpis'], 'key');

        foreach (['spend', 'cpa', 'roas'] as $hidden) {
            $this->assertNotContains($hidden, $keys);
        }
        $this->assertContains('purchases', $keys);
        $this->assertNotContains('cpm', array_column($this->family($res->json('data.objective_analytics'), 'awareness')['kpis'], 'key'));
    }

    public function test_switching_the_objective_breakdown_off_removes_the_section(): void
    {
        $res = $this->getJson('/api/v1/reports/shared/'.$this->link(['settings' => ['sections' => ['objective_breakdown' => false]]]).'/live')->assertOk();

        $this->assertNull($res->json('data.objective_analytics'));
    }

    public function test_the_generated_snapshot_carries_the_same_section_as_the_live_link(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        (new GenerateReportJob((string) $this->report->id))->handle(app(ReportGenerator::class));

        $data = $this->report->refresh()->data;
        $this->assertSame('completed', $this->report->status);

        // Whole project: both sales accounts. 1999 ÷ 59 — rebuilt from sums, never averaged.
        $sales = $this->family($data['objective_analytics'], 'sales');
        $this->assertSame(1_999.0, (float) $this->kpi($sales, 'spend')['value']);
        $this->assertEquals(round(1_999 / 59, 2), $this->kpi($sales, 'cpa')['value']);
        // TikTok sent no revenue: ROAS rests on Meta's revenue over Meta's spend alone, and says so.
        $this->assertEquals(10.0, $this->kpi($sales, 'roas')['value']);
        $this->assertSame(['tiktok'], $this->kpi($sales, 'roas')['not_reported_by']);
        $this->assertSame(['tiktok'], $this->kpi($sales, 'revenue')['not_reported_by']);
    }
}
