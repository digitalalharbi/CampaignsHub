<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2\Concerns;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Workspaces, members and projects for the notifications-v2 engine tests. Call `seedPermissions()` in setUp. */
trait BuildsWorkspaces
{
    protected function seedPermissions(): void
    {
        $this->seed(PermissionSeeder::class);
    }

    protected function tenant(string $name = 'Alpha', ?string $accountType = null): Tenant
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-nv2-'.uniqid(), 'status' => 'active', 'account_type' => $accountType]);
        app(TenantContext::class)->setTenantId($tenant->id);

        return $tenant;
    }

    /**
     * @param  list<string>|null  $permissions  null = every key
     */
    protected function member(Tenant $tenant, string $roleSlug = 'tenant-owner', ?array $permissions = null, string $portal = 'agency', string $email = ''): User
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::query()->where('tenant_id', $tenant->id)->where('slug', $roleSlug)->first()
            ?? Role::create(['tenant_id' => $tenant->id, 'name' => $roleSlug, 'slug' => $roleSlug]);
        $keys = $permissions ?? Permission::pluck('key')->all();
        if ($keys !== []) {
            $role->givePermissionTo(...$keys);
        }
        $user = User::create(['name' => 'U '.uniqid(), 'email' => $email !== '' ? $email : 'u-'.uniqid().'@nv2.test', 'password' => 'secret123']);
        $user->forceFill(['email_verified_at' => now()])->save();
        Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'portal' => $portal, 'status' => 'active']);
        $user->assignRole($role);

        return $user;
    }

    protected function project(Tenant $tenant, string $name = 'Project', string $status = 'active'): Project
    {
        app(TenantContext::class)->setTenantId($tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'C '.$name, 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);

        return Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $ws->id, 'name' => $name, 'status' => $status]);
    }

    /**
     * NOTIFY-STOPPED-001 — a project that SPENT yesterday: the only kind the budget scan asks about (a quiet or never-spent project
     * is told nothing).
     */
    protected function spendingProject(Tenant $tenant, string $name = 'Project', float $spend = 100.0): Project
    {
        $project = $this->project($tenant, $name);
        DailyMetric::query()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'provider' => 'snapchat', 'metric_key' => 'spend',
            'metric_date' => Carbon::today('Asia/Riyadh')->subDay()->toDateString(), 'attribution_window' => 'default',
            'value' => $spend, 'original_amount' => $spend, 'original_currency' => 'SAR', 'project_currency' => 'SAR',
        ]);

        return $project;
    }
}
