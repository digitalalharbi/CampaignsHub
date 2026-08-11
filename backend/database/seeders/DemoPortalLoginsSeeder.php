<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
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
 * One demo account per portal, and no account that spans two (SIGNUP-006).
 *
 * The defect this closes was in the sign-in page rather than the data: every portal tab offered the
 * same agency login, so "try the influencer portal" meant signing in as an agency operator and
 * looking at the agency console. A demo set that cannot demonstrate the difference between the
 * portals is worse than none, because it makes them look identical when they are not.
 *
 * The other two accounts already exist and are left alone — `agency@campaignshub.io` and
 * `advertiser@campaignshub.io` are owned by `DemoAccountsSeeder` and carry a whole demo world with them.
 * This seeder adds what was missing:
 *
 *   - `admin@campaignshub.io` — the platform owner's console, and the one an operator signs in with.
 *     Separate from `platform@campaignshub.io`, which `DatabaseSeeder` provisions in EVERY
 *     environment: this one is development-only and its password is published, so the two must not
 *     be the same account.
 *   - `client@campaignshub.io` — the client portal. See the note on that method: the portal's own
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
        $this->influencerOperator();
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
        $user = User::firstOrNew(['email' => 'admin@campaignshub.io']);

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

        $user = User::firstOrNew(['email' => 'client@campaignshub.io']);
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
         *
         * NAMED, not sorted for. This was `orderBy('created_at')->first()`, and the demo agency's
         * client spaces are all created inside one second by one seeding run — `created_at` is
         * `timestamp(0)`, so they tie exactly. SQL leaves the order among tied rows unspecified, and
         * Postgres answers from physical order, which changes as pages are reused. The account was
         * therefore scoped to an ARBITRARY one of six spaces: usually «demo-managed» — the one
         * `DemoClientPortalSeeder` fills — and sometimes a sibling that is empty.
         *
         * That is the whole of the intermittent `DemoClientPortalTest` failure. In isolation the
         * table is fresh, insertion order is physical order, and the first-created space wins every
         * time; in a full suite run, hundreds of rolled-back transactions leave dead tuples, the new
         * rows land in reused pages in a different order, and the tie breaks somewhere else. The
         * portal then opened onto a space with none of the seeded data, and a different subset of
         * this class failed depending on which space it landed in.
         *
         * The scope and the data now come from ONE constant, so they cannot drift apart. The
         * fallback keeps a stable, total ordering (`id` breaks any remaining tie) so that even an
         * install without that space picks the same space twice running.
         */
        $client = ClientWorkspace::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->getKey())
            ->where('slug', DemoClientPortalSeeder::WORKSPACE_SLUG)
            ->first()
            ?? ClientWorkspace::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->orderBy('created_at')->orderBy('id')->first();

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

    /**
     * The AGENCY side of the influencers portal (REVIEW-001).
     *
     * `layla@creators.demo` is a CREATOR — she sees her own agreements and nothing else, which is
     * the whole point of INFL-002. It also meant the operational half of that portal had no demo
     * login at all: the roster, the collaborations, the nominations and the tracking assets could be
     * built, tested and never once demonstrated by signing in.
     *
     * Two accounts in one portal is not the thing SIGNUP-006 forbids. That rule is that no account
     * may span two PORTALS — a skeleton key. These two are opposite sides of the same agreement, and
     * a portal that only ships one of them cannot show what it is for.
     */
    private function influencerOperator(): void
    {
        /*
         * Not seeded while the sub-system is switched off (INFL-OFF-001).
         *
         * A demo login for a portal nobody can open is worse than none: it is an account that signs
         * in, is told where it belongs, and is refused there. The account's DATA is untouched —
         * `migrate:fresh` is what removes rows, not this guard, and on an existing install the row
         * stays exactly where it is, ready for the day the flag flips back.
         */
        if (! Portal::Influencers->isEnabled()) {
            return;
        }

        $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'demo-agency')->first();

        if ($tenant === null) {
            $this->command?->warn('No demo-agency tenant — the influencer operator demo account was skipped.');

            return;
        }

        app(TenantContext::class)->setTenantId((string) $tenant->getKey());

        $user = User::firstOrNew(['email' => 'talent@demo-agency.local']);
        $user->forceFill([
            'name' => 'Talent Manager',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
        ])->save();

        /*
         * Everything the influencer surface needs, and `influencers.approve` deliberately INCLUDED:
         * a demo that can propose but never answer would leave the nomination queue permanently
         * stuck at «awaiting a decision», which demonstrates the opposite of what it should.
         *
         * `influencers.view_costs` is included too — this is the operator who negotiates the fee.
         */
        $role = Role::firstOrCreate(
            ['tenant_id' => $tenant->getKey(), 'slug' => 'talent-manager'],
            ['name' => 'Talent Manager', 'is_system' => true],
        );

        $role->givePermissionTo(...Permission::query()
            ->whereIn('key', [
                'influencers.view', 'influencers.manage', 'influencers.approve', 'influencers.view_costs',
                'campaigns.view', 'drive.view', 'messaging.view', 'reports.view',
            ])
            ->pluck('key')->all());

        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user->refresh(),
            tenant: $tenant,
            portal: Portal::Influencers,
            role: 'member',
        ));
    }

    private function report(): void
    {
        $this->command?->info('portal=/admin       | email=admin@campaignshub.io | password=password');
        $this->command?->info('portal=/app         | email=advertiser@campaignshub.io      | password=password');
        $this->command?->info('portal=/agency      | email=agency@campaignshub.io       | password=password');

        // Only announced while the portal is being offered (INFL-OFF-001). Printing a login for a
        // closed portal is how a demo script sends somebody to a door that will refuse them.
        if (Portal::Influencers->isEnabled()) {
            $this->command?->info('portal=/influencers | email=talent@demo-agency.local      | password=password (the AGENCY side — roster, nominations, tracking)');
            $this->command?->info('portal=/influencers | email=layla@creators.demo           | password=password (the CREATOR side — her own agreements only)');
        }

        $this->command?->info('portal=/portal      | email=client@campaignshub.io      | password=password');
    }
}
