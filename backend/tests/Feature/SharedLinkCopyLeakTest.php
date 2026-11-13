<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\ContentKey;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\ReportBreakdowns;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SHARE-COPY-LEAK-001 — a link with the ad copy and the headline switched off publishes neither, on ANY surface.
 *
 * Proven on Production read-only: a link with both switches off still returned a tweet's text in `preview.text` —
 * six times in `/live`, twice in `/creatives`, three times in `/creatives/summary`. `CreativePresenter::preview()`
 * gives a text-only creative (an X post, a Google search ad, a ChatGPT chat card) its own words in `text`, and the
 * redaction removed the row's `body`/`headline` and every carousel card's copy but never that envelope. The PDF's
 * per-platform content lists were built straight from the ads builder and never redacted at all.
 *
 * Every assertion reads the RAW response body: a hidden word is absent from the bytes, not blanked by a component.
 */
final class SharedLinkCopyLeakTest extends TestCase
{
    use RefreshDatabase;

    /** Every hidden string the fixture plants — none may appear in a hiding link's response. */
    private const HIDDEN = [
        'LeakHeadlineText', 'LeakBodyText', 'LeakHeadlineChat', 'LeakBodyChat',
        'LeakCardHeadline', 'LeakCardBody', 'LEAK_CARD_CTA', 'leak-card-dest.test',
        'LEAK_CTA_TEXT', 'leak-dest-text.test', 'LeakHeroHeadline', 'LeakHeroBody',
    ];

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private Report $report;

    /** @var array<string, ExternalCreative> */
    private array $creatives = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Leak', 'slug' => 'leak-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(), 'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);

        // An X post / Google search ad: its words ARE the creative, so the presenter draws them in `preview.text`.
        $this->creatives['text'] = $this->creative([
            'name' => 'Text ad one', 'format' => 'text_card', 'asset_url' => null,
            'headline' => 'LeakHeadlineText', 'body' => 'LeakBodyText',
            'cta' => 'LEAK_CTA_TEXT', 'destination_url' => 'https://leak-dest-text.test/p',
        ]);
        // A ChatGPT Ads chat card with no image link: the same envelope, the other branch of the presenter.
        $this->creatives['chat'] = $this->creative([
            'name' => 'Chat card one', 'format' => 'chat_card', 'asset_url' => null,
            'headline' => 'LeakHeadlineChat', 'body' => 'LeakBodyChat', 'cta' => null, 'destination_url' => null,
        ]);
        // A carousel: the hero's own copy and each card's copy.
        $this->creatives['carousel'] = $this->creative([
            'name' => 'Carousel one', 'format' => 'carousel', 'asset_url' => 'https://cdn.example.test/hero.jpg',
            'headline' => 'LeakHeroHeadline', 'body' => 'LeakHeroBody', 'cta' => 'SHOP_NOW', 'destination_url' => null,
            'cards' => [[
                'image_url' => 'https://cdn.example.test/card1.jpg', 'headline' => 'LeakCardHeadline', 'body' => 'LeakCardBody',
                'cta' => 'LEAK_CARD_CTA', 'destination_url' => 'https://leak-card-dest.test/c',
            ]],
        ]);

