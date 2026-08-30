<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Alerts are fail-closed across tenants: a tenant never sees or mutates another tenant's rules/events. */
final class AlertsIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenantWithOwner(string $slug): array
    {
        $tenant = Tenant::create(['name' => $slug, 'slug' => $slug, 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        app(TenantContext::class)->setTenantId($tenant->id);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => 'O', 'email' => "o@{$slug}.test", 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return [$tenant, $user];
    }

    /**
     * The rules list is bounded, newest first, and says how many there are.
     *
     * It used to return every row. Fine on the day a workspace writes its third rule and wrong
     * forever after — the payload and the number of cards rendered both grow without limit, and the
     * page a customer opens to add one rule gets slower every time anybody adds one. The acceptance
     * suite reached 316 rules and took the page past ten seconds to paint.
     *
     * Newest first is what makes the cap safe rather than merely smaller: the rule somebody has just
     * created is the one at the top, so a bounded list never hides what they are looking for.
     */
    public function test_the_rules_list_is_bounded_and_leads_with_the_newest(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('bounded');

        foreach (range(1, 105) as $i) {
            $rule = AlertRule::create([
                'tenant_id' => $tenant->id, 'type' => 'sync_failure',
                'name' => 'Rule '.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'active' => true,
            ]);
            // Stamped after creation: `created_at` is a timestamp the model sets itself, and 105 rows
            // written inside one second would otherwise all share it and make "newest" meaningless.
            $rule->forceFill(['created_at' => Carbon::now()->addSeconds($i)])->save();
        }

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/alerts/rules')->assertOk();

        $names = array_column((array) $res->json('data'), 'name');

        $this->assertCount(100, $names, 'the list must be bounded');
        $this->assertSame('Rule 105', $names[0], 'the newest rule must lead — a cap that hides it is worse than none');
        $this->assertSame(105, $res->json('meta.total'), 'the response must say how many there really are');
    }

    /**
     * The firing ledger is ordered the way somebody triaging it works down, and totally.
     *
     * `latest('last_triggered_at')` was neither. The evaluator writes a whole sweep in one pass, so
     * events routinely share a timestamp to the second and the rows behind them came back in whatever
     * order Postgres found convenient — an operator who resolves three, refreshes, and sees the list
     * rearrange cannot tell whether something fired or nothing did.
     *
     * The fixture makes both halves visible at once: every event shares ONE `last_triggered_at`, so
     * recency decides nothing and only the ranks can produce a stable answer.
     */
    public function test_the_event_ledger_is_ordered_for_triage_and_the_order_is_total(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('triage');
        $rule = AlertRule::create(['tenant_id' => $tenant->id, 'type' => 'sync_failure', 'name' => 'R', 'active' => true]);

        $moment = Carbon::parse('2026-08-20 09:00:00');
        $ids = [];
        foreach ([
            ['resolved', 'critical'], ['open', 'info'], ['snoozed', 'critical'],
            ['open', 'critical'], ['resolved', 'info'], ['open', 'warning'], ['snoozed', 'info'],
        ] as [$status, $severity]) {
            $ids["{$status}/{$severity}"] = AlertEvent::create([
                'tenant_id' => $tenant->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
                'dedup_key' => hash('sha256', (string) Str::uuid()),
                'status' => $status, 'severity' => $severity, 'last_triggered_at' => $moment,
            ])->id;
        }

        $order = array_column((array) $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/alerts/events')->assertOk()->json('data'), 'id');

        $this->assertSame(
            [
                $ids['open/critical'], $ids['open/warning'], $ids['open/info'],
                $ids['snoozed/critical'], $ids['snoozed/info'],
                $ids['resolved/critical'], $ids['resolved/info'],
            ],
            $order,
            'the queue must read open → snoozed → resolved, and critical → warning → info inside each',
        );
    }

    /** Same rank, same second: the id is what makes the answer repeatable rather than convenient. */
    public function test_two_identical_events_come_back_in_the_same_order_every_time(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('tiebreak');
        $rule = AlertRule::create(['tenant_id' => $tenant->id, 'type' => 'sync_failure', 'name' => 'R', 'active' => true]);

        $moment = Carbon::parse('2026-08-20 09:00:00');
        foreach (range(1, 12) as $i) {
            AlertEvent::create([
                'tenant_id' => $tenant->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
                'dedup_key' => hash('sha256', (string) Str::uuid()),
                'status' => 'open', 'severity' => 'warning', 'last_triggered_at' => $moment,
            ]);
        }

        $read = fn (): array => array_column((array) $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/alerts/events')->assertOk()->json('data'), 'id');

        $first = $read();
        $sorted = $first;
        sort($sorted);

        $this->assertSame($sorted, $first, 'twelve indistinguishable events must fall in id order, not the storage engine\'s');
        $this->assertSame($first, $read(), 'the same request twice must answer the same way');
    }

    /**
     * The tab badges are counted over the WHOLE ledger, not over the page that fits.
     *
     * `AlertsPage` reads this endpoint once with no status and derives open / critical / snoozed /
     * resolved by filtering the array it got back. With a silent cap those badges were counts of the
     * first 200 rows presented as counts of everything — wrong on any tenant past the cap, with
     * nothing on screen admitting it.
     *
     * 210 resolved events sit UNDER 40 open ones in the triage order, so the cap falls inside the
     * resolved block: the page cannot see them, and the badge must still say how many there are.
     */
    public function test_the_counts_describe_the_whole_ledger_even_when_the_page_is_capped(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner('capped');
        $rule = AlertRule::create(['tenant_id' => $tenant->id, 'type' => 'sync_failure', 'name' => 'R', 'active' => true]);

        $make = function (string $status, string $severity, int $n, string $at) use ($tenant, $rule): void {
            foreach (range(1, $n) as $i) {
                AlertEvent::create([
                    'tenant_id' => $tenant->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
                    'dedup_key' => hash('sha256', (string) Str::uuid()),
                    'status' => $status, 'severity' => $severity, 'last_triggered_at' => Carbon::parse($at),
                ]);
            }
        };
        /*
         * The resolved events are the NEWEST. Under the recency ordering this replaced, all 210 of
         * them would sort above every open alert and fill the page on their own — so the last
         * assertion below is a real test of what the cap drops, not an accident of insertion order.
         */
        $make('open', 'critical', 5, '2026-08-20 09:00:00');
        $make('open', 'warning', 35, '2026-08-20 09:00:00');
        $make('resolved', 'info', 210, '2026-08-20 10:00:00');

        $res = $this->actingAs($owner, 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();

        $this->assertCount(200, (array) $res->json('data'), 'the list must be bounded');
        $this->assertSame(250, $res->json('meta.total'));
        $this->assertSame(40, $res->json('meta.counts.open'), 'the open badge must count every open event, not the ones that fit');
        $this->assertSame(5, $res->json('meta.counts.open_critical'));
        $this->assertSame(210, $res->json('meta.counts.resolved'), 'the resolved badge must survive the cap that hides them');

        $returned = array_column((array) $res->json('data'), 'status');
        $this->assertSame(40, count(array_filter($returned, fn ($s) => $s === 'open')),
            'a cap must drop the oldest resolved rows, never an open one');
    }

    public function test_a_tenant_cannot_see_or_resolve_another_tenants_alerts(): void
    {
        // Tenant A: a rule + an open event.
        [$tenantA] = $this->tenantWithOwner('alpha');
        $rule = AlertRule::create(['tenant_id' => $tenantA->id, 'type' => 'sync_failure', 'name' => 'A rule', 'active' => true]);
        $event = AlertEvent::create(['tenant_id' => $tenantA->id, 'rule_id' => $rule->id, 'type' => 'sync_failure',
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => 'open', 'severity' => 'warning', 'last_triggered_at' => Carbon::now()]);

        // Tenant B's owner.
        [, $ownerB] = $this->tenantWithOwner('bravo');

        // B cannot see A's rule in the list.
        $rules = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/alerts/rules')->assertOk();
        $this->assertNotContains('A rule', array_column((array) $rules->json('data'), 'name'));

        // B cannot see A's event.
        $events = $this->actingAs($ownerB, 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();
        $this->assertNotContains((string) $event->id, array_column((array) $events->json('data'), 'id'));

        // B cannot resolve or snooze A's event — fail-closed route-model binding → 404.
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/alerts/events/{$event->id}/resolve")->assertNotFound();
        $this->actingAs($ownerB, 'sanctum')->postJson("/api/v1/alerts/events/{$event->id}/snooze", ['minutes' => 60])->assertNotFound();

        // The event is untouched in A.
        app(TenantContext::class)->setTenantId($tenantA->id);
        $this->assertSame('open', $event->refresh()->status);
    }
}
