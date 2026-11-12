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
 * REPORT-OPENABLE-OPTIONS-001 — master task 10, «ما يمكن فتحه»: each option tested on the link's OWN content lists, live and saved.
 *
 * The creative endpoints already honoured every switch (SharedReportCreativesTest, one case per option). The gallery in the link's payload
 * did not: «تشغيل الفيديو» off still shipped the film there, and a saved report's link published the ad copy its live twin withheld. Hidden
 * disappears; shown appears with its cover; a link made before the panel existed draws exactly what it drew.
 */
final class ReportOpenableOptionsTest extends TestCase
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
                'name' => "{$provider} creative", 'format' => 'video', 'status' => 'active',
                'video_url' => "https://cdn.test/{$provider}.mp4", 'thumbnail_url' => "https://cdn.test/{$provider}.jpg",
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

    /** A saved report's own link (no live scope): it serves the stored copy. @return array{0: ReportShare, 1: string} */
    private function snapshotShare(array $opts): array
    {
        return app(ShareService::class)->create($this->report, $opts, null);
    }

    /** @param array<string, mixed> $data @return list<array<string, mixed>> */
    private function previews(array $data): array
    {
        $rows = [];
        foreach (['ads', 'ads_weakest'] as $list) {
            foreach ((array) ($data[$list] ?? []) as $row) {
                if (is_array($row['preview'] ?? null)) {
                    $rows[] = $row['preview'];
                }
            }
        }
        foreach ((array) ($data['ads_groups'] ?? []) as $group) {
            foreach ((array) ($group['ads'] ?? []) as $row) {
                if (is_array($row['preview'] ?? null)) {
                    $rows[] = $row['preview'];
                }
            }
        }
        $this->assertNotSame([], $rows, 'no content reached the payload, so nothing is proved');

        return $rows;
    }

    public function test_video_off_takes_the_film_out_of_the_live_gallery_and_keeps_its_cover(): void
    {
        [, $raw] = $this->share([], ['settings' => ['creatives' => ['creatives' => true, 'video' => false]]]);

        foreach ($this->previews($this->live($raw)) as $preview) {
            $this->assertNull($preview['video_url'] ?? null, 'the film is still in the payload of a link that hides it');
            $this->assertNotEmpty($preview['thumbnail_url'] ?? null, 'a hidden film keeps its cover');
            $this->assertFalse($preview['can_zoom']);
            $this->assertFalse($preview['can_download']);
        }
    }

    public function test_video_zoom_and_download_on_reach_the_live_gallery(): void
    {
        [, $raw] = $this->share([], ['settings' => ['creatives' => ['creatives' => true, 'video' => true, 'image_zoom' => true, 'download' => true]]]);

        foreach ($this->previews($this->live($raw)) as $preview) {
            $this->assertStringEndsWith('.mp4', (string) ($preview['video_url'] ?? ''));
            $this->assertTrue($preview['can_zoom']);
            $this->assertTrue($preview['can_download']);
        }
    }

    public function test_a_link_made_before_the_panel_draws_what_it_always_drew(): void
    {
        [, $raw] = $this->share();

        foreach ($this->previews($this->live($raw)) as $preview) {
            $this->assertStringEndsWith('.mp4', (string) ($preview['video_url'] ?? ''), 'an existing shared link changed what it draws');
            $this->assertArrayNotHasKey('can_zoom', $preview);
        }
    }

    public function test_a_saved_reports_link_honours_video_and_copy_like_its_live_twin(): void
    {
        $row = ['name' => 'meta creative', 'provider' => 'meta', 'body' => 'Buy now', 'headline' => 'Sale', 'cta' => 'SHOP_NOW',
            'destination_url' => 'https://shop.test', 'preview' => ['state' => 'available', 'kind' => 'video',
                'video_url' => 'https://cdn.test/meta.mp4', 'thumbnail_url' => 'https://cdn.test/meta.jpg']];
        DB::table('reports')->where('id', $this->report->id)->update(['generated_at' => now(), 'data' => json_encode([
            'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'], 'currency' => 'SAR', 'objective' => 'sales',
            'kpis' => ['impressions' => 1000, 'clicks' => 10], 'metric_set' => ['impressions', 'clicks'], 'ads' => [$row], 'ads_roster' => [$row],
        ])]);
        $this->report = Report::withoutGlobalScopes()->findOrFail($this->report->id);

        [, $hidden] = $this->snapshotShare(['settings' => ['creatives' => ['creatives' => true, 'video' => false, 'ad_copy' => false, 'headline' => false, 'cta' => false, 'destination_url' => false]]]);
        $res = $this->getJson("/api/v1/reports/shared/{$hidden}")->assertOk();
        $ad = $res->json('data.data.ads.0');

        $this->assertIsArray($ad);
        $this->assertNull($ad['preview']['video_url'] ?? null);
        $this->assertSame('https://cdn.test/meta.jpg', $ad['preview']['thumbnail_url'] ?? null, 'the cover stays');
        foreach (['body', 'headline', 'cta', 'destination_url'] as $key) {
            $this->assertArrayNotHasKey($key, $ad, "the saved link published the {$key} its switch hides");
        }

        [, $shown] = $this->snapshotShare(['settings' => ['creatives' => ['creatives' => true, 'video' => true, 'ad_copy' => true, 'headline' => true, 'cta' => true, 'destination_url' => true]]]);
        $ad = $this->getJson("/api/v1/reports/shared/{$shown}")->assertOk()->json('data.data.ads.0') ?? $this->getJson("/api/v1/reports/shared/{$shown}")->json('data.ads.0');
        $this->assertSame('https://cdn.test/meta.mp4', $ad['preview']['video_url'] ?? null);
        $this->assertSame('Buy now', $ad['body'] ?? null);
        $this->assertSame('SHOP_NOW', $ad['cta'] ?? null);
    }
}
