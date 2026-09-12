<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * REPORT-OBJECTIVE-001/003 — awareness money never reaches a sales CPA.
 *
 * The scenario the requirement names, seeded literally: a high-spending awareness campaign with no
 * orders at all, a traffic campaign, and a sales campaign with real orders and revenue.
 *
 * The numbers are chosen so a mistake cannot hide in rounding. Sales spend 1000 over 50 orders is a
 * CPA of exactly 20. Blend the awareness campaign's 4000 and the traffic campaign's 1000 into the
 * numerator and it becomes 120 — six times the truth, on the single figure a client uses to decide
 * next month's budget. That is not a conservative estimate; it is a wrong answer, and this is the
 * test that says so.
 */
final class ObjectivePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-07-01';

    private const TO = '2026-07-31';

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create([
            'name' => 'Objective Co', 'slug' => 'objective-co', 'status' => 'active',
        ]);
        $this->holdingTenant((string) $this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'client-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'Project', 'status' => 'active',
        ]);

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@objective.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        // Every permission: what is under test is the arithmetic, not the gate. A partial grant here
        // produces a 403 that is correct and tells us nothing about whether awareness spend leaked.
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator->assignRole($role);
        // The ADVERTISER portal, whose access is not narrowed per client. An agency membership with
        // no client-scope rows reaches NO clients by design (ADR 0002), so it would be refused this
        // project — a correct refusal that has nothing to do with what is being tested here.
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $this->operator, tenant: $this->tenant, portal: Portal::App, role: 'owner',
        ));

        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );
        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(),
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => 'acct-'.uniqid(), 'name' => 'Ad account', 'currency' => 'SAR', 'status' => 'active',
        ]);

        // The three campaigns the requirement's test data names.
        $this->seedCampaign('حملة وعي', CampaignObjective::Awareness, spend: 4000, impressions: 2_000_000);
        $this->seedCampaign('حملة زيارات', CampaignObjective::Traffic, spend: 1000, clicks: 10_000);
        $this->seedCampaign('حملة مبيعات', CampaignObjective::Sales, spend: 1000, orders: 50, revenue: 10_000);

        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    private function read(array $query = []): TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/metrics/objective-performance?"
                // Defaults on the RIGHT: `+` keeps the left array's keys, so putting them first
                // would silently discard a caller's own window.
                .http_build_query($query + ['from' => self::FROM, 'to' => self::TO]))
            ->assertOk();
    }

    /** Acceptance case 1 — the one that makes the whole unit blocking. */
    public function test_the_sales_cpa_excludes_awareness_and_traffic_spend(): void
    {
        $direct = $this->read()->json('data.direct');

        $this->assertSame(1000.0, (float) $direct['spend'], 'the sales figure counted spend from another path');
        $this->assertSame(20.0, (float) $direct['cpa'], 'CPA is not sales spend ÷ sales orders');
        $this->assertSame(10.0, (float) $direct['roas'], 'ROAS is not sales revenue ÷ sales spend');

        // The blended figure of the same data. Stated here so the six-fold difference is on the
        // record: this is what the number would have been.
        $blended = $this->read()->json('data.blended');
        $this->assertSame(120.0, (float) $blended['blended_cpa']);
    }

    /**
     * Acceptance case 3 — both figures exist, under names that cannot be confused, and the blended
     * one says how much foreign spend it carries.
     */
    public function test_direct_and_blended_are_separate_and_separately_named(): void
    {
        $data = $this->read()->json('data');

        // `cpa` exists only inside `direct`. A top-level `cpa` would be a figure with no stated scope
        // — which is exactly how a blended number ends up being read as a direct one.
        $this->assertArrayNotHasKey('cpa', $data);
        $this->assertArrayNotHasKey('cpa', $data['blended']);
        $this->assertArrayHasKey('blended_cpa', $data['blended']);
        $this->assertSame(5000.0, (float) $data['blended']['includes_non_sales_spend']);
        $this->assertSame('sales-path spend ÷ sales-path orders', $data['direct']['formula']['cpa']);
    }

    /** Acceptance case 2 — the awareness path reports no cost per order, because it bought none. */
    /**
     * AGGREGATION-TRUTH-001 — a path's coverage has to describe the path it is attached to.
     *
     * Every path starts as `emptyPath()`, which states `no_contributors` because that is true of a
     * path nothing has been added to yet. Rows were then accumulated into it — spend, orders,
     * revenue, campaigns — and the coverage was never touched again.
     *
     * So on the owner's LIVE client report the conversion path carried 9,437.86 in spend and 566
     * orders while reporting that NOTHING had contributed to it. That is the inverse of the failure
     * the state exists to prevent: its own note says a surface can now say «no campaigns on this
     * path» instead of printing a row of zeros, and it was saying that over nine thousand of
     * somebody's money.
     */
    public function test_a_path_with_spend_does_not_report_that_nothing_contributed(): void
    {
        $paths = collect($this->read()->json('data.paths'));

        $conversion = $paths->firstWhere('path', 'conversion');

        $this->assertNotNull($conversion);
        $this->assertGreaterThan(0, (float) $conversion['spend'], 'the fixture must have conversion spend for this to mean anything');

        $this->assertNotSame(
            'no_contributors',
            $conversion['coverage']['state'],
            'a path with spend and orders reported that nothing contributed to it',
        );
        $this->assertNotEmpty(
            $conversion['coverage']['included_contributors'],
            'the coverage names no contributor for a path that has campaigns',
        );
    }

    /**
     * CLIENT-REPORT-ENTITY-BOUNDARY-001 — and what it names is a PLATFORM, never a campaign.
     *
     * The first version of the fix above recorded `unified_campaign_id`, and the client-link guard
     * refused it: «an internal id reached the client». It was right. A shared link carries
     * performance and never the campaign plan, and an id in a coverage list is the plan arriving
     * through a side door — readable in the JSON whatever the page chooses to draw.
     *
     * The provider is already published to a client in this same path's `platforms` breakdown, so
     * naming it costs nothing they cannot already see.
     */
    public function test_the_coverage_names_platforms_and_never_an_internal_id(): void
    {
        $paths = collect($this->read()->json('data.paths'));

        foreach ($paths as $path) {
            foreach ($path['coverage']['included_contributors'] as $contributor) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-/i',
                    (string) $contributor,
                    "«{$path['path']}» names an internal id in its coverage: {$contributor}",
                );
            }
        }

        /*
         * And it names a PROVIDER — a short lower-case key like `snapchat`, which a client already
         * sees in the platform breakdown — rather than anything with an internal shape.
         *
         * The provider name is not copied out of the fixture here: an earlier draft asserted
         * «snapchat» and failed on a seed that uses another platform, which proved nothing about the
         * rule and everything about the seed.
         */
        $conversion = $paths->firstWhere('path', 'conversion');

        foreach ($conversion['coverage']['included_contributors'] as $contributor) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]{2,30}$/',
                (string) $contributor,
                "the coverage names «{$contributor}», which is not a provider key",
            );
        }
    }

    /** ...and a path nothing ran on still says so, which is the state's whole purpose. */
    public function test_a_path_nothing_ran_on_still_reports_no_contributors(): void
    {
        $paths = collect($this->read()->json('data.paths'));

        foreach ($paths as $path) {
            if ((float) $path['spend'] === 0.0 && count($path['campaigns']) === 0) {
                $this->assertSame(
                    'no_contributors',
                    $path['coverage']['state'],
                    "«{$path['path']}» has nothing on it and no longer says so",
                );

                return;
            }
        }

        $this->markTestSkipped('This fixture has a contributor on every path, so the empty state cannot be checked here.');
    }

    public function test_the_awareness_path_reports_no_cost_per_order(): void
    {
        $paths = collect($this->read()->json('data.paths'))->keyBy('path');

        $awareness = $paths[MarketingPath::Awareness->value];
        $this->assertSame(4000.0, (float) $awareness['spend']);
        $this->assertSame(0.0, (float) $awareness['orders']);
        // Null, not 0. A zero CPA reads as «orders here are free», which is a claim; null is the
        // absence of one.
        $this->assertNull($awareness['cpa']);
        $this->assertNull($awareness['roas']);
        $this->assertSame(2.0, (float) $awareness['cpm'], 'awareness CPM is its own spend ÷ its own impressions × 1000');

        // …and it leads with the metrics that mean something for money spent on attention.
        $this->assertSame(['spend', 'impressions', 'reach', 'frequency', 'cpm'], $awareness['headline_metrics']);
    }

    /** Every excluded campaign is named, with its spend and the reason — the metric is auditable. */
    public function test_the_direct_figure_names_what_it_left_out(): void
    {
        $direct = $this->read()->json('data.direct');

        $this->assertCount(1, $direct['included_campaigns']);
        $this->assertSame('حملة مبيعات', $direct['included_campaigns'][0]['name']);

        $excluded = collect($direct['excluded_campaigns'])->keyBy('name');
        $this->assertSame(4000.0, (float) $excluded['حملة وعي']['spend']);
        $this->assertSame('not_a_sales_objective', $excluded['حملة وعي']['reason']);
        $this->assertSame(1000.0, (float) $excluded['حملة زيارات']['spend']);
    }

    /** Acceptance case 5 — narrowing the scope removes a campaign's spend AND its results. */
    public function test_choosing_campaigns_changes_every_figure(): void
    {
        $salesId = UnifiedCampaign::where('name', 'حملة مبيعات')->value('id');

        $data = $this->read(['campaign_ids' => [$salesId]])->json('data');

        $this->assertSame(1000.0, (float) $data['blended']['spend'], 'an excluded campaign still reached the blend');
        $this->assertSame(0.0, (float) $data['blended']['includes_non_sales_spend']);
        // With only the sales campaign in scope the two figures coincide — and they are still two
        // figures, reported under their own names.
        $this->assertSame((float) $data['direct']['cpa'], (float) $data['blended']['blended_cpa']);
    }

    /** A leads campaign is a conversion, and its spend is not a cost of SALES. */
    public function test_a_lead_campaign_is_a_conversion_but_not_a_sale(): void
    {
        $this->assertSame(MarketingPath::Conversion, CampaignObjective::Leads->path());
        $this->assertFalse(CampaignObjective::Leads->isSales());

        $this->seedCampaign('حملة عملاء محتملين', CampaignObjective::Leads, spend: 2000);

        $data = $this->read()->json('data');

        // Counting lead spend against store revenue would flatter ROAS by the whole cost of the
        // lead programme.
        $this->assertSame(1000.0, (float) $data['direct']['spend']);
        $this->assertSame(10.0, (float) $data['direct']['roas']);
    }

    /** An unclassified objective is treated as not-a-sale, so the error can only understate CPA. */
    public function test_an_unknown_objective_never_inflates_the_cost_per_order(): void
    {
        $this->seedCampaign('حملة بلا تصنيف', CampaignObjective::Other, spend: 9000);

        $direct = $this->read()->json('data.direct');

        $this->assertSame(20.0, (float) $direct['cpa']);
        $this->assertSame(MarketingPath::Awareness, CampaignObjective::Other->path());
    }

    /** Nothing in scope is an absent figure, never a zero dressed as a result. */
    public function test_an_empty_window_reports_nothing_rather_than_zero(): void
    {
        $data = $this->read(['from' => '2020-01-01', 'to' => '2020-01-31'])->json('data');

        $this->assertSame(0.0, (float) $data['direct']['spend']);
        $this->assertNull($data['direct']['cpa']);
        $this->assertNull($data['blended']['blended_cpa']);
    }

    /**
     * The generated report carries the split, and its shared link passes it to the client.
     *
     * The engine being right is not the deliverable — the report is. Before this, the snapshot's
     * `kpis.cpa` was the only cost-per-order in it, and that figure divides EVERY campaign's spend
     * by the sales campaigns' orders: 6000 ÷ 50 = 120 against a real cost of 20. A client reading
     * the report had no way to know which of the two they were looking at, because only one was
     * there.
     */
    public function test_the_generated_report_and_its_client_link_keep_the_two_figures_apart(): void
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'تقرير المبيعات — يوليو',
            'type' => 'monthly',
            'form' => 'executive_summary',
            'status' => 'processing',
            'period_start' => self::FROM,
            'period_end' => self::TO,
            'currency' => 'SAR',
        ]);

        (new GenerateReportJob((string) $report->id))
            ->handle(app(ReportGenerator::class));

        $data = $report->refresh()->data;
        $this->assertSame('completed', $report->status);

        // The blended figure the report used to print unqualified…
        $this->assertSame(120.0, round((float) $data['kpis']['cpa'], 2));
        // …beside the one that answers what an order actually costs.
        $this->assertSame(20.0, (float) $data['objective_performance']['direct']['cpa']);
        $this->assertSame(120.0, (float) $data['objective_performance']['blended']['blended_cpa']);
        $this->assertSame(5000.0, (float) $data['objective_performance']['blended']['includes_non_sales_spend']);

        /*
         * Immediately AFTER the executive summary it qualifies — asserted as adjacency, not as index 3.
         *
         * The position was pinned to the fourth slot, which was only true while every report carried
         * the same eleven sections. REPORT-DEPTH-001 gives the executive form a shorter deck — it
         * drops the recommendations — so the section it must follow moved up one and this read
         * «budget». The claim in the comment above was always about ADJACENCY: a reader must meet the
         * blended-versus-direct split before they act on the headline, whatever else the deck holds.
         */
        $types = array_column($data['slides'], 'type');
        $this->assertSame(
            array_search('executive_summary', $types, true) + 1,
            array_search('objective_performance', $types, true),
            'the objective split no longer sits immediately after the summary it qualifies',
        );

        // …and it survives into the five-page summary a client is sent, which is the version that
        // gets forwarded and quoted with no per-platform pages behind it to argue with.
        [, $token] = app(ShareService::class)->create($report, [], $this->operator->id);
        $shared = $this->getJson("/api/v1/reports/shared/{$token}")->assertOk();

        $this->assertContains('objective_performance', array_column($shared->json('data.data.slides'), 'type'));
        $this->assertSame(20.0, (float) $shared->json('data.data.objective_performance.direct.cpa'));
    }

    /** Every objective in the catalogue lands in exactly one path — no case falls through. */
    public function test_every_objective_belongs_to_one_path(): void
    {
        foreach (CampaignObjective::cases() as $objective) {
            $this->assertContains($objective->path()->value, MarketingPath::values());
        }

        $this->assertCount(count(CampaignObjective::cases()), CampaignObjective::catalogue());
    }

    /**
     * Correcting an objective is recorded as a review, and the correction moves the money
     * (REPORT-OBJECTIVE-002).
     *
     * A campaign misclassified as `sales` puts its whole spend in the client's cost per order. The
     * fix has to be one edit by an authorised person — and it has to leave a trail, because this is
     * the field that decides which figure a client acts on.
     */
    public function test_correcting_an_objective_is_audited_and_changes_the_figures(): void
    {
        $misfiled = UnifiedCampaign::where('name', 'حملة وعي')->first();
        $misfiled->forceFill(['objective' => CampaignObjective::Sales->value])->save();

        // Misfiled, its 4000 now sits in the sales CPA: 5000 ÷ 50 = 100 rather than 20.
        $this->assertSame(100.0, (float) $this->read()->json('data.direct.cpa'));

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/campaigns/{$misfiled->id}", [
                'name' => $misfiled->name,
                'objective' => CampaignObjective::Awareness->value,
            ])
            ->assertOk();

        $this->assertSame(20.0, (float) $this->read()->json('data.direct.cpa'));

        $misfiled->refresh();
        // Set by the server, never accepted from the request — a caller must not be able to claim
        // its own classification came from the platform.
        $this->assertSame('manual', $misfiled->objective_source);
        $this->assertSame($this->operator->id, $misfiled->objective_corrected_by);
        $this->assertNotNull($misfiled->objective_corrected_at);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'campaign.objective.corrected',
            'entity_id' => (string) $misfiled->id,
        ]);
    }

    /** An edit that leaves the objective alone is not a review, and must not claim to be one. */
    public function test_an_unrelated_edit_does_not_stamp_the_objective_as_reviewed(): void
    {
        $campaign = UnifiedCampaign::where('name', 'حملة مبيعات')->first();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->project->id}/campaigns/{$campaign->id}", ['name' => 'حملة مبيعات — الصيف'])
            ->assertOk();

        $this->assertSame('unset', $campaign->refresh()->objective_source);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'campaign.objective.corrected']);
    }

    /**
     * CROSS-PLATFORM-ATTRIBUTION-DEPTH-001 — one path, two kinds of result, and the blend declared.
     *
     * Owner rule: «never combine Purchases/Leads/Installs/Registrations/Conversations into one
     * Results number.» `CampaignObjective::path()` files Leads, App installs, Add to cart, Sales,
     * Conversions and Purchases on the SAME conversion path, so their orders were summed and `cpa`
     * divided the path's whole spend by the total — a cost per result blending the price of a lead
     * with the price of a sale, on a figure `LiveDetailTables` and `PrintDocument` show a client.
     *
     * The setup already sells 50 orders for 1000. Adding a lead programme — 2000 for 400 leads —
     * makes the arithmetic damning: the true cost of a sale is 20, the true cost of a lead is 5, and
     * the blend reads 6.67. The blend is not removed, because callers sum the aggregate and the
     * ratio is honestly recomputed from it; what it may no longer do is travel without its parts.
     */
    public function test_a_path_carrying_two_kinds_of_result_declares_what_the_number_is_made_of(): void
    {
        $this->seedCampaign('حملة عملاء محتملين', CampaignObjective::Leads, spend: 2000, orders: 400);

        $conversion = collect($this->read()->json('data.paths'))
            ->firstWhere('path', MarketingPath::Conversion->value);

        $this->assertTrue($conversion['results_mixed'], 'a path holding leads and sales did not say so');
        $this->assertTrue($conversion['cpa_mixes_result_types'], 'the blended cost per result was not declared');

        // The blend itself, unchanged: 3000 spent over 450 results.
        $this->assertSame(450.0, (float) $conversion['orders']);
        $this->assertSame(6.67, (float) $conversion['cpa']);

        // And what it is made of — largest first, each with its own count, under its own label.
        $this->assertSame(
            [['objective' => 'leads', 'orders' => 400.0], ['objective' => 'sales', 'orders' => 50.0]],
            array_map(
                static fn (array $part): array => ['objective' => $part['objective'], 'orders' => (float) $part['orders']],
                $conversion['result_composition'],
            ),
        );

        $labels = array_column($conversion['result_composition'], 'label_ar');
        $this->assertNotEmpty(array_filter($labels), 'a part of the blend reached the payload with no Arabic label');
    }

    /**
     * The vacuity check. A path whose results are all one kind is the ordinary case and must NOT be
     * decorated with a warning — a flag that is always true tells a reader nothing, and would put
     * «this mixes different results» under every honest cost per sale in the product.
     */
    public function test_a_path_with_one_kind_of_result_is_not_called_mixed(): void
    {
        $conversion = collect($this->read()->json('data.paths'))
            ->firstWhere('path', MarketingPath::Conversion->value);

        $this->assertFalse($conversion['results_mixed'], 'a path holding only sales was called mixed');
        $this->assertFalse($conversion['cpa_mixes_result_types']);
        $this->assertSame(20.0, (float) $conversion['cpa'], 'the unblended cost per sale changed');
        $this->assertSame(
            ['sales'],
            array_column($conversion['result_composition'], 'objective'),
        );
    }

    /**
     * An objective that ran and converted nobody is not part of what the number is made of.
     *
     * Listing it at zero would invite a reader to divide by it, and would make `results_mixed` true
     * for a path with exactly one real kind of result — which is the false positive the check above
     * exists to prevent, arrived at from the other side.
     */
    public function test_an_objective_that_produced_no_result_is_not_part_of_the_composition(): void
    {
        $this->seedCampaign('حملة تثبيتات بلا نتيجة', CampaignObjective::AppInstalls, spend: 700, orders: 0);

        $conversion = collect($this->read()->json('data.paths'))
            ->firstWhere('path', MarketingPath::Conversion->value);

        $this->assertSame(['sales'], array_column($conversion['result_composition'], 'objective'));
        $this->assertFalse($conversion['results_mixed']);
        // Its spend is still on the path — the money was spent — so the cost per sale rises honestly.
        $this->assertSame(1700.0, (float) $conversion['spend']);
    }

    private function seedCampaign(
        string $name,
        CampaignObjective $objective,
        float $spend = 0,
        float $impressions = 0,
        float $clicks = 0,
        float $orders = 0,
        float $revenue = 0,
    ): void {
        $this->holdingTenant((string) $this->tenant->id);

        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective->value,
            'total_budget' => 10_000, 'budget_currency' => 'SAR',
        ]);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $this->account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => 'meta', 'external_id' => 'ext-'.uniqid(), 'name' => $name, 'status' => 'active',
        ]);

        foreach ([
            // `conversions` is the product's one definition of an order — the same key the
            // dashboard, the analytics breakdowns and the report's own `kpis` divide spend by.
            'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks,
            'conversions' => $orders, 'revenue' => $revenue,
        ] as $key => $value) {
            if ($value === 0.0) {
                continue;
            }

            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $this->account->getKey(), 'external_campaign_id' => $external->id,
                'unified_campaign_id' => $campaign->id, 'provider' => 'meta',
                'metric_key' => $key, 'metric_date' => Carbon::parse('2026-07-10')->toDateString(),
                'value' => $value,
            ]);
        }
    }
}
