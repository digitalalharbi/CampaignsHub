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
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-FILTER-TRUTH-001 at the drill-down grain.
 *
 * The entity endpoint read the window, the parent and the attribution basis. It read no platform,
 * no objective and no campaign — while its own docblock claimed all three came "from the same
 * request helpers every other metric endpoint uses". So the ad-set and ad tables answered for the
 * whole project under chips naming one campaign, sitting directly beneath a campaign table that had
 * narrowed correctly. Two tables, one screen, one set of filters, two different questions.
 *
 * The subtle one is the objective. Both `external_campaigns` and `unified_campaigns` carry an
 * `objective` column: the first is what the provider said, the second is the campaign's objective in
 * this product, and the campaign grain filters on the second. Reading the provider's copy here would
 * be invisible on a healthy account and would silently diverge the moment an operator corrected an
 * objective — so the fixture deliberately disagrees between the two tables, and the assertion is
 * about which one won.
 */
final class EntityFilterTruthTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalAccount $account;

    /** The sales campaign, whose PROVIDER objective is deliberately something else. */
    private ExternalAdSet $salesSquad;

    private ExternalAdSet $awarenessSquad;

    private UnifiedCampaign $salesUnified;

    private UnifiedCampaign $awarenessUnified;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'EF', 'slug' => 'ef-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@ef.local', 'password' => 'secret123', 'email_verified_at' => now(),
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

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $this->account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-1', 'name' => 'Snap', 'status' => 'active', 'discovered_at' => now(),
        ]);

        $this->salesUnified = $this->unified('Ramadan Sales', 'sales');
        $this->awarenessUnified = $this->unified('Brand Awareness', 'awareness');

        /*
         * The provider's objective on the SALES campaign is `awareness`, and vice versa.
         *
         * Nothing in production has to look like this for the distinction to matter — an operator
         * correcting one objective produces exactly this disagreement. Written into the fixture, it
         * turns "which table does the objective come from" from a code-reading question into a
         * failing assertion.
         */
        $salesExternal = $this->external('cmp-sales', $this->salesUnified, providerObjective: 'awareness');
        $awarenessExternal = $this->external('cmp-awareness', $this->awarenessUnified, providerObjective: 'sales');

        $this->salesSquad = $this->squad('sq-sales', $salesExternal, 'Riyadh · 18-34');
        $this->awarenessSquad = $this->squad('sq-awareness', $awarenessExternal, 'Jeddah · 25-44');

        $this->metric($this->salesSquad, $salesExternal, 'snapchat', 100);
        $this->metric($this->awarenessSquad, $awarenessExternal, 'meta', 200);
    }

    public function test_without_a_filter_both_ad_squads_answer(): void
    {
        $this->assertSame(['Jeddah · 25-44', 'Riyadh · 18-34'], $this->names());
    }

    public function test_the_campaign_axis_narrows_the_drill_down(): void
    {
        $this->assertSame(
            ['Riyadh · 18-34'],
            $this->names(['campaign' => (string) $this->salesUnified->getKey()]),
        );
    }

    /**
     * The objective comes from the UNIFIED campaign, which is what the campaign grain filters on.
     *
     * The sales campaign's provider objective is `awareness` in this fixture. Asking for `sales` must
     * return it; a reader of `external_campaigns.objective` would return the other squad instead, and
     * the ad table would contradict the campaign table above it under one chip.
     */
    public function test_the_objective_axis_reads_the_unified_campaign_not_the_provider(): void
    {
        $this->assertSame(['Riyadh · 18-34'], $this->names(['objective' => 'sales']));
        $this->assertSame(['Jeddah · 25-44'], $this->names(['objective' => 'awareness']));
    }

    public function test_the_platform_axis_narrows_the_drill_down(): void
    {
        $this->assertSame(['Jeddah · 25-44'], $this->names(['provider' => 'meta']));
    }

    /**
     * The axes are an AND.
     *
     * An `OR` widens the answer while every single-axis test above still passes, so the composition
     * needs its own case: the sales campaign asked for under the other platform is not a wider match,
     * it is none.
     */
    public function test_the_axes_compose(): void
    {
        $this->assertSame([], $this->names([
            'campaign' => (string) $this->salesUnified->getKey(),
            'provider' => 'meta',
        ]));

        $this->assertSame(['Riyadh · 18-34'], $this->names([
            'campaign' => (string) $this->salesUnified->getKey(),
            'provider' => 'snapchat',
        ]));
    }

    /**
     * A campaign with no external campaign behind it empties the table.
     *
     * «This campaign has no ads» is a fact. The alternative — an unmatched filter widening back to
     * every ad in the project — is the leak this requirement exists to prevent, and it is the shape
     * a `whereIn` over an empty list takes if it is written the careless way.
     */
    public function test_a_campaign_that_maps_to_nothing_returns_nothing_rather_than_everything(): void
    {
        $orphan = $this->unified('Never Synced', 'sales');

        $this->assertSame([], $this->names(['campaign' => (string) $orphan->getKey()]));
    }

    public function test_the_endpoint_declares_the_axes_it_applied(): void
    {
        $scope = $this->fetch([
            'campaign' => (string) $this->salesUnified->getKey(),
            'objective' => 'sales',
            'provider' => 'snapchat',
        ])['filter_scope'];

        $this->assertSame(['provider', 'objective', 'campaign'], $scope['applied']);
        $this->assertSame([], $scope['unapplied']);
    }

    /**
     * ANALYTICS-FILTER-TRUTH-001 — the axes narrow; the PROJECT is not an axis.
     *
     * Every test above proves a filter can make this endpoint answer with less. This one proves what
     * no filter can make it answer with: another project's rows, in this tenant, whose ad-set ids the
     * caller knows and names as the parent it wants to drill into.
     *
     * The parent filter is the dangerous one. It is the only input that carries an ID the caller
     * chose, and `byEntity` applies it as `whereIn(external_campaign_id, …)` — so an id belonging to a
     * neighbouring project reaches the same column as a legitimate one. What keeps it honest is the
     * project predicate ABOVE it, and a predicate is only load-bearing while something fails when it
     * is removed. Nothing did.
     *
     * A shared tenant is deliberate: two projects of one agency is the arrangement where a leak is
     * both most likely and least visible, because every row passes the tenant check.
     */
    public function test_another_projects_rows_never_answer_however_they_are_asked_for(): void
    {
        // Its own workspace, as a second client of this agency actually has.
        $theirs = ClientWorkspace::create([
            'tenant_id' => $this->tenant->getKey(), 'name' => 'Neighbour', 'slug' => 'n-'.uniqid(),
            'mode' => 'managed', 'status' => 'active',
        ]);
        $other = Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(),
            'client_workspace_id' => $theirs->getKey(),
            'name' => 'A neighbouring client',
            'status' => 'active',
        ]);

        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $other->getKey(),
            'name' => 'Their campaign', 'status' => 'active', 'objective' => 'sales',
        ]);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $other->getKey(),
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $unified->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-theirs', 'name' => 'cmp-theirs',
            'status' => 'active', 'objective' => 'sales',
        ]);
        $squad = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $other->getKey(),
            'external_campaign_id' => $external->getKey(), 'provider' => 'snapchat',
            'external_id' => 'sq-theirs', 'name' => 'THEIR SQUAD', 'status' => 'active',
        ]);

        $model = new EntityDailyMetric;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $other->getKey(),
            'provider' => 'snapchat',
            'entity_type' => EntityDailyMetric::AD_SET,
            'entity_id' => $squad->getKey(),
            'external_entity_id' => $squad->external_id,
            'external_campaign_id' => $external->getKey(),
            'metric_date' => '2026-08-01',
            'attribution_window' => 'default',
            'is_demo' => false,
            'impressions' => 999999,
        ])->save();

        // Unfiltered: the neighbour is simply not in the answer.
        $this->assertNotContains('THEIR SQUAD', $this->names());

        // Named as the parent to drill into — the one input that carries a caller-chosen id.
        $this->assertSame([], $this->names(['parent' => (string) $external->getKey()]));

        // And named through every axis at once, in case one of them widens what the others narrowed.
        $this->assertSame([], $this->names([
            'parent' => (string) $external->getKey(),
            'campaign' => (string) $unified->getKey(),
            'objective' => 'sales',
            'provider' => 'snapchat',
        ]));
    }

    // ---- fixtures --------------------------------------------------------------------------------

    private function unified(string $name, string $objective): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => $name, 'status' => 'active', 'objective' => $objective,
        ]);
    }

    private function external(string $externalId, UnifiedCampaign $unified, string $providerObjective): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->account->getKey(),
            'unified_campaign_id' => $unified->getKey(),
            'provider' => 'snapchat', 'external_id' => $externalId, 'name' => $externalId,
            'status' => 'active', 'objective' => $providerObjective,
        ]);
    }

    private function squad(string $externalId, ExternalCampaign $campaign, string $name): ExternalAdSet
    {
        return ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_campaign_id' => $campaign->getKey(), 'provider' => 'snapchat',
            'external_id' => $externalId, 'name' => $name, 'status' => 'active',
        ]);
    }

    private function metric(ExternalAdSet $squad, ExternalCampaign $campaign, string $provider, float $impressions): void
    {
        $model = new EntityDailyMetric;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            /*
             * The provider on the METRIC row, which is the column the platform axis reads. It differs
             * from the ad set's own provider on purpose: if the filter were applied by joining out to
             * the structure tables instead, this row would be matched under the wrong platform.
             */
            'provider' => $provider,
            'entity_type' => EntityDailyMetric::AD_SET,
            'entity_id' => $squad->getKey(),
            'external_entity_id' => $squad->external_id,
            'external_campaign_id' => $campaign->getKey(),
            'metric_date' => '2026-08-01',
            'attribution_window' => 'default',
            'is_demo' => false,
            'impressions' => $impressions,
        ])->save();
    }

    // ---- reads -----------------------------------------------------------------------------------

    /** @param array<string, string> $query */
    private function fetch(array $query = []): array
    {
        $params = array_merge(['from' => '2026-07-25', 'to' => '2026-08-10'], $query);

        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/entities/ad_set?".http_build_query($params))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string> the ad-squad names the endpoint returned, sorted
     */
    private function names(array $query = []): array
    {
        $out = array_column($this->fetch($query)['entities'], 'name');
        sort($out);

        return $out;
    }
}
