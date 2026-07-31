<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Plans, subscriptions and revenue from the owner's side (ADMIN-002).
 *
 * The interesting assertions here are about honesty rather than access:
 *
 *   Revenue is per CURRENCY. A single total across currencies looks authoritative and means nothing.
 *   Revenue is COMMITTED subscription value, not cash — the invoices/payments ledger is an agency
 *   invoicing ITS client (`client_workspace_id` is NOT NULL), so counting it here would report
 *   customers' money as the platform's own business result.
 *   A plan's subscriber count is split by status, because 40 cancelled subscribers is not 40 customers.
 */
final class PlatformBillingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::create([
            'name' => 'Owner', 'email' => 'owner@platform.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name, 'slug' => Str::slug($name).'-'.uniqid(), 'status' => 'active',
        ]);
    }

    private function plan(string $code, float $price = 100, bool $active = true): SubscriptionPlan
    {
        return SubscriptionPlan::create([
            'code' => $code, 'name' => ucfirst($code), 'price_monthly' => $price,
            'currency' => 'SAR', 'is_active' => $active,
        ]);
    }

    public function test_the_plan_catalogue_splits_subscribers_by_status(): void
    {
        $plan = $this->plan('growth');
        $a = $this->tenant('A Co');
        $b = $this->tenant('B Co');

        Subscription::create(['tenant_id' => $a->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 3]);
        Subscription::create(['tenant_id' => $b->id, 'plan_id' => $plan->id, 'status' => 'cancelled', 'seats' => 1]);

        $row = collect(
            $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/plans')->assertOk()->json('data.plans')
        )->firstWhere('code', 'growth');

        // Two subscribers, but only one customer — one number would have said two.
        $this->assertSame(2, $row['subscribers']['total']);
        $this->assertSame(1, $row['subscribers']['active']);
    }

    /** Deactivating stops new sign-ups; it must not touch anyone already subscribed. */
    public function test_deactivating_a_plan_leaves_existing_subscriptions_alone(): void
    {
        $plan = $this->plan('legacy');
        $tenant = $this->tenant('Existing Co');
        $sub = Subscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1]);

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.plan.is_active', false);

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertSame($plan->id, $sub->fresh()->plan_id);
    }

    /**
     * The price IS editable from the console now — and changing it does not touch what existing
     * subscribers pay (PLAN-001).
     *
     * This test used to assert the opposite, and the reasoning behind it was sound: changing what
     * people already pay is a decision with contractual consequences, and one field on an admin page
     * would have applied it silently to everyone. The contract requires the catalogue to be editable,
     * so the fix was not to keep the field read-only but to remove the consequence — a subscription
     * now records the price it was sold at, and renewal reads that rather than the catalogue.
     */
    public function test_changing_a_catalogue_price_does_not_re_price_an_existing_subscriber(): void
    {
        $plan = $this->plan('growth', 100);
        $subscription = app(SubscriptionService::class)->assignPlan($this->tenant('Priced Co'), $plan);

        $this->assertSame('100.00', (string) $subscription->unit_amount, 'sold at the price of the day');

        $this->actingAs($this->owner(), 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->id}", ['price_monthly' => 400])
            ->assertOk();

        // The catalogue moved…
        $this->assertSame('400.00', (string) $plan->fresh()->price_monthly);
        // …and this customer still owes what they agreed to.
        $this->assertSame('100.00', (string) $subscription->fresh()->unit_amount);
    }

    public function test_changing_a_plan_is_audited(): void
    {
        $plan = $this->plan('growth');
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/admin/plans/{$plan->id}", ['is_active' => false])->assertOk();

        $entry = AuditLog::query()->where('action', 'platform.plan.updated')->firstOrFail();
        $this->assertSame($owner->id, $entry->user_id);
        $this->assertTrue($entry->before['is_active']);
        $this->assertFalse($entry->after['is_active']);
    }

    /** Subscriptions span tenants and name them — the owner should not have to resolve ids by hand. */
    public function test_subscriptions_span_tenants_and_name_them(): void
    {
        $plan = $this->plan('growth');
        $a = $this->tenant('Alpha Co');
        $b = $this->tenant('Beta Co');
        Subscription::create(['tenant_id' => $a->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1]);
        Subscription::create(['tenant_id' => $b->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1]);

        $rows = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/subscriptions')->assertOk()->json('data.subscriptions');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['Alpha Co', 'Beta Co'], array_column($rows, 'tenant_name'));
    }

    /**
     * The heart of it, and it is a statement about honesty rather than arithmetic.
     *
     * `invoices`/`payments` carry a NOT NULL `client_workspace_id` — that ledger is an AGENCY
     * invoicing ITS client. Reporting it as platform revenue would show customers' money as the
     * platform's own business result, which is the most flattering possible lie a console can tell.
     * So the figure is COMMITTED subscription value, per currency, and the payload says outright
     * that nothing has been collected.
     */
    public function test_revenue_reports_committed_subscription_value_per_currency(): void
    {
        $sar = $this->plan('sar-growth', 300);
        $usd = SubscriptionPlan::create([
            'code' => 'usd-growth', 'name' => 'USD Growth', 'price_monthly' => 80,
            'currency' => 'USD', 'is_active' => true,
        ]);

        $a = $this->tenant('A Co');
        $b = $this->tenant('B Co');
        $c = $this->tenant('C Co');
        Subscription::create(['tenant_id' => $a->id, 'plan_id' => $sar->id, 'status' => 'active', 'seats' => 1]);
        Subscription::create(['tenant_id' => $b->id, 'plan_id' => $sar->id, 'status' => 'active', 'seats' => 1]);
        Subscription::create(['tenant_id' => $c->id, 'plan_id' => $usd->id, 'status' => 'active', 'seats' => 1]);

        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/revenue')->assertOk()->json('data');

        $committed = collect($data['committed_monthly']);
        $this->assertCount(2, $committed, 'currencies must stand side by side, never blended');
        $this->assertSame('600.00', $committed->firstWhere('currency', 'SAR')['monthly']);
        $this->assertSame('80.00', $committed->firstWhere('currency', 'USD')['monthly']);

        // Said outright, so a reader cannot take the figure for cash received.
        $this->assertSame('not_implemented', $data['collection_status']);
    }

    /** A cancelled subscription is not committed value — it is a customer who left. */
    public function test_only_active_subscriptions_count_towards_committed_value(): void
    {
        $plan = $this->plan('growth', 500);
        $a = $this->tenant('Staying Co');
        $b = $this->tenant('Leaving Co');
        Subscription::create(['tenant_id' => $a->id, 'plan_id' => $plan->id, 'status' => 'active', 'seats' => 1]);
        Subscription::create(['tenant_id' => $b->id, 'plan_id' => $plan->id, 'status' => 'cancelled', 'seats' => 1]);

        $committed = collect(
            $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/admin/revenue')->assertOk()->json('data.committed_monthly')
        );

        $this->assertSame('500.00', $committed->firstWhere('currency', 'SAR')['monthly']);
        $this->assertSame(1, $committed->firstWhere('currency', 'SAR')['subscriptions']);
    }

    /** With no subscriptions the answer is an empty list — never a zero presented as a result. */
    public function test_a_platform_with_no_subscriptions_reports_nothing_rather_than_zero_revenue(): void
    {
        $data = $this->actingAs($this->owner(), 'sanctum')
            ->getJson('/api/v1/admin/revenue')->assertOk()->json('data');

        $this->assertSame([], $data['committed_monthly']);
        $this->assertSame('not_implemented', $data['collection_status']);
    }

    public function test_the_billing_console_is_closed_to_a_tenant_user(): void
    {
        $tenant = $this->tenant('Outsider Co');
        $user = User::create([
            'name' => 'Outsider', 'email' => 'outsider@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        foreach (['/api/v1/admin/plans', '/api/v1/admin/subscriptions', '/api/v1/admin/revenue'] as $path) {
            $this->actingAs($user, 'sanctum')->getJson($path)->assertForbidden();
        }
    }

    /** Separate case: `actingAs` persists for the rest of a test method, so this needs its own. */
    public function test_the_billing_console_requires_a_session(): void
    {
        foreach (['/api/v1/admin/plans', '/api/v1/admin/subscriptions', '/api/v1/admin/revenue'] as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
    }
}
