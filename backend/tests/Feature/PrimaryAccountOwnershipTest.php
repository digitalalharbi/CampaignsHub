<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * INTEGRATION-PRIMARY-ACCOUNT-001 — which project an account's data belongs to, changeable.
 *
 * ## Why `is_primary` is not a preference
 *
 * It reads like a cosmetic ordering flag and is not one. An external account can be bound to more
 * than one project, and `AccountAssignment::projectIdFor()` decides which project OWNS its rows by
 * `is_primary DESC, created_at ASC`. So the flag settles the account-scope chain for every sync that
 * account feeds — the same chain ACCOUNT-SCOPE-ISOLATION-001 is about.
 *
 * ## The gap
 *
 * It was written once, by the confirm step, and never again. An operator who confirmed without
 * naming a primary — or named the wrong one — had the account filed under the OLDEST binding for
 * ever, and the only way to change it was to unbind and rebind, which throws the history away.
 *
 * ## One owner, never two
 *
 * Setting this project's binding primary clears the flag on that account's other bindings, in one
 * transaction. Two primaries would make the ordering answer arbitrarily, which is the state it
 * exists to prevent; none would silently hand the account back to whichever binding is oldest.
 */
final class PrimaryAccountOwnershipTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    private ClientWorkspace $workspace;

    private ProviderConnection $connection;

    private Project $first;

    private Project $second;

    private ExternalAccount $shared;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);

        $this->first = $this->project('المشروع الأول');
        $this->second = $this->project('المشروع الثاني');

        $credential = new IntegrationCredential([
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('t');
        $credential->save();

        $this->connection = ProviderConnection::create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id,
            'provider' => 'snapchat', 'connection_name' => 'snapchat',
            'scope' => 'project_only', 'status' => 'connected',
        ]);

        $this->shared = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->id,
            'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => 'act-shared', 'name' => 'Shared',
            'status' => 'active', 'discovered_at' => now(),
        ]);
    }

    /**
     * **The gap, closed.** Ownership moves, and `projectIdFor` follows it.
     */
    public function test_naming_a_primary_moves_which_project_owns_the_account(): void
    {
        $this->bind($this->first, primary: true);
        $this->bind($this->second, primary: false);

        $this->assertSame(
            (string) $this->first->id,
            app(AccountAssignment::class)->projectIdFor($this->shared),
        );

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->second->id}/integrations/primary", [
                'external_account_id' => (string) $this->shared->id,
            ])
            ->assertOk();

        $this->assertSame(
            (string) $this->second->id,
            app(AccountAssignment::class)->projectIdFor($this->shared),
            'the account still files under the previous project',
        );
    }

    /** One owner, never two — the ordering has nothing to disambiguate. */
    public function test_only_one_binding_is_primary_for_an_account(): void
    {
        $this->bind($this->first, primary: true);
        $this->bind($this->second, primary: false);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->second->id}/integrations/primary", [
                'external_account_id' => (string) $this->shared->id,
            ])
            ->assertOk();

        $primaries = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $this->shared->id)
            ->where('is_primary', true)
            ->pluck('project_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $this->assertSame([(string) $this->second->id], $primaries);
    }

    /** Idempotent: naming the same account twice is the same decision. */
    public function test_naming_the_same_primary_twice_is_the_same_decision(): void
    {
        $this->bind($this->first, primary: true);

        for ($i = 0; $i < 2; $i++) {
            $this->actingAs($this->operator, 'sanctum')
                ->putJson("/api/v1/projects/{$this->first->id}/integrations/primary", [
                    'external_account_id' => (string) $this->shared->id,
                ])
                ->assertOk();
        }

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $this->shared->id)->where('is_primary', true)->count());
    }

    /**
     * A project cannot claim an account it does not actively hold.
     *
     * 404 rather than 422: «that account is not yours» is the same answer whether the binding
     * belongs to a neighbour or does not exist, and neither is this caller's business to tell apart.
     */
    public function test_a_project_cannot_claim_an_account_it_does_not_hold(): void
    {
        $this->bind($this->first, primary: true);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->second->id}/integrations/primary", [
                'external_account_id' => (string) $this->shared->id,
            ])
            ->assertNotFound();

        $this->assertSame(
            (string) $this->first->id,
            app(AccountAssignment::class)->projectIdFor($this->shared),
        );
    }

    /** A deselected binding is not a claim either — it stopped feeding this project. */
    public function test_a_deactivated_binding_cannot_be_made_primary(): void
    {
        $this->bind($this->first, primary: true);
        $this->bind($this->second, primary: false, active: false);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson("/api/v1/projects/{$this->second->id}/integrations/primary", [
                'external_account_id' => (string) $this->shared->id,
            ])
            ->assertNotFound();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function project(string $name): Project
    {
        return Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function bind(Project $project, bool $primary, bool $active = true): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $this->shared->id,
            'provider' => 'snapchat',
            'purpose' => 'reporting',
            'is_primary' => $primary,
            'is_active' => $active,
        ]);
    }
}
