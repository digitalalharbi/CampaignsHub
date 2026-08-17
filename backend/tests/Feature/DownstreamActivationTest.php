<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
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
 * RUNTIME-100 §20–§25 — one confirmed selection, and every surface that should light up does.
 *
 * ## What this suite is for, and what it deliberately is not
 *
 * Each surface below already has its own tests. What none of them held is the CHAIN: that the act of
 * assigning an account to a project is what makes campaigns appear, metrics appear, analytics add up,
 * reports have something to report, and — the half that matters more — that the OTHER client of the
 * same agency sees none of it.
 *
 * The two halves are inseparable. «The data arrived» is easy to satisfy by filing everything into the
 * first project to hand, which is exactly what `ASSIGN-PROJECT-001` and `METRICS-RUN-PROJECT-001`
 * both did. So every assertion here comes in a pair: the assigned project has it, and the other
 * client's project does not.
 *
 * ## Why the sandbox provider
 *
 * It is the one connector on this install that returns real structure and real insight rows without
 * credentials, so the pipeline runs end to end for real rather than against a mocked syncer. The
 * numbers below are the sandbox's own — nothing here asserts a figure this test invented.
 */
final class DownstreamActivationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $account;

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

        // A is created first on purpose: it is the project every «oldest project» fallback would pick.
        $this->clientA = $this->workspace('Client A');
        $this->clientB = $this->workspace('Client B');
        $this->projectA = $this->project('A — retainer', $this->clientA);
        $this->projectB = $this->project('B — retainer', $this->clientB);

        $this->account = $this->assignedAccount();

        app(TenantContext::class)->forget();
    }

    // ── The pipeline ──────────────────────────────────────────────────────────────────────────

    /** Structure lands in the assigned project, and nowhere else. */
    public function test_campaigns_arrive_in_the_assigned_project_only(): void
    {
        $this->firstSync();

        $imported = ExternalCampaign::withoutGlobalScopes()->get();

        $this->assertGreaterThan(0, $imported->count(), 'the first sync must actually import something');
        $this->assertSame(
            [$this->projectB->id],
            $imported->pluck('project_id')->unique()->values()->all(),
            'RUNTIME-100 §15: every imported campaign belongs to the project the account was assigned to',
        );
    }

    /** Metrics land against those campaigns, and carry the same project. */
    public function test_metrics_arrive_against_the_assigned_project_only(): void
    {
        $this->firstSync();

        $metrics = DailyMetric::withoutGlobalScopes()->get();

        $this->assertGreaterThan(0, $metrics->count(), 'the first sync must actually import metrics');
        $this->assertSame([$this->projectB->id], $metrics->pluck('project_id')->unique()->values()->all());
    }

    // ── The surfaces ──────────────────────────────────────────────────────────────────────────

    /**
     * **The defect, pinned.** RUNTIME-100 §20 — the Campaigns PAGE shows them, with nothing to press.
     *
     * The page lists `unified_campaigns`, and nothing in the sync path had ever created one:
     * `unified_campaign_id` was only ever set by hand, by a request conversion, or by the demo
     * seeder. So a real first sync filled `external_campaigns` correctly, attached every metric
     * correctly — and /app/campaigns was empty, with no error and nothing to do about it.
     */
    public function test_the_campaigns_page_shows_them_for_b_and_nothing_for_a(): void
    {
        $this->firstSync();

        $visible = $this->asOperator($this->projectB, 'campaigns');

        $this->assertGreaterThan(
            0,
            count($visible),
            'CAMPAIGNS-VISIBLE-001: campaigns were imported and the page that lists them stayed empty.',
        );
        $this->assertSame([], $this->asOperator($this->projectA, 'campaigns'));
    }

    /** Re-syncing does not create a second visible campaign for the same platform campaign. */
    public function test_a_second_sync_does_not_duplicate_the_visible_campaign(): void
    {
        $this->firstSync();
        $first = count($this->asOperator($this->projectB, 'campaigns'));

        $this->firstSync();

        $this->assertSame($first, count($this->asOperator($this->projectB, 'campaigns')));
    }

    /** RUNTIME-100 §22 §23 — the dashboard and analytics totals are the project's own. */
    public function test_analytics_totals_belong_to_the_assigned_project(): void
    {
        $this->firstSync();

        $b = $this->asOperator($this->projectB, 'metrics/summary?from=2020-01-01&to=2035-01-01');
        $a = $this->asOperator($this->projectA, 'metrics/summary?from=2020-01-01&to=2035-01-01');

        $this->assertGreaterThan(0, (float) ($b['current']['spend'] ?? 0), 'B spent what the account spent');
        $this->assertSame(0.0, (float) ($a['current']['spend'] ?? 0), 'A spent nothing, because none of this is A\'s');
    }

    /** The platform breakdown names the provider for B, and is empty for A. */
    public function test_the_platform_breakdown_is_scoped(): void
    {
        $this->firstSync();

        $this->assertNotSame([], $this->asOperator($this->projectB, 'metrics/platforms?from=2020-01-01&to=2035-01-01'));
        $this->assertSame([], $this->asOperator($this->projectA, 'metrics/platforms?from=2020-01-01&to=2035-01-01'));
    }

    /** RUNTIME-100 §25 — the agency's OTHER client sees none of this in their own analytics. */
    public function test_the_other_clients_analytics_contain_none_of_it(): void
    {
        $this->firstSync();

        $a = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/app/clients/{$this->clientA->id}/analytics?from=2020-01-01&to=2035-01-01")
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) ($a['totals']['spend'] ?? 0));
        $this->assertNotContains(
            $this->projectB->id,
            collect($a['projects'] ?? [])->pluck('project_id')->all(),
            'RUNTIME-100 §25: one agency client must not be able to see another\'s project at all',
        );
    }

    /** And the client whose account it is does see it. A fence that blocks everything proves nothing. */
    public function test_the_owning_clients_analytics_contain_it(): void
    {
        $this->firstSync();

        $b = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/app/clients/{$this->clientB->id}/analytics?from=2020-01-01&to=2035-01-01")
            ->assertOk()
            ->json('data');

        $this->assertGreaterThan(0, (float) ($b['totals']['spend'] ?? 0));
        $this->assertContains($this->projectB->id, collect($b['projects'] ?? [])->pluck('project_id')->all());
    }

    /** RUNTIME-100 §24 — a report built for B has the synced figures to report. */
    public function test_a_report_built_for_the_assigned_project_has_the_synced_figures(): void
    {
        $this->firstSync();

        $freshness = $this->asOperator($this->projectB, 'metrics/freshness');

        $this->assertNotSame([], $freshness, 'RUNTIME-100 §24: a report reads live project data, so freshness must be answerable');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /**
     * The first sync, exactly as `FirstSync` queues it: structure, then metrics.
     *
     * Run inline rather than through the queue because what is under test is the DATA the pipeline
     * produces and where it lands — the queueing itself is held by `AtomicSelectionConfirmTest`.
     */
    private function firstSync(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        app(AccountStructureSyncer::class)->sync($this->account);
        app(AccountMetricsSyncer::class)->sync(
            $this->account->refresh(),
            Carbon::now()->subDays(30)->startOfDay(),
            Carbon::now()->endOfDay(),
        );

        app(TenantContext::class)->forget();
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

    private function assignedAccount(): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $this->tenant->id,
            'provider' => 'sandbox', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'sandbox',
            'connection_name' => 'sandbox',
            'scope' => 'project_only',
            'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $connection->id,
            'provider' => 'sandbox',
            'account_type' => 'ad_account',
            'external_id' => 'sandbox-act-1',
            'name' => 'Sandbox account',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
            'last_synced_at' => null,
        ]);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->clientB->id,
            'project_id' => $this->projectB->id,
            'external_account_id' => $account->id,
            'provider' => 'sandbox',
            'purpose' => 'advertising',
            'is_active' => true,
        ]);

        return $account;
    }
}
