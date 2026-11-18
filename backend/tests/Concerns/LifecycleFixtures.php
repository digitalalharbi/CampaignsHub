<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PROJECT-LIFECYCLE-001 — generated fixtures for every workspace type (rule 1). No production id, ever.
 *
 * `lifecycleWorkspace('agency'|'business'|'portal')` builds a tenant of that type with:
 *  - an owner (role `tenant-owner`, every key) and a member holding projects.view + projects.update only;
 *  - one client; for `portal` also a client-portal user of that client.
 * The `operational*` helpers add the things an archive must stop, filtered by project id.
 */
trait LifecycleFixtures
{
    use GrantsMemberships;

    /**
     * @return array{tenant: Tenant, owner: User, member: User, client: ClientWorkspace, portal_user: User|null, type: string}
     */
    protected function lifecycleWorkspace(string $type = 'agency', string $suffix = ''): array
    {
        $this->seed(PermissionSeeder::class); // idempotent (firstOrCreate)
        $slug = $type.'-'.Str::lower(Str::random(6)).$suffix;
        $tenant = Tenant::create([
            'name' => ucfirst($type).' WS '.$suffix,
            'slug' => $slug,
            'status' => 'active',
            'account_type' => $type === 'business' ? 'self_serve_company' : 'agency',
        ]);
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        $ownerRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $memberRole = Role::create(['tenant_id' => $tenant->id, 'name' => 'Manager', 'slug' => 'project-manager']);
        $memberRole->givePermissionTo('projects.view', 'projects.view.all', 'projects.update', 'clients.view', 'clients.update');

        $portal = $type === 'business' ? Portal::App : Portal::Agency;
        $owner = User::create(['name' => 'Owner '.$slug, 'email' => "owner@{$slug}.test", 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($owner, $tenant, $portal, 'owner');
        $owner->assignRole($ownerRole);
        $member = User::create(['name' => 'Member '.$slug, 'email' => "member@{$slug}.test", 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($member, $tenant, $portal);
        $member->assignRole($memberRole);

        $client = ClientWorkspace::create([
            'name' => 'Client '.$slug, 'slug' => 'client-'.$slug, 'mode' => 'managed',
            'client_status' => $type === 'business' ? 'active' : 'onboarding',
        ]);

        $portalUser = null;
        if ($type === 'portal') {
            $portalUser = User::create(['name' => 'Portal '.$slug, 'email' => "portal@{$slug}.test", 'password' => 'secret123', 'email_verified_at' => now()]);
            $this->grantMembership($portalUser, $tenant, Portal::ClientPortal, 'member', [(string) $client->id]);
        }
        app(TenantContext::class)->forget();

        return ['tenant' => $tenant, 'owner' => $owner, 'member' => $member, 'client' => $client, 'portal_user' => $portalUser, 'type' => $type];
    }

    protected function lifecycleProject(Tenant $tenant, ClientWorkspace $client, string $name, string $status = 'active'): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => $status,
        ]);
    }

    protected function lifecycleAccount(Tenant $tenant, string $provider = 'snapchat', string $currency = 'USD'): ExternalAccount
    {
        $credential = new IntegrationCredential([
            'tenant_id' => $tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $provider.'-'.Str::lower(Str::random(8)), 'name' => 'Account '.$provider,
            'currency' => $currency, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }

    protected function lifecycleBind(ExternalAccount $account, Project $project): string
    {
        $id = (string) Str::uuid();
        DB::table('project_integration_bindings')->insert([
            'id' => $id, 'tenant_id' => $project->tenant_id, 'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id, 'external_account_id' => $account->id, 'provider' => $account->provider,
            'purpose' => 'advertising', 'is_primary' => true, 'is_active' => true, 'sync_enabled' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Everything an archive must stop, for one project.
     *
     * @return array<string,string> kind => row id
     */
    protected function lifecycleOperational(Project $project, ?User $creator = null): array
    {
        $now = now();
        $t = (string) $project->tenant_id;
        $p = (string) $project->id;
        $ids = [];

        $ids['report'] = (string) Str::uuid();
        DB::table('reports')->insert(['id' => $ids['report'], 'tenant_id' => $t, 'project_id' => $p, 'name' => 'R', 'type' => 'weekly', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $ids['schedule'] = (string) Str::uuid();
        DB::table('report_schedules')->insert(['id' => $ids['schedule'], 'tenant_id' => $t, 'project_id' => $p, 'report_id' => $ids['report'], 'name' => 'S', 'type' => 'weekly', 'frequency' => 'weekly', 'active' => true, 'next_run_at' => $now->copy()->addDay(), 'created_at' => $now, 'updated_at' => $now]);
        $ids['alert_rule'] = (string) Str::uuid();
        DB::table('alert_rules')->insert(['id' => $ids['alert_rule'], 'tenant_id' => $t, 'project_id' => $p, 'type' => 'spend_spike', 'name' => 'A', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $ids['alert_event'] = (string) Str::uuid();
        DB::table('alert_events')->insert(['id' => $ids['alert_event'], 'tenant_id' => $t, 'project_id' => $p, 'rule_id' => $ids['alert_rule'], 'type' => 'spend_spike', 'dedup_key' => 'k-'.$p, 'status' => 'open', 'severity' => 'warning', 'created_at' => $now, 'updated_at' => $now]);
        $ids['task'] = (string) Str::uuid();
        DB::table('tasks')->insert(['id' => $ids['task'], 'tenant_id' => $t, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $p, 'title' => 'T', 'status' => 'in_progress', 'priority' => 'normal', 'created_at' => $now, 'updated_at' => $now]);
        $ids['automation'] = (string) Str::uuid();
        DB::table('automation_rules')->insert(['id' => $ids['automation'], 'tenant_id' => $t, 'project_id' => $p, 'name' => 'Au', 'enabled' => true, 'mode' => 'notify', 'level' => 'campaign', 'logic' => 'all', 'conditions' => '[]', 'action' => '{}', 'schedule' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        $ids['routine'] = (string) Str::uuid();
        DB::table('agent_routines')->insert(['id' => $ids['routine'], 'tenant_id' => $t, 'project_id' => $p, 'routine' => 'daily_review', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

        return $ids;
    }
}
