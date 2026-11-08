<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Measurement\Ga4Visibility;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Measurement\Ga4ReportSections;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
use App\Domains\Reports\Sections\ReportViews;
use App\Domains\Reports\Sections\SectionSettings;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GA4-REPORT-SECTIONS-001 — the store reading in a report: four parts, each a SHOW/HIDE of its own,
 * on the live link and frozen in the saved report, with what a client may read decided on the server.
 *
 * Fixture rows stand in for a synced property; no live GA4 round trip is proven here.
 */
final class Ga4ReportSectionsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private const CAMPAIGN = '0b0f6a52-6f3c-4c1e-9a55-1a2b3c4d5e6f';

    private const UNIFIED = '1c1f7b63-7a4d-4d2f-8b66-2b3c4d5e6f70';

    private Tenant $tenant;

    private Project $project;

    private Project $sibling;

    private Report $report;

    private ExternalAccount $ads;

    private ExternalAccount $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $a = ClientWorkspace::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $a->id, 'name' => 'P', 'status' => 'active']);
        $this->sibling = Project::create(['client_workspace_id' => $b->id, 'name' => 'Q', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed', 'audience' => 'client',
            'currency' => 'USD', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'data' => [],
        ]);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        CurrencyRate::query()->create(['base_currency' => 'SAR', 'quote_currency' => 'USD', 'rate' => 0.25, 'rate_date' => '2026-09-01', 'source' => 'manual']);
        $this->ads = $this->adAccount('snapchat');
        $this->adMetric('conversions', 10);
        $this->adMetric('revenue', 500);
        $this->adMetric('spend', 123.45);
        $this->property = $this->property($this->project, '111');
        $this->seedProperty($this->property, $this->project);
    }

    public function test_the_four_parts_are_registered_on_by_default_and_each_owns_one_payload_key(): void
    {
        $registry = app(ReportSectionRegistry::class);
        foreach (Ga4ReportSections::CORE as $key) {
            $section = $registry->get($key);
            $this->assertSame([$key], $section->payloadKeys);
            $this->assertTrue($section->defaultFor('client'), "$key is shown unless the operator hides it");
            $this->assertTrue($section->defaultFor('internal'));
        }
    }

    public function test_a_link_shows_the_four_parts_with_the_figures_of_the_reading(): void
    {
        $live = $this->live();

        foreach (Ga4ReportSections::CORE as $key) {
            $this->assertContains($key, $live['report_sections']);
        }
        $kpis = collect($live['ga4_overview']['kpis'])->keyBy('key');
        $this->assertEquals(1000.0, $kpis['sessions']['current']);
        $this->assertEquals(0.4, $kpis['bounce_rate']['current']);
        $this->assertEquals(4000.0, $kpis['revenue']['current']);
        $this->assertEquals(1000.0, $kpis['revenue']['current_usd']);
        $this->assertEquals(200.0, $kpis['aov']['current']);
        $this->assertSame('SAR', $live['ga4_overview']['currency']);
        $this->assertCount(1, $live['ga4_overview']['series']);

        $this->assertSame(['Paid Social', 'Direct'], array_column($live['ga4_acquisition']['channels'], 'key'));
        $this->assertSame(['mobile', 'desktop'], array_column($live['ga4_acquisition']['devices'], 'key'));
        $this->assertEquals([1000, 150, 60, 20], array_column($live['ga4_acquisition']['funnel'], 'count'));

        $gap = $live['ga4_platform_gap'];
        $this->assertFalse($gap['summed']);
        $row = collect($gap['rows'])->firstWhere('platform', 'snapchat');
        $this->assertEquals(10.0, $row['reported']['conversions'], 'the platform side is the link’s own platform row');
        $this->assertEquals(12.0, $row['ga4']['purchases']);
        $this->assertEquals(-2.0, $row['gap']['conversions']);
        $this->assertEquals(-0.2, $row['gap']['conversions_share']);
        $this->assertEquals(500.0, $row['reported']['revenue_usd']);
        $this->assertEquals(600.0, $row['ga4']['revenue_usd']);
        $this->assertEquals(-100.0, $row['gap']['revenue_usd']);
        $this->assertSame(['direct'], array_column($gap['unpaid'], 'class'));

        $this->assertSame(['sources', 'landing_pages', 'countries', 'products'], array_keys($live['ga4_tables']['tables']));
        $this->assertSame('عباية سوداء', $live['ga4_tables']['tables']['products']['rows'][0]['name']);
    }

    public function test_the_website_view_is_the_only_view_that_draws_a_website_component(): void
    {
        $website = [...Ga4ReportSections::KEYS, 'site_measurement', 'store_analytics'];
        $this->assertContains('website', ReportViews::VIEWS);
        $this->assertSame(['ar' => 'الموقع', 'en' => 'Website'], ReportViews::LABELS['website']);
        foreach ($website as $key) {
            $drawnBy = array_keys(array_filter(ReportViews::DRAWS, static fn (array $keys): bool => in_array($key, $keys, true)));
            $this->assertCount(1, $drawnBy, "$key must appear in exactly one view, found: ".implode(',', $drawnBy));
            $this->assertSame($drawnBy[0], app(ReportSectionRegistry::class)->get($key)->view, "$key is grouped under the view that draws it");
        }
        $this->assertSame(['summary'], array_keys(array_filter(ReportViews::DRAWS, static fn (array $keys): bool => in_array('ga4_pulse', $keys, true))), 'the summary carries the one headline strip');
        foreach (array_diff($website, ['ga4_pulse']) as $key) {
            $this->assertSame(['website'], array_keys(array_filter(ReportViews::DRAWS, static fn (array $keys): bool => in_array($key, $keys, true))));
        }
    }

    public function test_a_link_without_the_website_view_carries_no_website_data_and_a_legacy_dashboard_list_gains_it(): void
    {
        $with = $this->read($this->link(['settings' => ['views' => ['summary', 'website']]]));
        $this->assertIsArray($with['ga4_overview']);
        $this->assertContains('website', $with['report_views']);

        $without = $this->read($this->link(['settings' => ['views' => ['summary', 'platforms']]]));
        foreach (['ga4_overview', 'ga4_acquisition', 'ga4_platform_gap', 'ga4_tables', 'ga4_attribution', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons'] as $key) {
            $this->assertNull($without[$key] ?? null, "$key stayed in the JSON of a link that has no website view");
        }
        $this->assertNotContains('website', $without['report_views']);

        $legacy = $this->read($this->link(['settings' => ['views' => ['summary', 'dashboard']]]));
        $this->assertContains('website', $legacy['report_views'], 'a list stored before the website view existed drew the store parts on the dashboard');
        $this->assertIsArray($legacy['ga4_overview']);

        $chosen = $this->read($this->link(['settings' => ['views' => ['summary', 'dashboard', ReportViews::WEBSITE_OFF]]]));
        $this->assertNotContains('website', $chosen['report_views'], 'a dashboard without the website, chosen now, stays without it');
        $this->assertNotContains(ReportViews::WEBSITE_OFF, $chosen['report_views'], 'the storage marker is never shown');
        $this->assertNull($chosen['ga4_overview'] ?? null);
    }

    public function test_the_platform_chips_and_the_not_included_banner_never_carry_the_website(): void
    {
        $live = $this->read($this->link([], ['providers' => ['snapchat', 'ga4']]));

        $this->assertSame(['snapchat'], $live['available']['providers'], 'the chip row is ad platforms only');
        $this->assertNotContains('ga4', array_column($live['freshness'], 'provider'), 'the website is not a platform these figures leave out');
    }

    public function test_the_marketing_preset_cuts_sales_figures_not_the_website_tab_and_a_hidden_chart_takes_its_closing_figures(): void
    {
        $live = $this->read($this->link(['settings' => ['ga4_hidden' => Ga4Visibility::forPreset('marketing')]]));
        $this->assertContains('website', $live['report_views'], 'a preset never hides the tab wholesale');
        $this->assertIsArray($live['ga4_overview']);
        $this->assertNotContains('revenue', array_column($live['ga4_overview']['kpis'], 'key'));
        $this->assertContains('sessions', array_column($live['ga4_overview']['kpis'], 'key'));

        $live = $this->read($this->link(['settings' => ['ga4_hidden' => ['chart.channels']]]));
        $this->assertNull($live['ga4_acquisition']['channels']);
        $this->assertNull($live['ga4_acquisition']['channels_meta'], 'the closing figures of a hidden chart are gone from the JSON');
        $this->assertIsArray($live['ga4_acquisition']['devices_meta']);
    }

    public function test_a_client_report_never_carries_campaign_names_or_spend(): void
    {
        $live = $this->live();
        $parts = (string) json_encode(array_intersect_key($live, array_flip(Ga4ReportSections::KEYS)), JSON_UNESCAPED_UNICODE);

        $this->assertArrayNotHasKey('campaigns', $live['ga4_tables']['tables']);
        $this->assertStringNotContainsString('eid_secret_campaign', $parts);
        $this->assertStringNotContainsString('spend', $parts);
        $this->assertStringNotContainsString('123.45', $parts);
        $this->assertStringNotContainsString('cost', $parts);

        // An INTERNAL report is the operator's own reading and keeps the campaigns table.
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['audience' => 'internal']);
        $internal = $this->live();
        $this->assertSame('eid_secret_campaign', $internal['ga4_tables']['tables']['campaigns']['rows'][0]['name']);
        $this->assertStringNotContainsString('spend', (string) json_encode(array_intersect_key($internal, array_flip(Ga4ReportSections::CORE))));
    }

    public function test_each_part_is_hidden_on_its_own_and_takes_its_data_with_it(): void
    {
        foreach (Ga4ReportSections::CORE as $hidden) {
            $this->choose([$hidden => false]);
            $live = $this->live();

            $this->assertNotContains($hidden, $live['report_sections']);
            $this->assertNull($live[$hidden] ?? null, "$hidden left its data behind");
            foreach (array_diff(Ga4ReportSections::CORE, [$hidden]) as $kept) {
                $this->assertContains($kept, $live['report_sections'], "hiding $hidden took $kept with it");
                $this->assertIsArray($live[$kept]);
            }
        }

        // The link's own switch does the same without touching the report.
        $this->choose([]);
        $live = $this->read($this->link(['settings' => ['section_overrides' => ['ga4_platform_gap']]]));
        $this->assertNull($live['ga4_platform_gap'] ?? null);
        $this->assertIsArray($live['ga4_overview']);
    }

    public function test_hiding_revenue_removes_every_ga4_amount_and_keeps_the_counts(): void
    {
        $live = $this->read($this->link(['hide_revenue' => true]));
        $parts = array_intersect_key($live, array_flip(Ga4ReportSections::CORE));
        $flat = (string) json_encode($parts);

        $this->assertNotContains('revenue', array_column($parts['ga4_overview']['kpis'], 'key'));
        $this->assertNotContains('aov', array_column($parts['ga4_overview']['kpis'], 'key'));
        $this->assertTrue($parts['ga4_overview']['revenue_hidden']);
        $this->assertNull($parts['ga4_overview']['currency']);
        foreach (['4000', '2400', '1600', '"SAR"'] as $amount) {
            $this->assertStringNotContainsString($amount === '"SAR"' ? $amount : ':'.$amount, $flat, "an amount ($amount) stayed");
        }
        $this->assertNull($parts['ga4_overview']['series'][0]['revenue']);
        $this->assertEquals(1000.0, $parts['ga4_overview']['series'][0]['sessions'], 'sessions are not money');
        $row = collect($parts['ga4_platform_gap']['rows'])->firstWhere('platform', 'snapchat');
        $this->assertEquals(-2.0, $row['gap']['conversions']);
        $this->assertNull($row['gap']['revenue_usd']);
        $this->assertNull($parts['ga4_tables']['tables']['products']['rows'][0]['revenue']);
    }

    public function test_hiding_revenue_also_empties_the_money_of_the_new_parts(): void
    {
        $live = $this->read($this->link(['hide_revenue' => true]));
        $parts = array_intersect_key($live, array_flip(['ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons']));
        $money = ['revenue', 'revenue_usd', 'aov', 'aov_usd', 'item_revenue', 'roas_ga4', 'gap_usd', 'value_usd'];
        $walk = function (array $a, string $path = '') use (&$walk, $money): array {
            $out = [];
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    $out = [...$out, ...$walk($v, $path.'/'.$k)];
                } elseif (is_numeric($v) && in_array($k, $money, true)) {
                    $out[] = $path.'/'.$k;
                }
            }

            return $out;
        };

        $this->assertSame([], $walk($parts));
        $this->assertStringNotContainsString('"SAR"', (string) json_encode($parts));
        $this->assertNotContains('revenue', array_column($parts['ga4_pulse']['kpis'], 'key'));
    }

    public function test_a_kpi_list_without_revenue_takes_ga4_money_out_too(): void
    {
        $live = $this->read($this->link([], ['metrics' => ['clicks', 'impressions']]));

        $this->assertNotContains('revenue', array_column($live['ga4_overview']['kpis'], 'key'));
        $this->assertNull($live['ga4_tables']['tables']['products']['rows'][0]['revenue']);
        $this->assertEquals(1000.0, collect($live['ga4_overview']['kpis'])->firstWhere('key', 'sessions')['current'], 'GA4 keeps its own vocabulary');
    }

    public function test_another_projects_property_never_enters_and_a_project_without_one_has_no_section(): void
    {
        $other = $this->property($this->sibling, '222');
        $this->seedProperty($other, $this->sibling, 'leak-', 77777);

        $flat = (string) json_encode($this->live(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('leak-', $flat);
        $this->assertStringNotContainsString('77777', $flat);

        // The project's own property is unbound: no figures, so no section and no zeroes.
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->property->id)->update(['is_active' => false]);
        $live = $this->live();
        foreach (Ga4ReportSections::KEYS as $key) {
            $this->assertNotContains($key, $live['report_sections']);
            $this->assertNull($live[$key] ?? null);
        }
    }

    public function test_a_link_that_names_no_source_reads_no_property(): void
    {
        $live = $this->read($this->link([], ['account_ids' => [], 'dynamic_campaigns' => false]));

        foreach (Ga4ReportSections::KEYS as $key) {
            $this->assertNull($live[$key] ?? null);
        }
    }

    public function test_a_saved_report_freezes_the_figures_and_a_later_sync_does_not_rewrite_it(): void
    {
        $data = $this->snapshot();
        $this->assertEquals(1000.0, collect($data['ga4_overview']['kpis'])->firstWhere('key', 'sessions')['current']);
        $this->assertArrayNotHasKey('campaigns', $data['ga4_tables']['tables']);
        $this->assertEquals(-2.0, collect($data['ga4_platform_gap']['rows'])->firstWhere('platform', 'snapchat')['gap']['conversions']);
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['data' => json_encode($data)]);
        $stored = Report::withoutGlobalScopes()->find($this->report->id)->data;

        // The property restates the day afterwards: the stored report keeps what it was saved with.
        DB::table('measurement_daily_metrics')->where('external_account_id', $this->property->id)->where('metric_key', 'sessions')->update(['value' => 5555]);

        $this->assertEquals($stored, Report::withoutGlobalScopes()->find($this->report->id)->data);
        $this->assertEquals(1000.0, collect($stored['ga4_overview']['kpis'])->firstWhere('key', 'sessions')['current']);

        // A part the operator hid is not frozen at all.
        $this->choose(array_fill_keys(Ga4ReportSections::KEYS, false));
        $none = $this->snapshot();
        foreach (Ga4ReportSections::KEYS as $key) {
            $this->assertNull($none[$key] ?? null);
        }
    }

    // ------------------------------------------------------------------ GA4-ATTRIBUTION-MATCH-001 / GA4-VISIBILITY-001

    private const SALES_KEYS = ['revenue', 'revenue_usd', 'purchases', 'aov', 'aov_usd', 'conversion_rate', 'transactions', 'items_purchased', 'item_revenue', 'roas_ga4', 'cost_per_purchase_usd'];

    /**
     * @param  array<string, mixed>  $data
     * @return list<string> the path of every sales figure that still carries a number
     */
    private function salesNumbers(array $data, string $path = ''): array
    {
        $found = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $found = [...$found, ...$this->salesNumbers($v, $path.'/'.$k)];
            } elseif (is_numeric($v) && is_string($k) && in_array($k, self::SALES_KEYS, true)) {
                $found[] = $path.'/'.$k;
            }
        }

        return $found;
    }

    public function test_the_measurement_parts_are_built_for_a_whole_project_link_and_say_what_they_are(): void
    {
        $live = $this->live();
        foreach (['ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons'] as $key) {
            $this->assertContains($key, $live['report_sections'], $key);
            $this->assertIsArray($live[$key], $key);
        }
        $this->assertFalse($live['ga4_attribution']['order_level']);
        $this->assertSame('ga4', $live['ga4_attribution']['basis']);
        $this->assertFalse($live['ga4_gap_reasons']['order_level']);
        $this->assertSame($live['ga4_gap_reasons']['headline']['gap'], $live['ga4_gap_reasons']['check']['sum']);
        $this->assertSame(['channel', 'platform', 'landing_page'], array_keys($live['ga4_classification']['groups']));
    }

    /**
     * REPORT-WEBSITE-GAP-READING-001 (Owner 2026-11-07) — «بشكل صارم لا تُظهر المصدر للبيانات»: a report carries no property name, id or
     * URL, and the gap reading speaks of tracking paths («مسار غير مباشر», «نقرات خارج التتبع»), never of sales «not from» the campaigns.
     */
    public function test_a_report_names_no_measurement_source_and_reads_the_gap_as_tracking_paths(): void
    {
        $this->choose(['site_measurement' => true]);
        foreach (['live' => $this->live(), 'snapshot' => $this->snapshot()] as $surface => $data) {
            $flat = (string) json_encode($data, JSON_UNESCAPED_UNICODE);
            foreach (['Store 111', '"111"', 'Google Analytics', 'جوجل أناليتكس', 'بلا منصة', 'بلا أثر', 'بلا حملة', 'من خارج الحملات'] as $needle) {
                $this->assertStringNotContainsString($needle, $flat, "{$surface}: {$needle}");
            }
            $labels = array_column($data['ga4_gap_reasons']['reasons'], 'label_ar', 'key');
            $this->assertSame('مسار غير مباشر', $labels['no_platform_trace'], $surface);
            $this->assertSame('نقرات خارج التتبع', $labels['no_trace'], $surface);
            $this->assertSame('Indirect path', array_column($data['ga4_gap_reasons']['reasons'], 'label_en', 'key')['no_platform_trace']);
        }
    }

    public function test_the_unmeasured_chips_are_a_switch_of_their_own_shown_unless_hidden(): void
    {
        $this->assertNotSame([], $this->live()['ga4_gap_reasons']['unmeasured'], 'nothing is hidden by default');
        $this->assertNotContains('gap.unmeasured', Ga4Visibility::forPreset('marketing'));
        $this->assertNotContains('gap.unmeasured', Ga4Visibility::forPreset('sales'));

        $live = $this->read($this->link(['settings' => ['ga4_hidden' => ['gap.unmeasured']]]));
        $this->assertSame([], $live['ga4_gap_reasons']['unmeasured']);
        foreach ($live['ga4_gap_reasons']['context'] as $c) {
            $this->assertTrue($c['measured'], $c['key'].' is an unmeasured chip and was hidden');
        }
        $this->assertNotSame([], $live['ga4_gap_reasons']['reasons'], 'the figures stay');
    }

    public function test_a_client_reading_of_the_new_parts_has_no_spend_and_no_campaign_names(): void
    {
        $parts = array_intersect_key($this->live(), array_flip(['ga4_attribution', 'ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons']));
        $flat = (string) json_encode($parts, JSON_UNESCAPED_UNICODE);

        foreach (['QCAMP', 'eid_secret_campaign', 'spend', '123.45', 'cost_per', 'roas'] as $needle) {
            $this->assertStringNotContainsString($needle, $flat, $needle);
        }
        $this->assertArrayNotHasKey('campaigns', $parts['ga4_attribution']['platforms'][0]);
    }

    public function test_an_internal_reader_gets_the_campaigns_and_the_reasons_rows(): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['audience' => 'internal']);
        $live = $this->live();

        $this->assertSame('QCAMP', $live['ga4_attribution']['platforms'][0]['campaigns'][0]['name']);
        $this->assertContains('QCAMP', array_column(array_column($live['ga4_gap_reasons']['reasons'], 'rows')[0], 'campaign'));
    }

    public function test_a_link_that_hides_sales_carries_no_sales_number_in_any_part(): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['audience' => 'internal']);
        $before = $this->live();
        $this->assertNotSame([], $this->salesNumbers(array_intersect_key($before, array_flip(Ga4ReportSections::KEYS))), 'the fixture has sales figures to cut');

        $live = $this->read($this->link(['settings' => ['ga4_hidden' => Ga4Visibility::forPreset('marketing')]]));
        $parts = array_intersect_key($live, array_flip(Ga4ReportSections::KEYS));

        $this->assertSame([], $this->salesNumbers($parts), 'a hidden figure is absent from the JSON the page, the PDF and the CSV are built from');
        $this->assertEquals(1000.0, collect($parts['ga4_overview']['kpis'])->firstWhere('key', 'sessions')['current'], 'marketing figures stay');
        $this->assertNull($parts['ga4_products'] ?? null, 'the products table is a sales element');
    }

    public function test_the_report_itself_can_hide_elements_and_the_saved_snapshot_freezes_the_cut(): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['audience' => 'internal', 'section_settings' => json_encode(['sections' => [], 'ga4_hidden' => ['tile.revenue', 'col.revenue', 'gap.reason.no_trace', 'platform:snapchat']])]);
        $data = $this->snapshot();

        $this->assertNotContains('revenue', array_column($data['ga4_overview']['kpis'], 'key'));
        $this->assertNotContains('no_trace', array_column($data['ga4_gap_reasons']['reasons'], 'key'));
        $this->assertSame([], array_filter($data['ga4_platform_gap']['rows'], static fn ($r): bool => $r['platform'] === 'snapchat'));
        $this->assertNull($data['ga4_tables']['tables']['sources']['rows'][0]['revenue']);
        $this->assertStringNotContainsString('"QCAMP"', (string) json_encode($data['ga4_gap_reasons']['reasons'][0]['rows'] ?? []).(string) json_encode(array_column($data['ga4_gap_reasons']['reasons'], 'rows')), 'the hidden reason took its rows with it');
    }

    public function test_a_hidden_list_is_normalised_and_a_stored_unknown_key_cuts_nothing(): void
    {
        $this->assertSame(['tile.revenue'], SectionSettings::fromArray(['sections' => [], 'ga4_hidden' => ['tile.revenue', '<script>', 7]], app(ReportSectionRegistry::class))->ga4Hidden());
    }

    public function test_a_link_narrower_than_its_project_never_receives_the_store_wide_parts(): void
    {
        $live = $this->read($this->link([], ['campaign_ids' => [self::CAMPAIGN], 'dynamic_campaigns' => false]));

        foreach (['ga4_pulse', 'ga4_classification', 'ga4_products', 'ga4_gap_reasons'] as $key) {
            $this->assertNull($live[$key] ?? null, "$key reads the whole property, which a campaign-scoped link does not name");
        }
        $this->assertNull($live['ga4_attribution'] ?? null);
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $data = app(ReportGenerator::class)->generate($this->report->fresh());
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $data;
    }

    /** @param  array<string, bool>  $sections */
    private function choose(array $sections): void
    {
        Report::withoutGlobalScopes()->whereKey($this->report->id)->update(['section_settings' => json_encode(['sections' => $sections])]);
    }

    /**
     * @param  array<string, mixed>  $opts
     * @param  array<string, mixed>  $scope
     */
    private function link(array $opts = [], array $scope = []): string
    {
        [, $raw] = app(ShareService::class)->create($this->report->fresh(), $opts + ['scope' => $scope + [
            'project_id' => (string) $this->project->id, 'campaign_ids' => [], 'account_ids' => [(string) $this->ads->id], 'providers' => [],
            'dynamic_campaigns' => true, 'earliest' => '2026-10-01', 'latest' => '2026-10-31',
        ]], null);

        return $raw;
    }

    /** @return array<string, mixed> */
    private function read(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live?from=2026-10-01&to=2026-10-31")->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function live(): array
    {
        return $this->read($this->link());
    }

    private function adAccount(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'Ads '.$provider);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account', 'external_id' => 'ad-'.$provider, 'name' => 'Ad', 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true,
        ]);

        return $account;
    }

    private function adMetric(string $key, float $value): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'created_at' => now(), 'updated_at' => now()];
        if (! DB::table('unified_campaigns')->where('id', self::UNIFIED)->exists()) {
            DB::table('unified_campaigns')->insert($base + [
                'id' => self::UNIFIED, 'client_workspace_id' => $this->project->client_workspace_id, 'name' => 'QCAMP', 'objective' => 'sales',
                'objective_source' => 'declared', 'status' => 'active', 'platforms' => json_encode(['snapchat']),
            ]);
            DB::table('external_campaigns')->insert($base + [
                'id' => self::CAMPAIGN, 'external_account_id' => $this->ads->id, 'unified_campaign_id' => self::UNIFIED, 'provider' => 'snapchat',
                'external_id' => 'ext-q', 'name' => 'QCAMP', 'status' => 'active', 'objective' => 'sales',
            ]);
        }
        DB::table('daily_metrics')->insert($base + [
            'id' => (string) Str::uuid(), 'external_account_id' => $this->ads->id, 'external_campaign_id' => self::CAMPAIGN,
            'unified_campaign_id' => self::UNIFIED, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => '2026-10-05',
            'value' => $value, 'project_currency' => 'USD', 'original_currency' => 'USD', 'original_amount' => $value,
        ]);
    }

    private function property(Project $project, string $external): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: 'ga4', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'GA4 '.$external);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'ga4', 'account_type' => Ga4PropertyDiscovery::ACCOUNT_TYPE, 'external_id' => $external, 'name' => 'Store '.$external,
            'status' => 'active', 'discovered_at' => Carbon::now(), 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'last_synced_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'ga4', 'purpose' => 'analytics', 'is_active' => true,
        ]);

        return $account;
    }

    private function seedProperty(ExternalAccount $property, Project $project, string $prefix = '', float $sessions = 1000): void
    {
        $daily = ['sessions' => $sessions, 'users' => 800, 'new_users' => 500, 'bounce_rate' => 0.4, 'engagement_rate' => 0.6, 'avg_session_seconds' => 75, 'transactions' => 20, 'revenue' => 4000, 'add_to_carts' => 150, 'checkouts' => 60];
        foreach ($daily as $key => $value) {
            DB::table('measurement_daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $property->id,
                'property_id' => (string) $property->external_id, 'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value,
                'currency' => $key === 'revenue' ? 'SAR' : null, 'timezone' => 'Asia/Riyadh', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $rows = [
            ['default_channel_group', 'Paid Social', '', ['sessions' => 600, 'purchases' => 12, 'revenue' => 2400]],
            ['default_channel_group', 'Direct', '', ['sessions' => 400, 'purchases' => 8, 'revenue' => 1600]],
            ['device', 'mobile', '', ['sessions' => 800, 'add_to_carts' => 120, 'checkouts' => 50, 'purchases' => 16, 'revenue' => 3200]],
            ['device', 'desktop', '', ['sessions' => 200, 'add_to_carts' => 30, 'checkouts' => 10, 'purchases' => 4, 'revenue' => 800]],
            ['source_medium', $prefix.'snapchat', 'cpc', ['sessions' => 600, 'engaged_sessions' => 300, 'purchases' => 12, 'revenue' => 2400]],
            ['source_medium', '(direct)', '(none)', ['sessions' => 400, 'engaged_sessions' => 300, 'purchases' => 8, 'revenue' => 1600]],
            ['campaign', $prefix.'eid_secret_campaign', 'snapchat / cpc', ['sessions' => 600, 'purchases' => 12, 'revenue' => 2400]],
            ['landing_page', '/'.$prefix.'abaya', '', ['sessions' => 1000, 'engaged_sessions' => 600, 'purchases' => 20, 'revenue' => 4000]],
            ['country', 'Saudi Arabia', '', ['sessions' => 1000, 'purchases' => 20, 'revenue' => 4000]],
            ['product', $prefix.'عباية سوداء', '', ['items_viewed' => 400, 'items_added_to_cart' => 90, 'items_purchased' => 20, 'item_revenue' => 4000]],
        ];
        foreach ($rows as [$breakdown, $d1, $d2, $metrics]) {
            DB::table('measurement_dimension_rows')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $property->id,
                'property_id' => (string) $property->external_id, 'metric_date' => '2026-10-05', 'breakdown' => $breakdown, 'dimension_1' => $d1, 'dimension_2' => $d2,
                'metrics' => json_encode($metrics), 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
