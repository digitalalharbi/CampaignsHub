<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Sections\ReportViews;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * REPORT-COMPOSITION-CONTROL-001 / Owner defect 106 — two-level control of a report: its MAIN
 * sections (the detailed link's tabs) and the components inside them, with «السجل اليومي» a switch
 * of its own that is off for a new client-facing report.
 *
 * Asserted on what the server SENDS — the live JSON, the files — because a tab or a table removed in
 * the page while its rows still travel is a CSS rule, not a control.
 */
final class ReportCompositionControlTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Comp', 'slug' => 'comp-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $all = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $all->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'Op', 'email' => 'op@comp.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($all);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'A campaign', 'status' => 'active', 'objective' => 'sales',
        ]);
        $account = (string) Str::uuid();
        $external = (string) Str::uuid();
        foreach ([['spend', 400.0], ['clicks', 90.0], ['impressions', 9000.0], ['conversions', 12.0]] as [$key, $value]) {
            foreach ([2, 3] as $daysAgo) {
                DailyMetric::create([
                    'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
                    'external_account_id' => $account, 'external_campaign_id' => $external,
                    'unified_campaign_id' => $this->campaign->getKey(), 'provider' => 'meta', 'metric_key' => $key,
                    'metric_date' => now()->subDays($daysAgo)->toDateString(), 'value' => $value,
                ]);
            }
        }
    }

    /**
     * A detailed live report and its link.
     *
     * @param  array<string, mixed>|null  $sectionSettings
     * @param  array<string, mixed>  $linkSettings
     * @return array{0: Report, 1: ReportShare, 2: string}
     */
    private function liveLink(?array $sectionSettings = null, array $linkSettings = []): array
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'Live', 'type' => 'live', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => now()->subDays(30)->toDateString(), 'period_end' => now()->toDateString(), 'currency' => 'SAR',
            'config' => ['live' => true, 'detail_level' => 'detailed'],
            'section_settings' => $sectionSettings,
        ]);
        [$share, $raw] = app(ShareService::class)->create($report, [
            'mode' => 'live',
            'scope' => [
                'project_id' => (string) $this->project->getKey(),
                'campaign_ids' => [(string) $this->campaign->getKey()],
                'providers' => ['meta'],
                'earliest' => now()->subDays(30)->toDateString(),
                'latest' => now()->toDateString(),
            ],
        ], null);
        if ($linkSettings !== []) {
            $share->settings = array_replace((array) ($share->settings ?? []), $linkSettings);
            $share->save();
        }

        return [$report, $share, $raw];
    }

    /** @return array<string, mixed> */
    private function live(string $raw): array
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
    }

    /**
     * A completed snapshot report with a day-by-day series, shared with downloads on.
     *
     * @param  array<string, mixed>|null  $sectionSettings
     * @return array{0: Report, 1: string}
     */
    private function snapshotLink(?array $sectionSettings): array
    {
        $data = [
            'kpis' => ['spend' => 100.0, 'conversions' => 4],
            'timeseries' => [
                ['date' => '2026-08-01', 'spend' => 50.0, 'impressions' => 1000, 'clicks' => 20, 'conversions' => 2],
                ['date' => '2026-08-02', 'spend' => 50.0, 'impressions' => 1100, 'clicks' => 22, 'conversions' => 2],
            ],
            'platforms' => [['provider' => 'meta', 'spend' => 100.0, 'conversions' => 4]],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
            'section_settings' => $sectionSettings,
        ]);
        [, $raw] = app(ShareService::class)->create($report, ['allow_download' => true], null);

        return [$report, $raw];
    }

    /** @return list<string> */
    private function sheetNames(string $raw): array
    {
        $bytes = $this->get("/api/v1/reports/shared/{$raw}/download/xlsx")->assertOk()->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $bytes);
        $names = IOFactory::load($path)->getSheetNames();
        @unlink($path);

        return $names;
    }

    private function shareSectionsUrl(Report $report, ReportShare $share): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/shares/{$share->getKey()}/sections";
    }

    private function sectionsUrl(Report $report): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections";
    }

    // ───────────────────────────── main sections, one at a time ─────────────────────────────

    public function test_an_untouched_detailed_link_offers_every_main_section(): void
    {
        [, , $raw] = $this->liveLink();

        $this->assertSame(ReportViews::VIEWS, $this->live($raw)['report_views']);
    }

    public function test_only_summary_offers_one_view_and_carries_nothing_only_other_views_draw(): void
    {
        [, , $raw] = $this->liveLink(['sections' => ['detailed_tables' => true, 'account_breakdown' => true, 'daily_records' => true], 'views' => ['summary']]);
        $payload = $this->live($raw);

        $this->assertSame(['summary'], $payload['report_views']);
        foreach (['detailed_tables', 'account_breakdown', 'daily_records', 'advanced_segmentation'] as $key) {
            $this->assertNotContains($key, $payload['report_sections'], "{$key} is drawn by no enabled view");
        }
        $this->assertArrayNotHasKey('accounts', $payload, 'the account rows leave with the Tables view');
        $this->assertContains('kpis', $payload['report_sections']);
    }

    public function test_only_dashboard(): void
    {
        [, , $raw] = $this->liveLink(['sections' => ['account_breakdown' => true, 'daily_records' => true], 'views' => ['dashboard']]);
        $payload = $this->live($raw);

        $this->assertSame(['dashboard'], $payload['report_views']);
        $this->assertNotContains('account_breakdown', $payload['report_sections']);
        $this->assertNotContains('daily_records', $payload['report_sections']);
        $this->assertContains('trends', $payload['report_sections']);
    }

    public function test_only_platforms(): void
    {
        [, , $raw] = $this->liveLink(['views' => ['platforms']]);
        $payload = $this->live($raw);

        $this->assertSame(['platforms'], $payload['report_views']);
        foreach (['budget_pacing', 'funnel', 'recommendations', 'objective_breakdown'] as $key) {
            $this->assertNotContains($key, $payload['report_sections'], $key);
        }
        $this->assertArrayNotHasKey('budget', $payload);
        $this->assertArrayNotHasKey('funnel', $payload);
    }

    public function test_only_tables_keeps_the_ledger_when_daily_records_are_on(): void
    {
        [, , $raw] = $this->liveLink(['sections' => ['daily_records' => true], 'views' => ['tables']]);
        $payload = $this->live($raw);

        $this->assertSame(['tables'], $payload['report_views']);
        $this->assertContains('daily_records', $payload['report_sections']);
        $this->assertNotContains('trends', $payload['report_sections'], 'the Tables view draws no chart');
        $this->assertNotEmpty($payload['timeseries'], 'the ledger is drawn from the series');
        $this->assertArrayNotHasKey('funnel', $payload);
    }

    public function test_mixed_main_sections_with_hidden_components(): void
    {
        [, , $raw] = $this->liveLink(['sections' => ['funnel' => false, 'daily_records' => false], 'views' => ['summary', 'tables']]);
        $payload = $this->live($raw);

        $this->assertSame(['summary', 'tables'], $payload['report_views']);
        $this->assertNotContains('funnel', $payload['report_sections']);
        $this->assertNotContains('daily_records', $payload['report_sections']);
        $this->assertArrayNotHasKey('funnel', $payload);
    }

    public function test_a_link_narrows_its_reports_main_sections_and_never_widens_them(): void
    {
        [, , $raw] = $this->liveLink(['views' => ['summary', 'tables']], ['views' => ['tables', 'dashboard']]);
        $this->assertSame(['tables'], $this->live($raw)['report_views']);

        [, , $raw] = $this->liveLink(['views' => ['summary']], ['views' => ['dashboard']]);
        $this->assertSame(['summary'], $this->live($raw)['report_views'], 'a link cannot open a view its report does not have');
    }

    public function test_a_summary_product_has_no_main_sections_to_narrow(): void
    {
        [$report, , $raw] = $this->liveLink(['views' => ['tables']]);
        $report->forceFill(['config' => ['live' => true, 'detail_level' => 'standard']])->save();

        $payload = $this->live($raw);
        $this->assertSame(ReportViews::VIEWS, $payload['report_views']);
        $this->assertContains('trends', $payload['report_sections'], 'a standard report is one composition; views do not apply');
    }

    // ───────────────────────────── daily records ─────────────────────────────

    /** Owner clarification 2026-10-19 — an independent SHOW/HIDE switch: shown unless the Owner hides it. */
    public function test_daily_records_are_shown_unless_the_owner_hides_them(): void
    {
        [, , $raw] = $this->liveLink();
        $this->assertContains('daily_records', $this->live($raw)['report_sections']);

        [, , $raw] = $this->liveLink(['sections' => ['daily_records' => false]]);
        $payload = $this->live($raw);
        $this->assertNotContains('daily_records', $payload['report_sections']);
        $this->assertContains('trends', $payload['report_sections'], 'hiding the ledger never takes the chart with it');
    }

    public function test_daily_records_hidden_reach_no_client_file(): void
    {
        [, $raw] = $this->snapshotLink(['sections' => ['daily_records' => false]]);

        $this->assertNotContains('daily_records', $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.report_sections'));
        $csv = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Daily Records', $csv);
        $this->assertStringNotContainsString('2026-08-01', $csv, 'no day of the ledger is in the file');
        $sheets = $this->sheetNames($raw);
        $this->assertNotContains('Daily Records', $sheets);
        $this->assertNotContains('Raw Metrics', $sheets);
    }

    public function test_daily_records_shown_reach_every_client_file(): void
    {
        [, $raw] = $this->snapshotLink(null);

        $this->assertContains('daily_records', $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.report_sections'));
        $csv = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Daily Records', $csv);
        $this->assertStringContainsString('2026-08-02', $csv);
        $this->assertContains('Daily Records', $this->sheetNames($raw));
    }

    public function test_a_link_switching_daily_records_off_overrides_its_report(): void
    {
        [$report, $raw] = $this->snapshotLink(['sections' => ['daily_records' => true]]);
        $share = ReportShare::query()->where('report_id', $report->getKey())->firstOrFail();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->shareSectionsUrl($report, $share), ['sections' => ['daily_records' => false]])
            ->assertOk();

        $this->assertStringNotContainsString('Daily Records', $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent());
    }

    // ───────────────────────────── persistence ─────────────────────────────

    public function test_the_configuration_survives_edit_and_reopen(): void
    {
        [$report] = $this->liveLink();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->sectionsUrl($report), ['sections' => ['daily_records' => true, 'funnel' => false], 'views' => ['summary', 'tables']])
            ->assertOk()
            ->assertJsonPath('data.views', ['summary', 'tables']);

        // Reopened: the stored choice is what the builder reads back.
        $reopened = $this->actingAs($this->operator, 'sanctum')->getJson($this->sectionsUrl($report))->assertOk()->json('data');
        $this->assertSame(['summary', 'tables'], $reopened['views']);
        $this->assertTrue($reopened['chosen']['daily_records']);
        $this->assertFalse($reopened['chosen']['funnel']);

        // An edit that touches only a component keeps the main sections.
        $this->actingAs($this->operator, 'sanctum')->putJson($this->sectionsUrl($report), ['sections' => ['funnel' => true]])->assertOk()
            ->assertJsonPath('data.views', ['summary', 'tables']);

        // `null` returns to «every view»; an empty list is not a report.
        $this->actingAs($this->operator, 'sanctum')->putJson($this->sectionsUrl($report), ['views' => []])->assertStatus(422);
        $this->actingAs($this->operator, 'sanctum')->putJson($this->sectionsUrl($report), ['views' => null])->assertOk()
            ->assertJsonPath('data.views', null);
    }

    public function test_the_link_editor_stores_and_returns_the_links_own_main_sections(): void
    {
        [$report, $share, $raw] = $this->liveLink(['views' => ['summary', 'dashboard', 'tables']]);
        $url = $this->shareSectionsUrl($report, $share);

        $this->actingAs($this->operator, 'sanctum')->putJson($url, ['views' => ['dashboard']])->assertOk()
            ->assertJsonPath('data.views', ['dashboard'])
            ->assertJsonPath('data.effective_views', ['dashboard']);
        $this->assertSame(['dashboard'], $this->live($raw)['report_views']);

        $this->actingAs($this->operator, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('data.report_views', ['summary', 'dashboard', 'tables']);
    }

    public function test_an_unknown_main_section_is_refused(): void
    {
        [$report] = $this->liveLink();

        $this->actingAs($this->operator, 'sanctum')->putJson($this->sectionsUrl($report), ['views' => ['campaigns']])->assertStatus(422);
    }

    public function test_existing_links_keep_every_tab_and_their_ledger(): void
    {
        // A report and link stored before this control existed: no views, no daily choice.
        [, , $raw] = $this->liveLink(['sections' => ['funnel' => false]]);
        $payload = $this->live($raw);

        $this->assertSame(ReportViews::VIEWS, $payload['report_views']);
        $this->assertContains('daily_records', $payload['report_sections']);
    }

    // ───────────────────────────── isolation ─────────────────────────────

    public function test_another_tenant_cannot_read_or_change_a_reports_composition(): void
    {
        [$report] = $this->liveLink(['views' => ['summary']]);

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $role = Role::create(['tenant_id' => $other->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger = User::create(['name' => 'S', 'email' => 's@other.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($stranger, $other);
        $stranger->assignRole($role);

        $this->actingAs($stranger, 'sanctum')->putJson($this->sectionsUrl($report), ['views' => ['tables']])->assertNotFound();
        $this->assertSame(['summary'], $report->fresh()->sectionSettings()->views());
    }
}
