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
use App\Domains\Reports\Sections\ReportSectionSurfaces;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Reports\Support\HiddenMoney;
use App\Domains\Reports\Support\ReportMetricVisibility;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\GrantsMemberships;
use Tests\Concerns\SeedsReportAccount;
use Tests\TestCase;

/**
 * REPORT-KPI-VISIBILITY-001 — every metric is its own show/hide switch, enforced on the server for every client surface.
 *
 * The case that matters is the owner's: a client sees spend, impressions, clicks, add-to-cart, purchases, revenue and ROAS — and never
 * the cost per purchase. So most of this file hides CPA and then sweeps every surface a client can reach (the shared page, the live
 * payload, the CSV, the spreadsheet) for ANY trace of it, while asserting that the purchases, spend, revenue and ROAS beside it stay.
 */
final class ReportKpiVisibilityTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;
    use SeedsReportAccount;

    /** Every payload key a cost-per-purchase can travel under. */
    private const CPA_KEYS = ['cpa', 'blended_cpa', 'cost_per_purchase', 'cost_per_result', 'cost_per'];

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $campaign;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'kpi-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'Sales push', 'status' => 'active', 'objective' => 'sales',
        ]);
        foreach (['spend' => 1000, 'impressions' => 50000, 'clicks' => 900, 'add_to_cart' => 120, 'purchases' => 40, 'revenue' => 5000] as $key => $value) {
            DailyMetric::create([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->getKey(), 'external_account_id' => (string) Str::uuid(),
                'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $this->campaign->getKey(),
                'provider' => 'meta', 'metric_key' => $key, 'metric_date' => '2026-08-10', 'value' => $value,
            ]);
        }
    }

    /** @param array<string, mixed> $config */
    private function snapshot(array $config = [], string $audience = 'client'): Report
    {
        $data = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'],
            'currency' => 'SAR',
            'objective' => 'sales',
            'kpis' => ['spend' => 1200.0, 'conversions' => 30.0, 'revenue' => 6000.0, 'roas' => 5.0, 'cpa' => 40.0, 'impressions' => 90000.0, 'clicks' => 900.0],
            'timeseries' => [
                ['date' => '2026-08-01', 'spend' => 600.0, 'conversions' => 15.0, 'cpa' => 40.0],
                ['date' => '2026-08-02', 'spend' => 600.0, 'conversions' => 15.0, 'cpa' => 40.0],
            ],
            'platforms' => [
                ['provider' => 'meta', 'spend' => 700.0, 'revenue' => 3500.0, 'conversions' => 20.0, 'roas' => 5.0, 'cpa' => 35.0],
                ['provider' => 'snapchat', 'spend' => 500.0, 'revenue' => 2500.0, 'conversions' => 10.0, 'roas' => 5.0, 'cpa' => 50.0],
            ],
            'summary' => ['إجمالي الإنفاق 1,200 SAR خلال الفترة، ومتوسط تكلفة النتيجة 40.', 'تم تحقيق 30 عملية شراء.'],
            'slides' => [
                ['id' => 'cover', 'type' => 'cover', 'order' => 1, 'visible' => true],
                ['id' => 'cmp', 'type' => 'platform_comparison', 'order' => 2, 'visible' => true],
            ],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => $audience, 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
            'config' => $config === [] ? null : $config, 'data' => $data,
        ]);
    }

    /** @param array<string, mixed> $opts */
    private function share(Report $report, array $opts = []): array
    {
        return app(ShareService::class)->create($report, ['allow_download' => true, 'hide_spend' => false, 'hide_revenue' => false] + $opts, null);
    }

    /** Every key at any depth, as a dotted path. @return list<string> */
    private function pathsOf(mixed $node, string $prefix = ''): array
    {
        $out = [];
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $path = $prefix === '' ? (string) $k : $prefix.'.'.$k;
                // A boolean under the name is a VISIBILITY FLAG (`creatives.cpa: false`), not a figure.
                if (! is_bool($v)) {
                    $out[] = $path;
                }
                $out = array_merge($out, $this->pathsOf($v, $path));
            }
        }

        return $out;
    }

    private function assertNoCpa(mixed $payload, string $where): void
    {
        $found = array_values(array_filter($this->pathsOf($payload), static fn (string $p): bool => in_array(substr(strrchr('.'.$p, '.'), 1), self::CPA_KEYS, true)));
        $this->assertSame([], $found, "a cost-per-purchase key reached {$where}");
        $this->assertStringNotContainsString('تكلفة النتيجة', (string) json_encode($payload, JSON_UNESCAPED_UNICODE), "the words «cost per result» reached {$where}");
    }

    public function test_the_shared_page_drops_cpa_and_keeps_what_it_is_made_of(): void
    {
        $report = $this->snapshot();
        [, $raw] = $this->share($report, ['settings' => ['hidden_metrics' => ['cpa']]]);

        $body = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');

        $this->assertNoCpa($body, 'the shared page');
        $this->assertEquals(1200.0, $body['data']['kpis']['spend']);
        $this->assertEquals(30.0, $body['data']['kpis']['conversions']);
        $this->assertEquals(6000.0, $body['data']['kpis']['revenue']);
        $this->assertEquals(5.0, $body['data']['kpis']['roas']);
        $this->assertEquals(700.0, $body['data']['platforms'][0]['spend']);
        $this->assertSame(['cpa'], $body['data']['hidden_metrics']);
    }

    public function test_without_a_choice_the_page_is_exactly_what_it_was(): void
    {
        $report = $this->snapshot();
        [, $raw] = $this->share($report);

        $body = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data');

        $this->assertEquals(40.0, $body['data']['kpis']['cpa'], 'a report that never chose still shows what it always showed');
        $this->assertArrayNotHasKey('hidden_metrics', $body['data']);
    }

    public function test_the_csv_and_the_workbook_have_no_cpa_column_or_value(): void
    {
        $report = $this->snapshot();
        [$share] = $this->share($report, ['settings' => ['hidden_metrics' => ['cpa']]]);
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());
        $fresh = $share->report()->withoutGlobalScopes()->first();

        $csv = app(ReportExporter::class)->render($fresh, 'csv', $share);
        $this->assertStringNotContainsString('cpa', strtolower($csv), 'the CSV still names a cost per purchase');
        $this->assertStringContainsString('spend', $csv);
        $this->assertStringContainsString('roas', $csv);
        $this->assertStringContainsString('revenue', $csv);

        $tmp = tempnam(sys_get_temp_dir(), 'kpi_').'.xlsx';
        file_put_contents($tmp, app(ReportExporter::class)->render($fresh, 'xlsx', $share));
        $book = IOFactory::load($tmp);
        @unlink($tmp);
        $cells = [];
        foreach ($book->getAllSheets() as $sheet) {
            foreach ($sheet->toArray() as $row) {
                foreach ($row as $cell) {
                    $cells[] = strtolower((string) $cell);
                }
            }
        }
        $this->assertNotContains('cpa', $cells, 'the workbook still has a CPA column');
        $this->assertNotContains('35', $cells, 'a platform CPA value reached the workbook');
        $this->assertNotContains('50', $cells);
        $this->assertContains('spend', $cells);
        $this->assertContains('roas', $cells);
    }

    public function test_a_link_with_no_choice_follows_its_report_and_a_stated_empty_list_overrides_it(): void
    {
        $report = $this->snapshot(['hidden_metrics' => ['cpa']]);

        [, $follows] = $this->share($report);
        $this->assertNoCpa($this->getJson("/api/v1/reports/shared/{$follows}")->assertOk()->json('data'), 'a link that follows the report');

        [, $owns] = $this->share($report, ['settings' => ['hidden_metrics' => []]]);
        $shown = $this->getJson("/api/v1/reports/shared/{$owns}")->assertOk()->json('data');
        $this->assertEquals(40.0, $shown['data']['kpis']['cpa'], 'a link that states «hide nothing» owns that choice');
    }

    public function test_a_file_exported_without_a_link_follows_the_reports_choice_for_a_client_but_an_internal_view_keeps_everything(): void
    {
        $client = $this->snapshot(['hidden_metrics' => ['cpa']]);
        $internal = $this->snapshot(['hidden_metrics' => ['cpa']], 'internal');
        app(TenantContext::class)->setTenantId((string) $this->tenant->getKey());

        $clientCsv = strtolower(app(ReportExporter::class)->render($client->fresh(), 'csv'));
        $this->assertStringNotContainsString('cpa', $clientCsv);

        $internalCsv = strtolower(app(ReportExporter::class)->render($internal->fresh(), 'csv'));
        $this->assertStringContainsString('cpa', $internalCsv, 'the agency’s own copy keeps every authorised metric');
    }

    public function test_the_live_payload_and_its_metric_selection_drop_cpa_beside_spend_and_revenue(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'Live', 'type' => 'live', 'audience' => 'client',
            'status' => 'completed', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
            'config' => ['live' => true, 'metrics' => ['spend', 'purchases', 'revenue', 'roas', 'cpa'], 'hidden_metrics' => ['cpa']],
        ]);
        [, $raw] = app(ShareService::class)->create($report, [
            'hide_spend' => false, 'hide_revenue' => false, 'allow_download' => true,
            'settings' => ['hidden_metrics' => ['cpa']],
            'scope' => [
                'project_id' => (string) $this->project->getKey(), 'campaign_ids' => [(string) $this->campaign->getKey()], 'providers' => ['meta'],
                'metrics' => ['spend', 'purchases', 'revenue', 'roas', 'cpa'], 'earliest' => '2026-08-01', 'latest' => '2026-08-31',
            ],
        ], null);

        $body = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $this->assertNoCpa($body, 'the live payload');
        $this->assertNotContains('cpa', (array) ($body['metrics'] ?? []), 'the KPI selection still offers cpa');
        $this->assertEquals(1000.0, $body['totals']['spend']);
        $this->assertEquals(5000.0, $body['totals']['revenue']);
        $this->assertEquals(40.0, $body['totals']['purchases']);
        $this->assertEquals(5.0, $body['totals']['roas']);
    }

    public function test_every_metric_is_its_own_switch_and_hiding_spend_or_revenue_rides_the_money_flags(): void
    {
        $report = $this->snapshot();

        // Spend by name: the money flags apply — spend and everything derived from it leave.
        [, $noSpend] = $this->share($report, ['settings' => ['hidden_metrics' => ['spend']]]);
        $body = $this->getJson("/api/v1/reports/shared/{$noSpend}")->assertOk()->json('data');
        $this->assertNull($body['data']['kpis']['spend']);
        $this->assertNull($body['data']['kpis']['cpa'] ?? null, 'a cost derived from the hidden spend left with it');

        // Clicks alone: clicks leave, impressions and the rest stay.
        [, $noClicks] = $this->share($report, ['settings' => ['hidden_metrics' => ['clicks']]]);
        $kpis = $this->getJson("/api/v1/reports/shared/{$noClicks}")->assertOk()->json('data.data.kpis');
        $this->assertArrayNotHasKey('clicks', $kpis);
        $this->assertArrayHasKey('impressions', $kpis);
        $this->assertArrayHasKey('cpa', $kpis);
    }

    public function test_the_redaction_removes_a_hidden_metric_at_any_depth_and_strikes_it_from_lists_and_prose(): void
    {
        $out = ReportMetricVisibility::redact([
            'totals' => ['spend' => 10, 'cpa' => 4, 'nested' => ['rows' => [['cpa' => 4, 'clicks' => 3]]]],
            'metrics' => ['spend', 'cpa', 'roas'],
            'metric_set' => ['cpa', 'purchases'],
            'summary' => ['Spend was high', 'متوسط تكلفة الشراء 40'],
        ], ['cpa']);

        $this->assertNoCpa($out, 'the redacted payload');
        $this->assertSame(['spend', 'roas'], $out['metrics']);
        $this->assertSame(['purchases'], $out['metric_set']);
        $this->assertSame(['Spend was high'], $out['summary']);
        $this->assertSame(10, $out['totals']['spend']);
        $this->assertSame(3, $out['totals']['nested']['rows'][0]['clicks']);

        // Idempotent and a no-op with nothing hidden.
        $this->assertSame($out, ReportMetricVisibility::redact($out, ['cpa']));
        $this->assertSame(['a' => ['cpa' => 1]], ReportMetricVisibility::redact(['a' => ['cpa' => 1]], []));
        $this->assertSame(['a' => 1], HiddenMoney::redact(['a' => 1, 'cpa' => 2], false, false, ['cpa']));
    }

    public function test_the_catalog_normalises_to_known_keys_once_each(): void
    {
        $this->assertSame(['clicks', 'cpa'], ReportMetricVisibility::normalise(['cpa', 'bogus', 'clicks', 'cpa', 12]));
        $this->assertSame([], ReportMetricVisibility::normalise('cpa'));
        $this->assertSame(['cpa'], ReportMetricVisibility::clientPreset());
        $keys = array_column(ReportMetricVisibility::catalog(), 'key');
        foreach (['spend', 'impressions', 'clicks', 'add_to_cart', 'purchases', 'revenue', 'roas', 'cpa', 'cpl', 'cpc', 'cpm', 'ctr', 'conversion_rate'] as $required) {
            $this->assertContains($required, $keys);
        }
    }

    public function test_a_new_client_report_starts_from_the_preset_an_internal_one_from_nothing_and_the_template_changes_the_next_start(): void
    {
        $scope = $this->reportScopeFor((string) $this->project->getKey());
        $make = fn (array $extra = []): Report => Report::withoutGlobalScopes()->findOrFail(
            $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->getKey()}/reports", [
                'name' => 'R', 'type' => 'executive', 'scope' => $scope,
            ] + $extra)->assertCreated()->json('data.id'),
        );

        $this->assertSame(['cpa'], $make()->config['hidden_metrics'], 'the client preset hides the cost per purchase');
        $this->assertArrayNotHasKey('hidden_metrics', (array) $make(['audience' => 'internal'])->config);
        $this->assertSame(['clicks'], $make(['hidden_metrics' => ['clicks', 'bogus']])->config['hidden_metrics']);

        $this->actingAs($this->owner, 'sanctum')->putJson("/api/v1/projects/{$this->project->getKey()}/reports/metric-template", ['hidden_metrics' => ['cpa', 'cpm']])->assertOk();
        $show = $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->getKey()}/reports/metric-template")->assertOk()->json('data');
        $this->assertSame(['cpa', 'cpm'], $show['template']);
        $this->assertSame(['cpa'], $show['preset']);
        $this->assertSame(['cpa', 'cpm'], $make()->config['hidden_metrics'], 'the next report starts from the client’s template');
    }

    public function test_reopening_a_report_changes_its_list_without_touching_the_rest_of_the_config(): void
    {
        $report = $this->snapshot(['hidden_metrics' => ['cpa'], 'columns' => ['platforms' => ['spend']]]);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}", ['hidden_metrics' => ['cpa', 'roas']])->assertOk();
        $config = (array) Report::withoutGlobalScopes()->findOrFail($report->getKey())->config;
        $this->assertSame(['roas', 'cpa'] === $config['hidden_metrics'] ? ['roas', 'cpa'] : ['cpa', 'roas'], $config['hidden_metrics']);
        $this->assertArrayHasKey('columns', $config);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}", ['hidden_metrics' => []])->assertOk();
        $this->assertSame([], Report::withoutGlobalScopes()->findOrFail($report->getKey())->config['hidden_metrics']);
    }

    public function test_the_page_applies_the_same_list_to_print_through_the_one_surface_resolver(): void
    {
        $report = $this->snapshot(['hidden_metrics' => ['cpa']]);
        $payload = app(ReportSectionSurfaces::class)->apply($report->data, $report, null, 'print', audience: 'client');

        $this->assertNoCpa($payload, 'the print payload');
        $this->assertEquals(1200.0, $payload['kpis']['spend']);
    }
}
