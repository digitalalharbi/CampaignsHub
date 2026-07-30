<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A scoped account manager in the demo agency (ADR 0002).
 *
 * Without this, the demo agency is three people who all see everything, and the model that the whole
 * portal is built on — a membership that names specific clients and is confined to them — is never
 * actually exercised by the seeded data. Anyone opening the demo would conclude the ceiling does
 * nothing, because there is no one standing under it.
 *
 * The manager is granted ONE client, deliberately, so that signing in as them shows the difference:
 * a client list of one, a dashboard that says so, and no way to reach the other four.
 *
 * Idempotent; safe to re-run.
 */
final class DemoAgencyScopeSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->id);

        // The clients this agency manages. Nothing to demonstrate without at least one.
        $client = ClientWorkspace::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('archived_at')
            ->orderBy('created_at')
            ->first();

        if ($client === null) {
            return;
        }

        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'account-manager'],
            ['name' => 'Account Manager', 'is_system' => true],
        );
        // `clients.view` WITHOUT `clients.view_all` — the combination the ceiling actually applies to.
        $role->givePermissionTo(
            'clients.view', 'clients.update', 'clients.view_analytics', 'clients.view_reports',
            'campaigns.view', 'projects.view', 'reports.view', 'requests.view', 'tasks.view',
        );

        $manager = User::firstOrCreate(
            ['email' => 'manager@demo-agency.local'],
            [
                'name' => 'Demo Account Manager',
                'password' => Hash::make('password'),
                'tenant_id' => $tenant->id,
                'email_verified_at' => now(),
            ],
        );
        $manager->assignRole($role);

        // Granting is additive, so re-running adds nothing and removes nothing.
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $manager,
            tenant: $tenant,
            portal: Portal::Agency,
            role: 'account_manager',
            clientScopeIds: [(string) $client->id],
        ));

        $this->command?->info("Demo agency: manager@demo-agency.local scoped to “{$client->name}”.");
    }
}
