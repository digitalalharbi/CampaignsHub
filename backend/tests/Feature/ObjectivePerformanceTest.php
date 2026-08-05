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
            'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks,
            'purchases' => $orders, 'revenue' => $revenue,
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
