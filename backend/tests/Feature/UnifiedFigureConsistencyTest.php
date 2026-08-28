<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UNIFIED-002 — one sync, one figure, on every surface that shows it.
 *
 * ## What this is for
 *
 * `UnifiedDataSourceTest` proves the ENGINES are singular: the alert evaluator and the dashboard
 * reach the same ROAS because they run the same aggregator, and the freshness badge is one verdict.
 * This proves the consequence a reader actually experiences — that the same spend appears, to the
 * digit, on the dashboard, in the analytics breakdowns, in the ad funnel, and inside the client's
 * own report link.
 *
 * The failure it exists to catch is not bad arithmetic. It is a page that grows its own query. That
 * change always looks harmless — a breakdown needs a column the aggregator does not return, so
 * somebody sums `daily_metrics` in a controller — and it is invisible until a client notices the
 * report link says one thing and the dashboard says another, at which point nobody can say which of
 * the two is lying. So the assertion is deliberately about EQUALITY between surfaces rather than
 * about any expected value: it fails on the day a second source appears, whatever that source
 * computes.
 *
 * ## Why the client link is in here
 *
 * Because it is the only one of these surfaces read by somebody with no session, no way to
 * cross-check, and no way to ask. It is also the one furthest from the aggregator — it goes through
 * the share ceiling — so it is where a divergence would survive longest.
 *
 * ## The second project
 *
 * Every assertion is paired with a project whose spend is a number impossible to confuse (999). A
 * surface that leaked it would still pass an equality check between two surfaces that both leaked,
 * so each figure is also pinned to the value belonging to the project under test.
 */
