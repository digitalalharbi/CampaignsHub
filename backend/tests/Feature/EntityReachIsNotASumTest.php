<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Metrics\Models\EntityDailyMetric;
use App\Domains\Metrics\Services\EntityMetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
/**
 * REACH-DEDUP-001 at the ad-set and ad grain — a sum of daily reach is not reach, and an average of
 * daily frequencies is not frequency.
 *
 * `entity_daily_metrics` stores one row per entity, per day. The provider deduplicated each day's reach
 * for that day; adding days counts a returning person once per day, and the mean of daily frequencies
 * weights a 100-impression day equally with a 100,000-impression day. Both are shown only where the
 * window holds exactly one row for the entity — the provider's own figure — and are «—» otherwise.
 */
final class EntityReachIsNotASumTest extends TestCase
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

    public function test_an_ad_set_over_two_days_has_no_reach_and_no_frequency(): void
    {
        $this->metric($this->squad->getKey(), ['impressions' => 9000, 'reach' => 4000, 'frequency' => 2.25]);
        $this->metric($this->squad->getKey(), ['impressions' => 1000, 'reach' => 800, 'frequency' => 1.25, 'metric_date' => '2026-08-02']);

        $row = $this->read()[0];

        $this->assertNull($row['reach'], '4,800 counts a person reached on both days twice');
        $this->assertNull($row['frequency'], '1.75 is the mean of two days, not a frequency');
        $this->assertEqualsWithDelta(10000, $row['impressions'], 0.01);
    }

    public function test_an_ad_set_on_one_day_keeps_the_providers_own_reach_and_frequency(): void
    {
        $this->metric($this->squad->getKey(), ['impressions' => 9000, 'reach' => 4000, 'frequency' => 2.25]);

        $row = $this->read()[0];

        $this->assertEqualsWithDelta(4000, $row['reach'], 0.01);
        $this->assertEqualsWithDelta(2.25, $row['frequency'], 0.001);
    }

    public function test_reach_not_reported_on_one_day_stays_not_reported(): void
    {
        $this->metric($this->squad->getKey(), ['impressions' => 9000]);

        $row = $this->read()[0];

        $this->assertNull($row['reach']);
        $this->assertNull($row['frequency']);
    }

    /** @return list<array<string,mixed>> */
    private function read(): array
    {
        return app(EntityMetricsAggregator::class)->byEntity(
            (string) $this->project->getKey(),
            EntityDailyMetric::AD_SET,
            Carbon::parse('2026-07-25'),
            Carbon::parse('2026-08-10'),
        );
    }

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
