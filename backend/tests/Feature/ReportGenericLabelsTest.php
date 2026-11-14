<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\CreativeVisibility;
use App\Domains\Reports\Support\GenericNames;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * REPORT-GENERIC-LABELS-001 (Owner file 2026-11-03 §7.1) — a hidden name stays hidden everywhere, and the rows stay.
 *
 * Every name in the world is overwritten with a sentinel beginning «ZZ~» (the tilde is outside every generated token's alphabet: trace marks, request ids, share and download references,
 * file ids are base32/alphanumeric/hex, so no random token can ever hold it — run 43575449716 was a «ZZ» inside a random token), so a leak is a substring of the raw bytes: the JSON a
 * browser receives (a hidden key is still a leak), the CSV, the unzipped XLSX and the document headless Chromium prints.
 */
final class ReportGenericLabelsTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private const SENTINEL = '/ZZ~[A-Z]+ ?[0-9a-f]*/';

    private const ALL = ['campaign', 'ad_set', 'account', 'content'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->buildWorld();
        DB::table('unified_campaigns')->update(['name' => DB::raw("'ZZ~CAMP '||right(id::text,6)"), 'client_display_name' => null]);
        DB::table('external_campaigns')->update(['name' => DB::raw("'ZZ~EXT '||right(id::text,6)")]);
        DB::table('external_ad_sets')->update(['name' => DB::raw("'ZZ~SET '||right(id::text,6)")]);
        DB::table('external_ads')->update(['name' => DB::raw("'ZZ~AD '||right(id::text,6)")]);
        DB::table('external_creatives')->update(['name' => DB::raw("'ZZ~CONTENT '||right(id::text,6)")]);
        DB::table('external_accounts')->update(['name' => DB::raw("'ZZ~ACCT '||right(id::text,6)")]);
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return array{0: ReportShare, 1: string} */
    private function link(array $generic = [], array $attributes = [], array $settings = []): array
    {
        [$share, $raw] = $this->liveLink();
        $this->context();
        $share = ReportShare::query()->findOrFail($share->id);
        $merged = array_merge((array) $share->settings, $settings, $generic === [] ? [] : ['generic_names' => $generic]);
        $share->forceFill(['settings' => $merged, 'allow_download' => true] + $attributes)->save();

        return [$share, $raw];
    }

    private function context(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    /** @return array<string, string> surface => the raw bytes a reader of the link can get */
    private function publicSurfaces(string $raw): array
    {
        $bytes = [];
        foreach ([
            'live' => 'live',
            'live_narrowed' => 'live?from=2026-10-05&to=2026-10-12',
            'platform_meta' => 'live/platform/meta',
            'platform_snapchat' => 'live/platform/snapchat',
            'platform_tiktok' => 'live/platform/tiktok',
            'widgets' => 'widgets',
        ] as $name => $path) {
            $bytes[$name] = (string) $this->getJson("/api/v1/reports/shared/{$raw}/{$path}")->assertOk()->getContent();
        }

        $live = $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data');
        $key = $live['ads_roster'][0]['content_key'] ?? null;
        $this->assertNotNull($key, 'the world produced no content to open');
        $bytes['content'] = (string) $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}")->assertOk()->getContent();

        return $bytes;
    }

    /** @param  array<string, string>  $surfaces */
    private function assertNoSentinel(array $surfaces, string $pattern = self::SENTINEL): void
    {
        foreach ($surfaces as $name => $bytes) {
            preg_match($pattern, $bytes, $m);
            $this->assertSame([], $m, "«{$name}» still carries a hidden name: ".($m === [] ? '' : mb_substr($bytes, max(0, (int) strpos($bytes, $m[0]) - 80), 200)));
        }
    }

    public function test_the_sentinel_is_detected_when_present_and_cannot_come_from_a_random_token(): void
    {
        $this->assertSame(1, preg_match(self::SENTINEL, '{"name":"ZZ~CONTENT 0a1b2c"}'), 'the sweep would miss a planted name');
        for ($i = 0; $i < 200; $i++) {
            $token = strtoupper(Str::random(12)).'-'.Str::upper(Str::random(8)).'ZZ'.Str::uuid().bin2hex(random_bytes(8));
            $this->assertSame(0, preg_match(self::SENTINEL, $token), 'a random token matched the sentinel');
        }
        $this->assertSame(0, preg_match(self::SENTINEL, '"report_trace":"WAKQN-ZZJF3","ZZZZZ-ZZZZZ"'));
    }

    public function test_the_same_link_without_the_switch_does_show_content_names_so_the_sweep_can_fail(): void
    {
        [, $raw] = $this->link();

        $this->assertStringContainsString('ZZ~CONTENT', $this->publicSurfaces($raw)['live']);
    }

    public function test_every_public_payload_of_a_live_link_carries_no_hidden_name(): void
    {
        [, $raw] = $this->link(self::ALL, settings: ['sections' => ['account_names' => true]]);

        $this->assertNoSentinel($this->publicSurfaces($raw));
    }

    public function test_the_content_library_its_detail_and_the_links_files_carry_no_hidden_name(): void
    {
        $settings = ['creatives' => CreativeVisibility::fromArray(array_fill_keys(CreativeVisibility::FLAGS, true))->toArray()];
        [, $control] = $this->link(settings: $settings);
        $this->assertStringContainsString('ZZ~CONTENT', (string) $this->getJson("/api/v1/reports/shared/{$control}/creatives")->assertOk()->getContent(), 'the library shows no names, so this proves nothing');

        [, $raw] = $this->link(self::ALL, settings: $settings);
        $library = $this->getJson("/api/v1/reports/shared/{$raw}/creatives")->assertOk();
        $rows = $library->json('data.creatives');
        $this->assertNotEmpty($rows);
        $ids = array_slice(array_column($rows, 'id'), 0, 2);
        $this->assertCount(2, $ids);

        $this->assertNoSentinel([
            'creatives' => (string) $library->getContent(),
            'summary' => (string) $this->getJson("/api/v1/reports/shared/{$raw}/creatives/summary")->assertOk()->getContent(),
            'creative' => (string) $this->getJson("/api/v1/reports/shared/{$raw}/creatives/{$ids[0]}")->assertOk()->getContent(),
            'comparison' => (string) $this->getJson("/api/v1/reports/shared/{$raw}/creatives/comparison?creative_ids[]={$ids[0]}&creative_ids[]={$ids[1]}")->getContent(),
            'file' => $this->downloaded($raw, 'csv'),
            'workbook' => $this->unzipped($this->downloaded($raw, 'xlsx')),
            'print' => $this->printed($raw),
        ]);
    }

    private function downloaded(string $raw, string $format): string
    {
        return $this->get("/api/v1/reports/shared/{$raw}/download/{$format}")->assertOk()->streamedContent();
    }

    private function unzipped(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path), 'the workbook does not open');
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        return $xml;
    }

    public function test_hiding_names_keeps_every_row_figure_and_section(): void
    {
        [, $named] = $this->link(settings: ['sections' => ['account_names' => true]]);
        [, $generic] = $this->link(self::ALL, settings: ['sections' => ['account_names' => true]]);

        $a = $this->getJson("/api/v1/reports/shared/{$named}/live")->assertOk()->json('data');
        $b = $this->getJson("/api/v1/reports/shared/{$generic}/live")->assertOk()->json('data');

        $this->assertNotEmpty($a['campaign_rows']);
        $this->assertSame($a['report_sections'], $b['report_sections'], 'a name switch took a section with it');
        $this->assertSame($a['totals'], $b['totals'], 'a name switch changed a figure');
        foreach (['campaign_rows', 'ads', 'ads_roster', 'ads_weakest', 'platforms', 'timeseries', 'funnel'] as $list) {
            $this->assertCount(count($a[$list] ?? []), $b[$list] ?? [], "a name switch took rows out of «{$list}»");
        }
        foreach ($a['campaign_rows'] as $i => $row) {
            foreach (['impressions', 'clicks', 'spend', 'native_leads', 'platform', 'status', 'objective', 'data_state'] as $field) {
                $this->assertSame($row[$field], $b['campaign_rows'][$i][$field], "campaign row {$i}: {$field} moved");
            }
        }
        foreach ($a['ads_roster'] as $i => $row) {
            $this->assertSame($row['metrics'], $b['ads_roster'][$i]['metrics'], "roster row {$i}: the figures moved");
        }
    }

    public function test_generic_labels_tell_rows_apart_and_one_content_keeps_one_label_across_lists_and_endpoints(): void
    {
        [, $raw] = $this->link(self::ALL);

        $live = $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data');
        $campaigns = array_column($live['campaign_rows'], 'name');
        $this->assertSame(count($campaigns), count(array_unique($campaigns)), 'two campaign rows read the same, so the comparison cannot be read');
        foreach ($campaigns as $name) {
            $this->assertMatchesRegularExpression('/^حملة [0-9]+$/u', $name, 'Latin digits, Arabic by default');
        }
        $roster = array_column($live['ads_roster'], 'name');
        $this->assertSame(count($roster), count(array_unique($roster)), 'two contents read the same');

        $byKey = array_column($live['ads_roster'], 'name', 'content_key');
        $key = array_key_first($byKey);
        $detail = $this->getJson("/api/v1/reports/shared/{$raw}/live/content/{$key}")->assertOk()->json('data');
        $this->assertSame($byKey[$key], $detail['content']['name'], 'the same content has another label in its drill-down');
    }

    public function test_labels_follow_the_reports_language(): void
    {
        [$share, $raw] = $this->link(self::ALL);
        $report = Report::query()->findOrFail($share->report_id);
        $report->forceFill(['config' => ['locale' => 'en'] + (array) $report->config])->save();

        $live = $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data');

        $this->assertMatchesRegularExpression('/^Campaign [0-9]+$/', $live['campaign_rows'][0]['name']);
        $this->assertMatchesRegularExpression('/^Content [0-9]+$/', $live['ads_roster'][0]['name']);
    }

    public function test_each_name_switch_is_independent(): void
    {
        [, $raw] = $this->link(['content']);
        $live = (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent();
        $this->assertStringNotContainsString('ZZ~CONTENT', $live);
        $this->assertStringContainsString('ZZ~CAMP', $live, 'hiding content names took the campaign names with them');

        [, $raw] = $this->link(['campaign']);
        $live = (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent();
        $this->assertStringNotContainsString('ZZ~CAMP', $live);
        $this->assertStringContainsString('ZZ~CONTENT', $live, 'hiding campaign names took the content names with them');
    }

    /** A snapshot whose stored document quotes names under every key and in prose, on an internal report's link. */
    private function snapshot(array $generic, array $attributes = []): array
    {
        $this->context();
        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'Snap', 'type' => 'executive', 'status' => 'completed', 'audience' => 'internal',
            'form' => 'detailed', 'currency' => 'SAR', 'period_start' => self::WORLD_FROM, 'period_end' => self::WORLD_TO,
            'section_settings' => ['sections' => ['detailed_tables' => true, 'account_breakdown' => true]],
            'generated_at' => now(),
            'data' => [
                'period' => ['from' => self::WORLD_FROM, 'to' => self::WORLD_TO],
                'currency' => 'SAR',
                'kpis' => ['spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0],
                'metric_set' => ['impressions', 'clicks'],
                'platforms' => [['provider' => 'meta', 'spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0]],
                'timeseries' => [['date' => '2026-10-01', 'impressions' => 1000, 'clicks' => 10, 'spend' => 100.0]],
                'campaigns' => [
                    ['campaign_id' => 'c1', 'campaign_name' => 'ZZ~STORED One', 'provider' => 'meta', 'spend' => 60.0, 'conversions' => 1],
                    ['campaign_id' => 'c2', 'campaign_name' => 'ZZ~STORED Two', 'provider' => 'meta', 'spend' => 40.0, 'conversions' => 1],
                ],
                'ad_sets' => [['ad_set_id' => 's1', 'ad_set_name' => 'ZZ~STOREDSET', 'campaign_name' => 'ZZ~STORED One', 'spend' => 60.0]],
                'best' => ['campaign' => 'ZZ~STORED One'],
                'ads' => [['name' => 'ZZ~STOREDAD', 'provider' => 'meta', 'campaign_name' => 'ZZ~STORED One', 'metrics' => ['clicks' => 5]]],
                'creatives' => [['name' => 'ZZ~STOREDAD', 'provider' => 'meta', 'campaign_name' => 'ZZ~STORED One', 'preview' => ['kind' => 'image', 'alt' => 'ZZ~STOREDAD'], 'metrics' => ['clicks' => 5]]],
                'accounts' => [['provider' => 'meta', 'account_name' => 'ZZ~STOREDACCT', 'spend' => 100.0]],
                'observations' => [['title' => 'A note', 'detail' => 'حملة «ZZ~STORED One» تستهلك الميزانية', 'scope' => ['type' => 'campaign', 'name' => 'ZZ~STORED One']]],
                'summary' => ['Campaign "ZZ~STORED Two" led the period'],
                'next_steps' => [['action' => 'Scale ZZ~STORED Two', 'reason' => 'It leads']],
                'slides' => [['id' => 'campaigns', 'type' => 'campaigns', 'order' => 1, 'visible' => true]],
            ],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'settings' => $generic === [] ? [] : ['generic_names' => $generic]] + $attributes, null);

        return [$report, $share, $raw];
    }

    /** @return array<string, string> */
    private function files(Report $report, ReportShare $share): array
    {
        $this->context();
        $exporter = app(ReportExporter::class);
        $document = app(ShareService::class)->documentReport($report, $share);
        $csv = $exporter->render($document, 'csv', $share);
        $xlsxBytes = $exporter->render($document, 'xlsx', $share);
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $xlsxBytes);
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $xml .= (string) $zip->getFromIndex($i);
        }
        $zip->close();
        $this->assertNotNull(IOFactory::load($path), 'the workbook does not open');
        unlink($path);

        return ['csv' => $csv, 'xlsx' => $xml, 'export_data' => (string) json_encode($exporter->exportData($document, $share), JSON_UNESCAPED_UNICODE)];
    }

    /** The document the real PDF download mints for headless Chromium, read back through the print route. */
    private function printed(string $raw): string
    {
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
        Cache::put('report-print:'.hash('sha256', 'generic-captured'), $minted, 300);

        return (string) $this->getJson('/api/v1/reports/print/generic-captured')->assertOk()->getContent();
    }

    public function test_a_snapshot_link_keeps_no_hidden_name_in_its_json_csv_xlsx_or_print_document(): void
    {
        [$report, $share, $raw] = $this->snapshot(self::ALL);

        $surfaces = ['shared' => (string) $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->getContent()];
        $surfaces['print'] = $this->printed($raw);
        $surfaces += $this->files($report, $share);

        $this->assertNoSentinel($surfaces);

        // A client's snapshot has no campaign rows by the client boundary; this report's own file does, and the comparison stayed:
        // both campaigns are rows, told apart, with their figures, and a note about one keeps its place under its label.
        $file = json_decode($surfaces['export_data'], true);
        $this->assertCount(2, $file['campaigns']);
        $this->assertNotSame($file['campaigns'][0]['campaign_name'], $file['campaigns'][1]['campaign_name'], 'the comparison cannot be read');
        $this->assertSame([60.0, 40.0], [(float) $file['campaigns'][0]['spend'], (float) $file['campaigns'][1]['spend']]);
        $this->assertCount(1, $file['observations'], 'a note about a campaign must stay under its generic label, not vanish');
        $this->assertStringContainsString($file['campaigns'][0]['campaign_name'], $file['observations'][0]['detail']);
        $this->assertMatchesRegularExpression('/حملة [0-9]+/u', $surfaces['csv']);
    }

    public function test_the_files_would_carry_the_names_without_the_switch_so_the_sweep_can_fail(): void
    {
        [$report, $share] = $this->snapshot([]);

        $files = $this->files($report, $share);
        $this->assertStringContainsString('ZZ~STORED', $files['csv']);
        $this->assertStringContainsString('ZZ~STORED', $files['xlsx']);
    }

    public function test_the_older_hide_campaign_names_switch_closes_its_snapshot_leaks_and_keeps_its_wording(): void
    {
        [$report, $share, $raw] = $this->snapshot([], ['hide_campaign_names' => true]);

        $surfaces = ['shared' => (string) $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->getContent()];
        $surfaces += $this->files($report, $share);

        foreach ($surfaces as $name => $bytes) {
            $this->assertStringNotContainsString('ZZ~STORED One', $bytes, "«{$name}» still names a campaign under the older switch");
            $this->assertStringNotContainsString('ZZ~STORED Two', $bytes, "«{$name}» still names a campaign under the older switch");
        }
        $file = json_decode($surfaces['export_data'], true);
        $this->assertSame(['حملة', 'حملة'], array_column($file['campaigns'], 'campaign_name'), 'an existing link keeps the words it showed');
        $this->assertSame('ZZ~STOREDSET', $file['ad_sets'][0]['ad_set_name'], 'the older switch is about campaigns only');
        $this->assertSame('ZZ~STOREDAD', $file['ads'][0]['name'], 'the older switch is about campaigns only');
    }

    public function test_a_real_name_shaped_like_a_stand_in_is_still_hidden(): void
    {
        $first = (string) DB::table('unified_campaigns')->orderBy('created_at')->orderBy('id')->value('id');
        DB::table('unified_campaigns')->where('id', $first)->update(['name' => 'Spring Sale — 2']);
        $creative = (string) DB::table('external_creatives')->orderBy('created_at')->orderBy('id')->value('id');
        DB::table('external_creatives')->where('id', $creative)->update(['name' => 'Agency account 7']);

        [, $raw] = $this->link(['campaign', 'content']);
        $live = (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent();

        $this->assertStringNotContainsString('Spring Sale', $live);
        $this->assertStringNotContainsString('Agency account 7', $live);
    }

    public function test_a_name_under_a_chart_label_is_hidden(): void
    {
        $this->context();
        $names = GenericNames::for(Report::create(['project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'config' => ['generic_names' => ['campaign']]]), null);
        $name = (string) DB::table('unified_campaigns')->orderBy('created_at')->orderBy('id')->value('name');

        $out = $names->apply(['chart' => ['series' => [['label' => $name, 'value' => 5], ['tooltip' => "Best: {$name}", 'value' => 6]]]]);

        $this->assertStringNotContainsString($name, (string) json_encode($out, JSON_UNESCAPED_UNICODE));
        $this->assertSame(5, $out['chart']['series'][0]['value'], 'a figure moved');
    }

    public function test_the_product_vocabulary_is_not_rewritten_by_a_name_that_looks_like_it(): void
    {
        $this->context();
        $names = GenericNames::for(Report::create(['project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'config' => ['generic_names' => ['campaign']]]), null);

        $in = ['campaigns' => [['campaign_name' => 'Purchases', 'provider' => 'meta', 'objective' => 'purchases', 'label_en' => 'Purchases', 'status' => 'Purchases']]];
        $out = $names->apply($in)['campaigns'][0];

        $this->assertMatchesRegularExpression('/^حملة [0-9]+$/u', $out['campaign_name']);
        $this->assertSame('Purchases', $out['label_en']);
        $this->assertSame('purchases', $out['objective']);
        $this->assertSame('Purchases', $out['status']);

        // And an applied document is a fixed point: a second pass changes nothing.
        $this->assertSame($names->apply($in), $names->apply($names->apply($in)));
    }

    public function test_the_older_switch_does_not_rewrite_a_content_name_that_quotes_its_campaign(): void
    {
        [, $control] = $this->link();
        $names = fn (string $raw): array => array_column($this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data.ads_roster'), 'name');

        $campaign = (string) DB::table('unified_campaigns')->orderBy('created_at')->orderBy('id')->value('name');
        DB::table('external_creatives')->update(['name' => DB::raw("'{$campaign} Reel '||right(id::text,6)")]);

        [, $older] = $this->link(attributes: ['hide_campaign_names' => true]);

        $this->assertSame($names($control), $names($older), 'an existing link on the older switch shows content names it did not show before');
    }

    /*
     * Owner correction 2026-11-04 (§8.3): a duplicate is a draft saved from the editor, and it gets no link. The report's own
     * name switches still travel with the copy (they are not something the editor shows, so they are kept as they were); the
     * link half of the old assertion — «a copied link is never more open than the link it came from» — is now met by there
     * being no copied link at all, which is asserted here in its place.
     */
    public function test_a_duplicated_report_keeps_its_name_switches_and_gets_no_link(): void
    {
        [$share] = $this->link(['content']);
        $this->operator();
        $report = Report::query()->findOrFail($share->report_id);
        $report->forceFill(['config' => ['generic_names' => ['account']] + (array) $report->config])->save();
        $base = "/api/v1/projects/{$this->project->id}/reports";

        $draft = $this->postJson("{$base}/{$report->id}/duplicate", ['name' => 'Copy', 'with_link' => true, 'share_id' => $share->id])->assertSuccessful()->json('data.draft');
        $this->context();
        $this->assertSame(1, ReportShare::query()->count(), 'pressing Duplicate makes no link');

        $accounts = (array) ($draft['scope']['account_ids'] ?? []);
        $this->assertNotSame([], $accounts, 'the live link report’s sources are handed to the editor');
        $copy = $this->postJson($base, [
            'name' => 'Copy', 'type' => $draft['type'], 'mode' => $draft['mode'], 'detail_level' => $draft['detail_level'],
            'period_start' => $draft['period_start'], 'period_end' => $draft['period_end'],
            'scope' => ['account_ids' => $accounts], 'draft' => true, 'source_report_id' => $report->id,
        ])->assertSuccessful()->json('data');
        $this->context();

        $this->assertSame(['account'], Report::query()->findOrFail($copy['id'])->config['generic_names'], 'a copy is never more open than the report it came from');
        $this->assertSame(0, ReportShare::query()->where('report_id', $copy['id'])->count(), 'no link is copied, so none can be more open than the one it came from');
    }

    public function test_every_generic_word_comes_from_one_place(): void
    {
        foreach ([
            'app/Domains/Reports/Services/ShareService.php',
            'app/Domains/Reports/Services/LiveReportService.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertDoesNotMatchRegularExpression("/=\s*'حملة/u", $source, "{$file} writes a generic campaign label of its own");
            $this->assertStringNotContainsString("'حساب '", $source, "{$file} writes a generic account label of its own");
        }
        $this->assertSame('حملة 3', GenericNames::label('campaign', 3, 'ar'));
        $this->assertSame('Content 3', GenericNames::label('content', 3, 'en'));
    }

    public function test_a_link_cannot_show_a_name_the_report_hides(): void
    {
        [$share, $raw] = $this->link();
        $report = Report::query()->findOrFail($share->report_id);
        $report->forceFill(['config' => ['generic_names' => ['content']] + (array) $report->config])->save();

        $this->assertStringNotContainsString('ZZ~CONTENT', (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent());
    }

    private function operator(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->context();
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => 'o'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        Sanctum::actingAs($user);
    }

    public function test_the_report_switch_saves_is_audited_and_reaches_its_links(): void
    {
        [$share, $raw] = $this->link();
        $this->operator();
        $url = "/api/v1/projects/{$this->project->id}/reports/{$share->report_id}/sections";

        $this->putJson($url, ['generic_names' => ['content', 'nonsense']])->assertUnprocessable();
        $this->putJson($url, ['generic_names' => ['content']])->assertOk()->assertJsonPath('data.generic_names', ['content']);
        $this->assertStringNotContainsString('ZZ~CONTENT', (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent());

        $this->context();
        $entry = AuditLog::query()->where('action', 'report.sections_updated')->where('entity_id', $share->report_id)->latest('created_at')->firstOrFail();
        $this->assertSame(['content'], $entry->after['generic_names'] ?? null);

        // Empty is stored as nothing: the report goes back to exactly the settings it had.
        $this->putJson($url, ['generic_names' => []])->assertOk()->assertJsonPath('data.generic_names', []);
        $this->context();
        $this->assertArrayNotHasKey('generic_names', (array) Report::query()->findOrFail($share->report_id)->config);
    }

    public function test_the_link_switch_saves_independently_and_says_what_the_report_holds(): void
    {
        [$share, $raw] = $this->link();
        $this->operator();
        $url = "/api/v1/projects/{$this->project->id}/reports/{$share->report_id}/shares/{$share->id}/sections";

        $this->putJson($url, ['generic_names' => ['campaign']])->assertOk()->assertJsonPath('data.generic_names', ['campaign'])->assertJsonPath('data.generic_names_of_report', []);
        $live = (string) $this->getJson("/api/v1/reports/shared/{$raw}/live")->getContent();
        $this->assertStringNotContainsString('ZZ~CAMP', $live);
        $this->assertStringContainsString('ZZ~CONTENT', $live);

        // Saving something else leaves the switch alone.
        $this->putJson($url, ['sections' => ['trends' => false]])->assertOk()->assertJsonPath('data.generic_names', ['campaign']);
    }

    public function test_a_viewer_cannot_change_a_name_switch(): void
    {
        [$share] = $this->link();
        $this->seed(PermissionSeeder::class);
        $this->context();
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'reports.view', ClientScopeResolver::ALL_CLIENTS);
        $user = User::create(['name' => 'V', 'email' => 'v'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        Sanctum::actingAs($user);

        $this->putJson("/api/v1/projects/{$this->project->id}/reports/{$share->report_id}/sections", ['generic_names' => ['content']])->assertForbidden();
    }

    public function test_it_can_be_chosen_when_a_link_is_created(): void
    {
        [$report] = $this->snapshot([]);
        $report->forceFill(['audience' => 'client'])->save();
        $this->operator();

        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares", ['generic_names' => ['content', 'campaign', 'nonsense']])
            ->assertUnprocessable();
        $id = $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/shares", ['generic_names' => ['content', 'campaign']])
            ->assertCreated()->json('data.id');

        $this->context();
        $this->assertSame(['campaign', 'content'], ReportShare::query()->findOrFail($id)->settings['generic_names']);
    }

    public function test_unknown_kinds_in_stored_settings_are_ignored(): void
    {
        $this->assertSame(['campaign'], GenericNames::normalise(['campaign', 'nonsense', 7, 'campaign']));
        $this->assertSame([], GenericNames::normalise('campaign'));
    }
}