final class UnifiedFigureConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-07-10';

    private const WINDOW = 'from=2026-07-01&to=2026-07-31';

    /** The figure under test. One sync wrote it; every surface must report exactly this. */
    private const SPEND = 100.0;

    private const CLICKS = 50.0;

    /** The neighbour's spend — chosen so a leak is unmistakable rather than plausible. */
    private const OTHER_SPEND = 999.0;

    private Tenant $tenant;

    private Project $project;

    private Project $otherProject;

    private User $operator;

    private UnifiedCampaign $campaign;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'U', 'slug' => 'u-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'O', 'email' => 'consistency-'.uniqid().'@u.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $workspace->id, 'name' => 'P', 'status' => 'active']);

        // A DIFFERENT client, not merely a different project — the isolation that matters commercially
        // is between two customers of the same agency.
        $otherWorkspace = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2-'.uniqid(), 'mode' => 'managed']);
        $this->otherProject = Project::create([
            'client_workspace_id' => $otherWorkspace->id, 'name' => 'P2', 'status' => 'active',
        ]);

        $this->account = $this->account('meta');

        $this->campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'حملة', 'status' => 'active', 'objective' => 'sales',
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);

        $otherCampaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->otherProject->id,
            'name' => 'حملة أخرى', 'status' => 'active', 'objective' => 'sales',
            'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);

        // ONE sync writes the figure under test; a second writes the neighbour's.
        $this->sync($this->project, $this->campaign, self::SPEND, self::CLICKS);
        $this->sync($this->otherProject, $otherCampaign, self::OTHER_SPEND, 1.0);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();
    }

    // ── the same figure, everywhere ───────────────────────────────────────────────────────────

    /**
     * Dashboard, analytics breakdowns and the ad funnel report one spend.
     *
     * Compared to each OTHER as well as to the seeded value: pinning only to 100 would still pass if
     * three surfaces each grew their own query and happened to agree today.
     */
    public function test_the_dashboard_the_breakdowns_and_the_funnel_report_one_spend(): void
    {
        $summary = $this->read('metrics/summary');
        $platforms = $this->read('metrics/platforms');
        $campaigns = $this->read('metrics/campaigns');
        $funnel = $this->read('metrics/funnel');

        $dashboard = (float) $summary->json('data.current.spend');
        $byPlatform = $this->sum($platforms->json('data'), 'spend');
        $byCampaign = $this->sum($campaigns->json('data'), 'spend');
        // `data` is the stage list; the spend the whole funnel is derived from rides in `meta`.
        $inFunnel = (float) $funnel->json('meta.spend');

        $this->assertSame(self::SPEND, $dashboard, 'the dashboard disagrees with the sync');
        $this->assertSame($dashboard, $byPlatform, 'the platform breakdown disagrees with the dashboard');
        $this->assertSame($dashboard, $byCampaign, 'the campaign breakdown disagrees with the dashboard');
        $this->assertSame($dashboard, $inFunnel, 'the funnel disagrees with the dashboard');
    }

    /**
     * …and so does the client's own link, read with no session at all.
     *
     * The furthest surface from the aggregator, and the only one whose reader cannot cross-check it.
     */
    public function test_the_client_report_link_reports_the_same_spend_without_a_session(): void
    {
        $dashboard = (float) $this->read('metrics/summary')->json('data.current.spend');

        $res = $this->getJson("/api/v1/reports/shared/{$this->liveLink()}/live")->assertOk();

        $this->assertSame(self::SPEND, (float) $res->json('data.totals.spend'));
        $this->assertSame($dashboard, (float) $res->json('data.totals.spend'), 'the client link disagrees with the dashboard');
        $this->assertSame(self::CLICKS, (float) $res->json('data.totals.clicks'));
    }

    // ── and never another client's ────────────────────────────────────────────────────────────

    /**
     * The neighbour's 999 appears on no surface, including the one with no session.
     *
     * Stated as «the total is exactly ours» rather than «999 is absent», because a surface that
     * summed both projects would report 1099 — a number in which neither figure is literally visible.
     */
    public function test_no_surface_carries_another_clients_figures(): void
    {
        $surfaces = [
            'dashboard' => (float) $this->read('metrics/summary')->json('data.current.spend'),
            'platforms' => $this->sum($this->read('metrics/platforms')->json('data'), 'spend'),
            'campaigns' => $this->sum($this->read('metrics/campaigns')->json('data'), 'spend'),
            'client link' => (float) $this->getJson("/api/v1/reports/shared/{$this->liveLink()}/live")
                ->assertOk()->json('data.totals.spend'),
        ];

        foreach ($surfaces as $name => $value) {
            $this->assertSame(self::SPEND, $value, "{$name} is not reporting this project's spend alone");
            $this->assertNotSame(self::SPEND + self::OTHER_SPEND, $value, "{$name} summed another client in");
        }

        // The neighbour's campaign is not even named in the breakdown.
        $names = array_column((array) $this->read('metrics/campaigns')->json('data'), 'name');
        $this->assertNotContains('حملة أخرى', $names);
    }

    /**
     * Every figure is accompanied by the freshness that qualifies it.
     *
     * «لا تعرض رقمًا في أي صفحة دون بيان مصدره ووقت آخر مزامنة». A number with no last-sync beside it
     * cannot be acted on: the reader cannot tell a real zero from a sync that never ran.
     */
    public function test_the_figures_are_accompanied_by_their_freshness(): void
    {
        $freshness = $this->read('metrics/freshness')->assertOk();

        // `data` is the per-source list, `meta.summary` the one verdict over all of them. Asserted
        // in the shape the endpoint actually serves — the draft guessed `data.sources`/`data.state`
        // and would have failed a working product.
        $this->assertNotEmpty($freshness->json('data'), 'no source is named beside the figures');
        $this->assertNotNull($freshness->json('meta.summary.state'), 'the figures carry no freshness verdict');
    }

    /**
     * PROVIDER-CROSS-SURFACE-PROPAGATION-001 — the surfaces this harness did not reach.
     *
     * The four tests above cover the dashboard, the breakdowns, the funnel and the client link. The
     * requirement names more: budget, the objective view and the campaign detail all read the same
     * ingested window, and each of them is a place where a second query could quietly appear.
     *
     * Asserted against the OTHER surfaces rather than against 100, for the reason the class docblock
     * gives: pinning only to a literal would still pass on the day three surfaces each grew their own
     * query and happened to agree.
     */
    public function test_budget_and_the_objective_view_read_the_same_window(): void
    {
        $dashboard = (float) $this->read('metrics/summary')->json('data.current.spend');

        // `spent`, not `spend` — the budget view names the money already used against a budget, and
        // asserting the wrong key would have passed a broken product by summing nothing to zero.
        $budget = $this->sum($this->read('metrics/budget')->json('data'), 'spent');
        $this->assertSame($dashboard, $budget, 'the budget view disagrees with the dashboard');

        /*
         * The objective view groups the same spend by family. Summed back up it must be the same
         * money — a grouping that loses or invents a riyal is a grouping nobody can reconcile.
         */
        // `data.paths` — the objective view groups by marketing path, and each path carries its own
        // spend. Summed back up it must be the same money: a grouping that loses or invents a riyal
        // is a grouping nobody can reconcile against the dashboard above it.
        $paths = $this->read('metrics/objective-performance')->json('data.paths');
        $this->assertIsArray($paths, 'the objective view did not answer');
        $this->assertNotEmpty($paths, 'a project with spend has no objective path');
        $this->assertSame(
            $dashboard,
            $this->sum($paths, 'spend'),
            'the objective breakdown does not sum to the dashboard',
        );
    }

    /**
     * The drill-down reads the same pipeline, and says so honestly when there is nothing beneath.
     *
     * This sync writes campaign-grain rows only, so the ad-set level has NOTHING — and the endpoint
     * must say that rather than inventing a level or erroring. «No ad squads» is a fact about this
     * account's data, and it is the answer a scoped report depends on being right.
     */
    public function test_the_drill_down_reports_what_is_beneath_without_inventing_it(): void
    {
        $entities = $this->read('metrics/entities/ad_set')->assertOk();

        $this->assertIsArray($entities->json('data.entities'), 'the drill-down did not answer at all');
        $this->assertSame([], $entities->json('data.entities'), 'entity rows appeared for a campaign-grain sync');
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /*
     * Named `read`, not `get`: `TestCase::get()` is public and PHP refuses to let a subclass
     * narrow it to private, so the whole file was a fatal error before it ran a single assertion.
     */
    private function read(string $path): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}?".self::WINDOW)
            ->assertOk();
    }

    /** @param array<int,array<string,mixed>>|null $rows */
    private function sum(?array $rows, string $key): float
    {
        return round(array_sum(array_map(
            static fn (array $row): float => (float) ($row[$key] ?? 0),
            $rows ?? [],
        )), 2);
    }

    private function liveLink(): string
    {
        $this->holdingTenant((string) $this->tenant->id);

        $report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'status' => 'completed',
            'currency' => 'SAR', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
            'data' => ['kpis' => ['spend' => self::SPEND]],
        ]);

        [, $raw] = app(ShareService::class)->create($report, [
            'scope' => [
                'project_id' => $this->project->id,
                'campaign_ids' => [$this->campaign->id],
                'providers' => ['meta'],
                'earliest' => '2026-07-01',
                'latest' => '2026-07-31',
            ],
        ], null);

        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        return $raw;
    }

    private function sync(Project $project, UnifiedCampaign $campaign, float $spend, float $clicks): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id,
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ext-'.uniqid(), 'name' => $campaign->name, 'status' => 'active',
        ]);

        app(UpsertDailyMetrics::class)->handle([
            $this->metric($project, $campaign, $external, 'spend', $spend),
            $this->metric($project, $campaign, $external, 'clicks', $clicks),
        ]);

        app(TenantContext::class)->forget();
    }

    private function metric(
        Project $project,
        UnifiedCampaign $campaign,
        ExternalCampaign $external,
        string $key,
        float $value,
    ): NormalizedMetric {
        return new NormalizedMetric(
            tenantId: (string) $this->tenant->id,
            projectId: (string) $project->id,
            provider: 'meta',
            externalAccountId: (string) $this->account->getKey(),
            externalCampaignId: (string) $external->getKey(),
            unifiedCampaignId: (string) $campaign->id,
            metricDate: Carbon::parse(self::DATE),
            metricKey: $key,
            value: $value,
            originalCurrency: 'SAR',
            projectCurrency: 'SAR',
            exchangeRate: 1.0,
            originalTimezone: 'UTC',
            projectTimezone: 'Asia/Riyadh',
            attributionWindow: '7d_click',
            sourceType: 'api',
            dataFreshnessAt: Carbon::parse(self::DATE)->endOfDay(),
        );
    }

    private function account(string $provider): ExternalAccount
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider,
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => "{$provider}-ad", 'name' => ucfirst($provider),
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }
}
