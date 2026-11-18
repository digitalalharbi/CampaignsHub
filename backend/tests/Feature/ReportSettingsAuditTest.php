<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\ContentFormatComparison;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportSectionSurfaces;
use App\Domains\Reports\Services\ShareService;
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
 * REPORT-SETTINGS-AUDIT-001 (Owner 2026-11-13: «تحقق من كل أيقونة في إعدادات التقارير أنها تعمل 100% — تقرير رابط أو محفوظ»).
 *
 * One test per switch the audit found broken on a surface. Each reads the RAW bytes a surface serves (the shared payload, the
 * print route Chromium draws the PDF from, the CSV and the XLSX) and proves the hidden figure or name is absent — with a
 * positive control proving the fixture reaches that surface when the switch is on, so an absence is never vacuous.
 */
final class ReportSettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['name' => 'Audit', 'slug' => 'audit-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sale', 'objective' => 'sales', 'status' => 'active',
        ]);

        $creatives = [];
        foreach (['AuditImageAd' => 'image', 'AuditVideoAd' => 'video'] as $name => $format) {
            $creatives[$name] = ExternalCreative::create([
                'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta', 'external_creative_id' => 'cr-'.Str::random(8), 'status' => 'active', 'name' => $name, 'format' => $format,
                'asset_url' => 'https://cdn.example.test/'.$format.'.jpg', 'headline' => $name.'Headline', 'body' => $name.'Body',
                'last_active_at' => now(), 'last_synced_at' => now(),
            ]);
            DB::table('creative_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                'creative_id' => $creatives[$name]->getKey(), 'campaign_id' => $this->campaign->getKey(), 'metric_date' => now()->subDays(2)->toDateString(),
                'spend' => 100, 'impressions' => 5000, 'clicks' => 120, 'conversions' => 4, 'revenue' => 500, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (['spend' => 200, 'impressions' => 10000, 'clicks' => 240, 'conversions' => 8] as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->getKey(), 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta', 'metric_key' => $key, 'metric_date' => now()->subDays(2)->toDateString(), 'value' => $value,
            ]);
        }

        $row = static fn (ExternalCreative $c): array => ['id' => (string) $c->getKey(), 'name' => $c->name, 'provider' => 'meta', 'headline' => $c->headline, 'body' => $c->body];
        $this->report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Monthly', 'type' => 'performance', 'status' => 'completed', 'audience' => 'client',
            'currency' => 'SAR', 'form' => 'detailed', 'campaign_objective' => 'sales',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(),
            'section_settings' => ['sections' => ['store_analytics' => true]],
            'data' => [
                'period' => ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString()],
                'currency' => 'SAR', 'objective' => 'sales', 'kpis' => ['impressions' => 10000, 'clicks' => 240], 'metric_set' => ['impressions', 'clicks'],
                'platforms' => [['provider' => 'meta', 'impressions' => 10000, 'clicks' => 240]],
                'ads' => array_map($row, array_values($creatives)), 'ads_roster' => array_map($row, array_values($creatives)),
                'ga4_overview' => [
                    'currency' => 'SAR', 'previous_period' => ['from' => '2026-01-01', 'to' => '2026-01-30'], 'revenue_hidden' => false,
                    'kpis' => [
                        ['key' => 'sessions', 'kind' => 'count', 'current' => 4321.5, 'previous' => 8181.5, 'change' => -3860, 'change_pct' => -0.4718, 'currency' => null],
                        ['key' => 'revenue', 'kind' => 'money', 'current' => 7777.77, 'previous' => 5555.55, 'change' => 2222.22, 'change_pct' => 0.4, 'currency' => 'SAR'],
                    ],
                    'series' => [['date' => now()->subDays(2)->toDateString(), 'sessions' => 4300, 'revenue' => 4444.44]],
                ],
                'store_analytics' => [
                    'title_ar' => 'تحليلات المتجر والزيارات', 'not_summed' => true,
                    'store' => [
                        'currency' => 'SAR', 'orders' => ['live' => 3, 'confirmed' => 3, 'cancelled' => 0, 'refunded' => 0],
                        'sales' => ['net' => ['current' => 6666.66, 'previous' => 3333.33, 'change_pct' => 1.0]],
                        'averages' => ['aov' => 123.45, 'items_per_order' => 2],
                        'top_products' => [['name' => 'Prod', 'quantity' => 1, 'orders' => 1, 'revenue' => 2121.21]],
                        'trend' => [['date' => now()->subDays(2)->toDateString(), 'orders' => 3, 'net' => 1111.11]],
                        'evidence' => null,
                    ],
                    'site' => null,
                ],
            ],
            'generated_at' => now(),
        ]);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    /** @param array<string, bool> $over */
    private function panel(array $over = []): array
    {
        return CreativeVisibility::fromArray(array_merge(array_fill_keys(CreativeVisibility::FLAGS, true), $over))->toArray();
    }

    /** @return array{0: ReportShare, 1: string} */
    private function link(array $settings, array $opts = []): array
    {
        return app(ShareService::class)->create($this->report, array_merge([
            'mode' => 'snapshot',
            'allow_download' => true,
            'settings' => $settings,
            // The content panel's library reads the link's ceiling; a saved report's link still bounds it.
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], $opts), null);
    }

    private function shared(string $token): string
    {
        $response = $this->getJson("/api/v1/reports/shared/{$token}");
        $response->assertOk();

        return self::withoutEnvelope((string) $response->getContent());
    }

    private function printed(ReportShare $share, string $layout = 'document'): string
    {
        $token = 'audit-print-'.uniqid();
        Cache::put('report-print:'.hash('sha256', $token), [
            'report_id' => (string) $this->report->id, 'share_id' => (string) $share->id,
            'type' => $layout, 'theme' => 'light', 'audience' => 'client', 'watermark' => false,
        ], 300);

        return self::withoutEnvelope((string) $this->getJson("/api/v1/reports/print/{$token}")->assertOk()->getContent());
    }

    /**
     * The served body without its envelope's per-request `meta` (a random `request_id` such as `req_351d3350f6b8181f`), so a
     * figure searched for as a substring can never be found inside a random id — the body is everything the reader is served.
     */
    private static function withoutEnvelope(string $raw): string
    {
        $body = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        unset($body['meta']);

        return str_replace('\\/', '/', (string) json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    private function file(string $token, string $format): string
    {
        $response = $this->get("/api/v1/reports/shared/{$token}/download/{$format}");
        $response->assertOk();
        $body = $response->streamedContent();
        if ($format !== 'xlsx') {
            return $body;
        }
        $path = tempnam(sys_get_temp_dir(), 'audit').'.xlsx';
        file_put_contents($path, $body);
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

    // ---- «أداء المحتوى» off: the link's creative rows leave its CSV and XLSX ---------------------------------------

    public function test_content_switched_off_on_a_link_takes_its_creative_rows_out_of_the_csv_and_xlsx(): void
    {
        [, $shown] = $this->link(['creatives' => $this->panel()]);
        foreach (['csv', 'xlsx'] as $format) {
            $this->assertStringContainsString('AuditVideoAd', $this->file($shown, $format), "{$format}: the creative rows never reached the file — nothing is proved");
        }

        [, $hidden] = $this->link(['creatives' => $this->panel(), 'section_overrides' => ['content_performance']]);
        foreach (['csv', 'xlsx'] as $format) {
            $body = $this->file($hidden, $format);
            $this->assertStringNotContainsString('AuditVideoAd', $body, "{$format}: content switched off, the file still lists the creatives");
            $this->assertStringNotContainsString('AuditImageAd', $body, "{$format}: content switched off, the file still lists the creatives");
        }
    }

    public function test_content_switched_off_takes_the_objective_analytics_best_and_weakest_content_with_it(): void
    {
        $this->report->forceFill(['section_settings' => ['sections' => ['content_performance' => false]]])->saveQuietly();
        $payload = [
            'ads' => [['name' => 'AuditVideoAd']],
            'creatives' => [['name' => 'AuditVideoAd']],
            'content_formats' => ['mix' => [['format' => 'video']]],
            'objective_analytics' => ['families' => [['family' => 'sales', 'content_ranking' => ['best' => ['name' => 'AuditVideoAd']]]]],
        ];

        $out = app(ReportSectionSurfaces::class)->apply($payload, $this->report, null, 'preview');

        $this->assertStringNotContainsString('AuditVideoAd', (string) json_encode($out), 'content off still names a creative');
        $this->assertArrayNotHasKey('creatives', $out);
        $this->assertArrayNotHasKey('content_formats', $out);
        $this->assertNull($out['objective_analytics']['families'][0]['content_ranking']);

        $this->report->forceFill(['section_settings' => null])->saveQuietly();
        $control = app(ReportSectionSurfaces::class)->apply($payload, $this->report->fresh(), null, 'preview');
        $this->assertSame('AuditVideoAd', $control['objective_analytics']['families'][0]['content_ranking']['best']['name']);
    }

    // ---- the PDF's per-platform pages obey the link's platform and content switches ------------------------------

    public function test_the_pdf_platform_pages_obey_the_links_platform_and_content_switches(): void
    {
        $this->report->forceFill(['config' => ['breakdowns' => ['pdf' => [ReportBreakdowns::PLATFORM => true]]]])->saveQuietly();

        [$shown] = $this->link(['creatives' => $this->panel()]);
        $printed = $this->printed($shown);
        $this->assertStringContainsString('platform_drilldowns', $printed, 'no platform pages were printed — nothing is proved');
        $this->assertStringContainsString('AuditVideoAd', $printed, 'the platform pages listed no content — nothing is proved');

        [$noPlatforms] = $this->link(['creatives' => $this->panel(), 'section_overrides' => ['platform_comparison']]);
        $this->assertStringNotContainsString('platform_drilldowns', $this->printed($noPlatforms), 'platforms hidden on the link, the PDF still prints a page per platform');

        [$noContent] = $this->link(['creatives' => $this->panel(), 'section_overrides' => ['content_performance']]);
        foreach (['document', 'presentation'] as $layout) {
            $this->assertStringNotContainsString('AuditVideoAd', $this->printed($noContent, $layout), "{$layout}: content hidden on the link, the PDF platform pages still list it");
        }
    }

    // ---- the content panel saved with the master off: no copy on a saved report's link ---------------------------

    public function test_a_saved_reports_link_whose_panel_was_saved_off_publishes_no_ad_copy(): void
    {
        [, $legacy] = $this->link([]);
        $this->assertStringContainsString('AuditVideoAdBody', $this->shared($legacy), 'a link made before the panel keeps the words it always showed');

        [$share, $off] = $this->link(['creatives' => $this->panel(['creatives' => false])]);
        $this->assertTrue($share->creativePanelConfigured());
        foreach ([$this->shared($off), $this->printed($share)] as $i => $body) {
            $this->assertStringNotContainsString('AuditVideoAdBody', $body, ($i === 0 ? 'page' : 'pdf').': the panel says the copy is hidden, the link publishes it');
            $this->assertStringNotContainsString('AuditVideoAdHeadline', $body);
        }
    }

    // ---- hide revenue, the website list and the previous period on a SAVED report's link -------------------------

    public function test_a_saved_reports_link_hiding_revenue_publishes_no_website_or_store_money(): void
    {
        $money = ['7777.77', '5555.55', '4444.44', '6666.66', '3333.33', '123.45', '2121.21', '1111.11'];

        [$shown, $shownToken] = $this->link([]);
        $body = $this->shared($shownToken);
        $this->assertStringContainsString('7777.77', $body, 'the GA4 money never reached the link — nothing is proved');
        $this->assertStringContainsString('6666.66', $body, 'the store money never reached the link — nothing is proved');

        [$share, $token] = $this->link([], ['hide_revenue' => true]);
        $this->assertTrue((bool) $share->hide_revenue);
        foreach (['page' => $this->shared($token), 'pdf' => $this->printed($share)] as $surface => $raw) {
            foreach ($money as $figure) {
                $this->assertStringNotContainsString($figure, $raw, "{$surface}: revenue hidden, {$figure} is still published");
            }
            $this->assertStringContainsString('4321.5', $raw, "{$surface}: hiding revenue took the visits with it");
        }
    }

    public function test_a_links_own_website_choices_apply_to_a_saved_report(): void
    {
        [, $token] = $this->link(['ga4_hidden' => ['tile.sessions']]);
        $body = $this->shared($token);

        $this->assertStringNotContainsString('4321.5', $body, 'the link hides the sessions tile and still publishes it');
        $this->assertStringContainsString('7777.77', $body, 'the link hid only the sessions tile');
    }

    public function test_previous_period_off_takes_the_website_and_store_comparison_with_it(): void
    {
        [, $shown] = $this->link([]);
        $this->assertStringContainsString('8181.5', $this->shared($shown), 'the previous figure never reached the link — nothing is proved');

        [$share, $token] = $this->link(['sections' => ['previous_comparison' => false]]);
        foreach (['page' => $this->shared($token), 'pdf' => $this->printed($share)] as $surface => $raw) {
            foreach (['8181.5', '5555.55', '3333.33', '-0.4718'] as $figure) {
                $this->assertStringNotContainsString($figure, $raw, "{$surface}: the comparison is off, {$figure} (the previous window) is still published");
            }
            $this->assertStringContainsString('4321.5', $raw, "{$surface}: this period's figure must stay");
        }
    }

    public function test_an_older_snapshot_missing_website_keys_still_opens_with_the_read_time_cuts(): void
    {
        $data = (array) $this->report->data;
        $data['ga4_platform_gap'] = ['currency' => 'SAR'];
        $data['ga4_classification'] = ['currency' => 'SAR'];
        $data['ga4_pulse'] = ['currency' => 'SAR'];
        $this->report->forceFill(['data' => $data])->saveQuietly();

        [$share, $token] = $this->link(['ga4_hidden' => ['tile.sessions', 'dim.channel']], ['hide_revenue' => true]);
        $body = $this->shared($token);
        $this->assertStringNotContainsString('7777.77', $body);
        $this->printed($share);
    }

    // ---- REPORT-FORMAT-COMPARE-COMPACT-001: the format comparison speaks of the report's own objective(s) ------------

    public function test_the_format_comparison_keeps_only_the_reports_objective_families(): void
    {
        $format = static fn (string $f, ?float $results): array => ['format' => $f, 'creatives' => 2, 'results' => $results, 'ctr' => 0.01];
        $payload = ['content_format_comparison' => ['currency' => 'SAR', 'objectives' => [
            ['family' => 'sales', 'formats' => [$format('video', 30), $format('image', 10)]],
            ['family' => 'awareness', 'formats' => [$format('video', 0), $format('image', null)]],
            ['family' => 'traffic', 'formats' => []],
        ]]];
        $apply = fn (Report $r): array => app(ReportSectionSurfaces::class)->apply($payload, $r, null, 'preview');

        $sales = $apply($this->report);
        $this->assertSame(['sales'], array_column($sales['content_format_comparison']['objectives'], 'family'), 'a sales report compares formats on sales only');
        $this->assertContains('content_format_comparison', $sales['report_sections']);

        // Several objectives: one block, a family each, and a family with no format is no row.
        $this->report->forceFill(['campaign_objective' => 'custom', 'config' => ['objectives' => ['sales', 'awareness', 'traffic']]])->saveQuietly();
        $this->assertSame(['sales', 'awareness'], array_column($apply($this->report->fresh())['content_format_comparison']['objectives'], 'family'));

        // An objective no family answers (a saved report's INFERRED lens shares the column): every family with formats stays.
        $this->report->forceFill(['campaign_objective' => 'leads', 'config' => []])->saveQuietly();
        $this->assertSame(['sales', 'awareness'], array_column($apply($this->report->fresh())['content_format_comparison']['objectives'], 'family'));

        // No format in any family: no comparison, no section — never an empty block.
        $empty = ['content_format_comparison' => ['objectives' => [['family' => 'sales', 'formats' => []]]]];
        $none = app(ReportSectionSurfaces::class)->apply($empty, $this->report->fresh(), null, 'preview');
        $this->assertArrayNotHasKey('content_format_comparison', $none);
        $this->assertNotContains('content_format_comparison', $none['report_sections']);

        // No objective chosen: every family that has formats.
        $this->report->forceFill(['campaign_objective' => null])->saveQuietly();
        $this->assertSame(['sales', 'awareness'], array_column($apply($this->report->fresh())['content_format_comparison']['objectives'], 'family'));
    }

    // ---- REPORT-FORMAT-COMPARE-TABLE-001: one table, a column per type, a row per KPI of the report -----------------

    /** @param array<string, float|null> $m */
    private static function fmt(string $format, array $m, int $creatives = 4, bool $small = false): array
    {
        $metrics = array_fill_keys(ContentFormatComparison::TABLE_KEYS, null);

        return ['format' => $format, 'creatives' => $creatives, 'spend' => $m['spend'] ?? null, 'small_sample' => $small, 'metrics' => array_replace($metrics, $m)];
    }

    private function sales(): array
    {
        return ['family' => 'sales', 'formats' => [
            self::fmt('video', ['spend' => 1000, 'impressions' => 50000, 'clicks' => 900, 'add_to_cart' => null, 'purchases' => 40, 'revenue' => 4200, 'roas' => 4.2, 'cpm' => 20, 'ctr' => 0.018, 'cpc' => 1.11]),
            self::fmt('image', ['spend' => 500, 'impressions' => 40000, 'clicks' => 400, 'add_to_cart' => null, 'purchases' => 10, 'revenue' => 1050, 'roas' => 2.1, 'cpm' => 12.5, 'ctr' => 0.01, 'cpc' => 1.25]),
            self::fmt('carousel', ['spend' => 0, 'impressions' => 0, 'clicks' => 0, 'purchases' => 0]),
        ]];
    }

    public function test_the_comparison_is_one_table_whose_rows_are_the_reports_kpis_and_never_empty(): void
    {
        $payload = ['metric_set' => ['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas'], 'content_format_comparison' => ['currency' => 'SAR', 'objectives' => [$this->sales()]]];
        $table = app(ReportSectionSurfaces::class)->apply($payload, $this->report, null, 'preview')['content_format_comparison']['table'];

        $this->assertSame(['spend', 'impressions', 'clicks', 'purchases', 'revenue', 'roas'], $table['rows'], 'rows are the report KPIs; add to cart answered by no type is no row');
        $this->assertSame(['video', 'image'], array_column($table['formats'], 'format'), 'a type with nothing in any row is no column');
        $this->assertEquals(4.2, $table['cells']['roas']['video']);
        $this->assertEquals(2.1, $table['cells']['roas']['image']);
        $this->assertSame('higher', $table['better']['roas']);
        $this->assertArrayNotHasKey('spend', $table['better'], 'spend names no winner');
        $this->assertSame(['roas', 'purchases', 'conversions', 'revenue', 'cpa'], $table['verdict_order']);
        $this->assertSame('العائد على الإنفاق', $table['labels']['roas']['ar']);
    }

    public function test_a_traffic_report_compares_types_on_the_traffic_set(): void
    {
        $this->report->forceFill(['campaign_objective' => 'traffic'])->saveQuietly();
        $traffic = ['family' => 'traffic', 'formats' => $this->sales()['formats']];
        $table = app(ReportSectionSurfaces::class)->apply(['content_format_comparison' => ['objectives' => [$traffic]]], $this->report->fresh(), null, 'preview')['content_format_comparison']['table'];

        // KPI-SET-PER-OBJECTIVE-001 — spend, impressions, clicks, CTR, CPM, frequency, CPC; frequency of a group of creatives is unknown → no row.
        $this->assertSame(['spend', 'impressions', 'clicks', 'ctr', 'cpm', 'cpc'], $table['rows']);
        $this->assertSame('lower', $table['better']['cpm']);
    }

    public function test_a_client_table_has_no_money_row(): void
    {
        $block = ContentFormatComparison::forAudience(['currency' => 'SAR', 'objectives' => [$this->sales()]], true, false, false);
        $payload = ['metric_set' => ['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas', 'cpa', 'cpm'], 'content_format_comparison' => $block];
        $table = app(ReportSectionSurfaces::class)->apply($payload, $this->report, null, 'preview')['content_format_comparison']['table'];

        foreach (['spend', 'roas', 'cpa', 'cpm'] as $money) {
            $this->assertNotContains($money, $table['rows'], "a client table carries {$money}");
        }
        $this->assertContains('purchases', $table['rows']);
    }

    public function test_several_families_merge_by_type_with_ratios_rebuilt_from_sums(): void
    {
        $this->report->forceFill(['campaign_objective' => 'custom', 'config' => ['objectives' => ['sales', 'traffic']]])->saveQuietly();
        $traffic = ['family' => 'traffic', 'formats' => [self::fmt('video', ['spend' => 1000, 'impressions' => 50000, 'clicks' => 2100, 'ctr' => 0.042, 'reach' => 9000])]];
        $sales = $this->sales();
        $sales['formats'][0]['metrics']['reach'] = 7000;
        $table = app(ReportSectionSurfaces::class)->apply(
            ['metric_set' => ['spend', 'clicks', 'ctr', 'reach'], 'content_format_comparison' => ['objectives' => [$sales, $traffic]]],
            $this->report->fresh(), null, 'preview',
        )['content_format_comparison']['table'];

        $this->assertEquals(2000, $table['cells']['spend']['video']);
        $this->assertEqualsWithDelta(3000 / 100000, $table['cells']['ctr']['video'], 1e-9, 'CTR is summed clicks over summed impressions, not an average of CTRs');
        $this->assertNull($table['cells']['reach']['video'] ?? null, 'reach is never added across families');
    }

    public function test_a_sales_table_names_conversions_where_no_type_reports_a_purchase(): void
    {
        $sales = $this->sales();
        foreach ($sales['formats'] as $i => $f) {
            $sales['formats'][$i]['metrics']['conversions'] = $f['metrics']['purchases'];
            $sales['formats'][$i]['metrics']['purchases'] = null;
        }
        $table = app(ReportSectionSurfaces::class)->apply(
            ['metric_set' => ['spend', 'purchases', 'roas'], 'content_format_comparison' => ['objectives' => [$sales]]], $this->report, null, 'preview',
        )['content_format_comparison']['table'];

        $this->assertSame(['spend', 'conversions', 'roas'], $table['rows'], 'orders fall back to conversions, named as conversions');
        $this->assertSame('التحويلات', $table['labels']['conversions']['ar']);
    }
}
