<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PORTFOLIO-SCOPE-001 — «جميع المشاريع» is a product, not the absence of a project.
 *
 * ## The distinction the owner is drawing
 *
 * There are two scopes and they are not interchangeable. A PROJECT scope answers about one client.
 * A PORTFOLIO scope answers about the agency. What must never exist is the middle state — a surface
 * that widens to every project because nobody chose one, so a number appears that no reader asked
 * for and cannot attribute.
 *
 * This product currently has the correct half of that: with no project selected, Analytics fetches
 * NOTHING (`enabled: Boolean(projectId)`). Fail-closed, and right. What it does not have is the
 * other half — a deliberate agency-wide view — so «all projects» was unavailable rather than
 * explicit, and the two are not the same answer.
 *
 * ## What is pinned here
 *
 * The reachability ceiling, first and hardest: a portfolio is bounded by the projects the READER may
 * reach, and a member of one client must not learn the agency's shape from it. Empty means empty —
 * never «everything», which is the inversion `ClientScopeResolver` and `DigestScope` were both
 * written to prevent, and this endpoint is a third place it would be easy to repeat.
 *
 * Then the money. Cross-project totals are where a portfolio invents figures: summing 100 SAR and
 * 100 USD into «200» is the classic one, and it is worse than useless because it looks precise.
 * Spend is therefore SEGMENTED by currency and never added across them.
 */
