<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Enums\CampaignObjective;
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
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * §14.6 — a report leads with what its money was buying, and claims nothing else.
 *
 * `ObjectivePerformanceTest` proves the ARITHMETIC keeps awareness spend out of a sales CPA. This
 * proves the report's LANGUAGE does the same: that a brand report does not open by crowning a
 * best-ROAS platform, does not recommend scaling a return nobody was buying, and is not filed as a
 * traffic report because it happened to produce no orders.
 *
 * The brand scenario is deliberately the one where a wrong figure is invisible. Every platform's
 * ROAS is null, so `sortByDesc('roas')` over a column of nulls returns whichever row is first — a
 * winner of a competition nobody entered, printed above the fold under the word «أفضل».
 */
final class ReportObjectiveLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-07-01';

    private const TO = '2026-07-31';

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $meta;

    private ExternalAccount $snapchat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Brand Co', 'slug' => 'brand-co', 'status' => 'active']);
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
            'name' => 'Op', 'email' => 'op@brand.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $this->operator, tenant: $this->tenant, portal: Portal::App, role: 'owner',
        ));

        $this->meta = $this->account('meta');
        $this->snapchat = $this->account('snapchat');

        app(ProjectContext::class)->setProjectId((string) $this->project->id);
    }

    /**
     * A month of pure brand spend is an AWARENESS report — not a traffic one.
     *
     * The old inference read outcomes: no revenue and no conversions meant «traffic», so the report
     * with the least to say about clicks was handed the layout built entirely around them.
     */
    public function test_a_brand_month_is_an_awareness_report_not_a_traffic_one(): void
    {
        $this->seedCampaign('حملة وعي — ميتا', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);
        $this->seedCampaign('حملة وعي — سناب', CampaignObjective::Reach, $this->snapchat, spend: 10_000, impressions: 4_000_000, reach: 900_000);

        $data = $this->generate();

        $this->assertSame('awareness', $data['objective']);
        $this->assertSame(['impressions', 'reach', 'frequency', 'cpm', 'video_views', 'ctr'], $data['metric_set']);
    }

    /**
     * The acceptance case: no sales claim anywhere in a report that bought no sales.
     *
     * Every string a client reads — the executive summary, the findings, the recommendations and the
     * platform notes — is searched for the two words. A single «ROAS» here is the defect.
     */
    public function test_a_brand_report_never_crowns_a_roas_winner_or_a_cost_per_order(): void
    {
        $this->seedCampaign('حملة وعي — ميتا', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);
        $this->seedCampaign('حملة وعي — سناب', CampaignObjective::Reach, $this->snapchat, spend: 10_000, impressions: 4_000_000, reach: 900_000);

        $data = $this->generate();

        foreach ($this->everySentence($data) as $where => $sentence) {
            $this->assertStringNotContainsString('ROAS', $sentence, "a return was claimed in {$where}");
            $this->assertStringNotContainsString('تكلفة نتيجة', $sentence, "a cost per result was claimed in {$where}");
        }

        // …and nobody is named «best» on a figure that does not exist for any of them.
        $this->assertNull($data['best']['platform_by_roas']);
        $this->assertNull($data['best']['platform_by_cpa']);
        $this->assertNull($data['best']['platform_by_results']);
    }

    /**
     * It still names a winner — on the metric brand money actually buys.
     *
     * Meta bought a thousand impressions for 5 SAR and Snapchat for 2.50. Ranked on CPM, lower is
     * better, so Snapchat leads. Ranked the old way it would have been whichever row sorted first.
     */
    public function test_the_brand_report_ranks_platforms_on_the_cost_of_reaching_people(): void
    {
        $this->seedCampaign('حملة وعي — ميتا', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);
        $this->seedCampaign('حملة وعي — سناب', CampaignObjective::Reach, $this->snapchat, spend: 10_000, impressions: 4_000_000, reach: 900_000);

        $data = $this->generate();

        $this->assertSame('cpm', $data['best']['basis']['key']);
        $this->assertSame('snapchat', $data['best']['platform']);
        $this->assertSame('2.50 SAR', $data['best']['platform_value']);
        $this->assertStringContainsString('تكلفة الألف ظهور', $data['summary'][0]);
    }

    /** A sales month keeps every sales figure it had — this rule narrows nothing that was earned. */
    public function test_a_sales_report_still_leads_with_the_return(): void
    {
        $this->seedCampaign('حملة مبيعات — ميتا', CampaignObjective::Sales, $this->meta, spend: 10_000, impressions: 500_000, clicks: 20_000, orders: 500, revenue: 60_000);
        $this->seedCampaign('حملة مبيعات — سناب', CampaignObjective::Purchases, $this->snapchat, spend: 10_000, impressions: 400_000, clicks: 10_000, orders: 200, revenue: 20_000);

        $data = $this->generate();

        $this->assertSame('sales', $data['objective']);
        $this->assertSame('roas', $data['best']['basis']['key']);
        $this->assertSame('meta', $data['best']['platform'], 'the higher return did not win');
        $this->assertSame('meta', $data['best']['platform_by_roas']);
        $this->assertStringContainsString('ROAS', $data['summary'][0]);
    }

    /**
     * A scope holding several objectives leads with neither of them.
     *
     * A cost per result across a brand budget and a sales budget divides one objective's money by
     * another objective's events. The report says so in a sentence rather than printing the figure.
     */
    public function test_a_mixed_scope_reports_operational_figures_and_says_why(): void
    {
        $this->seedCampaign('حملة وعي', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);
        $this->seedCampaign('حملة مبيعات', CampaignObjective::Sales, $this->snapchat, spend: 10_000, impressions: 400_000, clicks: 10_000, orders: 200, revenue: 20_000);

        $data = $this->generate();

        $this->assertSame('custom', $data['objective']);
        $this->assertNotContains('roas', $data['metric_set']);
        $this->assertNotContains('cpa', $data['metric_set']);
        $this->assertContains(
            'تضم هذه الفترة حملات بأهداف مختلفة، لذلك تُعرض مؤشرات كل مسار على حدة ولا تُدمج تكلفة النتيجة أو العائد بينها.',
            $data['summary'],
        );

        // The per-path figures are not lost — they move to the section built to separate them.
        $paths = collect($data['objective_performance']['paths'])->keyBy('path');
        $this->assertNull($paths['awareness']['cpa']);
        $this->assertSame(2.0, (float) $data['objective_performance']['direct']['roas']);
    }

    /**
     * Two campaigns that disagree about the objective but agree about the PATH still get a headline.
     *
     * Leads and app installs are both conversions and neither is a sale, so the honest lead is the
     * result and its cost — never a return on revenue that was never taken.
     */
    public function test_one_path_with_two_objectives_leads_on_cost_per_result_not_return(): void
    {
        $this->seedCampaign('حملة عملاء', CampaignObjective::Leads, $this->meta, spend: 8_000, impressions: 400_000, clicks: 20_000, orders: 400);
        $this->seedCampaign('حملة تثبيت', CampaignObjective::AppInstalls, $this->snapchat, spend: 2_000, impressions: 100_000, clicks: 5_000, orders: 100);

        $data = $this->generate();

        $this->assertSame('leads', $data['objective']);
        $this->assertSame('cpa', $data['best']['basis']['key']);
        // Both spend 20 per result, so neither leads on cost — the point is that no ROAS appears.
        $this->assertNull($data['best']['platform_by_roas']);
        foreach ($this->everySentence($data) as $where => $sentence) {
            $this->assertStringNotContainsString('ROAS', $sentence, "a return was claimed in {$where}");
        }
    }

    /**
     * A project nobody has classified reports exactly as it did before this existed.
     *
     * `unified_campaigns.objective` DEFAULTS to `other`, which sits on the awareness path — so
     * reading the column without checking `objective_source` would file every unclassified sales
     * project as brand spend and hide its revenue behind a CPM.
     */
    public function test_an_unclassified_project_falls_back_to_what_the_data_shows(): void
    {
        $this->seedCampaign('حملة بلا تصنيف', CampaignObjective::Other, $this->meta, spend: 10_000, impressions: 400_000, clicks: 20_000, orders: 500, revenue: 60_000, declared: false);

        $data = $this->generate();

        $this->assertSame('sales', $data['objective'], 'an unclassified project lost its revenue to the default objective');
    }

    /** An operator who names the objective outranks the inference — they know what the data cannot say. */
    public function test_an_operator_choice_outranks_the_inference(): void
    {
        $this->seedCampaign('حملة وعي', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);

        $data = $this->generate(['campaign_objective' => 'video']);

        $this->assertSame('video', $data['objective']);
    }

    /**
     * A metric no connected platform reports is absent, not a measured zero.
     *
     * The pivot coalesces, so an objective-aware layout asking for `reach` on platforms that do not
     * publish it would print «الوصول 0» — a claim that nobody was reached, on a report whose whole
     * purpose was reaching people.
     */
    public function test_the_snapshot_says_which_metrics_nobody_sent(): void
    {
        $this->seedCampaign('حملة وعي', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000);

        $data = $this->generate();

        $this->assertTrue($data['reported']['impressions']);
        $this->assertFalse($data['reported']['reach'], 'an unreported metric was indistinguishable from zero');
        $this->assertSame(0.0, (float) $data['kpis']['reach'], 'the coalesced total is exactly why `reported` has to exist');
    }

    /** The client's link carries the same objective and the same leader board — no second answer. */
    public function test_the_shared_link_carries_the_objective_layout(): void
    {
        $this->seedCampaign('حملة وعي — ميتا', CampaignObjective::Awareness, $this->meta, spend: 30_000, impressions: 6_000_000, reach: 1_500_000);
        $this->seedCampaign('حملة وعي — سناب', CampaignObjective::Reach, $this->snapchat, spend: 10_000, impressions: 4_000_000, reach: 900_000);

        $report = $this->reportRow();
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        [, $token] = app(ShareService::class)->create($report->refresh(), [], $this->operator->id);
        $shared = $this->getJson("/api/v1/reports/shared/{$token}")->assertOk();

        $this->assertSame('awareness', $shared->json('data.data.objective'));
        $this->assertSame('cpm', $shared->json('data.data.best.basis.key'));
        $this->assertNull($shared->json('data.data.best.platform_by_roas'));
    }

    /** The lens is a pure classifier — worth pinning without a database behind it. */
    public function test_a_campaign_that_spent_nothing_does_not_relabel_the_period(): void
    {
        $lens = ReportObjectiveLens::infer([
            ['objective' => 'sales', 'objective_source' => 'platform', 'spend' => 10_000, 'revenue' => 50_000],
            ['objective' => 'awareness', 'objective_source' => 'platform', 'spend' => 0],
        ]);

        $this->assertSame('sales', $lens->value());
        $this->assertTrue($lens->judgesOnRevenue());
        $this->assertFalse($lens->isMixed());
    }

    /**
     * Every sentence a reader will see, keyed by where it came from.
     *
     * @return array<string,string>
     */
    private function everySentence(array $data): array
    {
        $out = [];
        foreach ($data['summary'] as $i => $line) {
            $out["summary[{$i}]"] = $line;
        }
        foreach (['findings', 'recommendations'] as $section) {
            foreach ($data[$section] as $i => $item) {
                $out["{$section}[{$i}]"] = ($item['title'] ?? '').' '.($item['detail'] ?? '').' '.($item['kpi'] ?? '');
            }
        }
        foreach ($data['platform_notes'] as $provider => $note) {
            $out["platform_notes[{$provider}]"] = implode(' ', array_merge($note['strengths'], $note['weaknesses']));
        }

        return $out;
    }

    private function generate(array $overrides = []): array
    {
        $report = $this->reportRow($overrides);
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        return $report->refresh()->data;
    }

    private function reportRow(array $overrides = []): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'name' => 'تقرير الفترة',
            'type' => 'monthly',
            'form' => 'detailed',
            'status' => 'processing',
            'period_start' => self::FROM,
            'period_end' => self::TO,
            'currency' => 'SAR',
        ] + $overrides);
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
            'external_id' => 'acct-'.uniqid(), 'name' => $provider, 'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function seedCampaign(
        string $name,
        CampaignObjective $objective,
        ExternalAccount $account,
        float $spend = 0,
        float $impressions = 0,
        float $reach = 0,
        float $clicks = 0,
        float $orders = 0,
        float $revenue = 0,
        bool $declared = true,
    ): void {
        $this->holdingTenant((string) $this->tenant->id);

        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => $objective->value,
            'total_budget' => 100_000, 'budget_currency' => 'SAR',
        ]);

        /*
         * `objective_source` is deliberately NOT fillable — a request must not be able to claim its
         * own classification came from the platform (REPORT-OBJECTIVE-002). The resolver sets it,
         * and a fixture standing in for the resolver has to go the same way round it does.
         *
         * It is what separates «the platform said so» from «nobody has looked», and the whole reason
         * the inference reads this column and not just the objective beside it.
         */
        if ($declared) {
            $campaign->forceFill(['objective_source' => 'platform'])->save();
        }

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->getKey(), 'unified_campaign_id' => $campaign->id,
            'provider' => $account->provider, 'external_id' => 'ext-'.uniqid(), 'name' => $name, 'status' => 'active',
        ]);

        foreach ([
            'spend' => $spend, 'impressions' => $impressions, 'reach' => $reach,
            'clicks' => $clicks, 'conversions' => $orders, 'revenue' => $revenue,
        ] as $key => $value) {
            if ($value === 0.0) {
                continue;
            }

            DailyMetric::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $account->getKey(), 'external_campaign_id' => $external->id,
                'unified_campaign_id' => $campaign->id, 'provider' => $account->provider,
                'metric_key' => $key, 'metric_date' => Carbon::parse('2026-07-10')->toDateString(),
                'value' => $value,
            ]);
        }
    }
}
