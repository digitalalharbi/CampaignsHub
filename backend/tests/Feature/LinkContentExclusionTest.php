<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ContentKey;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REPORT-OWNER-CONTROLS-ALL-001 — the Owner takes individual creatives off ONE link.
 *
 * What is asserted is the contract on three sides at once: the content leaves every list and every
 * file of that link, the link's totals do NOT change (they are the financial truth, not a sum of the
 * visible cards), and the page says so — a count of what is not shown — instead of leaving a reader
 * with content that adds up to less than its headline.
 */
final class LinkContentExclusionTest extends TestCase
{
    use RefreshDatabase;

    private Report $report;

    private Project $project;

    private string $tenantId;

    /** @var list<string> */
    private array $campaigns = [];

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'A', 'slug' => 'excl-'.uniqid(), 'status' => 'active']);
        $this->tenantId = (string) $tenant->id;
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);

        foreach (['meta' => [13.5, 7.25, 101.75], 'tiktok' => [40.0, 2.5]] as $provider => $daily) {
            $campaign = UnifiedCampaign::create(['project_id' => $this->project->id, 'name' => "C {$provider}", 'status' => 'active', 'objective' => 'sales']);
            $this->campaigns[] = $campaign->id;
            $creative = ExternalCreative::create([
                'tenant_id' => $tenant->id, 'project_id' => $this->project->id, 'campaign_id' => $campaign->id,
                'provider' => $provider, 'external_creative_id' => 'cr-'.Str::random(8),
                'name' => "{$provider} creative", 'format' => 'image', 'status' => 'active',
            ]);
            foreach ($daily as $i => $spend) {
                $date = sprintf('2026-07-%02d', 10 + $i);
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'project_id' => $this->project->id,
                    'creative_id' => $creative->id, 'metric_date' => $date, 'spend' => $spend,
                    'impressions' => 1000 + $i * 7, 'clicks' => 30 + $i, 'conversions' => 1 + $i, 'revenue' => $spend * 3,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'external_account_id' => (string) Str::uuid(),
                    'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id,
                    'provider' => $provider, 'metric_key' => 'spend', 'metric_date' => $date, 'value' => $spend,
                ]);
            }
        }

        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'data' => [],
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string, mixed> $overrides @return array{0: ReportShare, 1: string} */
    private function share(array $excluded = [], array $overrides = []): array
    {
        return app(ShareService::class)->create($this->report, [
            'scope' => [
                'project_id' => $this->project->id, 'campaign_ids' => $this->campaigns,
                'providers' => ['meta', 'tiktok'], 'earliest' => '2026-07-01', 'latest' => '2026-07-31',
            ] + ($excluded === [] ? [] : ['excluded_creative_ids' => $excluded]),
        ] + $overrides, null);
    }

    /** @return array<string, mixed> */
    private function live(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-07-31")->assertOk()->json('data');
    }

    private function creativeId(string $name): string
    {
        return (string) ExternalCreative::withoutGlobalScopes()->where('name', $name)->value('id');
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function namesIn(array $data): array
    {
        return collect($data['ads_roster'] ?? [])->merge($data['ads'] ?? [])->merge($data['ads_weakest'] ?? [])->pluck('name')->unique()->values()->all();
    }

    public function test_totals_are_unchanged_and_the_page_counts_what_it_does_not_show(): void
    {
        [, $openRaw] = $this->share();
        [, $narrowRaw] = $this->share([$this->creativeId('tiktok creative')]);

        $open = $this->live($openRaw);
        $narrow = $this->live($narrowRaw);

        $this->assertContains('tiktok creative', $this->namesIn($open), 'the open link shows no content, so this proves nothing');
        $this->assertNotContains('tiktok creative', $this->namesIn($narrow));

        $this->assertSame(0, $open['creatives_hidden']);
        $this->assertSame(1, $narrow['creatives_hidden']);

        // The financial truth does not move: the platform rows and the headline are the same on both links.
        $this->assertEquals($open['platforms'], $narrow['platforms']);
        $this->assertEquals($open['kpis'] ?? null, $narrow['kpis'] ?? null);
        $this->assertEquals($open['timeseries'] ?? null, $narrow['timeseries'] ?? null);
        $tiktok = collect($narrow['platforms'])->firstWhere('provider', 'tiktok');
        $this->assertNotNull($tiktok, 'the hidden creative\'s platform vanished from the totals');
    }

    public function test_each_link_keeps_its_own_exclusions(): void
    {
        [$a, $aRaw] = $this->share([$this->creativeId('tiktok creative')]);
        [$b, $bRaw] = $this->share([$this->creativeId('meta creative')]);
        [, $cRaw] = $this->share();

        $this->assertNotContains('tiktok creative', $this->namesIn($this->live($aRaw)));
        $this->assertContains('meta creative', $this->namesIn($this->live($aRaw)));
        $this->assertNotContains('meta creative', $this->namesIn($this->live($bRaw)));
        $this->assertContains('tiktok creative', $this->namesIn($this->live($bRaw)));
        $this->assertEqualsCanonicalizing(['meta creative', 'tiktok creative'], array_intersect(['meta creative', 'tiktok creative'], $this->namesIn($this->live($cRaw))));
        $this->assertNotSame($a->trace_mark, $b->trace_mark);
    }

    public function test_an_excluded_creative_cannot_be_opened_by_its_key(): void
    {
        $id = $this->creativeId('tiktok creative');
        [$share, $raw] = $this->share([$id]);

        $this->getJson("/api/v1/reports/shared/{$raw}/live/content/".ContentKey::for($share, $id))->assertNotFound();
    }

    public function test_an_id_from_another_tenant_hides_and_counts_nothing(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $ws = ClientWorkspace::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'mode' => 'managed']);
        $project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'OP', 'status' => 'active']);
        $foreign = ExternalCreative::create([
            'tenant_id' => $other->id, 'project_id' => $project->id, 'campaign_id' => null,
            'provider' => 'meta', 'external_creative_id' => 'x-'.Str::random(6), 'name' => 'foreign creative', 'format' => 'image', 'status' => 'active',
        ]);
        app(TenantContext::class)->forget();

        [, $raw] = $this->share([(string) $foreign->id]);
        $data = $this->live($raw);

        $this->assertSame(0, $data['creatives_hidden'], 'another tenant\'s creative was counted as hidden here');
        $this->assertContains('meta creative', $this->namesIn($data));
        $this->assertContains('tiktok creative', $this->namesIn($data));
    }

    public function test_the_file_leaves_the_content_out_and_says_the_totals_include_it(): void
    {
        [$share, $raw] = $this->share([$this->creativeId('tiktok creative')], ['allow_download' => true, 'settings' => ['creatives' => ['creatives' => true, 'spend' => true]]]);
        app(TenantContext::class)->setTenantId($this->tenantId);
        $report = $share->report()->withoutGlobalScopes()->first();

        $document = app(ShareService::class)->downloadDocument($report, $share);
        $this->assertSame(1, $document['creatives_hidden']);
        $this->assertNotContains('tiktok creative', array_column((array) ($document['creatives'] ?? []), 'name'));
        $this->assertContains('meta creative', array_column((array) ($document['creatives'] ?? []), 'name'), 'the file lists no content at all, so this proves nothing');

        // The exporter reads exactly this document (PublicReportController::download hands it over as the report's data).
        $report->data = $document;
        $csv = app(ReportExporter::class)->render($report, 'csv', $share);
        $this->assertStringContainsString('Content not shown / محتوى غير معروض', $csv);
        $this->assertStringContainsString('meta creative', $csv);
        $this->assertStringNotContainsString('tiktok creative', $csv);
    }

    public function test_a_link_with_no_exclusions_says_nothing_about_hidden_content(): void
    {
        [$share, $raw] = $this->share([], ['settings' => ['creatives' => ['creatives' => true]]]);
        $this->assertSame(0, $this->live($raw)['creatives_hidden']);
        app(TenantContext::class)->setTenantId($this->tenantId);
        $report = $share->report()->withoutGlobalScopes()->first();
        $report->data = app(ShareService::class)->downloadDocument($report, $share);
        $this->assertStringNotContainsString('Content not shown', app(ReportExporter::class)->render($report, 'csv', $share));
    }

    public function test_a_revoked_link_serves_nothing(): void
    {
        [$share, $raw] = $this->share([$this->creativeId('tiktok creative')]);
        $share->forceFill(['revoked_at' => now()])->save();

        $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-07-01&to=2026-07-31")->assertStatus(404);
    }
}
