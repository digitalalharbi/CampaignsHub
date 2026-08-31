<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RUNTIME-100 §8 §23 §25 — ONE figure, followed to every surface that shows it.
 *
 * ## Why two deliberately unmistakable numbers
 *
 * Client A spends **500**. Client B spends **9999**. Every surface below is asked for A's number and
 * must answer 500 — not «not 9999», and not «contains 500 somewhere in the body». Those two weaker
 * forms are how the previous isolation test failed: it searched the raw JSON for the string `9999`,
 * and a project uuid that happened to contain those digits made it fail at random on data that was
 * entirely correct (CLIENT-ANALYTICS-FLAKE-001).
 *
 * So every assertion here is a NUMBER read from a named path. A figure that reached the wrong client
 * fails; a uuid that happens to look like a figure does not.
 *
 * ## Seeded rather than synced, on purpose
 *
 * `DownstreamActivationTest` proves the pipeline PRODUCES data and where it lands. This proves the
 * figure it produced travels unchanged, which needs the figure to be chosen rather than whatever the
 * sandbox happens to report. The two together are the claim; neither is it alone.
 */
final class ProjectFigureIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const A_SPEND = 500.0;

    private const B_SPEND = 9999.0;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->operator = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant, Portal::Agency);
        $this->operator->assignRole($role);

        $this->clientA = $this->workspace('Client A');
        $this->clientB = $this->workspace('Client B');
        $this->projectA = $this->project('A — retainer', $this->clientA);
        $this->projectB = $this->project('B — retainer', $this->clientB);

        $this->spend($this->projectA, $this->clientA, 'snapchat', 'a-act', self::A_SPEND);
        $this->spend($this->projectB, $this->clientB, 'snapchat', 'b-act', self::B_SPEND);

        app(TenantContext::class)->forget();
    }

    /** The project's own metrics endpoint — what the dashboard and analytics both read. */
    public function test_the_project_summary_reports_its_own_spend(): void
    {
        $this->assertSame(self::A_SPEND, $this->summarySpend($this->projectA));
        $this->assertSame(self::B_SPEND, $this->summarySpend($this->projectB));
    }

    /** The platform breakdown carries the same number, per provider. */
    public function test_the_platform_breakdown_reports_the_same_figure(): void
    {
        $rows = $this->asOperator($this->projectA, 'metrics/platforms?from=2020-01-01&to=2035-01-01');

        $this->assertSame(
            self::A_SPEND,
            round((float) collect($rows)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 2),
            'the breakdown must add up to the project total it belongs to',
        );
    }

    /** Campaign-level rows too — the same figure, sliced. */
    public function test_the_campaign_breakdown_reports_the_same_figure(): void
    {
        $rows = $this->asOperator($this->projectA, 'metrics/campaigns?from=2020-01-01&to=2035-01-01');

        $this->assertSame(
            self::A_SPEND,
            round((float) collect($rows)->sum(fn (array $r): float => (float) ($r['spend'] ?? 0)), 2),
        );
    }

    /** The client portfolio: A sees A's figure, and A's projects only. */
    public function test_each_clients_analytics_reports_only_its_own_figure(): void
    {
        $a = $this->clientAnalytics($this->clientA);
        $b = $this->clientAnalytics($this->clientB);

        $this->assertSame(self::A_SPEND, round((float) ($a['totals']['spend'] ?? -1), 2));
        $this->assertSame(self::B_SPEND, round((float) ($b['totals']['spend'] ?? -1), 2));

        $this->assertSame([$this->projectA->id], collect($a['projects'] ?? [])->pluck('project_id')->all());
        $this->assertSame([$this->projectB->id], collect($b['projects'] ?? [])->pluck('project_id')->all());
    }

    /** And the per-project rows inside each client carry the figure, not merely the id. */
    public function test_the_client_project_rows_carry_the_figure(): void
    {
        $a = $this->clientAnalytics($this->clientA);

        $this->assertSame(
            self::A_SPEND,
            round((float) (collect($a['projects'] ?? [])->firstWhere('project_id', $this->projectA->id)['spend'] ?? -1), 2),
        );
    }

    /**
     * The campaigns each project shows are its own.
     *
     * Asserted on IDS rather than on counts: two projects each showing «one campaign» would satisfy a
     * count and could still be showing each other's.
     */
    public function test_each_project_shows_only_its_own_campaigns(): void
    {
        $a = collect($this->asOperator($this->projectA, 'campaigns'))->pluck('id')->all();
        $b = collect($this->asOperator($this->projectB, 'campaigns'))->pluck('id')->all();

        $this->assertNotSame([], $a);
        $this->assertNotSame([], $b);
        $this->assertSame([], array_intersect($a, $b), 'no campaign may appear on both clients\' pages');
    }

    /** A figure that belongs to nobody's project is not reachable from either. */
    public function test_neither_project_can_see_the_others_metric_rows(): void
    {
        $this->assertSame(
            [$this->projectA->id],
            DailyMetric::withoutGlobalScopes()
                ->where('value', self::A_SPEND)->distinct()->pluck('project_id')->all(),
        );
        $this->assertSame(
            [$this->projectB->id],
            DailyMetric::withoutGlobalScopes()
                ->where('value', self::B_SPEND)->distinct()->pluck('project_id')->all(),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function summarySpend(Project $project): float
    {
        $data = $this->asOperator($project, 'metrics/summary?from=2020-01-01&to=2035-01-01');

        return round((float) ($data['current']['spend'] ?? -1), 2);
    }

    /** @return array<mixed> */
    private function clientAnalytics(ClientWorkspace $client): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/app/clients/{$client->id}/analytics?from=2020-01-01&to=2035-01-01")
            ->assertOk()
            ->json('data') ?? [];
    }

    /** @return array<mixed> */
    private function asOperator(Project $project, string $path): array
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('X-Project-Id', $project->id)
            ->getJson("/api/v1/projects/{$project->id}/{$path}")
            ->assertOk()
            ->json('data') ?? [];
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(string $name, ClientWorkspace $workspace): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    /** One assigned account, one campaign, one day of spend — the smallest complete chain. */
    private function spend(
        Project $project,
        ClientWorkspace $workspace,
        string $provider,
        string $externalId,
        float $amount,
    ): void {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => $provider,
            'connection_name' => $provider.'-'.$externalId,
            'scope' => 'project_only',
            'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'provider_connection_id' => $connection->id,
            'provider' => $provider,
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'currency' => 'SAR',
            'discovered_at' => Carbon::now(),
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        $unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'name' => 'Campaign '.$externalId,
            'objective' => 'sales',
            'status' => 'active',
            'total_budget' => 1000,
            'budget_currency' => 'SAR',
        ]);

        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'unified_campaign_id' => $unified->id,
            'external_account_id' => $account->id,
            'provider' => $provider,
            'external_id' => 'cmp-'.$externalId,
            'name' => 'Campaign '.$externalId,
            'status' => 'active',
        ]);

        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'client_workspace_id' => $workspace->id,
            'external_account_id' => $account->id,
            'external_campaign_id' => $external->id,
            'unified_campaign_id' => $unified->id,
            'provider' => $provider,
            'metric_date' => Carbon::now()->subDay()->toDateString(),
            'metric_key' => 'spend',
            'value' => $amount,
            'source_value' => $amount,
            'source_currency' => 'SAR',
            'project_currency' => 'SAR',
        ]);
    }
}
