<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ClientReportContentValidator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\SeedsDrilldownFixture;
use Tests\TestCase;

/**
 * REPORT-DRILLDOWN-001 — the platform drill-down as an OPTIONAL section of the PDF.
 *
 * Off by default, on every form. When the operator enables it, the print data carries one block per
 * platform — objective KPIs, trend, spend share against outcome share, best and weakest content with
 * their previews — built by the same `PlatformDrilldownBuilder` the live drawer uses, and never a
 * campaign. Asserted on the print route's JSON, which is exactly what Chromium renders into the file.
 */
final class PdfPlatformDrilldownTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDrilldownFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDrilldownFixture();
        $this->report->forceFill([
            'audience' => 'client',
            'scope' => ['project_ids' => [$this->project->id], 'campaign_ids' => $this->campaigns],
            'data' => ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31'], 'platforms' => [['provider' => 'meta'], ['provider' => 'tiktok']]],
        ])->saveQuietly();
    }

    /** @return array<string, mixed> */
    private function printData(?Report $report = null): array
    {
        $token = 'drill-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), ['report_id' => (string) ($report ?? $this->report)->id, 'type' => 'document', 'theme' => 'light', 'audience' => 'client'], 300);

        return $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data');
    }

    private function enable(bool $on = true): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/breakdowns", ['pdf' => [ReportBreakdowns::PLATFORM => $on]])
            ->assertOk()
            ->assertJsonPath('data.pdf.'.ReportBreakdowns::PLATFORM, $on);
        $this->app['auth']->forgetGuards();
    }

    public function test_the_pdf_carries_no_drilldown_by_default_on_either_form(): void
    {
        $this->assertSame([], $this->printData()['platform_drilldowns'] ?? []);

        $this->report->forceFill(['form' => 'executive_summary'])->saveQuietly();
        $this->assertSame([], $this->printData()['platform_drilldowns'] ?? []);
    }

    public function test_an_enabled_pdf_carries_each_platform_with_its_kpis_trend_shares_and_content(): void
    {
        $this->enable();
        $blocks = collect($this->printData()['platform_drilldowns'] ?? [])->keyBy('provider');

        $this->assertSame(['meta', 'tiktok'], $blocks->keys()->sort()->values()->all());

        $meta = $blocks['meta'];
        $this->assertEqualsWithDelta(300.0, $meta['totals']['spend'], 0.0001, 'the deselected account reached the PDF drill-down');
        $this->assertEqualsWithDelta(300.0, collect($meta['timeseries'])->sum('spend'), 0.0001);
        $this->assertEqualsWithDelta(0.75, $meta['shares']['spend']['share'], 0.0001);
        $this->assertSame('revenue', $meta['shares']['outcome']['metric']);
        $this->assertEqualsWithDelta(1500 / 1700, $meta['shares']['outcome']['share'], 0.0001);
        $this->assertNotNull(collect($meta['objectives'])->firstWhere('path', 'conversion'));

        $this->assertNotEmpty($meta['ads'], 'no content on the PDF drill-down, so the preview check proves nothing');
        $this->assertSame(['meta'], collect($meta['ads'])->pluck('provider')->unique()->values()->all());
        foreach ([...$meta['ads'], ...$meta['ads_weakest']] as $ad) {
            $this->assertArrayHasKey('preview', $ad, 'a PDF content row without its preview state cannot print the picture or say why not');
            $this->assertNotContains($ad['name'], ['Meta Elsewhere creative']);
        }
    }

    public function test_a_hidden_ads_section_takes_the_content_and_a_summary_can_opt_in(): void
    {
        $this->enable();
        $this->report->forceFill(['config' => $this->report->fresh()->config + ['slides' => [['id' => 'a', 'type' => 'ads', 'order' => 1, 'visible' => false]]]])->saveQuietly();
        $meta = collect($this->printData()['platform_drilldowns'])->firstWhere('provider', 'meta');
        $this->assertNotNull($meta, 'hiding the ads section removed the whole drill-down');
        $this->assertSame([], $meta['ads']);
        $this->assertSame([], $meta['ads_weakest']);

        $this->report->forceFill(['form' => 'executive_summary', 'config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]]])->saveQuietly();
        $meta = collect($this->printData()['platform_drilldowns'])->firstWhere('provider', 'meta');
        $this->assertNotEmpty($meta['ads'], 'an operator-enabled summary printed no content');
        $this->assertSame([], $meta['ads_weakest'], 'a summary carries no weakest content (ReportComposition)');
    }

    public function test_the_pdf_drilldown_never_carries_a_campaign(): void
    {
        $this->enable();
        $blocks = $this->printData()['platform_drilldowns'];

        $this->assertNotEmpty($blocks);
        $this->assertSame([], ClientReportContentValidator::campaignEntities(['platform_drilldowns' => $blocks]));
        foreach (['Meta Summer"', 'TikTok Launch"', ...$this->campaigns] as $needle) {
            $this->assertStringNotContainsString($needle, json_encode($blocks));
        }
    }

    public function test_a_hidden_platform_section_or_an_operator_switch_off_removes_it(): void
    {
        $this->enable();
        $this->assertNotEmpty($this->printData()['platform_drilldowns']);

        $this->report->forceFill(['config' => ($this->report->fresh()->config ?? []) + ['slides' => [['id' => 'p', 'type' => 'platform_comparison', 'order' => 1, 'visible' => false]]]])->saveQuietly();
        $this->assertSame([], $this->printData()['platform_drilldowns'] ?? [], 'a drill-down printed under a platform section the operator hid');

        $this->report->forceFill(['config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]]])->saveQuietly();
        $this->assertNotEmpty($this->printData()['platform_drilldowns']);
        $this->enable(false);
        $this->assertSame([], $this->printData()['platform_drilldowns'] ?? []);
    }

    public function test_only_an_operator_who_manages_reports_can_enable_it(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'reports.view');
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        app(TenantContext::class)->forget();

        $url = "/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/breakdowns";
        $this->actingAs($viewer, 'sanctum')->putJson($url, ['pdf' => [ReportBreakdowns::PLATFORM => true]])->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->operator, 'sanctum')->putJson($url, ['pdf' => [ReportBreakdowns::PLATFORM => 'yes']])->assertUnprocessable();
        $this->actingAs($this->operator, 'sanctum')->putJson($url, ['pdf' => ['campaign_drilldown' => true]])->assertUnprocessable();
        $this->assertSame([], $this->printData()['platform_drilldowns'] ?? []);
    }

    /**
     * A shared link's PDF prints the drill-down under that link's hide flags — SHARED-PDF-HIDE-FLAGS-001.
     *
     * The section is built from metrics, not from the share-filtered document, so it has to be told
     * which figures the link hides: a link hiding spend must not print a platform's spend, its spend
     * share, its cost per result or its ROAS one section below the page that hid them.
     */
    public function test_a_shared_pdf_prints_the_drilldown_without_what_the_link_hides(): void
    {
        $this->enable();
        [$share] = app(ShareService::class)->create($this->report, ['allow_download' => true, 'hide_spend' => true], null);

        $token = 'drill-share-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id, 'type' => 'document', 'theme' => 'light', 'audience' => 'client', 'share_id' => (string) $share->id,
        ], 300);
        $blocks = $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->json('data.data.platform_drilldowns');

        $this->assertNotEmpty($blocks, 'the shared PDF lost the section the operator enabled');
        $meta = collect($blocks)->firstWhere('provider', 'meta');
        $this->assertNull($meta['totals']['spend']);
        $this->assertNull($meta['shares']['spend'], 'a spend share of hidden spend discloses its distribution');
        foreach (['spend', 'cpa', 'roas', 'cpc', 'cpm'] as $money) {
            foreach ($meta['objectives'] as $block) {
                $this->assertNull($block['metrics'][$money] ?? null, "objective block printed hidden {$money}");
            }
        }
        foreach ([...$meta['timeseries'], ...$meta['ads'], ...$meta['ads_weakest']] as $row) {
            $this->assertNull($row['spend'] ?? null);
            $this->assertNull($row['roas'] ?? null);
        }
        $this->assertStringNotContainsString('300', (string) json_encode($meta['totals']['spend'] ?? null));
    }
}
