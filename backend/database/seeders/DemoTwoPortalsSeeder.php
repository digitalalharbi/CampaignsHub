<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

/**
 * ENSURE-PORTAL-SESSION-001 — a person who really holds two workspaces in two portals.
 *
 * `DemoPortalLoginsSeeder` keeps one account per portal so the portals can be told apart, which leaves the case that
 * tenant isolation is hardest on with no account: one session, two tenants. This is that account and nothing else. It
 * has no ad account, so it selects none, and it owns its two tenants so no other demo world's numbers move.
 *
 * DEVELOPMENT, E2E AND DEMO ONLY. Called from the same guarded block as the other demo logins, and it refuses in
 * production again here, because its password is published.
 */
final class DemoTwoPortalsSeeder extends Seeder
{
    public const EMAIL = 'two-portals@demo.campaignshub.io';

    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->warn('The two-portal demo account is development-only — skipped.');

            return;
        }

        $user = User::firstOrNew(['email' => self::EMAIL]);
        $user->forceFill([
            'name' => 'Two Portals',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ])->save();

        // Advertiser first: the first membership granted is the default.
        $this->workspace($user, 'Two Portals Brand', 'brand', Portal::App);
        $this->workspace($user, 'Two Portals Agency', 'agency', Portal::Agency);

        app(TenantContext::class)->forget();
    }

    private function workspace(User $user, string $name, string $type, Portal $portal): void
    {
        $slug = str($name)->slug()->value();

        $tenant = Tenant::query()->withoutGlobalScopes()->firstOrCreate(['slug' => $slug], [
            'name' => $name, 'status' => 'active', 'account_type' => $type,
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);

        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'slug' => 'tenant-owner'],
            ['name' => 'Tenant Owner', 'is_system' => true],
        );
        $role->givePermissionTo(...Permission::query()->pluck('key')->all());
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user->refresh(), tenant: $tenant, portal: $portal, role: 'owner',
        ));

        $client = ClientWorkspace::query()->firstOrCreate(['slug' => $slug.'-client'], [
            'name' => $name.' Client', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        Project::query()->firstOrCreate(
            ['client_workspace_id' => $client->getKey(), 'name' => $name.' Project'],
            ['status' => 'active'],
        );
    }
}
