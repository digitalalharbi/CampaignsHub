<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

/**
 * One demo account per portal, and no account that spans two (SIGNUP-006).
 *
 * The defect this closes was in the sign-in page rather than the data: every portal tab offered the
 * same agency login, so "try the influencer portal" meant signing in as an agency operator and
 * looking at the agency console. A demo set that cannot demonstrate the difference between the
 * portals is worse than none, because it makes them look identical when they are not.
 *
 * The other two accounts already exist and are left alone — `owner@demo-agency.local` and
 * `owner@demo-company.local` are owned by `DemoAccountsSeeder` and carry a whole demo world with them.
 * This seeder adds what was missing:
 *
 *   - `admin@demo-campaignshub.local` — the platform owner's console. ADDED rather than renaming
 *     `platform@mediabuying.local`, because renaming a provisioning account breaks every existing
 *     install that signs in with it.
 *   - `client@demo-portal.local` — the client portal. See the note on that method: the portal's own
 *     engine is still OTP, and this account does not pretend otherwise.
 *
 * DEVELOPMENT ONLY. `shouldRun()` refuses in production, so a deployed install has no account whose
 * password is published in a seeder.
 */
final class DemoPortalLoginsSeeder extends Seeder
{
    private const PASSWORD = 'password';

    public function run(): void
    {
        if (! $this->shouldRun()) {
            $this->command?->warn('Demo portal logins are development-only — skipped.');

            return;
        }

        $this->platformOwner();
        $this->clientPortalCustomer();

        $this->report();
    }

    /**
     * Never in production, and never where demo secrets are hidden.
     *
     * The same switch the rest of the product uses to decide whether a dev-only verification link may
     * be shown, so there is one answer to "is this a development environment?" rather than two that
     * can disagree.
     */
    private function shouldRun(): bool
    {
        return ! App::environment('production');
    }

    /** The platform owner's console — belongs to no tenant, by design (ADR 0002). */
    private function platformOwner(): void
    {
        $user = User::firstOrNew(['email' => 'admin@demo-campaignshub.local']);

        $user->forceFill([
            'name' => 'Platform Admin (demo)',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            // The console is reached by this flag, never by a membership: giving the owner one would
            // place them inside a workspace they administer.
            'is_platform_admin' => true,
        ])->save();
    }

    /**
     * The client portal's demo customer.
     *
     * **Honest note.** `/portal` is still served by its own OTP token engine (PORTAL-AUTH-001), so a
     * password on this account does not by itself open the client portal — the tracking link and the
     * one-time code do. The account exists so the membership model is complete and so the portal has a
     * named identity to demonstrate, and the sign-in page sends the client tab to `/portal/login`
     * rather than offering a password box that would not work.
     *
     * Faking it the other way — issuing a password login for a portal whose engine is OTP — is
     * exactly what the contract forbids: «لا تزوّر دخول عميل البوابة بكلمة مرور بينما محركها ما زال OTP».
     */
    private function clientPortalCustomer(): void
    {
        $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            $this->command?->warn('No demo-agency tenant — the client portal demo account was skipped.');

            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $user = User::firstOrNew(['email' => 'client@demo-portal.local']);
        $user->forceFill([
            'name' => 'Demo Client',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ])->save();

        /*
         * A client-portal role holds only what a customer of the agency may do: read their own
         * requests, quotes, invoices and files. Explicitly NOT the campaign or client permissions —
         * a portal customer who could list clients would be reading the agency's book of business.
         */
        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'slug' => 'client-portal'],
            ['name' => 'Client Portal', 'is_system' => true],
        );

        $role->givePermissionTo(...Permission::query()
            ->whereIn('key', ['requests.view', 'billing.view', 'drive.view', 'messaging.view'])
            ->pluck('key')->all());

        $user->assignRole($role);

        /*
         * Confined to ONE client space, through the constructor that exists to make that hard to get
         * wrong. A client-portal membership with no scope would show this customer the agency's whole
         * book of business, which is the failure `forAgencyClient` is named after.
         */
        $client = ClientWorkspace::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())->orderBy('created_at')->first();

        if ($client === null) {
            $this->command?->warn('The demo agency has no client space — the portal demo account has no membership.');

            return;
        }

        app(GrantMembership::class)->execute(MembershipGrant::forAgencyClient(
            user: $user->refresh(),
            tenant: $tenant,
            clientIds: [(string) $client->getKey()],
        ));
    }

    private function report(): void
    {
        $this->command?->info('portal=/admin       | email=admin@demo-campaignshub.local | password=password');
        $this->command?->info('portal=/app         | email=owner@demo-company.local      | password=password');
        $this->command?->info('portal=/agency      | email=owner@demo-agency.local       | password=password');
        $this->command?->info('portal=/influencers | email=layla@creators.demo           | password=password');
        $this->command?->info('portal=/portal      | email=client@demo-portal.local      | password=password (portal sign-in is still OTP — see the seeder)');
    }
}
