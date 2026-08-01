<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Give the demo platform a past, and something to administer (ADMIN-100).
 *
 * A fresh install has two tenants, both created in the same second and both healthy. `/admin` is a
 * console for looking after a PLATFORM: its growth chart drew one vertical spike, its subscription
 * chart had a single bar, and its "needs your attention" list was permanently empty. None of that
 * demonstrates anything, and a console that can only ever show the happy path is one nobody can
 * evaluate.
 *
 * So this seeds a small population — ten workspaces across ten months, on three plans, in the states
 * an operator actually has to deal with: paying, on trial, past due, cancelled and suspended.
 *
 * Four rules, because a demo that lies is worse than a demo that is thin:
 *
 * - **Development only**, guarded like every demo seeder. It writes `created_at`, and doing that to
 *   real tenants would falsify the one column recording when somebody actually joined.
 * - **Named as demo.** Every workspace carries `(Demo)` in its name and a `demo-` slug, so nothing
 *   here can be mistaken for a customer while reading the console.
 * - **Deterministic.** The spread comes from each row's index, never from a random number, so two
 *   installs of the same seed produce the same chart and a test can assert against it.
 * - **Idempotent.** Re-seeding updates the same slugs rather than minting a second population, which
 *   is how a local database ends up with ninety "Sandbox" rows.
 */
final class DemoPlatformHistorySeeder extends Seeder
{
    /**
     * The demo population: months ago, name, account type, plan, subscription state.
     *
     * Deliberately uneven — two workspaces in some months, none in others. A flat one-per-month
     * spread reads as generated, and a growth chart nobody believes teaches the reader to distrust
     * the real one.
     *
     * @var list<array{int, string, string, string, ?string}>
     */
    private const POPULATION = [
        [10, 'Najd Media', 'agency', 'scale', 'active'],
        [9, 'Rawaa Store', 'brand', 'growth', 'active'],
        [8, 'Mishaal Freelance', 'freelancer', 'starter', 'active'],
        [8, 'Layan Cosmetics', 'brand', 'growth', 'past_due'],
        [6, 'Tuwaiq Digital', 'agency', 'scale', 'active'],
        [5, 'Hala Foods', 'self_serve_company', 'growth', 'trialing'],
        [4, 'Sadeem Tech', 'in_house_team', 'growth', 'active'],
        [3, 'Wateen Clinics', 'brand', 'starter', 'cancelled'],
        [2, 'Qimam Consulting', 'agency', 'growth', 'trialing'],
        [1, 'Bareeq Retail', 'self_serve_company', 'starter', null],
    ];

    public function run(): void
    {
        if (App::environment('production')) {
            $this->command?->warn('Demo platform history is development-only — skipped.');

            return;
        }

        $this->backdateExisting();
        $this->seedPopulation();

        $this->command?->info('Demo: platform population seeded across ten months (all rows labelled Demo).');
    }

    /**
     * The two workspaces the other seeders build are the demo's centrepiece, and they were both
     * "opened today". Placed at the far end of the window so the chart starts somewhere.
     */
    private function backdateExisting(): void
    {
        $anchor = Carbon::now()->startOfMonth();

        foreach (['demo-agency' => 11, 'demo-company' => 10] as $slug => $monthsAgo) {
            Tenant::query()->withoutGlobalScopes()->where('slug', $slug)
                ->each(fn (Tenant $t) => $t->forceFill([
                    'created_at' => $anchor->copy()->subMonths($monthsAgo)->addDays(6),
                ])->saveQuietly());
        }
    }

    private function seedPopulation(): void
    {
        $anchor = Carbon::now()->startOfMonth();
        $plans = SubscriptionPlan::query()->get()->keyBy('code');

        foreach (self::POPULATION as $i => [$monthsAgo, $name, $type, $planCode, $subscriptionState]) {
            $openedAt = $anchor->copy()->subMonths($monthsAgo)->addDays(3 + (($i * 7) % 22));
            $slug = 'demo-'.Str::slug($name);

            /*
             * `suspended` is a TENANT state, not a subscription one — the last row carries no
             * subscription at all and is suspended outright, which is the case an operator meets
             * when an account is stopped for a reason that has nothing to do with billing.
             */
            $suspended = $subscriptionState === null;

            $tenant = Tenant::query()->withoutGlobalScopes()->firstOrNew(['slug' => $slug]);
            $tenant->forceFill([
                /*
                 * The key is set here rather than left to the model.
                 *
                 * `saveQuietly()` dispatches no model events at all — including `creating`, which is
                 * where the UUID would normally be minted. Saving quietly is what keeps this seeder
                 * from tripping observers that would stamp `created_at` back to now and undo the
                 * whole point, so the key is supplied instead.
                 */
                'id' => $tenant->exists ? $tenant->getKey() : (string) Str::uuid(),
                'name' => $name.' (Demo)',
                'status' => $suspended ? 'suspended' : 'active',
                'account_type' => $type,
                'subscription_plan' => $planCode,
                'enabled_modules' => ['paid_media'],
                'portal_enabled' => false,
                'created_at' => $openedAt,
                'updated_at' => $openedAt,
            ])->saveQuietly();

            if ($suspended) {
                DB::table('subscriptions')->where('tenant_id', $tenant->getKey())->delete();

                continue;
            }

            $plan = $plans->get($planCode);
            if ($plan === null) {
                continue;
            }

            /*
             * Priced from the PLAN at seed time and then stored on the subscription, which is how a
             * real signup works: the agreed amount is captured once and does not follow later edits
             * to the plan's price.
             */
            $existing = DB::table('subscriptions')->where('tenant_id', $tenant->getKey())->first();

            $row = [
                'tenant_id' => $tenant->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => $subscriptionState,
                'billing_interval' => 'monthly',
                'unit_amount' => $plan->price_monthly,
                'currency' => $plan->currency ?? 'SAR',
                'seats' => 3 + ($i % 5),
                'current_period_start' => $openedAt->copy()->startOfMonth(),
                'current_period_end' => Carbon::now()->addDays(30 - ($i * 2)),
                'trial_ends_at' => $subscriptionState === 'trialing' ? Carbon::now()->addDays(3 + $i) : null,
                'updated_at' => Carbon::now(),
            ];

            if ($existing === null) {
                DB::table('subscriptions')->insert($row + [
                    'id' => (string) Str::uuid(),
                    'created_at' => $openedAt,
                ]);
            } else {
                DB::table('subscriptions')->where('id', $existing->id)->update($row);
            }
        }
    }
}