final class PortfolioScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $agencyWide;

    private ClientWorkspace $acme;

    private ClientWorkspace $beta;

    private Project $acmeProject;

    private Project $betaProject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->agencyWide = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->agencyWide, $this->tenant);
        $this->agencyWide->assignRole($role);

        $this->acme = ClientWorkspace::create(['name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'mode' => 'managed']);
        $this->beta = ClientWorkspace::create(['name' => 'Beta', 'slug' => 'beta-'.uniqid(), 'mode' => 'managed']);

        $this->acmeProject = $this->project($this->acme, 'رزة أفينيو', 'active');
        $this->betaProject = $this->project($this->beta, 'عميل آخر', 'paused');
    }

    /** The agency-wide reader sees the agency. */
    public function test_the_portfolio_answers_for_every_project_the_reader_may_reach(): void
    {
        $data = $this->portfolio($this->agencyWide);

        $this->assertSame(2, $data['projects']['total']);
        $this->assertSame(1, $data['projects']['by_status']['active']);
        $this->assertSame(1, $data['projects']['by_status']['paused']);

        $names = array_column($data['projects']['items'], 'name');
        sort($names);
        $this->assertSame(['رزة أفينيو', 'عميل آخر'], $names);
    }

    /**
     * **The ceiling.** A member confined to one client learns nothing about the other.
     */
    public function test_a_reader_confined_to_one_client_sees_only_that_client(): void
    {
        $member = $this->userWith(['projects.view']);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->acmeProject->id,
            'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);

        $data = $this->portfolio($member);

        $this->assertSame(1, $data['projects']['total']);
        $this->assertSame(['رزة أفينيو'], array_column($data['projects']['items'], 'name'));
    }

    /**
     * Empty is empty. Never «everything».
     *
     * A reader with no project membership and no agency-wide grant is the exact shape that produced
     * the inversion elsewhere in this product — an absent scope read as an unlimited one.
     */
    public function test_a_reader_who_reaches_nothing_gets_an_empty_portfolio(): void
    {
        $stranger = $this->userWith(['projects.view']);

        $data = $this->portfolio($stranger);

        $this->assertSame(0, $data['projects']['total']);
        $this->assertSame([], $data['projects']['items']);
        $this->assertSame([], $data['spend']['by_currency']);
    }

    /**
     * Money is segmented by currency and never added across it.
     *
     * 100 SAR beside 100 USD is not 200 of anything. The portfolio states each currency's own total
     * and how many projects report in it, which is the only cross-project money statement that is
     * true without an exchange rate nobody supplied.
     */
    public function test_spend_is_segmented_by_currency_and_never_summed_across_it(): void
    {
        $this->spend($this->acmeProject, 'SAR', 1_000.0);
        $this->spend($this->betaProject, 'USD', 400.0);

        $data = $this->portfolio($this->agencyWide);

        $byCurrency = collect($data['spend']['by_currency'])->keyBy('currency');

        $this->assertEqualsWithDelta(1_000.0, (float) $byCurrency['SAR']['spend'], 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $byCurrency['USD']['spend'], 0.01);
        $this->assertSame(1, $byCurrency['SAR']['projects']);

        // No single total anywhere — the shape itself must refuse to offer one.
        $this->assertArrayNotHasKey('total', $data['spend']);
        $this->assertTrue($data['spend']['comparable'] === false, 'two currencies were declared comparable');
    }

    /** One currency across the estate IS comparable, and says so. */
    public function test_one_currency_across_the_estate_is_stated_as_comparable(): void
    {
        $this->spend($this->acmeProject, 'SAR', 1_000.0);
        $this->spend($this->betaProject, 'SAR', 250.0);

        $data = $this->portfolio($this->agencyWide);

        $this->assertTrue($data['spend']['comparable']);
        $this->assertCount(1, $data['spend']['by_currency']);
        $this->assertEqualsWithDelta(1_250.0, (float) $data['spend']['by_currency'][0]['spend'], 0.01);
    }

    /**
     * The portfolio says which projects need attention, reusing the projects list's own vocabulary.
     *
     * Not a second rulebook: `no_accounts`, `never_synced` and `stale` are the states the project
     * cards already speak, so a count here and a badge there cannot drift apart.
     */
    public function test_the_portfolio_counts_the_projects_needing_attention(): void
    {
        $data = $this->portfolio($this->agencyWide);

        // Neither project has a binding, so both are `no_accounts`.
        $this->assertSame(2, $data['attention']['total']);
        $this->assertSame(2, $data['attention']['by_state']['no_accounts']);
    }

    /** A portfolio is not reachable without the permission a project listing needs. */
    public function test_the_portfolio_refuses_a_reader_without_project_view(): void
    {
        $nobody = $this->userWith(['analytics.view']);

        $this->actingAs($nobody, 'sanctum')->getJson('/api/v1/portfolio/overview')->assertForbidden();
    }

    /** Another tenant's estate is never part of this one's portfolio. */
    public function test_another_tenants_projects_are_not_in_this_portfolio(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $otherClient = ClientWorkspace::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'mode' => 'managed']);
        Project::create([
            'tenant_id' => $other->id, 'client_workspace_id' => $otherClient->id,
            'name' => 'مشروع غريب', 'status' => 'active',
        ]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $data = $this->portfolio($this->agencyWide);

        $this->assertSame(2, $data['projects']['total']);
        $this->assertNotContains('مشروع غريب', array_column($data['projects']['items'], 'name'));
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function portfolio(User $user): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/portfolio/overview')
            ->assertOk()
            ->json('data');
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R'.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);

        $user = User::create(['name' => 'U', 'email' => uniqid().'@ag.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(ClientWorkspace $client, string $name, string $status): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $client->id,
            'name' => $name,
            'status' => $status,
        ]);
    }

    private function spend(Project $project, string $currency, float $amount): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'provider' => 'snapchat',
            /*
             * The account and campaign are required columns and are not what this test is about —
             * a portfolio total is a fact about the PROJECT, so these are stable stand-ins.
             */
            'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(),
            'attribution_window' => 'default',
            'source_type' => 'api',
            'is_demo' => false,
            'metric_key' => 'spend',
            'metric_date' => Carbon::today()->subDay()->toDateString(),
            'value' => $amount,
            'project_currency' => $currency,
            'original_currency' => $currency,
            'original_amount' => $amount,
            'converted_amount' => $amount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
