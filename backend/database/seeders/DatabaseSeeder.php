<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Tenancy\Models\Tenant;
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

        // 1e) Canonical metric catalogue — what each metric means, its unit, and whether it may be
        //     summed (NORM-001). Structural like the four above; it had been written and never called,
        //     so `metric_definitions` was empty everywhere and no surface could explain a number.
        $this->call(MetricDefinitionSeeder::class);

        /*
         * 2) Platform super-admin (idempotent).
         *
         * The address moved to the official `campaignshub.io` domain (IDENTITY-ACCOUNTS-001). It is
         * ADDITIVE on purpose: an install provisioned under the previous address keeps that account,
         * because renaming somebody's sign-in address underneath them locks them out of the console
         * that would let them fix it. A legacy owner therefore still works, and a fresh install gets
         * the official one — `is_platform_admin` is what grants the console, not the address.
         */
        $platform = User::firstOrCreate(
            ['email' => 'platform@campaignshub.io'],
            [
                'name' => 'Platform Admin',
                'password' => Hash::make('password'),
            ],
        );

        // `forceFill`, because `is_platform_admin` is not mass-assignable — provisioning the owner is
        // the one place it is set, and it has to be deliberate.
        if (! $platform->is_platform_admin) {
            $platform->forceFill(['is_platform_admin' => true])->save();
        }

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
                // DEMO-001: the credential → connection → ad account → external campaign chain the
                // analytics demo was missing, so the integration surfaces have real rows to show.
                DemoIntegrationsSeeder::class,
            ]);
        }

        // 4) LAST: every tenant user needs a membership, or they have no portal to land in. Runs after
        //    all the seeders above so it catches whatever they created, and is idempotent.

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
            // SIGNUP-006 — one demo login per portal, so the five can actually be told apart.
            // Development only; the seeder refuses in production.
            $this->call(DemoPortalLoginsSeeder::class);
            // DEMO-PORTAL-001 — and something for that client login to actually open. AFTER the
            // logins seeder, because it fills the one client space that account is scoped to; before
            // it, the demo client portal was eight empty sections.
            $this->call(DemoClientPortalSeeder::class);
            // §15.16 — the ten creative cases the analysis surfaces have to be reviewable against.
            // AFTER the portal seeder, because it hangs its creatives off that project's campaigns.
            $this->call(DemoCreativeAnalysisSeeder::class);
            /*
             * DEMO-RESEED-002 — the ranked creative list, seeded LAST among the campaign work.
             *
             * It used to sit up with the analytics and report seeders, and it finds its campaigns by
             * asking which ones have daily metrics. `DemoClientPortalSeeder` writes daily metrics for
             * the client's own campaigns and runs after it, so on a FIRST seed three campaigns had
             * none yet and were skipped — 78 creatives instead of 90. Running `db:seed` a second time
             * found them and quietly filled the gap, which is why the shortfall survived: the demo
             * databases that were looked at had all been seeded twice.
             *
             * Nothing was duplicated and nothing was orphaned either way. What was wrong is that
             * `migrate:fresh --seed` — the command the installation guide gives — produced a smaller
             * demo world than the same seeders run twice, and no one reading either would know.
             */
            $this->call(DemoCreativesSeeder::class);
            // DEMO-COMMERCE — the merchant's ledger. AFTER `DemoIntegrationsSeeder`, because an order
            // can only carry a `utm_campaign` that names a real campaign once those exist; without
            // them every order would seed as unattributed and the store half of the funnel, and
            // REPORT-OBJECTIVE-005's Store-Confirmed block, would still have nothing to show.
            $this->call(DemoCommerceSeeder::class);
            // ADMIN-100 — spread the demo tenants' creation dates back across ten months, so the
            // platform growth chart shows a shape instead of one spike in the current month. LAST
            // among the demo seeders, because it rewrites what they have just created.
            $this->call(DemoPlatformHistorySeeder::class);
        }

        /*
         * 6) LAST: give every seeded workspace a real account state (SIGNUP-001).
         *
         * The seeders above write `status` directly, which was the only lifecycle column that
         * existed when they were written. Left alone they produce workspaces that are OPERATING on
         * `status` while their state still reads `draft` — the exact drift between the two columns
         * that `TransitionAccountState` exists to prevent, visible on any fresh install.
         *
         * Done here rather than in each seeder because it must catch whatever they created, however
         * they created it, and because the reason is the same for all of them.
         */
        $transitions = app(TransitionAccountState::class);
        foreach (Tenant::whereIn('status', ['active', 'trialing'])->get() as $tenant) {
            if ($transitions->stateOf($tenant) === AccountState::Draft) {
                $transitions->provision(
                    $tenant,
                    AccountState::Active,
                    'Demo workspace — provisioned active so the portals have something to show.',
                );
            }
        }
    }
}
