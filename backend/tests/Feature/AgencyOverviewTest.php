<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/**
 * DASHBOARD-FIRST-SCREEN-001 — the agency overview is the dashboard's own ceiling applied to money.
 *
 * A scoped operator's spend covers exactly the clients their counts cover; the currency is named only
 * when every row agrees; the previous window is the same length ending the day before.
 */
final class AgencyOverviewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Overview Agency', 'slug' => 'overview-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function campaign(ClientWorkspace $client, string $objective = 'sales'): UnifiedCampaign
    {
        $project = Project::create([
            'tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id,
            'name' => 'P '.uniqid(), 'status' => 'active',
        ]);

        return UnifiedCampaign::create([
            'tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id,
            'project_id' => $project->id, 'name' => 'C '.uniqid(), 'objective' => $objective, 'status' => 'active',
        ]);
    }

    private function spend(UnifiedCampaign $campaign, string $provider, string $date, float $spend, float $conversions, string $currency = 'SAR'): void
    {
        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s.$campaign->id.$provider);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->agency->id, projectId: $campaign->project_id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: $provider, metricKey: $k, metricDate: Carbon::parse($date),
            value: $v, unifiedCampaignId: $campaign->id, projectCurrency: $currency,
        );
        app(UpsertDailyMetrics::class)->handle([
            $m('impressions', 1000), $m('clicks', 50), $m('conversions', $conversions), $m('spend', $spend), $m('revenue', $spend * 3),
        ]);
    }


    /** @param array<string, float> $figures metric key => value for one day */
    private function figures(UnifiedCampaign $campaign, string $provider, string $date, array $figures): void
    {
        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s.$campaign->id.$provider);
        app(UpsertDailyMetrics::class)->handle(array_map(fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->agency->id, projectId: $campaign->project_id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: $provider, metricKey: $k, metricDate: Carbon::parse($date),
            value: $v, unifiedCampaignId: $campaign->id, projectCurrency: 'SAR',
        ), array_keys($figures), array_values($figures)));
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null): User
    {
        $user = User::create(['name' => 'Op', 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member', clientScopeIds: $clientScope,
        ));

        return $user;
    }

    private function url(): string
    {
        return '/api/v1/agency/overview?from=2026-06-01&to=2026-06-30';
    }

    public function test_an_unrestricted_operator_sees_the_whole_agency_in_one_currency(): void
    {
        $a = $this->campaign($this->client('Alpha'));
        $b = $this->campaign($this->client('Beta'));
        $this->spend($a, 'meta', '2026-06-10', 1000, 10);
        $this->spend($b, 'google', '2026-06-12', 500, 5);
        $this->spend($b, 'google', '2026-05-20', 200, 2); // previous window

        $user = $this->operator('all@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.scope.client_count', 2)
            ->assertJsonPath('data.scope.project_count', 2)
            ->assertJsonPath('data.currency', 'SAR')
            ->assertJsonPath('data.current.spend', 1500)
            ->assertJsonPath('data.current.conversions', 15)
            ->assertJsonPath('data.previous.spend', 200)
            ->assertJsonPath('data.previous_period.from', '2026-05-02')
            ->assertJsonPath('data.previous_period.to', '2026-05-31')
            ->assertJsonCount(2, 'data.by_provider')
            ->assertJsonCount(2, 'data.timeseries');
    }

    /** The money narrows with the scope — the same ceiling the counts use, not a wider one. */
    public function test_a_scoped_operator_sees_only_their_clients_money(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $this->spend($this->campaign($mine), 'meta', '2026-06-10', 1000, 10);
        $this->spend($this->campaign($theirs), 'google', '2026-06-12', 500, 5);

        $user = $this->operator('scoped@test.dev', ['clients.view'], [(string) $mine->id]);

        $this->actingAs($user, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.scope.client_count', 1)
            ->assertJsonPath('data.scope.is_restricted', true)
            ->assertJsonPath('data.current.spend', 1000)
            ->assertJsonCount(1, 'data.by_provider')
            ->assertJsonPath('data.by_provider.0.provider', 'meta');
    }

    /** Two currencies in scope: no unit is named, and the page prints bare figures rather than a guess. */
    public function test_mixed_currencies_name_no_currency(): void
    {
        $a = $this->campaign($this->client('Riyals'));
        $b = $this->campaign($this->client('Dollars'));
        $this->spend($a, 'meta', '2026-06-10', 1000, 10, 'SAR');
        $this->spend($b, 'google', '2026-06-12', 500, 5, 'USD');

        $user = $this->operator('mixed@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.currency', null);
    }

    public function test_an_agency_with_no_projects_reports_nothing_rather_than_sample_data(): void
    {
        $this->client('Empty');
        $user = $this->operator('empty@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);

        $this->actingAs($user, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.by_provider', [])
            ->assertJsonPath('data.freshness.last_synced_at', null);
    }

    public function test_requires_the_clients_permission(): void
    {
        $user = $this->operator('noperm@test.dev', ['campaigns.view']);

        $this->actingAs($user, 'sanctum')->getJson($this->url())->assertForbidden();
    }

    /**
     * DASH-KPI-OBJECTIVE-001 — views, clicks, leads and orders are different things: the dashboard states each
     * family's result and its own cost per result, and never a cost per result across them.
     */
    public function test_results_and_cost_per_result_are_split_by_objective_never_blended(): void
    {
        $client = $this->client('Mixed');
        $sales = $this->campaign($client, 'sales');
        $leads = $this->campaign($client, 'leads');
        $traffic = $this->campaign($client, 'traffic');
        $this->figures($sales, 'meta', '2026-06-10', ['spend' => 1000, 'conversions' => 10, 'revenue' => 4000, 'impressions' => 90000, 'clicks' => 900]);
        $this->figures($leads, 'meta', '2026-06-10', ['spend' => 400, 'leads' => 20, 'impressions' => 40000, 'clicks' => 400]);
        $this->figures($traffic, 'tiktok', '2026-06-10', ['spend' => 100, 'clicks' => 50, 'impressions' => 80000]);

        $user = $this->operator('obj@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);
        $by = collect($this->actingAs($user, 'sanctum')->getJson($this->url())->assertOk()->json('data.by_objective'))->keyBy('family');

        $this->assertSame(10.0, (float) $by['sales']['result']);
        $this->assertSame(100.0, (float) $by['sales']['cost_per_result']);   // 1000 / 10 orders — not 1500 / 80
        $this->assertSame(20.0, (float) $by['leads']['result']);
        $this->assertSame(20.0, (float) $by['leads']['cost_per_result']);    // 400 / 20 leads
        $this->assertSame(50.0, (float) $by['traffic']['result']);
        $this->assertSame(2.0, (float) $by['traffic']['cost_per_result']);   // 100 / 50 clicks
        $this->assertSame('clicks', $by['traffic']['result_key']);
    }

    public function test_roas_covers_only_sales_campaigns_with_revenue_and_says_how_many_entered(): void
    {
        $client = $this->client('Roas');
        $a = $this->campaign($client, 'sales');
        $b = $this->campaign($client, 'sales');   // spends, reports no revenue: not in ROAS
        $leads = $this->campaign($client, 'leads');
        $this->figures($a, 'meta', '2026-06-10', ['spend' => 1000, 'conversions' => 10, 'revenue' => 4000]);
        $this->figures($b, 'meta', '2026-06-10', ['spend' => 500, 'conversions' => 2]);
        $this->figures($leads, 'meta', '2026-06-10', ['spend' => 300, 'leads' => 6, 'revenue' => 9999]);

        $user = $this->operator('roas@test.dev', ['clients.view', ClientScopeResolver::ALL_CLIENTS]);
        $by = collect($this->actingAs($user, 'sanctum')->getJson($this->url())->assertOk()->json('data.by_objective'))->keyBy('family');

        $this->assertSame(4.0, (float) $by['sales']['roas']);   // 4000 / 1000: the leads revenue and the revenue-less campaign are out
        $this->assertSame(1, $by['sales']['roas_campaigns']);
        $this->assertSame(2, $by['sales']['sales_campaigns']);
        $this->assertNull($by['leads']['roas']);
    }
}
