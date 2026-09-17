<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CreativeMetrics;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\BoundAccountVisibility;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\DataFreshnessService;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
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
 * ACCOUNT-SCOPE-ISOLATION-001 — a deselected account's rows are not this project's figures, on every
 * surface, and come back the moment it is re-selected.
 *
 * ONE connection, FOUR accounts, ONE selected — and rows of every grain for each of them filed under
 * the selected account's project, which is what a re-bind, a deselection and a pre-assignment sync
 * each leave behind. The surfaces are asserted against EACH OTHER and against the stored rows of the
 * selected account, never against a literal: a dashboard that hid the right rows beside a report
 * that did not would still be the Owner's complaint.
 */
final class BoundAccountVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private Project $project;

    private Project $otherProject;

    private ProviderConnection $connection;

    private ExternalAccount $selected;

    private ExternalAccount $deselected;

    private ExternalAccount $elsewhere;

    private ExternalAccount $unbound;

    /** @var array<string, array{campaign: string, unified: string, creative: string}> */
    private array $rowsOf = [];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = $this->project('Selected');
        $this->otherProject = $this->project('Other');
        $this->connection = $this->connection();

        $this->selected = $this->account('act-selected');
        $this->deselected = $this->account('act-deselected');
        $this->elsewhere = $this->account('act-elsewhere');
        $this->unbound = $this->account('act-unbound');

        $this->bind($this->selected, $this->project, true);
        $this->bind($this->deselected, $this->project, false);
        $this->bind($this->elsewhere, $this->otherProject, true);

        // Distinct spends per account so a surface that counted the wrong one cannot land on the right sum.
        foreach ([[$this->selected, 100.0], [$this->deselected, 10.0], [$this->elsewhere, 1.0], [$this->unbound, 0.5]] as [$account, $spend]) {
            $this->seedAccount($account, $spend);
        }

        app(ProjectContext::class)->setProjectId($this->project->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_every_surface_shows_the_selected_account_and_agrees_with_the_others(): void
    {
        $expected = $this->storedSpendOf($this->selected) + (BoundAccountVisibility::UNBOUND_ROWS_VISIBLE ? $this->storedSpendOf($this->unbound) : 0.0);

        $surfaces = $this->spendOnEverySurface();

        foreach ($surfaces as $name => $spend) {
            $this->assertEqualsWithDelta($expected, $spend, 0.001, "{$name} does not read the selected account's own rows");
        }

        // Ad-set and creative grains: the deselected and re-bound accounts' entities are absent.
        $adSets = array_column(app(EntityMetricsAggregator::class)->byEntity($this->project->id, EntityDailyMetric::AD_SET, Carbon::parse(self::FROM), Carbon::parse(self::TO)), 'entity_id');
        $this->assertContains($this->rowsOf[$this->selected->id]['ad_set'], $adSets);
        $this->assertNotContains($this->rowsOf[$this->deselected->id]['ad_set'], $adSets, 'the deselected account\'s ad set is still on the drill-down');
        $this->assertNotContains($this->rowsOf[$this->elsewhere->id]['ad_set'], $adSets, 'an account bound to another project still shows here');

        $creatives = $this->listedCreativeIds();
        $this->assertContains($this->rowsOf[$this->selected->id]['creative'], $creatives);
        $this->assertNotContains($this->rowsOf[$this->deselected->id]['creative'], $creatives, 'the deselected account\'s creative is still in the library');
        $this->assertNotContains($this->rowsOf[$this->elsewhere->id]['creative'], $creatives);

        $figures = app(CreativeMetrics::class)->forCreatives(array_values(array_map(fn ($r) => $r['creative'], $this->rowsOf)), Carbon::parse(self::FROM), Carbon::parse(self::TO));
        $this->assertArrayHasKey($this->rowsOf[$this->selected->id]['creative'], $figures);
        $this->assertArrayNotHasKey($this->rowsOf[$this->deselected->id]['creative'], $figures, 'the deselected account\'s creative still carries figures');
    }

    public function test_re_selecting_the_account_brings_its_history_back_on_every_surface(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $this->deselected->id)
            ->update(['is_active' => true]);

        $expected = $this->storedSpendOf($this->selected) + $this->storedSpendOf($this->deselected)
            + (BoundAccountVisibility::UNBOUND_ROWS_VISIBLE ? $this->storedSpendOf($this->unbound) : 0.0);

        foreach ($this->spendOnEverySurface() as $name => $spend) {
            $this->assertEqualsWithDelta($expected, $spend, 0.001, "{$name} did not bring the re-selected account's history back");
        }

        $this->assertContains($this->rowsOf[$this->deselected->id]['creative'], $this->listedCreativeIds());
    }

    public function test_nothing_was_deleted_by_being_hidden(): void
    {
        $this->spendOnEverySurface();

        $this->assertEqualsWithDelta(10.0 * 2, $this->storedSpendOf($this->deselected), 0.001);
        $this->assertSame(2, DB::table('creative_daily_metrics')->where('creative_id', $this->rowsOf[$this->deselected->id]['creative'])->count());
    }

    public function test_the_rows_of_an_account_bound_to_another_project_are_that_projects_figures(): void
    {
        app(ProjectContext::class)->setProjectId($this->otherProject->id);

        // The other project holds none of these rows itself (they were filed under the first project),
        // so it shows nothing — the rows are not moved by a reader, and the inventory names them.
        $this->assertEqualsWithDelta(0.0, (float) (app(MetricsAggregator::class)->totals(Carbon::parse(self::FROM), Carbon::parse(self::TO))['spend'] ?? 0), 0.001);
    }

    public function test_the_no_binding_record_branch_is_pinned_to_the_inventorys_reading(): void
    {
        // Flipped in one place, once the Production inventory has said which reading is right.
        $this->assertTrue(BoundAccountVisibility::UNBOUND_ROWS_VISIBLE);

        $creatives = $this->listedCreativeIds();
        $this->assertContains($this->rowsOf[$this->unbound->id]['creative'], $creatives);
    }

    // ── surfaces ───────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, float> */
    private function spendOnEverySurface(): array
    {
        $from = Carbon::parse(self::FROM);
        $to = Carbon::parse(self::TO);

        $dashboard = (float) (app(MetricsAggregator::class)->totals($from, $to)['spend'] ?? 0);

        $freshness = app(DataFreshnessService::class)->sources($this->tenant->id, [$this->project->id]);
        $freshnessRows = count(array_filter($freshness, fn (array $s): bool => ($s['provider'] ?? null) === 'snapchat'));
        $this->assertSame(1, $freshnessRows, 'freshness should list the one provider the selected account reports through');

        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'R', 'type' => 'monthly',
            'status' => 'draft', 'currency' => 'SAR', 'period_start' => self::FROM, 'period_end' => self::TO, 'data' => [],
        ]);
        $snapshot = (float) (app(ReportGenerator::class)->generate($report)['kpis']['spend'] ?? 0);

        $live = (float) ($this->getJson('/api/v1/reports/shared/'.$this->liveLink($report).'/live?from='.self::FROM.'&to='.self::TO)
            ->assertOk()->json('data.totals.spend') ?? 0);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);

        return ['dashboard' => $dashboard, 'snapshot report' => $snapshot, 'live client link' => $live];
    }

    /** @return list<string> */
    private function listedCreativeIds(): array
    {
        $rows = app(CreativeRows::class);
        $query = $rows->query()->where('project_id', $this->project->id);
        $rows->applyFilters($query, ['from' => self::FROM, 'to' => self::TO]);

        return $query->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    private function liveLink(Report $report): string
    {
        [, $raw] = app(ShareService::class)->create($report, [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => array_values(array_map(fn ($r) => $r['unified'], $this->rowsOf)),
                'providers' => ['snapchat'],
                'earliest' => self::FROM,
                'latest' => self::TO,
            ],
        ], null);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $raw;
    }

    // ── fixture ────────────────────────────────────────────────────────────────────────────────────

    private function storedSpendOf(ExternalAccount $account): float
    {
        return (float) DB::table('daily_metrics')
            ->where('external_account_id', $account->id)
            ->where('metric_key', 'spend')
            ->sum('value');
    }

    private function project(string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function connection(): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id, 'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'snapchat',
            'connection_name' => 'snap-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function account(string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $this->connection->id, 'provider' => 'snapchat',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(ExternalAccount $account, Project $project, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    /** Every grain for one account, filed under the SELECTED project, two days of the window. */
    private function seedAccount(ExternalAccount $account, float $spend): void
    {
        $base = ['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'created_at' => now(), 'updated_at' => now()];
        $campaign = (string) Str::uuid();
        $adSet = (string) Str::uuid();
        $ad = (string) Str::uuid();
        $creative = (string) Str::uuid();

        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $this->workspace->id,
            'name' => 'U '.$account->external_id, 'objective' => 'sales', 'status' => 'active', 'platforms' => ['snapchat'],
        ]);

        DB::table('external_campaigns')->insert($base + [
            'id' => $campaign, 'external_account_id' => $account->id, 'unified_campaign_id' => $unified->id, 'provider' => 'snapchat',
            'external_id' => 'cmp-'.$account->external_id, 'name' => 'n', 'status' => 'active', 'objective' => 'sales',
        ]);
        DB::table('external_ad_sets')->insert($base + [
            'id' => $adSet, 'external_campaign_id' => $campaign, 'unified_campaign_id' => $unified->id, 'provider' => 'snapchat', 'external_id' => 'sq-'.$account->external_id, 'name' => 'n',
        ]);
        DB::table('external_creatives')->insert($base + [
            'id' => $creative, 'external_campaign_id' => $campaign, 'campaign_id' => $unified->id, 'provider' => 'snapchat',
            'external_creative_id' => 'cr-'.$account->external_id, 'name' => 'n', 'format' => 'image', 'asset_url' => 'https://cdn.example/'.$account->external_id,
        ]);
        DB::table('external_ads')->insert($base + [
            'id' => $ad, 'external_campaign_id' => $campaign, 'external_ad_set_id' => $adSet, 'unified_campaign_id' => $unified->id, 'creative_id' => $creative,
            'provider' => 'snapchat', 'external_id' => 'ad-'.$account->external_id, 'name' => 'n',
        ]);

        foreach (['2026-08-10', '2026-08-11'] as $date) {
            foreach (['spend' => $spend, 'impressions' => 1000, 'clicks' => 10] as $key => $value) {
                DB::table('daily_metrics')->insert($base + [
                    'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                    'unified_campaign_id' => $unified->id, 'provider' => 'snapchat', 'metric_key' => $key, 'metric_date' => $date,
                    'value' => $value, 'project_currency' => 'SAR', 'original_currency' => 'SAR', 'data_freshness_at' => now(),
                ]);
            }
            DB::table('entity_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
                'provider' => 'snapchat', 'entity_type' => 'ad_set', 'entity_id' => $adSet, 'external_entity_id' => 'sq-'.$account->external_id,
                'metric_date' => $date, 'spend' => $spend, 'impressions' => 1000,
            ]);
            DB::table('creative_daily_metrics')->insert($base + [
                'id' => (string) Str::uuid(), 'creative_id' => $creative, 'campaign_id' => $unified->id, 'metric_date' => $date,
                'spend' => $spend, 'impressions' => 1000, 'clicks' => 10,
            ]);
        }

        $this->rowsOf[$account->id] = ['campaign' => $campaign, 'unified' => (string) $unified->id, 'creative' => $creative, 'ad_set' => $adSet];
    }
}
