<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
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
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Support\DetailLevel;
use App\Domains\Reports\Support\ReportScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
    /** A platform campaign behind each unified one, and where the sales campaign's ad set sends people. */
    private function structure(): void
    {
        foreach ([['meta', $this->sales, 'WHATSAPP'], ['tiktok', $this->awareness, null]] as [$provider, $unified, $destination]) {
            $external = new ExternalCampaign;
            $external->forceFill([
                'id' => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'scope-http:ext:'.$unified->name),
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'unified_campaign_id' => $unified->getKey(),
                'external_account_id' => $this->accounts[$provider],
                'external_id' => 'x-'.$unified->name,
                'name' => $unified->name,
                'provider' => $provider,
                'status' => 'active',
            ])->save();

            if ($destination !== null) {
                $set = new ExternalAdSet;
                $set->forceFill([
                    'tenant_id' => $this->tenant->getKey(),
                    'project_id' => $this->project->getKey(),
                    'external_campaign_id' => $external->getKey(),
                    'unified_campaign_id' => $unified->getKey(),
                    'provider' => $provider,
                    'external_id' => 'as-'.$unified->name,
                    'name' => 'Set',
                    'status' => 'active',
                    'destination_type' => $destination,
                ])->save();
            }
        }
    }

    private function optionNames(array $query): array
    {
        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope/options?'.http_build_query($query)))
            ->assertOk()
            ->json('data');

        return [array_column($data['campaigns'], 'name'), $data];
    }

    /**
     * REPORT-SETUP-001 — each choice narrows the lists below it, on the server.
     */
    public function test_the_provider_narrows_accounts_and_campaigns(): void
    {
        $this->structure();

        [$names, $data] = $this->optionNames(['providers' => ['meta']]);

        $this->assertSame(['Sales'], $names);
        $this->assertSame(['meta'], array_values(array_unique(array_column($data['accounts'], 'provider'))));
    }

    public function test_the_account_narrows_campaigns(): void
    {
        $this->structure();

        [$names] = $this->optionNames(['account_ids' => [$this->accounts['tiktok']]]);

        $this->assertSame(['Awareness'], $names);
    }

    public function test_the_objective_narrows_campaigns(): void
    {
        $this->structure();

        [$names] = $this->optionNames(['objectives' => ['awareness']]);

        $this->assertSame(['Awareness'], $names);
    }

    public function test_the_destination_narrows_campaigns_and_is_offered_with_its_count(): void
    {
        $this->structure();

        [$names, $data] = $this->optionNames(['destinations' => ['whatsapp']]);

        $this->assertSame(['Sales'], $names);
        $this->assertSame('whatsapp', $data['campaigns'][0]['destination']);

        $offered = array_column($data['destinations'], null, 'key');
        $this->assertSame(1, $offered['whatsapp']['campaign_count']);
        $this->assertTrue($offered['whatsapp']['messaging']);
        $this->assertArrayNotHasKey('website', $offered, 'a destination nobody uses was offered');
    }

    public function test_combined_filters_intersect(): void
    {
        $this->structure();

        [$names] = $this->optionNames(['providers' => ['tiktok'], 'destinations' => ['whatsapp']]);

        $this->assertSame([], $names, 'a campaign the choices above it exclude was offered');
    }

    public function test_a_chosen_destination_is_persisted_and_bounds_the_report_to_its_campaigns(): void
    {
        $this->structure();

        $scope = ReportScope::fromArray(['destinations' => ['whatsapp'], 'project_ids' => [(string) $this->project->getKey()]]);

        $this->assertSame(['whatsapp'], $scope->toArray()['destinations']);
        $this->assertSame([(string) $this->sales->getKey()], $scope->resolvedCampaignIds());
    }

    public function test_an_unknown_destination_is_dropped_rather_than_widening_the_report(): void
    {
        $scope = ReportScope::fromArray(['destinations' => ['carrier_pigeon']]);

        $this->assertArrayNotHasKey('destinations', $scope->toArray());
    }

    /**
     * REPORT-DETAIL-LEVEL-001 — «مختصر», «قياسي», «تفصيلي» each change what the report contains.
     */
    private function createWith(array $extra): Report
    {
        Queue::fake();

        $id = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url(), array_merge([
                'name' => 'تقرير هنكا',
                'type' => 'executive',
                'period_start' => '2026-07-01',
                'period_end' => '2026-07-31',
                'scope' => ['account_ids' => array_values($this->accounts)],
            ], $extra))
            ->assertCreated()
            ->json('data.id');

        return Report::withoutGlobalScopes()->findOrFail($id);
    }

    public function test_a_summary_level_report_is_the_executive_form_without_the_deep_sections(): void
    {
        $report = $this->createWith(['detail_level' => 'summary']);

        $this->assertSame('executive_summary', $report->form);
        $this->assertSame('summary', $report->config['detail_level']);
        $sections = $report->section_settings['sections'];
        foreach (['objective_breakdown', 'detailed_tables', 'funnel', 'advanced_segmentation', 'account_breakdown'] as $off) {
            $this->assertFalse($sections[$off], "{$off} stayed on in a summary");
        }
        $this->assertTrue($sections['kpis']);
        $this->assertTrue($sections['content_performance']);
    }

    public function test_a_standard_level_adds_objective_and_campaign_breakdown_but_not_the_deep_sections(): void
    {
        $report = $this->createWith(['detail_level' => 'standard']);

        $this->assertSame('detailed', $report->form);
        $sections = $report->section_settings['sections'];
        $this->assertTrue($sections['objective_breakdown']);
        $this->assertTrue($sections['detailed_tables']);
        $this->assertFalse($sections['funnel']);
        $this->assertFalse($sections['advanced_segmentation']);
    }

    public function test_a_detailed_level_switches_the_deep_sections_on(): void
    {
        $sections = $this->createWith(['detail_level' => 'detailed'])->section_settings['sections'];

        foreach (['objective_breakdown', 'detailed_tables', 'funnel', 'budget_pacing', 'advanced_segmentation', 'account_breakdown'] as $on) {
            $this->assertTrue($sections[$on], "{$on} was left out of a detailed report");
        }
    }

    public function test_the_owners_own_section_switches_win_over_the_level(): void
    {
        $report = $this->createWith([
            'detail_level' => 'detailed',
            'section_settings' => ['sections' => ['funnel' => false, 'recommendations' => false]],
        ]);

        $sections = $report->section_settings['sections'];
        $this->assertFalse($sections['funnel']);
        $this->assertFalse($sections['recommendations']);
        $this->assertTrue($sections['advanced_segmentation']);
    }

    public function test_a_link_report_carries_its_level_into_the_form_and_the_sections_it_leaves_out(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url('/live'), [
                'name' => 'رابط هنكا',
                'account_ids' => array_values($this->accounts),
                'from' => '2026-07-01',
                'to' => '2026-07-31',
                'detail_level' => 'summary',
            ])
            ->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->findOrFail($res->json('data.share_id'));
        $report = Report::withoutGlobalScopes()->findOrFail($res->json('data.report_id'));

        $this->assertSame('summary', $report->config['detail_level']);
        $this->assertSame('executive_summary', $share->form);
        $this->assertEqualsCanonicalizing(
            ['objective_breakdown', 'detailed_tables', 'funnel', 'budget_pacing', 'advanced_segmentation', 'account_breakdown'],
            (array) ($share->settings['section_overrides'] ?? []),
        );
    }

    /**
     * REPORT-OWNER-CONTROL-001 — the Owner's `sections` map is ON as well as OFF, over the level's preset.
     *
     * A standard link has no advanced segmentation; the Owner switches it ON and it is in the report's
     * own settings, not behind a disabled switch. And a section the Owner switches OFF that the preset
     * had on (the funnel, on a detailed link) becomes a link-level hide.
     */
    public function test_the_owners_sections_map_turns_a_section_on_and_off_over_the_level(): void
    {
        $res = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/live", [
                'name' => 'رابط',
                'account_ids' => array_values($this->accounts),
                'from' => '2026-07-01',
                'to' => '2026-07-31',
                'detail_level' => 'standard',
                'sections' => ['advanced_segmentation' => true, 'recommendations' => false],
            ])
            ->assertCreated();

        $share = ReportShare::withoutGlobalScopes()->findOrFail($res->json('data.share_id'));
        $report = Report::withoutGlobalScopes()->findOrFail($res->json('data.report_id'));

        $this->assertTrue($report->section_settings['sections']['advanced_segmentation'], 'a switch ON was ignored');
        $this->assertFalse($report->section_settings['sections']['recommendations']);
        $this->assertContains('recommendations', (array) ($share->settings['section_overrides'] ?? []));
        $this->assertNotContains('advanced_segmentation', (array) ($share->settings['section_overrides'] ?? []));
    }

    /**
     * REPORT-PERIOD-001 — a saved report of a kind computes its own window from the anchor.
     */
    public function test_a_saved_monthly_report_covers_the_calendar_month_of_its_anchor(): void
    {
        $report = $this->createWith(['period_kind' => 'monthly', 'period_start' => '2026-07-05', 'period_end' => '2026-07-20']);

        $this->assertSame('2026-07-01', $report->period_start->toDateString());
        $this->assertSame('2026-07-31', $report->period_end->toDateString());
        $this->assertSame('monthly', $report->config['period_kind']);
    }

    /**
     * REPORT-ACCOUNT-BREAKDOWN-001 — the saved report's DEPTH follows the level through the same sections.
     *
     * A detailed snapshot carries the provider → ad-account split and its deck has the page; a standard
     * snapshot computes neither. One saved configuration (`section_settings`) decides both.
     */
    public function test_a_saved_detailed_report_carries_the_account_split_and_a_standard_one_does_not(): void
    {
        $detailed = $this->report();
        $detailed->forceFill(['section_settings' => ['sections' => DetailLevel::Detailed->sections()]])->save();
        $standard = $this->report();
        $standard->forceFill(['section_settings' => ['sections' => DetailLevel::Standard->sections()]])->save();

        $deep = $this->generate($detailed);
        $balanced = $this->generate($standard);

        $this->assertNotEmpty($deep['accounts'] ?? [], 'the detailed snapshot must carry the ad-account split');
        $this->assertEmpty($balanced['accounts'] ?? [], 'a standard snapshot must not compute it');
    }

    public function test_an_unknown_level_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url(), ['name' => 'X', 'type' => 'executive', 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'detail_level' => 'exhaustive'])
            ->assertStatus(422);
    }

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
    /**
     * REPORT-SCOPE-SELECTION-001 — every axis states whether its list is complete.
     *
     * With nothing truncated, every flag is false. That is the case worth pinning first: a picker
     * that always warned would be a picker nobody reads the warning on.
     */
    public function test_the_options_endpoint_says_nothing_was_truncated_when_nothing_was(): void
    {
        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope/options'))->assertOk()->json('data');

        $this->assertSame(
            ['campaigns' => false, 'ad_sets' => false, 'ads' => false, 'creatives' => false],
            $data['truncated'],
        );
        $this->assertSame(500, $data['limit']);
    }

    /**
     * The campaign list had NO limit — it sent every campaign in the project into the builder.
     *
     * That is the cardinality this requirement is about, and the failure is not only weight: an
     * operator scanning five hundred chips is choosing a report scope by scrolling. The list is now
     * bounded, and — because a list that stops without saying so tells an operator their campaign
     * does not exist — the response says it stopped.
     */
    public function test_a_project_past_the_cap_is_bounded_and_says_so(): void
    {
        for ($i = 0; $i < 501; $i++) {
            UnifiedCampaign::create([
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'name' => sprintf('Bulk %04d', $i),
                'objective' => 'sales',
                'status' => 'active',
            ]);
        }

        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope/options'))->assertOk()->json('data');

        $this->assertCount(500, $data['campaigns'], 'the campaign list is not bounded');
        $this->assertTrue($data['truncated']['campaigns'], 'the cap was reached and not reported');
        /* The other axes are untouched by a campaign flood — the flags are per axis, not one flag. */
        $this->assertFalse($data['truncated']['ads']);
    }

    /**
     * The cap is applied by the DATABASE, not by trimming an array that was already loaded.
     *
     * Worth asserting separately, and only discovered by trying: removing `limit()` from the query
     * leaves every other assertion here green, because the response is trimmed afterwards and still
     * looks right. The response being right was never the point — the requirement is that a project
     * with four hundred thousand ads does not load them to show five hundred, and a test that reads
     * only the response cannot tell the difference.
     */
    public function test_the_cap_is_applied_in_the_query_and_not_after_it(): void
    {
        DB::enableQueryLog();
        $this->actingAs($this->operator, 'sanctum')->getJson($this->url('/scope/options'))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        foreach (['unified_campaigns', 'external_ad_sets', 'external_ads', 'external_creatives'] as $table) {
            $selects = array_values(array_filter(
                array_column($queries, 'query'),
                static fn (string $sql): bool => str_contains($sql, '"'.$table.'"') && str_starts_with($sql, 'select'),
            ));

            $this->assertNotEmpty($selects, "no select was issued against {$table}");
            $this->assertTrue(
                (bool) array_filter($selects, static fn (string $sql): bool => str_contains($sql, 'limit')),
                "the options query against {$table} fetches every row and trims afterwards",
            );
        }
    }

    /**
     * Exactly at the cap is NOT truncated.
     *
     * The off-by-one that matters: inferring «more» from a full page reports entities that do not
     * exist, and an operator told their list is incomplete when it is complete goes looking for
     * something that was never missing. One row past the cap is fetched so the answer is measured.
     */
    public function test_exactly_at_the_cap_is_not_reported_as_truncated(): void
    {
        // Two already exist from setUp, so 498 more makes exactly 500.
        for ($i = 0; $i < 498; $i++) {
            UnifiedCampaign::create([
                'tenant_id' => $this->tenant->getKey(),
                'project_id' => $this->project->getKey(),
                'name' => sprintf('Exact %04d', $i),
                'objective' => 'sales',
                'status' => 'active',
            ]);
        }

        $data = $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url('/scope/options'))->assertOk()->json('data');

        $this->assertCount(500, $data['campaigns']);
        $this->assertFalse($data['truncated']['campaigns'], 'a full page was reported as truncated');
    }

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

        /*
         * 3. The objective split — where the narrowing used to be checked on the campaign list.
         *
         * CLIENT-REPORT-ENTITY-BOUNDARY-001 emptied `campaigns` in the stored document, so the
         * roster can no longer be the witness that a scope narrowed. The path totals are a better
         * one anyway: they prove the excluded campaign's spend left the ARITHMETIC, which is what
         * this test is actually about, rather than proving a name left a list.
         */
        $spentPaths = array_values(array_filter(
            $narrowed['objective_performance']['paths'],
            fn ($p) => $p['spend'] > 0,
        ));
        $this->assertCount(1, $spentPaths, 'the excluded campaign’s path still carried spend');
        $this->assertEqualsWithDelta(1000.0, $spentPaths[0]['spend'], 0.01);

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
                'scope' => ['account_ids' => [$this->accounts['meta']], 'providers' => ['meta'], 'campaign_ids' => [(string) $this->sales->getKey()]],
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

    /**
     * REPORT-SCOPE-HIERARCHY-001 — a saved report carries exactly the accounts it was created with.
     *
     * Meta spent 1000 and TikTok 500 in the same project. A report created for the Meta account
     * generates 1000 — the TikTok account's 500 is nowhere in it — and the stored scope is what every
     * later reader (Preview, share, PDF, export) applies.
     */
    public function test_a_saved_report_generates_only_its_selected_accounts(): void
    {
        $meta = $this->createWith(['scope' => ['account_ids' => [$this->accounts['meta']]]]);
        $tiktok = $this->createWith(['scope' => ['account_ids' => [$this->accounts['tiktok']]]]);

        $this->assertSame([$this->accounts['meta']], $meta->scope['account_ids']);
        $this->assertEqualsWithDelta(1000.0, $this->generate($meta)['kpis']['spend'], 0.01);
        $this->assertEqualsWithDelta(500.0, $this->generate($tiktok)['kpis']['spend'], 0.01);
    }

    public function test_creating_a_saved_report_without_an_account_is_refused(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->postJson($this->url(), ['name' => 'x', 'type' => 'executive'])
            ->assertStatus(422);
    }

    /**
     * REPORT-SCOPE-HIERARCHY-001 — a saved report's scope can no longer be cleared to «the whole project».
     *
     * That reading is how one client's report carried another client's accounts. The request is refused
     * and the report keeps the scope it had.
     */
    public function test_a_scope_with_no_ad_account_is_refused_and_the_report_keeps_its_scope(): void
    {
        $report = $this->report(['providers' => ['meta']]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), ['scope' => []])
            ->assertStatus(422);

        $this->assertSame(['meta'], $report->refresh()->scope['providers']);
    }

    /** A campaign from outside the selected accounts is refused, not dropped and not widened. */
    public function test_a_foreign_id_is_refused_and_the_scope_is_unchanged(): void
    {
        $report = $this->report(['providers' => ['meta']]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['account_ids' => [$this->accounts['meta']], 'campaign_ids' => [(string) Uuid::uuid4()]],
            ])
            ->assertStatus(422);

        $this->assertSame(['meta'], $report->refresh()->scope['providers']);
    }

    /** A campaign of another account is refused under this account. */
    public function test_a_campaign_of_another_account_is_refused(): void
    {
        $report = $this->report(['providers' => ['meta']]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['account_ids' => [$this->accounts['meta']], 'campaign_ids' => [(string) $this->awareness->getKey()]],
            ])
            ->assertStatus(422);
    }

    /** An unknown objective is refused outright rather than stored and ignored. */
    public function test_an_unknown_objective_is_refused(): void
    {
        $report = $this->report();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/scope"), [
                'scope' => ['account_ids' => [$this->accounts['meta']], 'objectives' => ['make_me_rich']],
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
