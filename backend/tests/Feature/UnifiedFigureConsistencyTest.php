<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CampaignAnnotation;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Services\DigestRecommendations;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
    /** Set by `entityRows()`: `external_campaign_id` is a uuid FK, never the provider's own string id. */
    private string $campaignA = '';

    private string $campaignB = '';

    private const SPEND = 100.0;

    private const CLICKS = 50.0;

    /** The neighbour's spend — chosen so a leak is unmistakable rather than plausible. */
    private const OTHER_SPEND = 999.0;

    private Tenant $tenant;

    private Project $project;

    private Project $otherProject;

    private User $operator;

    private UnifiedCampaign $campaign;

    /** The neighbouring CLIENT's campaign — the name that must never appear on this project's surfaces. */
    private UnifiedCampaign $otherCampaign;

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
        $this->otherCampaign = $otherCampaign;

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

    /**
     * PROVIDER-CROSS-SURFACE-PROPAGATION-001 — the drill-down carries the SAME window, to the digit.
     *
     * The negative above proves the level does not invent rows. This is its other half, and the one a
     * reader actually depends on: when a provider really does report ad-set grain, the figures that
     * reach the drill-down are the figures the campaign level already showed.
     *
     * A drill-down is the surface where a second query is most tempting — it needs a parent filter the
     * campaign breakdown does not — and the divergence it would produce is the most convincing kind,
     * because the ad-set rows would each look plausible and only their SUM would contradict the
     * campaign above them.
     */
    public function test_the_drill_down_carries_the_same_window_it_drilled_into(): void
    {
        $this->entityRows();

        $entities = $this->read('metrics/entities/ad_set')->assertOk();
        $rows = $entities->json('data.entities');

        $this->assertNotSame([], $rows, 'the ad-set level reported nothing for a sync that wrote it');

        $dashboard = (float) $this->read('metrics/summary')->json('data.current.spend');

        $this->assertSame(
            $dashboard,
            $this->sum($rows, 'spend'),
            'the ad sets beneath the campaign do not add up to the campaign the reader drilled into',
        );
    }

    /**
     * And narrowing to a parent narrows the DATA, not just the label.
     *
     * `parent` changes the database scope. A drill-down that filtered on the client would show one
     * campaign's name over another campaign's rows, and every figure on the page would be real —
     * which is precisely why nothing on screen would look wrong.
     */
    public function test_narrowing_the_drill_down_to_a_parent_excludes_the_other_parent(): void
    {
        $this->entityRows();

        $mine = $this->read('metrics/entities/ad_set', '&parent='.$this->campaignA)->assertOk();
        $names = array_column($mine->json('data.entities'), 'external_id');

        $this->assertContains('sq-a', $names, 'the ad set under the requested campaign was dropped');
        $this->assertNotContains('sq-b', $names, "another campaign's ad set survived the parent filter");
    }

    /**
     * MONEY-USD-002 — every surface states the SAME unit for the same window.
     *
     * The harness above proves the figures match. A figure is only half a statement: 100 rendered under
     * «SAR» when it is 100 USD is a 3.75× error that looks entirely correct, and it is the one kind of
     * wrongness a reader cannot detect by looking. The client link is where it would survive longest —
     * read by somebody with no session, no second surface to compare against, and no way to ask.
     *
     * The metrics surfaces derive the unit from the rows themselves (`rangeCurrency`). A report carries
     * a STORED `currency` column instead. Those are two sources for one fact, which is the shape every
     * divergence in this file has taken, so the parity is asserted rather than assumed.
     */
    public function test_every_surface_states_the_same_currency_for_the_window(): void
    {
        $summary = $this->read('metrics/summary')->json('data.currency');

        $this->assertNotNull($summary, 'the dashboard did not say what its money is in');

        $stated = [
            'dashboard' => $summary,
            'entities' => $this->read('metrics/entities/ad_set')->json('data.currency'),
        ];

        // The generated report and the client link — the two surfaces furthest from the aggregator.
        $this->holdingTenant((string) $this->tenant->id);

        $report = Report::create([
            'project_id' => $this->project->id,
            'name' => 'Currency parity',
            'type' => 'executive',
            // Completed with a document, because an unpublished report has no client link to read —
            // the same shape the share test above uses.
            'status' => 'completed',
            // Stamped SAR at creation, which is what a report carries today: a STORED unit rather than
            // one derived from the rows. If the two can disagree, this is where it shows.
            'currency' => 'SAR',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'data' => ['kpis' => ['spend' => self::SPEND]],
        ]);

        $generated = app(ReportGenerator::class)->generate($report);
        $stated['report'] = $generated['currency'] ?? null;

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

        $stated['client_link'] = $this->getJson('/api/v1/reports/shared/'.$raw)
            ->assertOk()
            ->json('data.currency');

        foreach ($stated as $surface => $currency) {
            $this->assertSame(
                $summary,
                $currency,
                "«{$surface}» states {$currency} for a window the dashboard states {$summary} for — one of them is mislabelling real money",
            );
        }
    }

    /**
     * Content and Alerts read the same ingested window — and say nothing false when it is empty.
     *
     * Neither carries a spend total to reconcile, so the property is different and worth stating: a
     * sync that wrote campaign-grain rows and no creatives must produce an EMPTY creative library
     * rather than an error or an invented row, and the alert surface must answer for this workspace
     * alone.
     *
     * This is the half of propagation that is easy to get wrong in the other direction — a surface
     * that errors on an account with no creatives looks broken to a customer whose account is simply
     * new.
     */
    public function test_content_and_alerts_answer_for_this_project_without_inventing_rows(): void
    {
        // `data.creatives`, inside a paginated envelope — asserting a bare `data` array would be
        // asserting a shape this endpoint has never served, and would fail a working product.
        $creatives = $this->read('creatives')->assertOk();
        $this->assertIsArray($creatives->json('data.creatives'), 'the creative library did not answer');
        $this->assertSame([], $creatives->json('data.creatives'), 'a creative appeared for a sync that wrote none');
        $this->assertSame(0, $creatives->json('data.total'), 'the library counted creatives it did not return');

        /*
         * Alerts are workspace-scoped rather than project-scoped, so this asks the workspace route
         * and requires it to answer at all. The isolation that matters here is the tenant's, and the
         * neighbour's project belongs to a different CLIENT inside the same tenant — so an alert
         * naming their campaign would be the leak.
         */
        $alerts = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();
        $this->assertIsArray($alerts->json('data'), 'the alert surface did not answer');

        $names = array_column((array) $alerts->json('data'), 'title');
        $this->assertNotContains('حملة أخرى', $names, 'an alert named another client’s campaign');
    }

    /**
     * RECOMMENDATIONS: the screen and the digest agree, and neither publishes a retraction.
     *
     * This is the surface the harness had never reconciled. It carries no spend, so «the same figure
     * everywhere» is the wrong property — what propagates here is a set of human judgements, read by
     * two different services. `CampaignAnnotationController::projectIndex` builds the screen with a
     * join and an ordering of its own; `DigestRecommendations::forProject` builds the email with an
     * explicit tenant and project bound as values. Two readers, one table, and no reason they agree
     * beyond having been written to agree — which is exactly the shape this harness exists to catch.
     *
     * Three properties, and the third is the one that would embarrass a customer:
     *
     *   1. The two surfaces name the SAME approved recommendations.
     *   2. Neither carries the neighbouring client's, even though both clients live in this tenant.
     *   3. A recommendation that was never approved reaches the screen — where its status is visible
     *      and a reviewer can act on it — and NEVER the email. `hidden` and `rejected` are decisions
     *      to stop showing something, and an inbox is the one surface that cannot be retracted.
     */
    public function test_recommendations_reach_the_screen_and_the_email_as_the_same_set(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);

        $approved = $this->recommendation($this->project->id, $this->campaign->id, 'approved', 'ارفع ميزانية الحملة');
        $this->recommendation($this->project->id, $this->campaign->id, 'draft', 'مسودة لم تُراجع بعد');
        $this->recommendation($this->project->id, $this->campaign->id, 'rejected', 'اقتراح مرفوض');
        $neighbour = $this->recommendation(
            $this->otherProject->id,
            $this->otherCampaign->id,
            'approved',
            'توصية لعميل آخر',
        );

        app(TenantContext::class)->forget();

        $screen = (array) $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/recommendations?status=approved")
            ->assertOk()
            ->json('data');

        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $email = app(DigestRecommendations::class)->forProject(
            (string) $this->tenant->id,
            (string) $this->project->id,
            Carbon::today()->subDays(30),
            Carbon::today(),
        );
        app(TenantContext::class)->forget();

        $onScreen = array_column($screen, 'id');
        $inEmail = array_column($email, 'id');

        $this->assertSame([(string) $approved->id], $onScreen, 'the screen did not show the approved recommendation alone');
        $this->assertSame($onScreen, $inEmail, 'the screen and the digest disagree about which recommendations exist');

        // The neighbour, by id AND by title — an id match alone would pass a surface that leaked a
        // sentence while renumbering it.
        $this->assertNotContains((string) $neighbour->id, $inEmail, 'the digest carried another client’s recommendation');
        $this->assertNotContains('توصية لعميل آخر', array_column($email, 'title'));
        $this->assertNotContains('توصية لعميل آخر', array_column($screen, 'title'));

        // The retraction rule, stated as an assertion rather than as a docblock.
        $this->assertNotContains('مسودة لم تُراجع بعد', array_column($email, 'title'), 'a draft was mailed');
        $this->assertNotContains('اقتراح مرفوض', array_column($email, 'title'), 'a rejected recommendation was mailed');

        $unfiltered = array_column(
            (array) $this->actingAs($this->operator, 'sanctum')
                ->getJson("/api/v1/projects/{$this->project->id}/recommendations")
                ->assertOk()
                ->json('data'),
            'title',
        );
        $this->assertContains('مسودة لم تُراجع بعد', $unfiltered, 'the draft vanished from the screen too — a reviewer cannot act on what they cannot see');
    }

    /**
     * A generated REPORT carries the same window as the dashboard it was made from.
     *
     * The live link is already asserted above; a generated report is the other document a client
     * receives, and it is built by a different service (`ReportGenerator`) reading the same
     * aggregator. That is exactly the shape of divergence this harness exists to catch — «a page
     * grows its own query» applies to documents too, and a report is the copy a client keeps.
     */
    public function test_a_generated_report_carries_the_same_spend_as_the_dashboard(): void
    {
        $dashboard = (float) $this->read('metrics/summary')->json('data.current.spend');

        $this->holdingTenant((string) $this->tenant->id);

        $report = Report::create([
            'project_id' => $this->project->id,
            'name' => 'R2',
            'type' => 'executive',
            'status' => 'pending',
            'currency' => 'SAR',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'data' => [],
        ]);

        // `generate()` RETURNS the document; persisting it is the job's business, so the returned
        // array is what to assert — reading `$report->data` back would have tested the job instead.
        $generated = app(ReportGenerator::class)->generate($report);

        $kpis = (array) ($generated['kpis'] ?? []);
        $this->assertArrayHasKey('spend', $kpis, 'the generated report carries no spend at all');
        $this->assertSame(
            $dashboard,
            (float) $kpis['spend'],
            'the generated report disagrees with the dashboard it was made from',
        );

        app(TenantContext::class)->forget();
    }

    /**
     * The digest EMAIL reports the same money as the dashboard — the last surface in the chain.
     *
     * This is the one figure in the product that a person reads before they have opened anything:
     * it arrives on a lock screen, and it is what decides whether they log in at all. A digest that
     * disagrees with the dashboard sends somebody to look for a problem that is not there, or worse,
     * reassures them about one that is.
     *
     * `buildRange` over the report's own window rather than `build`'s rolling one, so the two are
     * asked about the SAME days — comparing a seven-day email against a July dashboard would be
     * comparing two different questions and calling the difference a defect.
     */
    public function test_the_digest_email_reports_the_same_spend_as_the_dashboard(): void
    {
        $dashboard = (float) $this->read('metrics/summary')->json('data.current.spend');

        $this->holdingTenant((string) $this->tenant->id);

        $digest = app(DailyDigest::class)->buildRange(
            $this->operator,
            (string) $this->tenant->id,
            [(string) $this->project->id],
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        );

        $this->assertSame(
            $dashboard,
            (float) ($digest['totals']['spend'] ?? -1),
            'the digest email disagrees with the dashboard',
        );

        // …and it does not sum the neighbour in, which is the same isolation the pages are held to.
        $this->assertNotSame(self::SPEND + self::OTHER_SPEND, (float) ($digest['totals']['spend'] ?? -1));

        app(TenantContext::class)->forget();
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /*
     * Named `read`, not `get`: `TestCase::get()` is public and PHP refuses to let a subclass
     * narrow it to private, so the whole file was a fatal error before it ran a single assertion.
     */
    /** One human-written recommendation, in a given lifecycle state. */
    private function recommendation(string $projectId, string $campaignId, string $status, string $title): CampaignAnnotation
    {
        return CampaignAnnotation::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $projectId,
            'campaign_id' => $campaignId,
            'kind' => 'recommendation',
            'status' => $status,
            'title' => $title,
            'priority' => 'high',
            'created_by' => $this->operator->getKey(),
        ]);
    }

    private function read(string $path, string $extra = ''): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/{$path}?".self::WINDOW.$extra)
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

    /**
     * Ad-set grain for THIS project, split across two campaigns so a parent filter has something to
     * exclude, and summing to exactly the campaign-grain spend the rest of the file asserts.
     *
     * Written straight to `entity_daily_metrics` because that is where the syncer puts entity grain —
     * `UpsertDailyMetrics` is the campaign-grain door. Using the campaign door here would prove the
     * drill-down agrees with a table it does not read.
     */
    private function entityRows(): void
    {
        $this->holdingTenant((string) $this->tenant->id);

        $half = round(self::SPEND / 2, 2);

        $this->campaignA = (string) Str::uuid();
        $this->campaignB = (string) Str::uuid();

        foreach ([[$this->campaignA, 'sq-a'], [$this->campaignB, 'sq-b']] as [$campaignExternalId, $adSetExternalId]) {
            (new EntityDailyMetric)->forceFill([
                'id' => (string) Str::uuid(),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'external_account_id' => $this->account->getKey(),
                'provider' => 'meta',
                'entity_type' => EntityDailyMetric::AD_SET,
                'entity_id' => (string) Str::uuid(),
                'external_entity_id' => $adSetExternalId,
                'external_campaign_id' => $campaignExternalId,
                'metric_date' => self::DATE,
                'attribution_window' => 'default',
                'is_demo' => false,
                'spend' => $half,
                'original_currency' => 'SAR',
                'project_currency' => 'SAR',
            ])->save();
        }

        app(TenantContext::class)->forget();
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
