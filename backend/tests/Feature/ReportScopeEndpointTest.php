<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * §14.5 over HTTP — choosing a report's scope, editing it in place, and saving it to reuse.
 *
 * `ReportScopeTest` proves the narrowing arithmetic. This class proves the three things only the
 * endpoints can:
 *
 *   1. **Excluding a campaign takes its spend and its results out of EVERY section** — the KPI cards,
 *      the platform table, the campaign list, the funnel and the Direct/Blended split. This is the
 *      acceptance case «استبعاد حملة والتأكد من خروج إنفاقها ونتائجها من جميع البطاقات والرسوم
 *      والجداول», and it is the one that catches a scope honoured in four places and forgotten in a
 *      fifth.
 *   2. **A scope is edited on the report, not by making a second one** — the same id, the same link,
 *      new bounds. Creating a new report would leave the client holding the old link, still live and
 *      now wrong.
 *   3. **An id that is not this project's is refused rather than stored**, and refusing it never
 *      quietly reopens the axis it was on.
 */
final class ReportScopeEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $project;

    private UnifiedCampaign $sales;

    private UnifiedCampaign $awareness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Scope HTTP', 'slug' => 'scope-http', 'status' => 'active']);
        // Held for the whole case: this class both creates tenant-scoped rows directly and issues
        // HTTP calls, and a request that ends by clearing the context would leave the assertions
        // reading through no tenant at all.
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@scope.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(),
            'name' => 'P', 'status' => 'active',
        ]);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->sales = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Sales', 'objective' => 'sales', 'status' => 'active',
        ]);
        $this->awareness = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'client_workspace_id' => $client->getKey(), 'name' => 'Awareness', 'objective' => 'awareness', 'status' => 'active',
        ]);

        // Real accounts behind the metrics: the options endpoint resolves account NAMES, and ids
        // that exist nowhere would make it answer with an empty picker for a project that has spend.
        $this->accounts = [
            'meta' => (string) $this->account($client, 'meta')->getKey(),
            'tiktok' => (string) $this->account($client, 'tiktok')->getKey(),
        ];

        // The sales campaign sells; the awareness campaign only spends. Excluding the second must
        // change the spend everywhere and leave the orders alone.
        $this->metrics($this->sales, 'meta', ['spend' => 1000.0, 'impressions' => 50000.0, 'clicks' => 900.0, 'conversions' => 50.0, 'revenue' => 6000.0]);
        $this->metrics($this->awareness, 'tiktok', ['spend' => 500.0, 'impressions' => 900000.0, 'clicks' => 400.0]);
    }

    /** @var array<string, string> provider => ad account id */
    private array $accounts = [];

    private function account(ClientWorkspace $client, string $provider): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $connection = ProviderConnection::create([
            'credential_id' => $credential->getKey(), 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::create([
            'tenant_id' => $this->tenant->getKey(),
            'client_workspace_id' => $client->getKey(),
            'provider_connection_id' => $connection->getKey(),
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => 'acct-'.$provider, 'name' => ucfirst($provider).' Ads',
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    /** @param array<string, float> $values */
    private function metrics(UnifiedCampaign $campaign, string $provider, array $values): void
    {
        $rows = [];
        foreach ($values as $key => $value) {
            $rows[] = new NormalizedMetric(
                tenantId: (string) $this->tenant->getKey(),
                projectId: (string) $this->project->getKey(),
                externalAccountId: $this->accounts[$provider],
                externalCampaignId: (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'scope-http:camp:'.$campaign->name),
                provider: $provider,
                metricKey: $key,
                metricDate: Carbon::parse('2026-07-15'),
                value: $value,
                unifiedCampaignId: (string) $campaign->getKey(),
            );
        }

        app(UpsertDailyMetrics::class)->handle($rows);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports".$suffix;
    }

    private function report(?array $scope = null): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'monthly', 'form' => 'detailed', 'status' => 'draft',
            'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'currency' => 'SAR',
            'scope' => $scope,
        ]);
    }

    /** Generate a report's snapshot the way the queue does. */
    private function generate(Report $report): array
    {
        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));

        return (array) $report->refresh()->data;
    }

    /** The picker is offered what this project actually has data for. */
    public function test_the_options_endpoint_offers_this_projects_own_choices(): void
    {
        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope/options'))
            ->assertOk()
            ->json('data');

        $this->assertEqualsCanonicalizing(['meta', 'tiktok'], $data['providers']);
        $this->assertCount(2, $data['campaigns']);
        $this->assertNotEmpty($data['accounts']);
        $this->assertNotEmpty($data['objectives']);
        $this->assertNotEmpty($data['paths']);

        // The picker is told what each axis can bound, so it can say so beside the deeper ones.
        $this->assertContains('ad_set_ids', $data['grain']['resolved_to_campaign']);
        $this->assertContains('providers', $data['grain']['figures']);
    }

    /**
     * THE acceptance case: an excluded campaign leaves every card, chart and table.
     *
     * Asserted section by section rather than on the headline total, because a scope applied to the
     * KPIs and forgotten by the campaign list produces a report whose parts do not add up to its own
     * total — and that is a defect a reader discovers in front of their client.
     */
    public function test_excluding_a_campaign_removes_its_spend_and_results_from_every_section(): void
    {
        $whole = $this->generate($this->report());
        $this->assertEqualsWithDelta(1500.0, $whole['kpis']['spend'], 0.01);

        $narrowed = $this->generate($this->report([
            'campaign_ids' => [(string) $this->sales->getKey()],
        ]));

        // 1. The KPI cards.
        $this->assertEqualsWithDelta(1000.0, $narrowed['kpis']['spend'], 0.01);
        $this->assertEqualsWithDelta(50000.0, $narrowed['kpis']['impressions'], 0.01);

        // 2. The platform table — the excluded campaign's platform is gone entirely.
        $providers = array_column($narrowed['platforms'], 'provider');
        $this->assertSame(['meta'], $providers);

        // 3. The campaign list.
        $this->assertCount(1, $narrowed['campaigns']);
        $this->assertSame('Sales', $narrowed['campaigns'][0]['campaign_name']);

        // 4. The funnel, and the spend it divides by.
        $this->assertEqualsWithDelta(1000.0, $narrowed['funnel_spend'], 0.01);

        // 5. The Direct/Blended split — the section a reader checks when a headline looks wrong.
        $this->assertEqualsWithDelta(1000.0, $narrowed['objective_performance']['blended']['spend'], 0.01);
        $this->assertEqualsWithDelta(0.0, $narrowed['objective_performance']['blended']['includes_non_sales_spend'], 0.01);
    }

    /**
     * With the awareness campaign in scope, Blended and Direct differ — and the split says why.
     *
     * The pair of assertions matters more than either alone: it proves the exclusion above changed
     * the numbers because the campaign left, not because the section stopped computing.
     */
    public function test_the_split_still_separates_the_two_figures_inside_a_scope(): void
    {
        $data = $this->generate($this->report(['providers' => ['meta', 'tiktok']]));
        $op = $data['objective_performance'];

        $this->assertEqualsWithDelta(1000.0, $op['direct']['spend'], 0.01);
        $this->assertEqualsWithDelta(20.0, $op['direct']['cpa'], 0.01);      // 1000 ÷ 50
        $this->assertEqualsWithDelta(30.0, $op['blended']['blended_cpa'], 0.01); // 1500 ÷ 50
        $this->assertEqualsWithDelta(500.0, $op['blended']['includes_non_sales_spend'], 0.01);
    }

    /** A platform axis narrows the split too — the slide cannot contradict the cards above it. */
    public function test_a_platform_scope_narrows_the_objective_split(): void
    {
        $data = $this->generate($this->report(['providers' => ['meta']]));

        $this->assertEqualsWithDelta(1000.0, $data['kpis']['spend'], 0.01);
        $this->assertEqualsWithDelta(1000.0, $data['objective_performance']['blended']['spend'], 0.01);
    }

    /**
     * The scope is edited ON the report: same id, same link, new bounds, regenerated.
     */
    public function test_a_reports_scope_is_edited_in_place_and_the_report_regenerates(): void
    {
        $report = $this->report();

        $response = $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['providers' => ['meta'], 'campaign_ids' => [(string) $this->sales->getKey()]],
            ])
            ->assertOk();

        $this->assertSame((string) $report->getKey(), $response->json('data.id'));
        $this->assertSame('processing', $response->json('data.status'));
        $this->assertSame(1, Report::query()->where('project_id', $this->project->getKey())->count());

        $stored = $report->refresh()->scope;
        $this->assertSame(['meta'], $stored['providers']);
        $this->assertSame([(string) $this->sales->getKey()], $stored['campaign_ids']);

        // …and the regenerated snapshot honours it.
        $this->assertEqualsWithDelta(1000.0, $this->generate($report)['kpis']['spend'], 0.01);
    }

    /** Clearing the scope is expressible, and returns the report to the whole project. */
    public function test_clearing_a_scope_returns_the_report_to_the_whole_project(): void
    {
        $report = $this->report(['providers' => ['meta']]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), ['scope' => []])
            ->assertOk();

        $this->assertNull($report->refresh()->scope);
        $this->assertEqualsWithDelta(1500.0, $this->generate($report)['kpis']['spend'], 0.01);
    }

    /**
     * An id from outside this project is dropped — and dropping it does NOT reopen the axis.
     *
     * This is the fail-closed half of the rule. Filtering a foreign campaign id down to an empty list
     * would leave `campaign_ids` unbounded, so a scope an operator set deliberately would silently
     * become «the whole project» — the widening this unit exists to make impossible.
     */
    public function test_a_foreign_id_is_dropped_without_reopening_the_axis(): void
    {
        $report = $this->report();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['campaign_ids' => [(string) Uuid::uuid4()]],
            ])
            ->assertOk();

        $this->assertSame([ReportScope::IMPOSSIBLE], $report->refresh()->scope['campaign_ids']);
        $this->assertEqualsWithDelta(0.0, $this->generate($report)['kpis']['spend'], 0.01);
    }

    /** An unknown objective is refused outright rather than stored and ignored. */
    public function test_an_unknown_objective_is_refused(): void
    {
        $report = $this->report();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['objectives' => ['make_me_rich']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('scope.objectives.0');
    }

    /** A scope can be saved, listed and applied again — the reusable half of §14.5. */
    public function test_a_scope_can_be_saved_as_a_template_and_read_back(): void
    {
        $created = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/scope-templates'), [
                'name' => 'مسار المبيعات — شهري',
                'description' => 'حملات المبيعات على ميتا',
                'scope' => ['paths' => ['conversion'], 'providers' => ['meta']],
            ])
            ->assertCreated();

        $this->assertFalse($created->json('data.shared'));
        $this->assertEqualsCanonicalizing(['providers', 'paths'], $created->json('data.bound_axes'));

        $listed = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope-templates'))
            ->assertOk()
            ->json('data.templates');

        $this->assertCount(1, $listed);
        $this->assertSame('مسار المبيعات — شهري', $listed[0]['name']);
    }

    /**
     * A template meant for every client may not name one client's campaigns.
     *
     * Applied elsewhere its campaign axis would resolve to nothing and the report would come back
     * empty — which reads as a data problem rather than as a template that never applied.
     */
    public function test_a_shared_template_cannot_name_project_specific_selections(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/scope-templates'), [
                'name' => 'Reusable',
                'shared' => true,
                'scope' => ['campaign_ids' => [(string) $this->sales->getKey()]],
            ])
            ->assertStatus(422);

        $this->assertSame(0, ReportScopeTemplate::query()->count());
    }

    /** A shared template naming only platform-independent axes is accepted, and listed everywhere. */
    public function test_a_shared_template_of_paths_and_platforms_is_accepted(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/scope-templates'), [
                'name' => 'Awareness only',
                'shared' => true,
                'scope' => ['paths' => ['awareness']],
            ])
            ->assertCreated()
            ->assertJsonPath('data.shared', true);

        $this->assertNull(ReportScopeTemplate::query()->first()->project_id);
    }

    /** Editing a template does not touch the reports already built from it. */
    public function test_editing_a_template_leaves_existing_reports_alone(): void
    {
        $template = ReportScopeTemplate::create([
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'name' => 'T', 'scope' => ['providers' => ['meta']],
        ]);

        $report = $this->report(['providers' => ['meta']]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/scope-templates/{$template->getKey()}"), [
                'scope' => ['providers' => ['tiktok']],
            ])
            ->assertOk();

        $this->assertSame(['meta'], $report->refresh()->scope['providers']);
    }

    /** Reading a scope back explains what each axis reaches, for the report's own «what this covers». */
    public function test_reading_a_scope_back_explains_what_each_axis_reaches(): void
    {
        $report = $this->report(['providers' => ['meta'], 'paths' => ['conversion']]);

        $explain = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url("/{$report->getKey()}/scope"))
            ->assertOk()
            ->json('data.explain');

        $grains = array_column($explain, 'grain', 'axis');
        $this->assertSame('figures', $grains['providers']);
        $this->assertSame('figures', $grains['paths']);
    }

    /** Without `reports.view` none of it is reachable. */
    public function test_the_scope_endpoints_need_permission(): void
    {
        $stranger = User::create([
            'name' => 'S', 'email' => 's@scope.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $this->tenant);

        $report = $this->report();

        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/scope/options'))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')->getJson($this->url('/scope-templates'))->assertForbidden();
        $this->actingAs($stranger, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), ['scope' => []])
            ->assertForbidden();
    }
}
