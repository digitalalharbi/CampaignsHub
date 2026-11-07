<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * ADMIN-FULL-CONTROL-001, part two — a workspace and a person, run from the console.
 *
 * Each action is proved twice: that the platform owner can do it, and that nobody else can. Money never moves, no provider is
 * called, the other workspace is never touched, no platform role is ever granted, and nobody becomes an owner by accident.
 */
final class AdminFullControlTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private Tenant $acme;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();
        Http::fake();
        $this->acme = $this->tenant('Acme');
        $this->beta = $this->tenant('Beta');
        app(TenantContext::class)->forget();
    }

    private function tenant(string $name): Tenant
    {
        $t = Tenant::create(['name' => $name, 'slug' => strtolower($name).'-'.uniqid(), 'status' => 'active', 'account_type' => 'agency']);
        $t->forceFill(['account_state' => AccountState::Active->value])->save();
        foreach (['tenant-owner' => 'Owner', 'media-buyer' => 'Media buyer', 'platform-admin' => 'Sneaky', 'super-admin' => 'Sneakier'] as $slug => $label) {
            Role::create(['tenant_id' => $t->id, 'name' => $label, 'slug' => $slug]);
        }

        return $t->refresh();
    }

    private function person(string $email, array $in = []): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret1234']);
        $u->forceFill(['email_verified_at' => now()])->save();
        foreach ($in as [$tenant, $slug]) {
            app(TenantContext::class)->setTenantId((string) $tenant->id);
            $this->grantMembership($u, $tenant);
            $u->roles()->attach(Role::query()->where('tenant_id', $tenant->id)->where('slug', $slug)->value('id'));
        }
        app(TenantContext::class)->forget();

        return $u->refresh();
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Operator', 'email' => 'op-'.uniqid().'@platform.test', 'password' => 'operator-pass-1']);
        $u->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $u->refresh();
    }

    private function plan(string $code = 'solo'): SubscriptionPlan
    {
        return SubscriptionPlan::query()->where('code', $code)->firstOrFail();
    }

    private function subscribed(Tenant $tenant, array $over = []): Subscription
    {
        $plan = $this->plan();
        $sub = new Subscription;
        $sub->forceFill($over + [
            'tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'active', 'billing_interval' => 'monthly',
            'unit_amount' => $plan->priceFor('monthly'), 'currency' => $plan->currency, 'seats' => 1,
            'current_period_end' => now()->addDays(10),
        ])->save();

        return $sub->refresh();
    }

    private function subscriptionOf(Tenant $tenant): ?Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->first();
    }

    private function control(Tenant $tenant, array $body, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->id}/subscription", $body + ['reason' => 'Agreed with the owner']);
    }

    private function auditOf(string $action): AuditLog
    {
        return AuditLog::query()->where('action', $action)->latest('created_at')->firstOrFail();
    }

    private function assertNoMoneyMoved(): void
    {
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame(0, SubscriptionInvoice::query()->withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    // ── The subscription, with no payment ─────────────────────────────────────────────────────

    public function test_the_console_reads_the_subscription_plans_and_history(): void
    {
        $this->subscribed($this->acme);

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/admin/tenants/{$this->acme->id}/subscription")->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.plan_code', 'solo')
            ->assertJsonPath('data.subscription.currency', $this->plan()->currency)
            ->assertJsonPath('data.subscription.open_ended', false)
            ->assertJsonStructure(['data' => ['plans' => [['code', 'name', 'currency']], 'history']]);
    }

    public function test_activate_opens_a_lapsed_workspace_with_a_date_and_no_payment(): void
    {
        $this->subscribed($this->acme, ['status' => 'suspended', 'current_period_end' => now()->subMonth(), 'grace_ends_at' => now()->subWeek()]);
        $this->acme->forceFill(['account_state' => AccountState::Suspended->value, 'status' => 'suspended'])->save();
        $ends = now()->addMonths(3)->toDateString();

        $this->control($this->acme, ['action' => 'activate', 'ends_on' => $ends])->assertOk()
            ->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.subscription.commercial_basis', 'admin_grant');

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame($ends, $sub->current_period_end?->toDateString());
        $this->assertNull($sub->grace_ends_at);
        $this->assertSame('active', $this->acme->refresh()->status);
        $this->assertSame(AccountState::Active, $this->acme->account_state);
        $this->assertNoMoneyMoved();

        $entry = $this->auditOf('platform.subscription.activate');
        $this->assertSame((string) $this->acme->id, (string) $entry->tenant_id);
        $this->assertSame('Agreed with the owner', $entry->reason);
        $this->assertSame('suspended', $entry->before['status']);
        $this->assertSame('active', $entry->after['status']);
    }

    public function test_activate_creates_the_subscription_a_workspace_never_had_and_needs_a_plan(): void
    {
        $this->control($this->acme, ['action' => 'activate', 'ends_on' => now()->addMonth()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('plan_code');

        $this->control($this->acme, ['action' => 'activate', 'ends_on' => now()->addMonth()->toDateString(), 'plan_code' => 'solo'])->assertOk()
            ->assertJsonPath('data.subscription.plan_code', 'solo');
        $this->assertSame('solo', $this->acme->refresh()->subscription_plan);
    }

    public function test_a_date_in_the_past_is_refused(): void
    {
        $this->subscribed($this->acme, ['status' => 'past_due']);

        $this->control($this->acme, ['action' => 'activate', 'ends_on' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('ends_on');
        $this->assertSame('past_due', $this->subscriptionOf($this->acme)->status);
    }

    public function test_extend_moves_the_end_and_clears_a_lapse_without_charging(): void
    {
        $this->subscribed($this->acme, ['status' => 'past_due', 'current_period_end' => now()->subDay(), 'grace_ends_at' => now()->addDays(3)]);
        $ends = now()->addYear()->toDateString();

        $this->control($this->acme, ['action' => 'extend', 'ends_on' => $ends])->assertOk();

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame('active', $sub->status);
        $this->assertSame($ends, $sub->current_period_end?->toDateString());
        $this->assertNull($sub->grace_ends_at);
        $this->assertNoMoneyMoved();
        $this->assertSame(0, app(SubscriptionLifecycle::class)->duePreview()['renewals_due']);
    }

    public function test_extend_refuses_an_earlier_date_and_open_ended_access(): void
    {
        $this->subscribed($this->acme, ['current_period_end' => now()->addMonths(6)]);
        $this->control($this->acme, ['action' => 'extend', 'ends_on' => now()->addMonth()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('ends_on');

        $this->control($this->acme, ['action' => 'complimentary', 'confirm_complimentary' => true])->assertOk();
        $this->control($this->acme, ['action' => 'extend', 'ends_on' => now()->addYear()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('action');
    }

    public function test_extending_a_trial_moves_the_trial_too(): void
    {
        $this->subscribed($this->acme, ['status' => 'trialing', 'trial_ends_at' => now()->addDay(), 'current_period_end' => now()->addDay()]);
        $ends = now()->addMonth()->toDateString();

        $this->control($this->acme, ['action' => 'extend', 'ends_on' => $ends])->assertOk();

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame('trialing', $sub->status);
        $this->assertSame($ends, $sub->trial_ends_at?->toDateString());
    }

    public function test_end_stops_access_keeps_the_data_and_can_be_reopened(): void
    {
        $this->subscribed($this->acme);
        $member = $this->person('sam@x.test', [[$this->acme, 'tenant-owner']]);

        $this->control($this->acme, ['action' => 'end'])->assertOk()->assertJsonPath('data.subscription.status', 'canceled');

        $this->assertSame('suspended', $this->acme->refresh()->status);
        $this->assertSame(AccountState::Suspended, $this->acme->account_state, 'reversible by an administrator, unlike a closed account');
        $this->assertTrue(Membership::query()->where('tenant_id', $this->acme->id)->where('user_id', $member->id)->exists());

        // Ending twice is a refusal rather than a second audit line.
        $this->control($this->acme, ['action' => 'end'])->assertStatus(422);

        $this->control($this->acme, ['action' => 'activate', 'ends_on' => now()->addMonth()->toDateString()])->assertOk();
        $this->assertSame('active', $this->acme->refresh()->status);
        $this->assertNoMoneyMoved();
    }

    public function test_a_closed_account_is_not_silently_resurrected(): void
    {
        $this->subscribed($this->acme, ['status' => 'canceled']);
        $this->acme->forceFill(['account_state' => AccountState::Cancelled->value, 'status' => 'inactive'])->save();

        $this->control($this->acme, ['action' => 'activate', 'ends_on' => now()->addMonth()->toDateString()])->assertStatus(422);
        $this->assertSame(AccountState::Cancelled, $this->acme->refresh()->account_state);
        $this->assertSame('canceled', $this->subscriptionOf($this->acme)->status);
    }

    public function test_change_plan_moves_the_plan_at_its_own_price_and_currency_with_no_charge(): void
    {
        $this->subscribed($this->acme);
        $other = SubscriptionPlan::query()->where('code', '!=', 'solo')->whereNotNull('price_monthly')->where('is_active', true)->firstOrFail();

        $this->control($this->acme, ['action' => 'change_plan', 'plan_code' => $other->code, 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.subscription.plan_code', $other->code)
            ->assertJsonPath('data.subscription.currency', $other->currency)
            ->assertJsonPath('data.subscription.status', 'active');

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame(number_format((float) $other->priceFor('monthly'), 2, '.', ''), number_format((float) $sub->unit_amount, 2, '.', ''));
        $this->assertSame($other->code, $this->acme->refresh()->subscription_plan);
        $this->assertNoMoneyMoved();

        $this->control($this->acme, ['action' => 'change_plan', 'plan_code' => $other->code, 'interval' => 'monthly'])->assertStatus(422);
        $this->control($this->acme, ['action' => 'change_plan', 'plan_code' => 'no-such-plan'])->assertStatus(422)->assertJsonValidationErrors('plan_code');
    }

    public function test_change_plan_keeps_complimentary_access_complimentary(): void
    {
        $this->control($this->acme, ['action' => 'complimentary', 'plan_code' => 'solo', 'confirm_complimentary' => true])->assertOk();
        $other = SubscriptionPlan::query()->where('code', '!=', 'solo')->whereNotNull('price_monthly')->where('is_active', true)->firstOrFail();

        $this->control($this->acme, ['action' => 'change_plan', 'plan_code' => $other->code])->assertOk();

        $sub = $this->subscriptionOf($this->acme);
        $this->assertTrue($sub->isComplimentary());
        $this->assertNull($sub->current_period_end);
    }

    // ── Complimentary on a workspace that exists, and the way out of it ───────────────────────

    public function test_complimentary_needs_a_yes_and_lifts_a_lapse_with_no_end_date(): void
    {
        $this->subscribed($this->acme, ['status' => 'past_due', 'grace_ends_at' => now()->addDay(), 'trial_ends_at' => now()->subDay()]);

        $this->control($this->acme, ['action' => 'complimentary'])->assertStatus(422)->assertJsonValidationErrors('confirm_complimentary');
        $this->assertSame('past_due', $this->subscriptionOf($this->acme)->status);

        $this->control($this->acme, ['action' => 'complimentary', 'confirm_complimentary' => true])->assertOk()
            ->assertJsonPath('data.subscription.open_ended', true)
            ->assertJsonPath('data.subscription.commercial_basis', RegistrationDecision::BASIS_COMPLIMENTARY);

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->current_period_end);
        $this->assertNull($sub->grace_ends_at);
        $this->assertNull($sub->trial_ends_at);
        $this->assertNoMoneyMoved();
    }

    public function test_it_can_be_ended(): void
    {
        $this->control($this->acme, ['action' => 'complimentary', 'plan_code' => 'solo', 'confirm_complimentary' => true])->assertOk();

        $this->control($this->acme, ['action' => 'end'])->assertOk();

        $sub = $this->subscriptionOf($this->acme);
        $this->assertSame('canceled', $sub->status);
        $this->assertNull($sub->commercial_basis);
        $this->assertSame('suspended', $this->acme->refresh()->status);
        $this->assertSame(RegistrationDecision::BASIS_COMPLIMENTARY, $this->auditOf('platform.subscription.end')->before['commercial_basis']);
    }

    public function test_it_can_convert_to_paid_and_the_first_money_is_asked_for_on_the_date_set(): void
    {
        $this->control($this->acme, ['action' => 'complimentary', 'plan_code' => 'solo', 'confirm_complimentary' => true])->assertOk();
        $due = now()->addMonth()->startOfDay();

        $this->control($this->acme, ['action' => 'convert_paid', 'ends_on' => $due->toDateString(), 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.subscription.commercial_basis', null)
            ->assertJsonPath('data.subscription.open_ended', false)
            ->assertJsonPath('data.subscription.current_period_end', fn ($v) => str_starts_with((string) $v, $due->toDateString()));

        $this->assertNoMoneyMoved();

        // The ordinary path takes over: nothing before the date, a charge at the plan's own price and currency after it.
        $lifecycle = app(SubscriptionLifecycle::class);
        $this->assertSame(0, $lifecycle->duePreview()['renewals_due']);
        Carbon::setTestNow($due->copy()->addDays(2));
        $this->assertSame(1, $lifecycle->duePreview()['renewals_due']);
    }

    public function test_it_can_convert_to_a_discount_on_the_next_charge_only_in_the_plans_currency(): void
    {
        $this->control($this->acme, ['action' => 'complimentary', 'plan_code' => 'solo', 'confirm_complimentary' => true])->assertOk();
        $plan = $this->plan();
        $full = (float) $plan->priceFor('monthly');
        $half = number_format(round($full * 0.5, 2), 2, '.', '');

        foreach ([0, 100, 150] as $bad) {
            $this->control($this->acme, ['action' => 'convert_discount', 'discount_percent' => $bad, 'ends_on' => now()->addDay()->toDateString(), 'interval' => 'monthly'])
                ->assertStatus(422)->assertJsonValidationErrors('discount_percent');
        }

        $this->control($this->acme, ['action' => 'convert_discount', 'discount_percent' => 50, 'ends_on' => now()->addDay()->toDateString(), 'interval' => 'monthly'])->assertOk()
            ->assertJsonPath('data.subscription.next_charge.amount', $half)
            ->assertJsonPath('data.subscription.next_charge.currency', $plan->currency)
            ->assertJsonPath('data.subscription.next_charge.discount_percent', 50);

        $sub = $this->subscriptionOf($this->acme);
        $this->assertNull($sub->commercial_basis);
        $this->assertSame(number_format($full, 2, '.', ''), number_format((float) $sub->unit_amount, 2, '.', ''), 'the agreed price is untouched');

        $charge = app(SubscriptionCheckout::class)->chargeSubscription($sub);
        // PRICING-VAT-001: the discounted PRICE is what is agreed; VAT is added on it where the catalogue is VAT-exclusive.
        $this->assertSame($half, number_format((float) $charge['payment']->net_amount, 2, '.', ''));
        $this->assertSame(
            number_format((float) $charge['payment']->net_amount + (float) $charge['payment']->tax_amount, 2, '.', ''),
            number_format((float) $charge['payment']->amount, 2, '.', ''),
        );
        $this->assertSame($plan->currency, $charge['payment']->currency);

        // Confirmed once: the next renewal is back at the full price.
        app(SubscriptionLifecycle::class)->renewalPaid($charge['payment']->refresh());
        $this->assertNull($this->subscriptionOf($this->acme)->next_charge_discount_percent);
    }

    public function test_only_complimentary_access_converts(): void
    {
        $this->subscribed($this->acme);

        $this->control($this->acme, ['action' => 'convert_paid', 'ends_on' => now()->addMonth()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('action');
    }

    public function test_a_paid_renewal_clears_a_basis_that_said_no_money_was_taken(): void
    {
        $sub = $this->subscribed($this->acme);
        $sub->forceFill(['commercial_basis' => 'admin_grant'])->save();

        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'tenant_id' => $this->acme->id, 'subscription_id' => $sub->id, 'purpose' => 'subscription', 'plan_code' => 'solo', 'billing_interval' => 'monthly',
            'provider' => 'sandbox', 'amount' => '10.00', 'currency' => $this->plan()->currency, 'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'test:renewal',
        ])->save();

        app(SubscriptionLifecycle::class)->renewalPaid($payment);

        $this->assertNull($sub->refresh()->commercial_basis);
    }

    public function test_every_subscription_action_needs_a_reason(): void
    {
        $this->subscribed($this->acme);
        $admin = $this->admin();

        foreach (['activate', 'extend', 'end', 'complimentary', 'convert_paid', 'convert_discount', 'change_plan'] as $action) {
            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/v1/admin/tenants/{$this->acme->id}/subscription", ['action' => $action, 'plan_code' => 'solo', 'ends_on' => now()->addYear()->toDateString(), 'confirm_complimentary' => true, 'discount_percent' => 10])
                ->assertStatus(422)->assertJsonValidationErrors('reason');
        }
        $this->assertSame('active', $this->subscriptionOf($this->acme)->status);
    }

    public function test_the_other_workspace_is_never_touched(): void
    {
        $this->subscribed($this->acme);
        $other = $this->subscribed($this->beta);
        $before = $other->getAttributes();

        $this->control($this->acme, ['action' => 'complimentary', 'confirm_complimentary' => true])->assertOk();
        $this->control($this->acme, ['action' => 'end'])->assertOk();

        $this->assertSame($before['status'], $other->refresh()->status);
        $this->assertSame((string) $before['current_period_end'], (string) $other->getAttributes()['current_period_end']);
        $this->assertSame('active', $this->beta->refresh()->status);
        $this->assertSame(0, AuditLog::query()->where('action', 'like', 'platform.subscription.%')->where('tenant_id', $this->beta->id)->count());
    }

    public function test_a_subscription_action_writes_no_secret_into_the_audit_trail(): void
    {
        $this->subscribed($this->acme);
        $this->control($this->acme, ['action' => 'complimentary', 'confirm_complimentary' => true, 'current_password' => 'operator-pass-1'])->assertOk();

        $this->assertStringNotContainsString('operator-pass-1', (string) json_encode($this->auditOf('platform.subscription.complimentary')->getAttributes()));
    }

    // ── People in a workspace ─────────────────────────────────────────────────────────────────

    private function add(Tenant $tenant, array $body, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/tenants/{$tenant->id}/members", $body + ['reason' => 'Asked for by the agency owner']);
    }

    public function test_an_existing_person_is_added_with_a_role_and_keeps_their_other_workspace(): void
    {
        $sam = $this->person('sam@x.test', [[$this->beta, 'media-buyer']]);

        $this->add($this->acme, ['email' => 'SAM@x.test', 'role' => 'media-buyer'])->assertOk()
            ->assertJsonPath('data.added', true)
            ->assertJsonPath('data.invitation', null);

        $this->assertTrue(Membership::query()->where('tenant_id', $this->acme->id)->where('user_id', $sam->id)->where('status', 'active')->exists());
        $this->assertTrue(Membership::query()->where('tenant_id', $this->beta->id)->where('user_id', $sam->id)->exists());
        $this->assertSame(['media-buyer'], $sam->roles()->where('roles.tenant_id', $this->acme->id)->pluck('slug')->all());

        $entry = $this->auditOf('platform.member.added');
        $this->assertSame((string) $this->acme->id, (string) $entry->tenant_id);
        $this->assertSame('Asked for by the agency owner', $entry->reason);
        $this->assertSame('sam@x.test', $entry->after['email']);
    }

    public function test_someone_with_no_account_is_invited_and_the_link_is_never_stored_in_the_audit(): void
    {
        $response = $this->add($this->acme, ['email' => 'new@x.test', 'role' => 'media-buyer'])->assertOk()
            ->assertJsonPath('data.added', false);
        $link = (string) $response->json('data.invitation.accept_link');
        $this->assertStringContainsString('/invite/accept?token=', $link);

        $this->assertNull(User::query()->where('email', 'new@x.test')->first(), 'no account is created and no password is set');
        $this->assertStringNotContainsString('token=', (string) json_encode($this->auditOf('platform.member.added')->getAttributes()));
        $this->assertStringNotContainsString(explode('token=', $link)[1], (string) json_encode($this->auditOf('platform.member.added')->getAttributes()));
    }

    public function test_adding_never_makes_an_owner_or_grants_a_platform_role(): void
    {
        $sam = $this->person('sam@x.test');

        foreach (['tenant-owner', 'platform-admin', 'super-admin'] as $role) {
            $this->add($this->acme, ['email' => 'sam@x.test', 'role' => $role])->assertStatus(422)->assertJsonValidationErrors('role');
        }
        // Not writable by any field on this request either.
        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'media-buyer', 'is_platform_admin' => true])->assertOk();

        $this->assertFalse((bool) $sam->refresh()->is_platform_admin);
        $this->assertSame(['media-buyer'], $sam->roles()->where('roles.tenant_id', $this->acme->id)->pluck('slug')->all());
    }

    public function test_adding_refuses_another_workspaces_role_a_platform_admin_and_a_repeat(): void
    {
        Role::create(['tenant_id' => $this->beta->id, 'name' => 'Beta only', 'slug' => 'beta-only']);
        $this->person('sam@x.test');

        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'beta-only'])->assertStatus(422)->assertJsonValidationErrors('role');

        $otherAdmin = $this->admin();
        $this->add($this->acme, ['email' => $otherAdmin->email, 'role' => 'media-buyer'])->assertForbidden();

        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'media-buyer'])->assertOk();
        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'media-buyer'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_adding_respects_the_seat_cap_the_console_itself_set(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $this->subscribed($this->acme);
        $this->actingAs($this->admin(), 'sanctum')->putJson("/api/v1/admin/tenants/{$this->acme->id}/limits/team_members", ['mode' => 'cap', 'limit' => 1, 'reason' => 'One seat only'])->assertOk();
        $this->person('sam@x.test');

        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'media-buyer'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_pending_invitation_is_listed_and_reissued_with_a_reason_for_this_workspace_only(): void
    {
        $admin = $this->admin();
        $first = (string) $this->add($this->acme, ['email' => 'new@x.test', 'role' => 'media-buyer'], $admin)->assertOk()->json('data.invitation.accept_link');

        $listed = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/tenants/{$this->acme->id}")->assertOk()
            ->assertJsonPath('data.pending_invitations.0.email', 'new@x.test')
            ->assertJsonPath('data.pending_invitations.0.role', 'media-buyer');
        $this->assertArrayNotHasKey('token_hash', $listed->json('data.pending_invitations.0'));
        $id = (string) $listed->json('data.pending_invitations.0.id');

        $url = "/api/v1/admin/tenants/{$this->acme->id}/invitations/{$id}/reissue";
        $this->actingAs($admin, 'sanctum')->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('reason');

        // Reached through the other workspace, this invitation does not exist.
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/tenants/{$this->beta->id}/invitations/{$id}/reissue", ['reason' => 'Wrong workspace'])->assertNotFound();

        $second = (string) $this->actingAs($admin, 'sanctum')->postJson($url, ['reason' => 'The first mail never arrived'])->assertOk()->json('data.invitation.accept_link');
        $this->assertNotSame($first, $second, 'a fresh link, and the old one stops working');
        $this->assertSame(1, DB::table('workspace_invitations')->where('tenant_id', $this->acme->id)->where('email', 'new@x.test')->whereNull('accepted_at')->count());
        $this->assertSame('media-buyer', DB::table('workspace_invitations')->where('tenant_id', $this->acme->id)->value('role_slug'));

        $this->assertStringNotContainsString('token=', (string) json_encode($this->auditOf('platform.member.invitation_reissued')->getAttributes()));
    }

    public function test_the_drawer_says_when_an_account_is_deactivated(): void
    {
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);
        $sam->forceFill(['disabled_at' => now()])->save();

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/admin/tenants/{$this->acme->id}")->assertOk()
            ->assertJsonPath('data.people.0.disabled', true);
    }

    // ── A role change, and owners ─────────────────────────────────────────────────────────────

    public function test_a_platform_role_is_refused_even_when_a_workspace_holds_a_role_with_that_name(): void
    {
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);

        foreach (['platform-admin', 'super-admin'] as $role) {
            $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/tenants/{$this->acme->id}/members/{$sam->uuid}", ['action' => 'change_role', 'role' => $role, 'reason' => 'Trying it on', 'confirm_owner' => true])
                ->assertStatus(422)->assertJsonValidationErrors('role');
        }
        $this->assertFalse((bool) $sam->refresh()->is_platform_admin);
    }

    public function test_nobody_becomes_an_owner_without_an_explicit_yes(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);
        $url = "/api/v1/admin/tenants/{$this->acme->id}/members/{$sam->uuid}";

        $this->actingAs($this->admin(), 'sanctum')->postJson($url, ['action' => 'change_role', 'role' => 'tenant-owner', 'reason' => 'Handing over'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_owner');
        $this->assertSame(['media-buyer'], $sam->roles()->where('roles.tenant_id', $this->acme->id)->pluck('slug')->all());

        $this->actingAs($this->admin(), 'sanctum')->postJson($url, ['action' => 'change_role', 'role' => 'tenant-owner', 'reason' => 'Handing over', 'confirm_owner' => true])->assertOk();
        $this->assertSame(['tenant-owner'], $sam->roles()->where('roles.tenant_id', $this->acme->id)->pluck('slug')->all());
    }

    // ── An account ────────────────────────────────────────────────────────────────────────────

    private function access(User $target, array $body, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->admin(), 'sanctum')->postJson("/api/v1/admin/users/{$target->uuid}/access", $body + ['reason' => 'Left the agency']);
    }

    public function test_an_account_is_deactivated_and_reactivated_with_a_reason_and_its_sessions_end(): void
    {
        $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $sam = $this->person('sam@x.test', [[$this->acme, 'media-buyer']]);
        $sam->createToken('laptop');

        $this->access($sam, ['action' => 'deactivate'])->assertOk()->assertJsonPath('data.disabled', true);
        $this->assertNotNull($sam->refresh()->disabled_at);
        $this->assertSame(0, $sam->tokens()->count());

        $entry = $this->auditOf('platform.user.deactivate');
        $this->assertSame('Left the agency', $entry->reason);
        $this->assertSame('sam@x.test', $entry->after['email']);
        $this->assertNotNull($entry->after['disabled_at']);
        $this->assertNull($entry->before['disabled_at']);

        $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/login', ['email' => 'sam@x.test', 'password' => 'secret1234'])->assertStatus(403);

        $this->access($sam, ['action' => 'activate'])->assertOk()->assertJsonPath('data.disabled', false);
        $this->assertNull($sam->refresh()->disabled_at);
    }

    public function test_the_last_active_owner_is_not_deactivated_and_a_deactivated_owner_does_not_count(): void
    {
        $owner = $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $this->access($owner, ['action' => 'deactivate'])->assertStatus(422)->assertJsonValidationErrors('action');
        $this->assertNull($owner->refresh()->disabled_at);

        $second = $this->person('second@x.test', [[$this->acme, 'tenant-owner']]);
        $this->access($second, ['action' => 'deactivate'])->assertOk();

        // The deactivated one is not somebody who can answer for this workspace.
        $this->access($owner, ['action' => 'deactivate'])->assertStatus(422);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/tenants/{$this->acme->id}/members/{$owner->uuid}", ['action' => 'suspend', 'reason' => 'Leaving now'])
            ->assertStatus(422);
    }

    public function test_an_operator_cannot_deactivate_themselves_or_another_platform_admin(): void
    {
        $operator = $this->admin();
        $this->access($operator, ['action' => 'deactivate'], $operator)->assertStatus(422);
        $this->assertNull($operator->refresh()->disabled_at);

        $this->access($this->admin(), ['action' => 'deactivate'], $operator)->assertForbidden();
    }

    // ── The status switch ─────────────────────────────────────────────────────────────────────

    public function test_reactivating_a_workspace_needs_a_reason_as_suspending_does(): void
    {
        $this->acme->forceFill(['status' => 'suspended'])->save();
        $url = "/api/v1/admin/tenants/{$this->acme->id}/status";

        $this->actingAs($this->admin(), 'sanctum')->patchJson($url, ['status' => 'active'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('suspended', $this->acme->refresh()->status);

        $this->actingAs($this->admin(), 'sanctum')->patchJson($url, ['status' => 'active', 'reason' => 'The dispute is settled'])->assertOk();
        $this->assertSame('active', $this->acme->refresh()->status);
    }

    // ── The denial, as hard as the success ────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function everyControl(): array
    {
        $reason = ['reason' => 'Trying from outside'];

        return [
            'read the subscription' => ['GET', '/tenants/{t}/subscription', []],
            'activate' => ['POST', '/tenants/{t}/subscription', ['action' => 'activate', 'plan_code' => 'solo', 'ends_on' => '2099-01-01'] + $reason],
            'extend' => ['POST', '/tenants/{t}/subscription', ['action' => 'extend', 'ends_on' => '2099-01-01'] + $reason],
            'end' => ['POST', '/tenants/{t}/subscription', ['action' => 'end'] + $reason],
            'complimentary' => ['POST', '/tenants/{t}/subscription', ['action' => 'complimentary', 'plan_code' => 'solo', 'confirm_complimentary' => true] + $reason],
            'convert to paid' => ['POST', '/tenants/{t}/subscription', ['action' => 'convert_paid', 'ends_on' => '2099-01-01'] + $reason],
            'convert to a discount' => ['POST', '/tenants/{t}/subscription', ['action' => 'convert_discount', 'ends_on' => '2099-01-01', 'discount_percent' => 50] + $reason],
            'change plan' => ['POST', '/tenants/{t}/subscription', ['action' => 'change_plan', 'plan_code' => 'solo'] + $reason],
            'suspend' => ['PATCH', '/tenants/{t}/status', ['status' => 'suspended'] + $reason],
            'reactivate' => ['PATCH', '/tenants/{t}/status', ['status' => 'active'] + $reason],
            'add a member' => ['POST', '/tenants/{t}/members', ['email' => 'climber@x.test', 'role' => 'media-buyer'] + $reason],
            'change a role' => ['POST', '/tenants/{t}/members/{u}', ['action' => 'change_role', 'role' => 'tenant-owner', 'confirm_owner' => true] + $reason],
            'remove a member' => ['POST', '/tenants/{t}/members/{u}', ['action' => 'remove'] + $reason],
            'deactivate' => ['POST', '/users/{u}/access', ['action' => 'deactivate'] + $reason],
            'activate an account' => ['POST', '/users/{u}/access', ['action' => 'activate'] + $reason],
            'password link' => ['POST', '/users/{u}/access', ['action' => 'password_link', 'current_password' => 'secret1234'] + $reason],
            'owner invitation' => ['POST', '/tenants/{t}/owner-invitation', ['owner_email' => 'climber@x.test']],
            'reissue an invitation' => ['POST', '/tenants/{t}/invitations/some-id/reissue', $reason],
            'support session' => ['POST', '/tenants/{t}/support-session', ['current_password' => 'secret1234'] + $reason],
        ];
    }

    /**
     * @dataProvider everyControl
     *
     * @param  array<string, mixed>  $body
     */
    public function test_a_workspace_owner_gets_403_and_changes_nothing(string $method, string $path, array $body): void
    {
        $owner = $this->person('owner@x.test', [[$this->acme, 'tenant-owner']]);
        $victim = $this->person('victim@x.test', [[$this->acme, 'media-buyer']]);
        $sub = $this->subscribed($this->acme);
        $auditBefore = AuditLog::query()->where('action', 'like', 'platform.%')->count();

        $url = '/api/v1/admin'.str_replace(['{t}', '{u}'], [(string) $this->acme->id, (string) $victim->uuid], $path);

        $this->actingAs($owner, 'sanctum')->json($method, $url, $body)->assertForbidden();

        // Nor can a person from outside the workspace, nor anyone signed out.
        $stranger = $this->person('stranger@x.test', [[$this->beta, 'tenant-owner']]);
        $this->actingAs($stranger, 'sanctum')->json($method, $url, $body)->assertForbidden();
        app('auth')->forgetGuards();
        $this->json($method, $url, $body)->assertUnauthorized();

        $this->assertSame('active', $sub->refresh()->status);
        $this->assertNull($sub->commercial_basis);
        $this->assertSame('active', $this->acme->refresh()->status);
        $this->assertNull($victim->refresh()->disabled_at);
        $this->assertSame(['media-buyer'], $victim->roles()->where('roles.tenant_id', $this->acme->id)->pluck('slug')->all());
        $this->assertSame(2, Membership::query()->where('tenant_id', $this->acme->id)->count());
        $this->assertSame(0, DB::table('workspace_invitations')->count());
        $this->assertSame($auditBefore, AuditLog::query()->where('action', 'like', 'platform.%')->count());
    }

    public function test_the_trail_files_these_under_subscriptions_and_permissions(): void
    {
        $admin = $this->admin();
        $this->subscribed($this->acme);
        $this->control($this->acme, ['action' => 'complimentary', 'confirm_complimentary' => true], $admin)->assertOk();
        $this->person('sam@x.test');
        $this->add($this->acme, ['email' => 'sam@x.test', 'role' => 'media-buyer'], $admin)->assertOk();

        $subscriptions = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit?category=subscriptions')->assertOk()->json('data.entries'))->pluck('action');
        $this->assertContains('platform.subscription.complimentary', $subscriptions);

        $permissions = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/audit?category=permissions')->assertOk()->json('data.entries'))->pluck('action');
        $this->assertContains('platform.member.added', $permissions);
    }
}
