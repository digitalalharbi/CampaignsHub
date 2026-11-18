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
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * REPORT-AUTO-COMPOSE-001 (Owner file 2026-11-03 §7.2) — an automatic mode that picks sections and indicators by the campaign's
 * objective and the data available, only when somebody picks it, from what the report or link may already show.
 */
final class ReportAutoComposeTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->buildWorld();
        $this->seed(PermissionSeeder::class);
        $this->owner = $this->userWith(Permission::pluck('key')->all(), 'owner');
        Sanctum::actingAs($this->owner);
        $this->context();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    private function context(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions, string $slug): User
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $slug, 'slug' => $slug]);
        $role->givePermissionTo(...$permissions);
        $user = User::create(['name' => $slug, 'email' => $slug.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function base(Report $report): string
    {
        return "/api/v1/projects/{$this->project->id}/reports/{$report->id}/auto-compose";
    }

    /** @param array<string, mixed> $data */
    private function snapshot(string $objective, array $data = [], array $attributes = []): Report
    {
        $this->context();

        return Report::create($attributes + [
            'project_id' => $this->project->id, 'name' => 'Snap', 'type' => 'executive', 'status' => 'completed', 'audience' => 'client',
            'form' => 'detailed', 'campaign_objective' => $objective, 'currency' => 'SAR', 'period_start' => self::WORLD_FROM, 'period_end' => self::WORLD_TO,
            'generated_at' => now(),
            'data' => $data + [
                'period' => ['from' => self::WORLD_FROM, 'to' => self::WORLD_TO],
                'currency' => 'SAR',
                'kpis' => ['spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0, 'purchases' => 2, 'reach' => 800, 'cpm' => 100.0, 'frequency' => null, 'roas' => 0.5, 'add_to_cart' => null, 'ctr' => 0.01, 'cpc' => 10.0],
                'metric_set' => ['spend', 'impressions', 'clicks'],
                'platforms' => [['provider' => 'meta', 'spend' => 100.0, 'impressions' => 1000, 'clicks' => 10, 'conversions' => 2, 'revenue' => 50.0]],
                'timeseries' => [['date' => '2026-10-01', 'impressions' => 500, 'clicks' => 5], ['date' => '2026-10-02', 'impressions' => 500, 'clicks' => 5]],
                'funnel' => [['key' => 'impressions', 'value' => 1000, 'reported' => true], ['key' => 'clicks', 'value' => 10, 'reported' => true]],
                'budget' => [],
            ],
        ]);
    }

    /** @return array<string, array<string, mixed>> section key => its row in the proposal */
    private function rows(array $proposal): array
    {
        return array_column($proposal['sections'], null, 'key');
    }

    public function test_it_is_opt_in_a_proposal_changes_nothing(): void
    {
        $report = $this->snapshot('awareness');
        $before = $report->fresh()->only(['section_settings', 'config', 'data']);

        $proposal = $this->getJson($this->base($report))->assertOk()->json('data');

        $this->assertSame('awareness', $proposal['objective']);
        $this->assertFalse($proposal['applied']);
        $this->assertSame($before, $report->fresh()->only(['section_settings', 'config', 'data']), 'looking at a proposal wrote to the report');
    }

    public function test_sections_follow_the_objective(): void
    {
        $awareness = $this->rows($this->getJson($this->base($this->snapshot('awareness')))->json('data'));
        $this->assertSame('hide', $awareness['funnel']['decision'], 'an awareness report bought no funnel');
        $this->assertSame('objective', $awareness['funnel']['reason']);

        $sales = $this->rows($this->getJson($this->base($this->snapshot('sales')))->json('data'));
        $this->assertSame('show', $sales['funnel']['decision'], 'a sales report keeps its funnel');
        $this->assertNull($sales['funnel']['reason']);
    }

    public function test_indicators_follow_the_objective_and_are_only_what_was_reported(): void
    {
        $internal = ['audience' => 'internal'];
        $proposal = $this->getJson($this->base($this->snapshot('awareness', attributes: $internal)))->json('data');
        $this->assertSame(['spend', 'impressions', 'reach', 'cpm'], $proposal['metrics'], 'an awareness report leads with reach, not with returns');
        $this->assertContains(['key' => 'frequency', 'reason' => 'not_reported'], $proposal['metrics_left_out'], 'a figure nobody reported is left out and says so, never a zero');

        $sales = $this->getJson($this->base($this->snapshot('sales', attributes: $internal)))->json('data');
        $this->assertContains('roas', $sales['metrics']);
        $this->assertNotContains('add_to_cart', $sales['metrics'], 'nobody reported a basket');
        $this->assertNotContains('reach', $sales['metrics'], 'incompatible with the objective');
    }

    public function test_on_a_client_report_it_does_not_introduce_a_money_figure_the_report_does_not_already_show(): void
    {
        $proposal = $this->getJson($this->base($this->snapshot('awareness')))->json('data');

        $this->assertSame(['spend', 'impressions', 'reach'], $proposal['metrics'], 'spend was already on this report; a cost derived from it was not');
        $this->assertContains(['key' => 'cpm', 'reason' => 'hidden_by_report'], $proposal['metrics_left_out']);

        $noSpend = $this->getJson($this->base($this->snapshot('awareness', ['metric_set' => ['impressions', 'clicks']])))->json('data');
        $this->assertNotContains('spend', $noSpend['metrics']);
    }

    public function test_a_section_with_no_data_says_so_and_is_not_offered_as_available(): void
    {
        $rows = $this->rows($this->getJson($this->base($this->snapshot('sales')))->json('data'));

        $this->assertSame('unavailable', $rows['budget_pacing']['decision']);
        $this->assertSame('no_data', $rows['budget_pacing']['reason']);
    }

    public function test_no_data_is_never_stored_as_a_hide_so_a_live_link_recovers_when_data_arrives(): void
    {
        $report = $this->snapshot('sales');
        $this->postJson($this->base($report))->assertOk();

        $this->assertArrayNotHasKey('budget_pacing', (array) ($report->fresh()->section_settings['sections'] ?? []));
    }

    public function test_it_never_switches_on_what_is_off(): void
    {
        $report = $this->snapshot('sales', attributes: ['section_settings' => ['sections' => ['trends' => false]]]);

        $rows = $this->rows($this->getJson($this->base($report))->json('data'));
        $this->assertSame('off', $rows['trends']['decision'], 'the operator hid it');
        $this->assertSame('off', $rows['detailed_tables']['decision'], 'off by default on a client report');
        $this->assertSame('off', $rows['site_measurement']['decision'], 'a disclosure is the operator\'s to make');

        $this->postJson($this->base($report))->assertOk();
        $sections = $report->fresh()->sectionSettings()->toArray()['sections'];
        $this->assertFalse($sections['trends']);
        $this->assertArrayNotHasKey('detailed_tables', $sections);
        $this->assertNotContains('detailed_tables', $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/sections")->json('data.visible'));
    }

    public function test_applying_writes_ordinary_switches_and_the_indicators_reach_every_surface(): void
    {
        $report = $this->snapshot('awareness');
        $this->postJson($this->base($report))->assertOk()->assertJsonPath('data.applied', true);

        $fresh = $report->fresh();
        $this->assertFalse($fresh->sectionSettings()->toArray()['sections']['funnel'], 'a plain switch, editable by hand');
        [, $raw] = app(ShareService::class)->create($fresh, ['allow_download' => true], null);

        $this->context();
        $file = app(ReportExporter::class)->exportData($fresh->fresh());
        $this->assertSame(['spend', 'impressions', 'reach'], $file['metric_set']);
        $this->assertArrayNotHasKey('funnel', $file, 'the file still carries what auto hid');

        $shared = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');
        $this->assertSame(['spend', 'impressions', 'reach'], $shared['metric_set']);
        $this->assertArrayNotHasKey('funnel', $shared);

        $preview = $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}")->assertOk()->json('data.data');
        $this->assertSame(['spend', 'impressions', 'reach'], $preview['metric_set'] ?? null, 'the preview and the client see one composition');
    }

    public function test_the_stored_snapshot_is_not_rewritten(): void
    {
        $report = $this->snapshot('awareness');
        $data = $report->fresh()->data;

        $this->postJson($this->base($report))->assertOk();

        $this->assertSame($data, $report->fresh()->data, 'a Snapshot is history: composing it changes what is shown, never what was recorded');
    }

    public function test_manual_edits_follow_and_survive_and_revert_restores_what_was_there(): void
    {
        $report = $this->snapshot('awareness', attributes: ['section_settings' => ['sections' => ['trends' => false]]]);
        $this->postJson($this->base($report))->assertOk();

        // Add back what auto hid, remove something it kept: the ordinary endpoint, nothing special.
        $this->putJson("/api/v1/projects/{$this->project->id}/reports/{$report->id}/sections", ['sections' => ['funnel' => true, 'daily_records' => false]])->assertOk();
        $sections = $report->fresh()->sectionSettings()->toArray()['sections'];
        $this->assertTrue($sections['funnel']);
        $this->assertFalse($sections['daily_records']);
        $this->assertTrue($this->getJson($this->base($report))->json('data.applied'), 'a manual edit does not undo the mode');

        $this->deleteJson($this->base($report))->assertOk()->assertJsonPath('data.applied', false);
        $fresh = $report->fresh();
        $this->assertSame(['trends' => false], $fresh->sectionSettings()->toArray()['sections']);
        $this->assertArrayNotHasKey('auto_compose', (array) $fresh->config);
    }

    public function test_a_live_link_composes_from_its_own_ceiling_and_never_widens(): void
    {
        [$share, $raw] = $this->liveLink(accounts: [$this->boundAccounts[0]]);
        $this->context();
        $report = Report::query()->findOrFail($share->report_id);
        $share = ReportShare::query()->findOrFail($share->id);
        $share->forceFill(['hide_spend' => true, 'hide_revenue' => true])->save();
        $scope = (array) $share->fresh()->scope;

        $before = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');

        $proposal = $this->getJson($this->base($report).'?share_id='.$share->id)->assertOk()->json('data');
        $this->assertSame('link', $proposal['level']);
        $this->assertNotContains('spend', $proposal['metrics'], 'the link hides spend');
        $this->assertNotContains('revenue', $proposal['metrics'], 'the link hides revenue');
        foreach ($proposal['metrics_left_out'] as $left) {
            if (in_array($left['key'], ['spend', 'cpm', 'cpc', 'roas', 'revenue'], true)) {
                $this->assertSame('hidden_by_link', $left['reason']);
            }
        }

        $this->postJson($this->base($report), ['share_id' => $share->id])->assertOk();

        $this->context();
        $stored = ReportShare::query()->findOrFail($share->id);
        $this->assertTrue($stored->hide_spend, 'choosing indicators turned the hidden spend back on');
        $this->assertSame($scope['account_ids'], $stored->scope['account_ids'], 'the ceiling moved');
        $this->assertSame($scope['campaign_ids'], $stored->scope['campaign_ids'], 'the ceiling moved');
        $this->assertSame($scope['providers'], $stored->scope['providers'], 'the ceiling moved');

        $after = $this->getJson("/api/v1/reports/shared/{$raw}/live")->assertOk()->json('data');
        $this->assertEquals($before['totals']['impressions'], $after['totals']['impressions'], 'an account outside the ceiling came in');
        $this->assertNull($after['totals']['spend'] ?? null);
        $this->assertCount(count($before['campaign_rows']), $after['campaign_rows']);
        $this->assertSame($proposal['metrics'], $after['metrics']);
    }

    public function test_a_link_can_be_reverted_and_its_earlier_cards_come_back(): void
    {
        [$share, $raw] = $this->liveLink();
        $this->context();
        $report = Report::query()->findOrFail($share->report_id);
        $share = ReportShare::query()->findOrFail($share->id);
        $share->forceFill(['scope' => ['metrics' => ['impressions']] + (array) $share->scope])->save();
        $original = $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data');

        $this->postJson($this->base($report), ['share_id' => $share->id])->assertOk();
        $this->deleteJson($this->base($report), ['share_id' => $share->id])->assertOk();

        $again = $this->getJson("/api/v1/reports/shared/{$raw}/live")->json('data');
        unset($original['report_trace'], $again['report_trace']);
        $this->assertSame($original, $again, 'revert did not restore the link');
    }

    public function test_a_snapshot_link_presents_its_own_indicators_without_widening_or_rewriting(): void
    {
        $report = $this->snapshot('awareness', ['metric_set' => ['spend', 'impressions', 'clicks']]);
        $data = $report->fresh()->data;
        [$share, $raw] = app(ShareService::class)->create($report, ['allow_download' => true, 'hide_spend' => true], null);
        $this->context();

        $proposal = $this->postJson($this->base($report), ['share_id' => $share->id])->assertOk()->json('data');
        $this->assertNotContains('spend', $proposal['metrics']);

        $this->assertSame($proposal['metrics'], $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.metric_set'));
        $this->assertSame($data, $report->fresh()->data);
        $this->context();
        $this->assertTrue(ReportShare::query()->findOrFail($share->id)->hide_spend);
        // The report itself and its other links are not composed by a choice on one link.
        $this->assertSame(['spend', 'impressions', 'clicks'], app(ReportExporter::class)->exportData($report->fresh())['metric_set']);
    }

    public function test_both_actions_are_audited(): void
    {
        $report = $this->snapshot('awareness');
        $this->postJson($this->base($report))->assertOk();
        $this->deleteJson($this->base($report))->assertOk();

        $this->context();
        $actions = AuditLog::query()->where('entity_id', $report->id)->pluck('action')->all();
        $this->assertContains('report.auto_compose_applied', $actions);
        $this->assertContains('report.auto_compose_reverted', $actions);
    }

    public function test_reading_needs_view_and_changing_needs_manage(): void
    {
        $report = $this->snapshot('awareness');
        Sanctum::actingAs($this->userWith(['projects.view', 'projects.view.all', 'reports.view', ClientScopeResolver::ALL_CLIENTS], 'viewer'));
        $this->getJson($this->base($report))->assertOk();
        $this->postJson($this->base($report))->assertForbidden();
        $this->deleteJson($this->base($report))->assertForbidden();
        $this->assertArrayNotHasKey('auto_compose', (array) $report->fresh()->config);
    }

    public function test_another_tenants_report_and_another_reports_link_are_not_found(): void
    {
        $report = $this->snapshot('awareness');
        [$other] = $this->liveLink();
        $this->getJson($this->base($report).'?share_id='.$other->id)->assertNotFound();
        $this->postJson($this->base($report), ['share_id' => $other->id])->assertNotFound();

        $this->context();
        $elsewhere = Tenant::create(['name' => 'Else', 'slug' => 'else-'.uniqid(), 'status' => 'active']);
        Report::withoutGlobalScopes()->whereKey($report->id)->update(['tenant_id' => $elsewhere->id]);
        $this->getJson($this->base($report))->assertNotFound();
    }

    public function test_a_report_with_no_figures_yet_does_not_guess(): void
    {
        [$share] = $this->liveLink();
        $this->context();
        $report = Report::query()->findOrFail($share->report_id);
        $report->forceFill(['campaign_objective' => 'traffic'])->save();

        $proposal = $this->getJson($this->base($report))->assertOk()->json('data');

        $this->assertFalse($proposal['availability_judged']);
        // KPI-SET-PER-OBJECTIVE-001 — the traffic set gained CPM and frequency; frequency is not money, so a client keeps it.
        $this->assertSame(['impressions', 'clicks', 'ctr', 'frequency'], $proposal['metrics'], 'with no figures to read, a client report still gains no money figure');
        $this->assertFalse($proposal['metrics_apply'], 'a live report\'s cards belong to its links');
    }
}
