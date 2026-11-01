<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;

/**
 * Two clients in one agency, one project each, and operators confined to one of them — the fixture the
 * client-ceiling tests share (SEC-CLUSTER-4). `$this->owner()` is the unrestricted reader, `$this->scopedTo()`
 * a membership named to a single client: it must reach that client and nothing else.
 */
trait BuildsCeilingFixture
{
    protected Tenant $agency;

    protected ClientWorkspace $clientA;

    protected ClientWorkspace $clientB;

    protected Project $projectA;

    protected Project $projectB;

    protected function buildCeilingFixture(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Ceiling Agency', 'slug' => 'ceiling-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);

        $this->clientA = $this->makeClient('Client A', $this->agency);
        $this->clientB = $this->makeClient('Client B', $this->agency);
        $this->projectA = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->clientA->id, 'name' => 'Project A', 'status' => 'active']);
        $this->projectB = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $this->clientB->id, 'name' => 'Project B', 'status' => 'active']);
    }

    protected function makeClient(string $name, Tenant $tenant): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    /**
     * @param  list<string>  $permissions  empty = every permission in the catalogue
     * @param  list<string>|null  $clientIds  null/[] = no scope rows
     */
    protected function operatorIn(Tenant $tenant, array $permissions = [], ?array $clientIds = null, ?string $email = null): User
    {
        $user = User::create([
            'name' => 'Op '.uniqid(), 'email' => $email ?? 'op-'.uniqid().'@ceiling.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...($permissions === [] ? Permission::pluck('key')->all() : $permissions));
        $user->assignRole($role);
        $this->grantMembership($user, $tenant, Portal::Agency, 'member', $clientIds ?? []);

        return $user;
    }

    /** The unrestricted agency reader: every permission, `clients.view_all`, no scope rows. */
    protected function owner(): User
    {
        return $this->operatorIn($this->agency);
    }

    /**
     * A member holding $permissions (plus clients.view so the ceiling is exercised, not the permission),
     * named to exactly one client.
     *
     * @param  list<string>  $permissions
     */
    protected function scopedTo(ClientWorkspace $client, array $permissions): User
    {
        return $this->operatorIn($this->agency, array_values(array_unique([...$permissions, 'clients.view'])), [(string) $client->id]);
    }
}