        foreach (array_values($this->creatives) as $i => $creative) {
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'creative_id' => $creative->getKey(), 'campaign_id' => $this->campaign->getKey(),
                'metric_date' => now()->subDays(2)->toDateString(),
                'spend' => 100 + $i * 10, 'impressions' => 5000 + $i * 100, 'clicks' => 120 + $i * 5, 'conversions' => 4 + $i, 'revenue' => 500 + $i * 50,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        // The platform totals the per-platform drawer and the PDF's platform pages are opened from.
        foreach (['spend' => 330, 'impressions' => 15300, 'clicks' => 375, 'conversions' => 15] as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->getKey(), 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta', 'metric_key' => $key, 'metric_date' => now()->subDays(2)->toDateString(), 'value' => $value,
            ]);
        }

        $row = fn (string $k): array => [
            'id' => (string) $this->creatives[$k]->getKey(), 'name' => $this->creatives[$k]->name, 'provider' => 'meta',
            'headline' => $this->creatives[$k]->headline, 'body' => $this->creatives[$k]->body,
            'cta' => $this->creatives[$k]->cta, 'destination_url' => $this->creatives[$k]->destination_url,
        ];
        $this->report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Monthly', 'type' => 'performance', 'status' => 'completed', 'audience' => 'client',
            'currency' => 'SAR', 'form' => 'detailed', 'campaign_objective' => 'sales',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'data' => [
                'period' => ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()],
                'currency' => 'SAR', 'objective' => 'sales', 'kpis' => ['impressions' => 15300, 'clicks' => 375], 'metric_set' => ['impressions', 'clicks'],
                'ads' => [$row('text'), $row('chat'), $row('carousel')], 'ads_roster' => [$row('text'), $row('chat'), $row('carousel')],
            ],
            'generated_at' => now(),
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    private function creative(array $over): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'campaign_id' => $this->campaign->getKey(),
            'provider' => 'meta', 'external_creative_id' => 'cr-'.Str::random(8), 'status' => 'active',
            'last_active_at' => now(), 'last_synced_at' => now(),
        ], $over));
    }

    /** @param array<string, bool> $over */
    private function switches(array $over = []): array
    {
        return CreativeVisibility::fromArray(array_merge([
            'creatives' => true, 'video' => true, 'image_zoom' => true, 'download' => true,
            'ad_copy' => true, 'headline' => true, 'cta' => true, 'destination_url' => true,
            'comparison' => true, 'spend' => true, 'revenue' => true, 'cpa' => true, 'roas' => true,
            'insights' => true, 'recommendations' => true,
        ], $over))->toArray();
    }

    private function hidingCopy(): array
    {
        return $this->switches(['ad_copy' => false, 'headline' => false, 'cta' => false, 'destination_url' => false]);
    }

    /** @return array{0: ReportShare, 1: string} */
    private function link(array $creatives, string $mode, array $opts = []): array
    {
        return app(ShareService::class)->create($this->report, array_merge([
            'mode' => $mode,
            'allow_download' => true,
            'settings' => ['creatives' => $creatives],
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], $opts), null);
    }

    private function raw(string $token, string $path): string
    {
        $response = $this->getJson("/api/v1/reports/shared/{$token}{$path}");
        $this->assertSame(200, $response->getStatusCode(), "{$path} answered {$response->getStatusCode()}");

        return (string) $response->getContent();
    }

    /** What the print route serves Chromium for this link's PDF — the exact bytes the PDF is drawn from. */
    private function printed(ReportShare $share, string $layout): string
    {
        $token = 'leak-print-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id, 'share_id' => (string) $share->id,
            'type' => $layout, 'theme' => 'light', 'audience' => 'client', 'watermark' => false,
        ], 300);
        $response = $this->getJson("/api/v1/reports/print/{$token}");
        $response->assertOk();

        return (string) $response->getContent();
    }

    /** @return list<string> */
    private function leaked(string $body): array
    {
        // JSON escapes `/`; compare on the decoded text as well as the raw bytes.
        $plain = str_replace('\\/', '/', $body);

        return array_values(array_filter(self::HIDDEN, static fn (string $s): bool => str_contains($plain, $s)));
    }

    /** @return array<string, string> path => raw body, for every public endpoint a live link answers */
    private function liveSurfaces(ReportShare $share, string $token): array
    {
        $window = '?from='.now()->subDays(30)->toDateString().'&to='.now()->toDateString();
        $ids = array_map(static fn (ExternalCreative $c): string => (string) $c->getKey(), $this->creatives);
        $surfaces = [
            '/live' => $this->raw($token, '/live'.$window),
            '/live/platform/meta' => $this->raw($token, '/live/platform/meta'.$window),
            '/creatives' => $this->raw($token, '/creatives'),
            '/creatives/summary' => $this->raw($token, '/creatives/summary'),
            '/creatives/comparison' => $this->raw($token, '/creatives/comparison?creative_ids[]='.$ids['text'].'&creative_ids[]='.$ids['carousel'].'&creative_ids[]='.$ids['chat']),
        ];
        foreach ($ids as $k => $id) {
            $surfaces["/creatives/{$k}"] = $this->raw($token, "/creatives/{$id}");
            $surfaces["/live/content/{$k}"] = $this->raw($token, '/live/content/'.ContentKey::for($share, $id).$window);
        }

        return $surfaces;
    }

    // ---- the positive control: the fixture really reaches every surface when shown ------------------------------

    public function test_the_words_reach_the_payload_when_the_switches_are_on(): void
    {
        [$share, $token] = $this->link($this->switches(), 'live');
        $surfaces = $this->liveSurfaces($share, $token);

        foreach (['/live', '/live/platform/meta', '/creatives', '/creatives/summary', '/creatives/text', '/live/content/text'] as $path) {
            $this->assertContains('LeakBodyText', $this->leaked($surfaces[$path]), "{$path}: the shown ad text is missing — the fixture proves nothing");
            $this->assertContains('LeakHeadlineText', $this->leaked($surfaces[$path]), "{$path}: the shown headline is missing");
        }
        $this->assertContains('LeakBodyChat', $this->leaked($surfaces['/creatives']));
        $this->assertContains('LeakCardBody', $this->leaked($surfaces['/creatives/carousel']), 'a shown carousel card lost its copy');
        $this->assertContains('LEAK_CTA_TEXT', $this->leaked($surfaces['/creatives/text']));

        $text = $this->getJson("/api/v1/reports/shared/{$token}/creatives/{$this->creatives['text']->getKey()}")->json('data.creative.preview.text');
        $this->assertSame(['headline' => 'LeakHeadlineText', 'body' => 'LeakBodyText'], $text, 'switches ON must keep the text card exactly');
    }

    // ---- the defect, per endpoint -------------------------------------------------------------------------------

    public function test_a_live_link_hiding_the_copy_publishes_none_of_it_on_any_endpoint(): void
    {
        [$share, $token] = $this->link($this->hidingCopy(), 'live');

        foreach ($this->liveSurfaces($share, $token) as $path => $body) {
            $this->assertSame([], $this->leaked($body), "{$path} publishes words the link hides");
        }
    }

    public function test_the_text_card_envelope_is_null_not_an_empty_shell(): void
    {
        [, $token] = $this->link($this->hidingCopy(), 'live');

        $preview = $this->getJson("/api/v1/reports/shared/{$token}/creatives/{$this->creatives['text']->getKey()}")->assertOk()->json('data.creative.preview');
        $this->assertIsArray($preview);
        $this->assertArrayHasKey('text', $preview);
        $this->assertNull($preview['text'], 'a text card with both words hidden must say «no words», not ship an empty pair');
    }

    public function test_hiding_only_the_headline_keeps_the_body_and_vice_versa(): void
    {
        [, $noHeadline] = $this->link($this->switches(['headline' => false]), 'live');
        $text = $this->getJson("/api/v1/reports/shared/{$noHeadline}/creatives/{$this->creatives['text']->getKey()}")->json('data.creative.preview.text');
        $this->assertSame(['body' => 'LeakBodyText'], $text);

        [, $noBody] = $this->link($this->switches(['ad_copy' => false]), 'live');
        $text = $this->getJson("/api/v1/reports/shared/{$noBody}/creatives/{$this->creatives['text']->getKey()}")->json('data.creative.preview.text');
        $this->assertSame(['headline' => 'LeakHeadlineText'], $text);
    }

    public function test_a_saved_reports_link_hiding_the_copy_publishes_none_of_it(): void
    {
        [, $shown] = $this->link($this->switches(), 'snapshot');
        $this->assertContains('LeakBodyText', $this->leaked($this->raw($shown, '')), 'the snapshot fixture never reached the page');

        [, $token] = $this->link($this->hidingCopy(), 'snapshot');
        $this->assertSame([], $this->leaked($this->raw($token, '')), 'the saved report link publishes words it hides');
    }

    public function test_the_pdf_of_a_link_hiding_the_copy_prints_none_of_it(): void
    {
        foreach (['live', 'snapshot'] as $mode) {
            [$shown] = $this->link($this->switches(), $mode);
            $this->assertNotSame([], $this->leaked($this->printed($shown, 'document')), "{$mode}: the PDF fixture never reached the print data");

            [$share] = $this->link($this->hidingCopy(), $mode);
            foreach (['document', 'presentation'] as $layout) {
                $this->assertSame([], $this->leaked($this->printed($share, $layout)), "{$mode}/{$layout}: the PDF prints words the link hides");
            }
        }
    }

    /**
     * The PDF's per-platform pages (`platform_drilldowns`) carry their own best/weakest content lists, built from the ads builder
     * and never redacted — the hero's and the cards' copy printed on paper while the page itself withheld it.
     */
    public function test_the_pdf_platform_pages_of_a_link_hiding_the_copy_print_none_of_it(): void
    {
        $this->report->forceFill(['config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]]])->saveQuietly();

        foreach (['live', 'snapshot'] as $mode) {
            [$shown] = $this->link($this->switches(), $mode);
            $printed = $this->printed($shown, 'document');
            $this->assertStringContainsString('platform_drilldowns', $printed, "{$mode}: no platform pages were printed, so nothing is proved");
            $this->assertContains('LeakBodyText', $this->leaked($printed), "{$mode}: the shown copy is missing from the PDF");

            [$share] = $this->link($this->hidingCopy(), $mode);
            foreach (['document', 'presentation'] as $layout) {
                $this->assertSame([], $this->leaked($this->printed($share, $layout)), "{$mode}/{$layout}: the PDF platform pages print words the link hides");
            }
        }
    }

    // ---- the reviewer's findings on the same endpoints: hidden money and campaign names ---------------------------

    /**
     * Money the platform reported and we could not convert (a USD account with no USD→SAR rate — every Snapchat account on
     * Production) lives in `spend_original`/`revenue_original`. The summary's alerts, spend-by-kind and image-against-video
     * sections, and the detail's per-platform siblings, carried it on a link hiding spend and revenue.
     */
    public function test_a_link_hiding_money_publishes_no_withheld_original_on_the_summary_or_the_detail(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $group = CreativeGroup::create(['project_id' => $this->project->getKey(), 'name' => 'G', 'method' => 'manual']);
        $money = [];
        foreach (['image' => 'https://cdn.example.test/m1.jpg', 'video' => 'https://cdn.example.test/m2.jpg'] as $format => $asset) {
            $c = $this->creative(['name' => "Money {$format}", 'format' => $format, 'asset_url' => $asset, 'thumbnail_url' => $asset,
                'video_url' => $format === 'video' ? 'https://cdn.example.test/m2.mp4' : null, 'creative_group_id' => $group->getKey()]);
            $money[] = $c;
            foreach ([2, 12] as $daysAgo) {
                DB::table('creative_daily_metrics')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                    'creative_id' => $c->getKey(), 'campaign_id' => $this->campaign->getKey(), 'metric_date' => now()->subDays($daysAgo)->toDateString(),
                    'spend' => null, 'spend_original' => 4125.37, 'revenue' => null, 'revenue_original' => 9876.54, 'original_currency' => 'USD',
                    'impressions' => 20000, 'clicks' => 300, 'conversions' => 9, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
        $hidden = ['4125.3', '9876.5', '8250.7', '19753.0', '"USD"'];
        $found = static fn (string $body): array => array_values(array_filter($hidden, static fn (string $h): bool => str_contains($body, $h)));

        [, $shown] = $this->link($this->switches(), 'live');
        $this->assertNotSame([], $found($this->raw($shown, '/creatives/summary')), 'the withheld originals never reached the summary — nothing is proved');

        [, $token] = $this->link($this->switches(['spend' => false, 'revenue' => false]), 'live');
        $this->assertSame([], $found($this->raw($token, '/creatives/summary')), '/creatives/summary publishes money the link hides');
        foreach ($money as $c) {
            $detail = $this->raw($token, '/creatives/'.$c->getKey());
            $this->assertStringContainsString('by_platform', $detail);
            $this->assertSame([], $found($detail), '/creatives/{id} publishes money the link hides');
        }
    }

    /** CLIENT-REPORT-ENTITY-BOUNDARY-001 — a finding names the content, never the campaign it ran in. */
    public function test_the_summary_findings_name_no_campaign(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign->forceFill(['name' => 'CampaignNameMustNotTravel'])->save();
        // Strong clicks, weak conversion against three peers on the same path: a peer finding about this creative.
        foreach ([[900, 1], [250, 30], [240, 28], [260, 31]] as $i => [$clicks, $conversions]) {
            $c = $this->creative(['name' => "Peer {$i}", 'format' => 'image', 'asset_url' => "https://cdn.example.test/p{$i}.jpg"]);
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'creative_id' => $c->getKey(), 'campaign_id' => $this->campaign->getKey(), 'metric_date' => now()->subDays(3)->toDateString(),
                'spend' => 200, 'impressions' => 12000, 'clicks' => $clicks, 'conversions' => $conversions, 'revenue' => 400,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        [, $token] = $this->link($this->switches(), 'live');
        $response = $this->getJson("/api/v1/reports/shared/{$token}/creatives/summary")->assertOk();
        $this->assertNotSame([], (array) $response->json('data.insights.items'), 'no finding was produced — nothing is proved');
        $this->assertStringNotContainsString('CampaignNameMustNotTravel', (string) $response->getContent(), 'a finding names the campaign');
        $this->assertStringNotContainsString((string) $this->campaign->getKey(), (string) $response->getContent(), 'a finding carries the campaign id');
    }

    public function test_the_csv_and_xlsx_of_a_link_hiding_the_copy_carry_none_of_it(): void
    {
        [, $token] = $this->link($this->hidingCopy(), 'live');

        foreach (['csv', 'xlsx'] as $format) {
            $response = $this->get("/api/v1/reports/shared/{$token}/download/{$format}");
            $response->assertOk();
            $body = $response->streamedContent();
            if ($format === 'xlsx') {
                $body = $this->xlsxText($body);
            }
            $this->assertSame([], $this->leaked($body), "{$format}: the file carries words the link hides");
        }
    }

    private function xlsxText(string $binary): string
    {
        $path = tempnam(sys_get_temp_dir(), 'leak').'.xlsx';
        file_put_contents($path, $binary);
        $zip = new \ZipArchive;
        $text = '';
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $text .= (string) $zip->getFromIndex($i);
            }
            $zip->close();
        }
        @unlink($path);

        return $text;
    }
}
