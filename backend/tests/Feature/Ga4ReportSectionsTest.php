<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Measurement\Ga4PropertyDiscovery;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\CurrencyRate;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Measurement\Ga4ReportSections;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionRegistry;
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
        foreach (Ga4ReportSections::KEYS as $key) {
            $section = $registry->get($key);
            $this->assertSame([$key], $section->payloadKeys);
            $this->assertTrue($section->defaultFor('client'), "$key is shown unless the operator hides it");
            $this->assertTrue($section->defaultFor('internal'));
        }
    }

    public function test_a_link_shows_the_four_parts_with_the_figures_of_the_reading(): void
    {
        $live = $this->live();

        foreach (Ga4ReportSections::KEYS as $key) {
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
        $this->assertStringNotContainsString('spend', (string) json_encode(array_intersect_key($internal, array_flip(Ga4ReportSections::KEYS))));
    }

    public function test_each_part_is_hidden_on_its_own_and_takes_its_data_with_it(): void
    {
        foreach (Ga4ReportSections::KEYS as $hidden) {
            $this->choose([$hidden => false]);
            $live = $this->live();

            $this->assertNotContains($hidden, $live['report_sections']);
            $this->assertNull($live[$hidden] ?? null, "$hidden left its data behind");
            foreach (array_diff(Ga4ReportSections::KEYS, [$hidden]) as $kept) {
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
        $parts = array_intersect_key($live, array_flip(Ga4ReportSections::KEYS));
        $flat = (string) json_encode($parts);

        $this->assertNotContains('revenue', array_column($parts['ga4_overview']['kpis'], 'key'));
        $this->assertNotContains('aov', array_column($parts['ga4_overview']['kpis'], 'key'));
        $this->assertTrue($parts['ga4_overview']['revenue_hidden']);
        $this->assertNull($parts['ga4_overview']['currency']);
        foreach (['4000', '2400', '1600', '600', '1000.0', '"SAR"'] as $amount) {
            $this->assertStringNotContainsString($amount === '"SAR"' ? $amount : ':'.$amount, $flat, "an amount ($amount) stayed");
        }
        $this->assertNull($parts['ga4_overview']['series'][0]['revenue']);
        $this->assertEquals(1000.0, $parts['ga4_overview']['series'][0]['sessions'], 'sessions are not money');
        $row = collect($parts['ga4_platform_gap']['rows'])->firstWhere('platform', 'snapchat');
        $this->assertEquals(-2.0, $row['gap']['conversions']);
        $this->assertNull($row['gap']['revenue_usd']);
        $this->assertNull($parts['ga4_tables']['tables']['products']['rows'][0]['revenue']);
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
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $this->ads->id, 'external_campaign_id' => self::CAMPAIGN, 'provider' => 'snapchat',
            'metric_date' => '2026-10-05', 'metric_key' => $key, 'value' => $value, 'project_currency' => 'USD',
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
