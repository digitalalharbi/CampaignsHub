<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
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
 * ANALYTICS-DRILLDOWN-001 — the Ad Set and Ads levels reach a screen.
 *
 * Analytics could show Overview, Platform and Campaign because `daily_metrics` answers at the
 * campaign grain. Beneath that there was nothing: 187 ad squads and 5,706 ads on the live account
 * with no table to read from and therefore no tab. This is the endpoint between the data and a
 * screen, and these tests are about what it will actually put in front of an operator.
 */
final class EntityMetricsApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private ExternalCampaign $campaign;

    private ExternalAdSet $squad;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Entities', 'slug' => 'entities-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create([
            'name' => 'Op', 'email' => 'op@entities.local', 'password' => 'secret123', 'email_verified_at' => now(),
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

        // `external_account_id` is NOT NULL: a campaign always belongs to an account, and the
        // schema refuses a detached one rather than storing an orphan.
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->getKey(),
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'snapchat',
        );

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'provider_connection_id' => $connection->getKey(),
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-1', 'name' => 'Snap', 'status' => 'active', 'discovered_at' => now(),
        ]);

        $this->campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $account->getKey(),
            'provider' => 'snapchat', 'external_id' => 'cmp-1', 'name' => 'Campaign', 'status' => 'active',
        ]);

        $this->squad = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_campaign_id' => $this->campaign->getKey(), 'provider' => 'snapchat',
            'external_id' => 'sq-1', 'name' => 'Riyadh · 18-34', 'status' => 'active',
        ]);
    }

    /** An ad squad reaches the API with a NAME — a drill-down of provider ids is not a screen. */
    public function test_an_ad_squad_arrives_with_its_name_and_figures(): void
    {
        $this->metric($this->squad->getKey(), ['impressions' => 90000, 'clicks' => 300, 'reach' => 45000]);

        $row = $this->fetchLevel('ad_set')[0];

        $this->assertSame('Riyadh · 18-34', $row['name'], 'The figures are in one table and the name in another.');
        $this->assertSame('sq-1', $row['external_id']);
        $this->assertEqualsWithDelta(90000, $row['impressions'], 0.01);
        $this->assertEqualsWithDelta(45000, $row['reach'], 0.01);
        $this->assertEqualsWithDelta(0.00333, $row['ctr'], 0.0001);
    }

    /** Withheld money survives to the client in the contract's own field names. */
    public function test_withheld_money_reaches_the_client_as_an_original(): void
    {
        $this->metric($this->squad->getKey(), [
            'impressions' => 1000, 'spend' => null, 'spend_original' => 412.5,
            'original_currency' => 'USD', 'project_currency' => 'SAR',
        ]);

        $row = $this->fetchLevel('ad_set')[0];

        $this->assertNull($row['spend']);
        $this->assertEqualsWithDelta(412.5, $row['spend_original'], 0.01);
        $this->assertSame('USD', $row['money_original_currency']);
        $this->assertNull($row['cpm'], 'A CPM over a withheld spend would read as free.');
    }

    /**
     * The parent filter narrows the QUERY.
     *
     * Post-filtering rows would mean fetching all 5,706 ads to show twenty, and a paginated total
     * that lies about how many there are.
     */
    public function test_the_parent_filter_narrows_to_that_parents_children(): void
    {
        $otherCampaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_account_id' => $this->campaign->external_account_id,
            'provider' => 'snapchat', 'external_id' => 'cmp-2', 'name' => 'Other', 'status' => 'active',
        ]);

        $other = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_campaign_id' => $otherCampaign->getKey(), 'provider' => 'snapchat',
            'external_id' => 'sq-2', 'name' => 'Jeddah', 'status' => 'active',
        ]);

        $this->metric($this->squad->getKey(), ['impressions' => 10], (string) $this->campaign->getKey());
        $this->metric($other->getKey(), ['impressions' => 20], (string) $otherCampaign->getKey(), externalId: 'sq-2');

        $rows = $this->fetchLevel('ad_set', ['parent' => (string) $this->campaign->getKey()]);

        $this->assertCount(1, $rows);
        $this->assertSame('Riyadh · 18-34', $rows[0]['name']);
    }

    /** An explicitly empty parent means «this parent has no children», never «show me everything». */
    public function test_an_empty_parent_returns_nothing_rather_than_everything(): void
    {
        $this->metric($this->squad->getKey(), ['impressions' => 10]);

        $this->assertCount(0, $this->fetchLevel('ad_set', ['parent' => '']));
    }

    /** The ad rung answers too, and knows its ad set. */
    public function test_the_ad_level_answers_with_its_parents(): void
    {
        $ad = ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'external_ad_set_id' => $this->squad->getKey(), 'external_campaign_id' => $this->campaign->getKey(),
            'provider' => 'snapchat', 'external_id' => 'ad-1', 'name' => 'Swipe up', 'status' => 'active',
        ]);

        $this->metric($ad->getKey(), ['impressions' => 500], null, EntityDailyMetric::AD, (string) $this->squad->getKey(), 'ad-1');

        $row = $this->fetchLevel('ad')[0];

        $this->assertSame('Swipe up', $row['name']);
        $this->assertSame((string) $this->squad->getKey(), $row['ad_set_id'], 'Drill-down needs the parent on the row.');
    }

    /** An unknown level is refused — an empty list would read as «this level has no data». */
    public function test_an_unknown_level_is_refused_rather_than_answered_emptily(): void
    {
        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/entities/nonsense")
            ->assertNotFound();
    }

    /** Reading metrics needs the permission, like every other metric surface. */
    public function test_it_refuses_a_caller_without_the_permission(): void
    {
        $stranger = User::create([
            'name' => 'S', 'email' => 's@entities.local', 'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $this->grantMembership($stranger, $this->tenant);

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/entities/ad_set")
            ->assertForbidden();
    }

    /**
     * ANALYTICS-OBJECTIVE-FILTERS-001 — a family narrows the DATASET, not just the KPI order.
     *
     * «Show me the awareness work» means awareness AND reach; «sales» means sales, conversions,
     * purchases and add-to-cart. Expanding the family in the backend is what makes the totals on the
     * page match the filter on the page — a family resolved in the frontend would filter rows that
     * were already aggregated across every objective, and the figures would stay unfiltered while
     * the screen looked filtered.
     */
    public function test_an_objective_family_expands_to_its_member_objectives(): void
    {
        $awareness = \App\Domains\Campaigns\Enums\ObjectiveFamily::Awareness;

        $members = array_values(array_filter(
            \App\Domains\Campaigns\Enums\CampaignObjective::cases(),
            static fn ($o): bool => $o->family() === $awareness,
        ));

        $values = array_map(static fn ($o): string => $o->value, $members);

        $this->assertContains('awareness', $values);
        $this->assertContains('reach', $values, 'A reach buy is awareness work and must come with it.');

        // The request is accepted and scoped; an unknown family is ignored rather than erroring.
        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/summary?objective_family=awareness&from=2026-07-25&to=2026-08-10")
            ->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/summary?objective_family=nonsense&from=2026-07-25&to=2026-08-10")
            ->assertOk();
    }

    /** @return list<array<string,mixed>> */
    private function fetchLevel(string $level, array $query = []): array
    {
        $params = array_merge([
            'from' => '2026-07-25', 'to' => '2026-08-10',
        ], $query);

        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/metrics/entities/{$level}?".http_build_query($params))
            ->assertOk()
            ->json('data.entities');
    }

    /** @param array<string,mixed> $values */
    private function metric(
        string $entityId,
        array $values,
        ?string $campaignId = null,
        string $type = EntityDailyMetric::AD_SET,
        ?string $adSetId = null,
        string $externalId = 'sq-1',
    ): void {
        $model = new EntityDailyMetric;
        $model->forceFill([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->getKey(),
            'project_id' => $this->project->getKey(),
            'provider' => 'snapchat',
            'entity_type' => $type,
            'entity_id' => $entityId,
            'external_entity_id' => $externalId,
            'external_campaign_id' => $campaignId,
            'external_ad_set_id' => $adSetId,
            'metric_date' => '2026-08-01',
            'attribution_window' => 'default',
            'is_demo' => false,
            ...$values,
        ])->save();
    }
}
