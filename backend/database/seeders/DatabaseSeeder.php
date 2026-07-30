<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Global permission catalogue (all environments — structural, safe in production).
        $this->call(PermissionSeeder::class);

        // 1b) Canonical request service types + lifecycle statuses (structural, all environments).
        $this->call(RequestCatalogSeeder::class);

        // 1c) Global subscription plan catalogue (starter/growth/scale) — structural, safe in production.
        $this->call(SubscriptionPlanSeeder::class);

        // 1d) Canonical platform taxonomy (definitions + options) — structural, idempotent, all environments.
        $this->call(TaxonomyEngineSeeder::class);

        // 2) Platform super-admin (idempotent).
        $platform = User::firstOrCreate(
            ['email' => 'platform@mediabuying.local'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
                'is_platform_admin' => true,
                'tenant_id' => null,
            ],
        );

        // Verified on creation, because this account is PROVISIONED by whoever installs the system,
        // not self-registered. Leaving it unverified put the platform owner behind a confirmation
        // email sent to an address no mail provider will ever deliver to — they signed in and met
        // "confirm your email" instead of their console, with no way through.
        if ($platform->email_verified_at === null) {
            $platform->forceFill(['email_verified_at' => now()])->save();
        }

        // 3) Demo tenant + demo users + Sandbox data — DEV/LOCAL/DEMO only, NEVER in production.
        if (App::environment(['local', 'testing', 'demo'])) {
            $this->call(DemoSeeder::class);

            // 3a) Deterministic demo accounts for the THREE experiences (Operations Console / SaaS Workspace /
            //     Client Portal). Idempotent; must run AFTER DemoSeeder (it ensures the demo-agency logins).
            $this->call(DemoAccountsSeeder::class);
        }

        // 3b) Heavy demo data (metrics/reports/creatives) — local/demo ONLY, never in `testing`
        //     (test cases seed their own minimal fixtures; this chain would bloat every test DB).
        //     Ordered so a fresh local/demo seed yields a fully-populated demo via the real tables.
        if (App::environment(['local', 'demo'])) {
            $this->call([
                DemoAnalyticsSeeder::class,
                DemoReportsSeeder::class,
                DemoCreativesSeeder::class,
                // DEMO-001: the credential → connection → ad account → external campaign chain the
                // analytics demo was missing, so the integration surfaces have real rows to show.
                DemoIntegrationsSeeder::class,
            ]);
        }

        // 4) LAST: every tenant user needs a membership, or they have no portal to land in. Runs after
        //    all the seeders above so it catches whatever they created, and is idempotent.
        $this->call(MembershipBackfillSeeder::class);

        // 5) After the backfill, because this one grants a membership DELIBERATELY rather than
        //    migrating one: a manager confined to a single client, so the demo actually exercises
        //    the ceiling instead of showing three accounts that all see everything.
        if (App::environment(['local', 'demo'])) {
            $this->call(DemoAgencyScopeSeeder::class);
            // And a client contact named on two brands, so the isolated client space has something
            // to isolate — otherwise the picker never appears and the boundary is never seen.
            $this->call(DemoClientSpacesSeeder::class);
            // Influencer work in three states — published, owed, and late — because a demo where
            // everything is fine shows none of what the portal is for.
            $this->call(DemoInfluencersSeeder::class);
        }
    }
}
