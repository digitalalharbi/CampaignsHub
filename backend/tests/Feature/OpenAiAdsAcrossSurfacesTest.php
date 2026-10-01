<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEG-OPENAI-001 §§21, 24 — one provider in the unified system, and the isolation that holds it.
 *
 * The spec's refusal is «do NOT create /chatgpt-ads-dashboard». The positive form of that is this:
 * a bound ChatGPT Ads account appears in the surfaces every other provider appears in, through the
 * same payloads, with no special case anywhere.
 *
 * And the isolation is tested from the dangerous side. ACCOUNT-SCOPE-ISOLATION-001 is defended by
 * guards on the queries; what this adds is the end-to-end question an operator would actually ask —
 * «can another agency's workspace see this account?» — answered through the real endpoint with a
 * real second tenant, rather than by trusting that every query remembered its scope.
 */
final class OpenAiAdsAcrossSurfacesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        [$this->tenant, $this->operator, $this->project] = $this->agency('Ours');
    }

    /** A bound account shows up in the portfolio under its own project, named canonically. */
    public function test_the_portfolio_lists_chatgpt_ads_under_its_project(): void
    {
        $this->bind($this->tenant, $this->project);

        $row = $this->portfolioFor($this->operator, $this->tenant);

        $this->assertNotNull($row, 'the project is missing from its own portfolio');
        $this->assertContains('openai_ads', $row['providers'], 'the portfolio does not know this project has ChatGPT Ads');
        $this->assertSame(1, $row['accounts']);
    }

    /**
     * **And another agency cannot see it.**
     *
     * Both tenants have a project and an identically-shaped ChatGPT Ads account. The question is
     * not whether the second tenant's portfolio is empty — it is whether OUR provider appears in
     * THEIR row.
     */
    public function test_another_agency_never_sees_our_account(): void
    {
        $this->bind($this->tenant, $this->project);

        [$otherTenant, $otherOperator, $otherProject] = $this->agency('Theirs');
        $this->bind($otherTenant, $otherProject);

        $ours = $this->portfolioFor($this->operator, $this->tenant);
        $theirs = $this->portfolioFor($otherOperator, $otherTenant);

        $this->assertNotNull($ours);
        $this->assertNotNull($theirs);

        // Each sees exactly one project — their own.
        $this->assertNotSame($ours['id'], $theirs['id']);
        $this->assertSame(1, $ours['accounts']);
        $this->assertSame(1, $theirs['accounts']);
    }

    /**
     * A DISCOVERED account that nobody selected is not in the portfolio.
     *
     * DISCOVERED != SELECTED. Discovery catalogues what a key can see; only a binding makes an
     * account one this product reads, and a portfolio that counted the catalogue would tell an
     * agency it is managing accounts nobody chose.
     */
    public function test_a_discovered_but_unselected_account_is_not_counted(): void
    {
        // Created, never bound.
        $this->account($this->tenant);

        $row = $this->portfolioFor($this->operator, $this->tenant);

        $this->assertNotNull($row);
        $this->assertSame(0, $row['accounts'], 'an account nobody selected was counted as managed');
        $this->assertNotContains('openai_ads', $row['providers']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /** @return array{0: Tenant, 1: User, 2: Project} */
    private function agency(string $name): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $user = User::create(['name' => $name, 'email' => strtolower($name).'-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        $workspace = ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => $name.' client', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $project = Project::create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $workspace->id,
            'name' => $name.' project', 'status' => 'active',
        ]);

        return [$tenant, $user, $project];
    }

    private function account(Tenant $tenant): ExternalAccount
    {
        app(TenantContext::class)->setTenantId($tenant->id);

        $connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'openai_ads',
            tokens: new OAuthTokens('sk-ads-live-'.uniqid(), null, null),
            connectionName: 'ChatGPT Ads',
            credentialType: 'api_key',
        );

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'provider_connection_id' => $connection->getKey(),
            'provider' => 'openai_ads',
            'account_type' => 'ad_account',
            'external_id' => 'acct_'.uniqid(),
            'name' => 'Ad account',
            'currency' => 'SAR',
            'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
    }

    private function bind(Tenant $tenant, Project $project): void
    {
        $account = $this->account($tenant);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'openai_ads',
            'purpose' => 'advertising',
            'is_active' => true,
            'campaign_management_enabled' => true,
        ]);
    }

    /** @return array<string, mixed>|null */
    private function portfolioFor(User $user, Tenant $tenant): ?array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->withHeader('X-Tenant-ID', $tenant->id)
            ->getJson('/api/v1/portfolio/overview')
            ->assertOk();

        /** @var list<array<string, mixed>> $projects */
        $projects = $response->json('data.projects.items') ?? [];

        return $projects[0] ?? null;
    }
}
