<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Accounts\Services\RegistrationPolicy;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\Workspace;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * SIGNUP-DECISION-001 — the decision binds to the engine, and money only ever means money.
 *
 * Approval is not payment, a waiver is not paid, and a workspace appears only when `ProvisionWorkspace` itself accepts
 * what cleared the gate. Replays make nothing twice.
 */
final class RegistrationDecisionTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->assertingAcrossTenants();
    }

    private function admin(string $email = 'owner@platform.test'): User
    {
        $user = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** An application exactly where the Owner's was: both facts proved, and sitting at the payment gate. */
    private function awaitingPayment(string $email = 'applicant@a.test', array $overrides = []): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill(array_merge([
            'email' => $email, 'name' => 'Applicant', 'tenant_name' => 'Applicant Co', 'account_type' => 'brand',
            'requested_portal' => 'app', 'plan_code' => 'solo', 'billing_interval' => 'annual', 'phone' => self::aFreshSaudiNumber(),
            'password' => Hash::make('secret1234'),
            'state' => AccountState::ApprovedAwaitingPayment->value,
            'email_verified_at' => now()->subDay(), 'mobile_verified_at' => now()->subDay(),
        ], $overrides))->save();

        if (! SubscriptionPlan::query()->where('code', 'solo')->exists()) {
            $this->seed(SubscriptionPlanSeeder::class);
        }

        return $request->refresh();
    }

    private function decide(User $admin, RegistrationRequest $request, array $input)
    {
        return $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision", $input + ['reason' => 'Reviewed by the owner']);
    }

    private function waiver(RegistrationRequest $request, array $over = []): array
    {
        $plan = SubscriptionPlan::query()->where('code', $request->plan_code)->firstOrFail();

        return $over + [
            'outcome' => RegistrationDecision::WAIVER,
            'confirm_waiver' => true,
            'confirm_amount' => number_format((float) $plan->priceFor((string) $request->billing_interval), 2, '.', ''),
        ];
    }

    private function subscriptionOf(RegistrationRequest $request): ?Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $request->refresh()->tenant_id)->first();
    }

    private function settle(RegistrationRequest $request, string $amount, string $currency): SubscriptionPayment
    {
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'registration_request_id' => $request->getKey(), 'purpose' => 'subscription', 'plan_code' => $request->plan_code,
            'billing_interval' => $request->billing_interval, 'provider' => 'sandbox', 'amount' => $amount, 'currency' => $currency,
            'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'test:'.Str::uuid(),
        ])->save();

        return $payment;
    }

    private function assertExactlyOneOfEach(): void
    {
        $this->assertSame(1, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Workspace::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Membership::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Project::query()->withoutGlobalScopes()->count());
    }

    // ── 1. Payment required ───────────────────────────────────────────────────────────────────

    public function test_approval_with_payment_required_activates_nothing_until_a_settled_charge(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED])->assertOk()
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment')
            ->assertJsonPath('data.registration.provisioned', false);

        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, SubscriptionPayment::count());

        $this->settle($request, '999.00', 'SAR');
        app(AdvanceRegistration::class)->paymentConfirmed($request);

        $this->assertSame(AccountState::Active, $request->refresh()->state);
        $this->assertExactlyOneOfEach();
    }

    public function test_the_applicant_hears_awaiting_payment_rather_than_workspace_ready(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED])->assertOk();

        $message = SubscriptionNotification::query()->where('event', 'registration_approved')->firstOrFail();
        $this->assertStringContainsString('إتمام الدفع', (string) $message->body);
        $this->assertStringNotContainsString('استخدام', mb_substr((string) $message->subject, 0, 5));
    }

    public function test_a_gateway_that_is_not_configured_is_said_separately_and_paid_stays_false(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision/preview", ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'reason' => 'Looking at it'])
            ->assertOk()->assertJsonStructure(['data' => ['payment_provider_ready', 'terms' => ['amount_due', 'currency', 'vat'], 'access']]);

        $this->assertSame(0, RegistrationDecision::count(), 'a preview writes nothing');
    }

    public function test_approving_twice_is_one_decision_and_one_message(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED])->assertOk();
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED])->assertOk()
            ->assertJsonPath('data.replayed', true);

        $this->assertSame(1, RegistrationDecision::count());
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'registration_approved')->count());
    }

    // ── 2. Discount ───────────────────────────────────────────────────────────────────────────

    public function test_a_discount_is_the_exact_remainder_and_activates_only_on_it(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $plan = SubscriptionPlan::query()->where('code', 'solo')->firstOrFail();
        $list = (float) $plan->priceFor('annual');
        $expected = number_format(round($list * 0.75, 2), 2, '.', '');

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 25])->assertOk()
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment')
            ->assertJsonPath('data.registration.commercial.amount_due', $expected)
            ->assertJsonPath('data.registration.commercial.quote.due_now', $expected)
            ->assertJsonPath('data.registration.commercial.renewal_amount', number_format($list, 2, '.', ''))
            ->assertJsonPath('data.registration.commercial.currency', $plan->currency)
            ->assertJsonPath('data.registration.provisioned', false);

        $vat = RegistrationDecision::query()->firstOrFail()->terms['vat'];
        $this->assertSame(number_format(round((float) $expected * 0.15, 2), 2, '.', ''), $vat['tax']);

        // The charge opened is the remainder, and the gate does not open on anything less.
        $this->seedChargeFor($request, $expected, $plan->currency);

        $this->settle($request, number_format((float) $expected - 1, 2, '.', ''), $plan->currency);
        $this->expectException(RuntimeException::class);
        app(ProvisionWorkspace::class)->execute($request);
    }

    private function seedChargeFor(RegistrationRequest $request, string $expected, string $currency): void
    {
        $result = app(SubscriptionCheckout::class)->startRegistrationPayment($request);

        // PRICING-VAT-001: the remainder is the PRICE; a VAT-exclusive catalogue charges it with VAT added, the split kept on the payment.
        $this->assertSame($expected, number_format((float) $result['payment']->net_amount, 2, '.', ''));
        $this->assertSame(
            number_format((float) $result['payment']->net_amount + (float) $result['payment']->tax_amount, 2, '.', ''),
            number_format((float) $result['payment']->amount, 2, '.', ''),
        );
        $this->assertSame($currency, $result['payment']->currency);
        $this->assertNotSame('paid', $result['payment']->status);
    }

    public function test_paying_the_discounted_remainder_activates_and_renewal_keeps_the_full_price(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $plan = SubscriptionPlan::query()->where('code', 'solo')->firstOrFail();
        $expected = number_format(round((float) $plan->priceFor('annual') * 0.5, 2), 2, '.', '');

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 50])->assertOk();

        $this->settle($request, $expected, $plan->currency);
        app(AdvanceRegistration::class)->paymentConfirmed($request);

        $this->assertSame(AccountState::Active, $request->refresh()->state);
    }

    public function test_a_discount_out_of_range_is_refused(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        foreach ([0, 100, 150] as $percent) {
            $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => $percent])->assertStatus(422);
        }

        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_changing_a_discount_opens_a_new_charge_rather_than_reusing_the_old_price(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $first = app(SubscriptionCheckout::class)->startRegistrationPayment($request);
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 10])->assertOk();
        $second = app(SubscriptionCheckout::class)->startRegistrationPayment($request->refresh());

        $this->assertNotSame((string) $first['payment']->getKey(), (string) $second['payment']->getKey());
        $this->assertLessThan((float) $first['payment']->amount, (float) $second['payment']->amount);
    }

    // ── 3. Waiver ─────────────────────────────────────────────────────────────────────────────

    public function test_a_waiver_activates_with_exactly_one_of_each_and_no_money_recorded(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $emailProved = $request->email_verified_at;

        $res = $this->decide($admin, $request, $this->waiver($request))->assertOk()
            ->assertJsonPath('data.registration.state', 'active')
            ->assertJsonPath('data.registration.provisioned', true)
            ->assertJsonPath('data.registration.commercial.waived_by_admin', true)
            ->assertJsonPath('data.registration.commercial.scope', 'one_period')
            ->assertJsonPath('data.registration.commercial.amount_due', '0.00');

        $this->assertNotNull($res->json('data.registration.commercial.renewal_on'));

        $this->assertExactlyOneOfEach();
        $this->assertSame(0, SubscriptionPayment::count(), 'no payment and no provider transaction is created');
        $this->assertSame(0, SubscriptionInvoice::query()->withoutGlobalScopes()->count());

        $subscription = $this->subscriptionOf($request);
        $this->assertSame('active', $subscription->status);
        $this->assertSame(RegistrationDecision::BASIS_WAIVED, $subscription->commercial_basis);
        $this->assertNull($subscription->provider);
        $this->assertNotSame('paid', $subscription->status);
        $this->assertTrue($subscription->current_period_end->lte(now()->addYear()->addMinute()), 'one period, never lifetime');

        // Verified facts were preserved, not refreshed.
        $user = User::where('email', 'applicant@a.test')->firstOrFail();
        $this->assertTrue($emailProved->equalTo($user->email_verified_at));
        $this->assertFalse((bool) $user->is_platform_admin);

        $message = SubscriptionNotification::query()->where('event', 'registration_approved_waived')->firstOrFail();
        $this->assertStringContainsString('لم يُخصم', (string) $message->body);
    }

    public function test_a_waiver_needs_a_reason_a_yes_and_the_exact_amount(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $url = "/api/v1/admin/registrations/{$request->getKey()}/decision";

        $this->actingAs($admin, 'sanctum')->postJson($url, $this->waiver($request))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->decide($admin, $request, $this->waiver($request, ['confirm_waiver' => false]))->assertStatus(422);
        $this->decide($admin, $request, array_diff_key($this->waiver($request), ['confirm_amount' => 1]))->assertStatus(422);
        $this->decide($admin, $request, $this->waiver($request, ['confirm_amount' => '1.00']))->assertStatus(409);

        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_a_waiver_preview_states_the_exact_fee_and_period_and_decides_nothing(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $fee = $this->waiver($request)['confirm_amount'];

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision/preview", ['outcome' => RegistrationDecision::WAIVER, 'reason' => 'Looking at it'])
            ->assertOk()
            ->assertJsonPath('data.terms.fee', $fee)
            ->assertJsonPath('data.terms.scope', 'one_period')
            ->assertJsonPath('data.terms.amount_due', '0.00')
            ->assertJsonPath('data.access.creates.tenant', 1)
            ->assertJsonPath('data.access.role', 'owner');

        $this->assertSame(0, RegistrationDecision::count());
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
    }

    public function test_the_decision_is_audited_with_actor_reason_old_and_new(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'reason' => 'First look at it'])->assertOk();
        $this->decide($admin, $request, $this->waiver($request, ['reason' => 'Pilot customer, owner agreed']))->assertOk();

        $log = AuditLog::query()->where('action', 'registration.decision.made')->get()
            ->firstOrFail(fn (AuditLog $row) => $row->after['outcome'] === RegistrationDecision::WAIVER);
        $this->assertSame((int) $admin->getKey(), (int) $log->user_id);
        $this->assertSame('Pilot customer, owner agreed', $log->reason);
        $this->assertSame(RegistrationDecision::PAYMENT_REQUIRED, $log->before['outcome']);
        $this->assertSame(RegistrationDecision::WAIVER, $log->after['outcome']);
        $this->assertNotNull($log->created_at);
    }

    public function test_replaying_a_waiver_makes_nothing_twice(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $input = $this->waiver($request, ['idempotency_key' => 'form-1']);

        $this->decide($admin, $request, $input)->assertOk()->assertJsonPath('data.replayed', false);
        $this->decide($admin, $request, $input)->assertOk()->assertJsonPath('data.replayed', true);

        app(AdvanceRegistration::class)->reconcile($request);
        app(ProvisionWorkspace::class)->execute($request->refresh());

        $this->assertExactlyOneOfEach();
        $this->assertSame(1, User::where('email', 'applicant@a.test')->count());
        $this->assertSame(1, Subscription::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'registration_approved_waived')->count());
        $this->assertSame(0, SubscriptionInvoice::query()->withoutGlobalScopes()->count());
    }

    public function test_an_idempotency_key_cannot_be_reused_for_a_different_decision(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'idempotency_key' => 'k-1'])->assertOk();
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 10, 'idempotency_key' => 'k-1'])->assertStatus(409);
    }

    public function test_a_decision_taken_on_a_stale_view_is_refused(): void
    {
        $admin = $this->admin();
        $other = $this->admin('second@platform.test');
        $request = $this->awaitingPayment();

        $this->decide($other, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'expected_decision_id' => null])->assertOk();

        // The first reviewer still thinks nothing has been decided.
        $this->decide($admin, $request, $this->waiver($request, ['expected_decision_id' => null]))->assertStatus(409);

        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(RegistrationDecision::PAYMENT_REQUIRED, RegistrationDecision::query()->where('status', RegistrationDecision::IN_FORCE)->value('outcome'));
    }

    public function test_the_database_itself_refuses_two_decisions_in_force(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED])->assertOk();

        $this->expectException(QueryException::class);
        $second = new RegistrationDecision;
        $second->forceFill([
            'registration_request_id' => $request->getKey(), 'outcome' => RegistrationDecision::WAIVER,
            'status' => RegistrationDecision::IN_FORCE, 'reason' => 'race',
        ])->save();
    }

    // ── What a waiver must not be ─────────────────────────────────────────────────────────────

    public function test_a_bare_requires_payment_false_is_refused_and_activates_nothing(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/admin/registrations/{$request->getKey()}", ['requires_payment' => false, 'reason' => 'Trying the old way'])
            ->assertStatus(422);

        foreach ([['discount_percent' => 50], ['trial_days' => 30]] as $stored) {
            $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/v1/admin/registrations/{$request->getKey()}", $stored + ['reason' => 'Trying the old way'])
                ->assertStatus(422);
        }
    }

    public function test_a_concession_already_on_the_row_does_not_open_the_gate(): void
    {
        $request = $this->awaitingPayment();
        $request->forceFill(['review_concessions' => ['policy' => ['requires_payment' => false], 'reason' => 'Old toggle', 'decided_by' => 1]])->save();

        $this->assertTrue(app(RegistrationPolicy::class)->for($request)['requires_payment']);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, app(AdvanceRegistration::class)->reconcile($request)->state);

        $this->expectException(RuntimeException::class);
        app(ProvisionWorkspace::class)->execute($request);
    }

    public function test_the_provisioner_refuses_an_expired_waiver_and_one_for_another_plan(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment('expired@a.test');
        $request->forceFill(['state' => AccountState::MobileVerificationRequired->value, 'mobile_verified_at' => null])->save();

        $this->decide($admin, $request, $this->waiver($request))->assertOk()
            ->assertJsonPath('data.registration.state', 'mobile_verification_required');

        $decision = RegistrationDecision::query()->firstOrFail();
        $terms = $decision->terms;
        $terms['period_end'] = now()->subDay()->toIso8601String();
        $decision->forceFill(['terms' => $terms])->save();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, app(AdvanceRegistration::class)->mobileVerified($request->refresh())->state);

        $terms['period_end'] = now()->addMonth()->toIso8601String();
        $terms['plan_code'] = 'some-other-plan';
        $decision->forceFill(['terms' => $terms])->save();

        $this->expectException(RuntimeException::class);
        app(ProvisionWorkspace::class)->execute($request->refresh());
    }

    public function test_a_waiver_decided_by_someone_no_longer_an_administrator_clears_nothing(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $request->forceFill(['state' => AccountState::MobileVerificationRequired->value, 'mobile_verified_at' => null])->save();
        $this->decide($admin, $request, $this->waiver($request))->assertOk();

        $admin->forceFill(['is_platform_admin' => false])->save();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, app(AdvanceRegistration::class)->mobileVerified($request->refresh())->state);
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
    }

    public function test_a_waiver_taken_before_verification_finishes_activates_only_when_it_does(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $request->forceFill(['state' => AccountState::MobileVerificationRequired->value, 'mobile_verified_at' => null])->save();

        $this->decide($admin, $request, $this->waiver($request))->assertOk()
            ->assertJsonPath('data.registration.provisioned', false);
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'registration_approved_steps_remain')->count());
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertNull($request->refresh()->mobile_verified_at, 'a decision proves nothing');

        app(AdvanceRegistration::class)->mobileVerified($request);

        $this->assertExactlyOneOfEach();
        $this->assertSame(RegistrationDecision::BASIS_WAIVED, $this->subscriptionOf($request)->commercial_basis);
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'registration_approved_waived')->count());
    }

    public function test_a_refunded_payment_clears_nothing(): void
    {
        $request = $this->awaitingPayment();
        $payment = $this->settle($request, '999.00', 'SAR');
        $payment->forceFill(['refunded_at' => now()])->save();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, app(AdvanceRegistration::class)->reconcile($request)->state);
    }

    public function test_after_a_settled_payment_there_is_nothing_to_concede(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $request->forceFill(['state' => AccountState::MobileVerificationRequired->value, 'mobile_verified_at' => null])->save();
        $this->settle($request, '999.00', 'SAR');

        $this->decide($admin, $request, $this->waiver($request))->assertStatus(422);
        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_a_duplicate_email_account_is_never_attached(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment('taken@a.test');
        User::create(['name' => 'Already here', 'email' => 'taken@a.test', 'password' => 'secret1234']);

        $this->decide($admin, $request, $this->waiver($request))->assertStatus(422);
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
    }

    public function test_a_rejected_or_provisioned_application_cannot_be_decided_again(): void
    {
        $admin = $this->admin();
        $rejected = $this->awaitingPayment('rejected@a.test');
        $this->decide($admin, $rejected, ['outcome' => RegistrationDecision::REJECT, 'reason' => 'Not a real business'])->assertOk()
            ->assertJsonPath('data.registration.state', 'rejected');
        $this->decide($admin, $rejected, $this->waiver($rejected))->assertStatus(422);

        $active = $this->awaitingPayment('active@a.test');
        $this->decide($admin, $active, $this->waiver($active))->assertOk();
        $this->decide($admin, $active, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 10])->assertStatus(422);
    }

    public function test_when_the_waived_period_ends_payment_is_due_and_nothing_is_taken(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $this->decide($admin, $request, $this->waiver($request))->assertOk();
        $subscription = $this->subscriptionOf($request);

        Carbon::setTestNow($subscription->current_period_end->copy()->addDay());
        app(SubscriptionLifecycle::class)->runDueWork(app(SubscriptionCheckout::class));
        Carbon::setTestNow();

        $this->assertSame('past_due', $subscription->refresh()->status);
        $this->assertSame(0, SubscriptionPayment::query()->where('status', 'paid')->count());
    }

    // ── 4. Trial ──────────────────────────────────────────────────────────────────────────────

    public function test_a_trial_is_active_explicitly_dated_and_ends_at_the_payment_gate(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();
        $ends = now()->addDays(14)->toDateString();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::TRIAL, 'trial_ends_on' => $ends])->assertOk()
            ->assertJsonPath('data.registration.state', 'active')
            ->assertJsonPath('data.registration.commercial.renewal_on', $ends);

        $subscription = $this->subscriptionOf($request);
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame($ends, $subscription->trial_ends_at->toDateString());
        $this->assertSame(RegistrationDecision::BASIS_TRIAL, $subscription->commercial_basis);
        $this->assertNull($subscription->auto_convert_consent_at, 'nobody agreed to be charged');
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertExactlyOneOfEach();

        // The reminder comes before the end, in words that promise no automatic charge.
        Carbon::setTestNow(now()->addDays(13));
        app(SubscriptionLifecycle::class)->warnEndingTrials();
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'admin_trial_ending')->count());

        Carbon::setTestNow(now()->addDays(3));
        app(SubscriptionLifecycle::class)->convertDueTrials();
        Carbon::setTestNow();

        $this->assertSame('past_due', $subscription->refresh()->status);
        $this->assertNotNull($subscription->grace_ends_at);
        $this->assertSame(0, SubscriptionPayment::query()->where('status', 'paid')->count());
    }

    public function test_a_trial_needs_a_real_future_date(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        foreach ([now()->subDay()->toDateString(), now()->addYears(3)->toDateString()] as $date) {
            $this->decide($admin, $request, ['outcome' => RegistrationDecision::TRIAL, 'trial_ends_on' => $date])->assertStatus(422);
        }
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::TRIAL])->assertStatus(422);
    }

    // ── External settlement ───────────────────────────────────────────────────────────────────

    private function evidence(string $amount = '999999.00', string $currency = 'SAR'): array
    {
        return ['reference' => 'BANK-77', 'amount' => $amount, 'currency' => $currency, 'paid_on' => now()->toDateString()];
    }

    public function test_external_settlement_needs_evidence_and_a_second_person(): void
    {
        $admin = $this->admin();
        $second = $this->admin('second@platform.test');
        $request = $this->awaitingPayment();
        $currency = SubscriptionPlan::query()->where('code', 'solo')->value('currency');

        // No bare «mark paid»: no evidence, no record.
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::EXTERNAL_SETTLEMENT])->assertStatus(422);
        // Evidence that does not cover the fee.
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::EXTERNAL_SETTLEMENT, 'evidence' => $this->evidence('1.00', $currency)])->assertStatus(422);

        $res = $this->decide($admin, $request, ['outcome' => RegistrationDecision::EXTERNAL_SETTLEMENT, 'evidence' => $this->evidence(currency: $currency)])->assertOk()
            ->assertJsonPath('data.decision.status', RegistrationDecision::AWAITING_FINANCE)
            ->assertJsonPath('data.registration.provisioned', false);
        $id = $res->json('data.decision.id');
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());

        $verify = "/api/v1/admin/registrations/{$request->getKey()}/decisions/{$id}/verify-settlement";
        $this->actingAs($admin, 'sanctum')->postJson($verify, ['reason' => 'I checked my own entry'])->assertForbidden();
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());

        $this->actingAs($second, 'sanctum')->postJson($verify, ['reason' => 'Matched on the bank statement'])->assertOk()
            ->assertJsonPath('data.registration.state', 'active');

        $this->assertExactlyOneOfEach();
        $this->assertSame(RegistrationDecision::BASIS_EXTERNAL, $this->subscriptionOf($request)->commercial_basis);
        $this->assertSame(0, SubscriptionPayment::count(), 'it is not recorded as a gateway payment');
    }

    // ── 5. Access scope and isolation ─────────────────────────────────────────────────────────

    /** @return array{tenant: Tenant, role: Role, allowed: Project, other: Project} */
    private function existingWorkspace(string $name = 'Agency Co'): array
    {
        $tenant = Tenant::create(['name' => $name, 'slug' => Str::slug($name), 'status' => 'active', 'account_type' => 'agency', 'subscription_plan' => 'solo']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);

        $owner = User::create(['name' => 'Owner of '.$name, 'email' => Str::slug($name).'-owner@a.test', 'password' => 'secret1234']);
        $owner->forceFill(['email_verified_at' => now()])->save();
        $ownerRole = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'tenant-owner'], ['name' => 'Owner', 'is_system' => true]);
        $owner->assignRole($ownerRole);
        Membership::create(['user_id' => $owner->id, 'tenant_id' => $tenant->id, 'portal' => 'agency', 'role' => 'owner', 'status' => 'active', 'is_default' => true]);

        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'slug' => 'viewer'], ['name' => 'Viewer', 'is_system' => true]);
        // Read access, and deliberately not `projects.view.all`: reach comes from the project membership.
        $role->givePermissionTo('projects.view', 'campaigns.view');

        $client = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => $name.' client', 'slug' => Str::slug($name).'-'.Str::random(4), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $allowed = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => $name.' allowed', 'status' => 'active']);
        $other = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => $name.' other', 'status' => 'active']);

        $this->seed(SubscriptionPlanSeeder::class);
        $sub = new Subscription;
        $sub->forceFill(['tenant_id' => $tenant->id, 'plan_id' => SubscriptionPlan::query()->where('code', 'agency')->value('id') ?? SubscriptionPlan::query()->value('id'), 'status' => 'active', 'billing_interval' => 'monthly', 'unit_amount' => '10.00', 'currency' => 'SAR', 'seats' => 1])->save();

        app(TenantContext::class)->forget();

        return ['tenant' => $tenant, 'role' => $role, 'allowed' => $allowed, 'other' => $other];
    }

    private function joinInput(array $w, array $access = []): array
    {
        return [
            'outcome' => RegistrationDecision::JOIN_EXISTING,
            'access' => $access + [
                'mode' => 'join_existing', 'tenant_id' => (string) $w['tenant']->id, 'role' => 'viewer',
                'portal' => 'agency', 'project_ids' => [(string) $w['allowed']->id],
            ],
        ];
    }

    public function test_a_limited_role_reaches_only_its_project_and_cannot_cross_a_tenant(): void
    {
        $admin = $this->admin();
        $w = $this->existingWorkspace();
        $elsewhere = $this->existingWorkspace('Stranger Co');
        $request = $this->awaitingPayment('limited@a.test');

        $preview = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision/preview", $this->joinInput($w) + ['reason' => 'Looking at it'])
            ->assertOk()
            ->assertJsonPath('data.access.creates.tenant', 0)
            ->assertJsonPath('data.access.creates.membership', 1)
            ->assertJsonPath('data.access.role', 'viewer');
        $this->assertSame([$w['allowed']->name], $preview->json('data.access.projects'));

        $this->decide($admin, $request, $this->joinInput($w))->assertOk()->assertJsonPath('data.registration.state', 'active');

        $tenants = Tenant::query()->withoutGlobalScopes()->count();
        $this->assertSame(2, $tenants, 'joining creates no workspace');
        $this->assertSame((string) $w['tenant']->id, (string) $request->refresh()->tenant_id);

        $user = User::where('email', 'limited@a.test')->firstOrFail();
        $this->assertFalse((bool) $user->is_platform_admin);
        $membership = Membership::query()->withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('viewer', $membership->role);
        $this->assertSame((string) $w['tenant']->id, (string) $membership->tenant_id);
        $this->assertSame(1, ProjectMembership::query()->withoutGlobalScopes()->where('user_id', $user->id)->count());
        $this->assertFalse($user->fresh()->roles()->where('slug', 'tenant-owner')->exists());

        // No fee, no subscription, and the workspace's own subscription is untouched.
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame('10.00', (string) Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $w['tenant']->id)->value('unit_amount'));

        $this->actingAs($user->fresh(), 'sanctum');
        $this->withHeaders(['X-Tenant-Id' => (string) $w['tenant']->id]);

        $this->getJson("/api/v1/projects/{$w['allowed']->id}/campaigns")->assertOk();
        $this->getJson("/api/v1/projects/{$w['other']->id}/campaigns")->assertForbidden();
        $crossed = $this->getJson("/api/v1/projects/{$elsewhere['allowed']->id}/campaigns")->status();
        $this->assertContains($crossed, [403, 404]);

        // The platform console and another tenant's membership stay out of reach.
        $this->getJson('/api/v1/admin/registrations')->assertForbidden();
        $this->assertSame(0, Membership::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('tenant_id', $elsewhere['tenant']->id)->count());
    }

    public function test_the_targets_offer_no_ownership_and_only_that_workspaces_own_things(): void
    {
        $admin = $this->admin();
        $w = $this->existingWorkspace();
        $elsewhere = $this->existingWorkspace('Stranger Co');

        $res = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/tenants/{$w['tenant']->id}/decision-targets")->assertOk();

        $this->assertSame(['viewer'], $res->json('data.roles.*.slug'));
        $this->assertContains((string) $w['allowed']->id, $res->json('data.projects.*.id'));
        $this->assertNotContains((string) $elsewhere['allowed']->id, $res->json('data.projects.*.id'));

        $customer = User::create(['name' => 'Customer', 'email' => 'customer@a.test', 'password' => 'secret1234']);
        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/admin/tenants/{$w['tenant']->id}/decision-targets")->assertForbidden();
    }

    public function test_a_project_or_client_of_another_workspace_is_refused(): void
    {
        $admin = $this->admin();
        $w = $this->existingWorkspace();
        $elsewhere = $this->existingWorkspace('Stranger Co');
        $request = $this->awaitingPayment('crossing@a.test');

        $this->decide($admin, $request, $this->joinInput($w, ['project_ids' => [(string) $elsewhere['allowed']->id]]))
            ->assertStatus(422)->assertJsonValidationErrors('access.project_ids');

        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_nobody_is_made_an_owner_or_an_administrator_through_this_path(): void
    {
        $admin = $this->admin();
        $w = $this->existingWorkspace();
        $request = $this->awaitingPayment('climber@a.test');

        $this->decide($admin, $request, $this->joinInput($w, ['role' => 'tenant-owner']))->assertStatus(422);
        $this->decide($admin, $request, $this->joinInput($w, ['role' => 'super-admin']))->assertStatus(422);
        $this->decide($admin, $request, $this->joinInput($w, ['portal' => 'admin']))->assertStatus(422);
        $this->decide($admin, $request, $this->waiver($request, ['access' => ['portal' => 'admin']]))->assertStatus(422);
        $this->decide($admin, $request, $this->waiver($request, ['access' => ['portal' => 'portal']]))->assertStatus(422);

        $this->decide($admin, $request, $this->waiver($request, ['is_platform_admin' => true]))->assertOk();
        $this->assertFalse((bool) User::where('email', 'climber@a.test')->firstOrFail()->is_platform_admin);
    }

    public function test_a_workspace_without_an_owner_or_in_bad_standing_cannot_be_joined(): void
    {
        $admin = $this->admin();
        $w = $this->existingWorkspace();
        $request = $this->awaitingPayment('join@a.test');

        Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $w['tenant']->id)->update(['status' => 'past_due']);
        $this->decide($admin, $request, $this->joinInput($w))->assertStatus(422);
        Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $w['tenant']->id)->update(['status' => 'active']);

        Membership::query()->withoutGlobalScopes()->where('tenant_id', $w['tenant']->id)->update(['status' => 'suspended']);
        $this->decide($admin, $request, $this->joinInput($w))->assertStatus(422);
    }

    public function test_a_new_workspace_keeps_its_owner_with_only_the_modules_chosen(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment('narrow@a.test', ['service' => 'combined']);

        $this->decide($admin, $request, $this->waiver($request, ['access' => ['portal' => 'app', 'modules' => ['paid_media']]]))->assertOk();

        $tenant = Tenant::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['paid_media'], $tenant->enabled_modules);

        $user = User::where('email', 'narrow@a.test')->firstOrFail();
        $membership = Membership::query()->withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('owner', $membership->role);
        $this->assertSame('app', $membership->portal->value);
    }

    public function test_a_module_nobody_knows_is_refused(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, $this->waiver($request, ['access' => ['modules' => ['everything']]]))->assertStatus(422);
        $this->decide($admin, $request, $this->waiver($request, ['access' => ['modules' => []]]))->assertStatus(422);
    }

    // ── Who may decide, and the other outcomes ────────────────────────────────────────────────

    public function test_only_a_platform_administrator_can_decide_preview_or_confirm(): void
    {
        $request = $this->awaitingPayment();
        $customer = User::create(['name' => 'Customer', 'email' => 'customer@a.test', 'password' => 'secret1234']);
        $customer->forceFill(['email_verified_at' => now()])->save();
        $base = "/api/v1/admin/registrations/{$request->getKey()}";
        $input = $this->waiver($request) + ['reason' => 'Trying my luck'];

        $this->postJson("{$base}/decision", $input)->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->postJson("{$base}/decision", $input)->assertForbidden();
        $this->actingAs($customer, 'sanctum')->postJson("{$base}/decision/preview", $input)->assertForbidden();

        // A settlement waiting on a second person cannot be confirmed by a customer either.
        $pending = new RegistrationDecision;
        $pending->forceFill([
            'registration_request_id' => $request->getKey(), 'outcome' => RegistrationDecision::EXTERNAL_SETTLEMENT,
            'status' => RegistrationDecision::AWAITING_FINANCE, 'reason' => 'Evidence entered',
        ])->save();
        $this->actingAs($customer, 'sanctum')->postJson("{$base}/decisions/{$pending->getKey()}/verify-settlement", ['reason' => 'Trying my luck'])->assertForbidden();
        $this->assertSame(RegistrationDecision::AWAITING_FINANCE, $pending->refresh()->status);
        $pending->delete();

        $this->assertSame(0, RegistrationDecision::count());
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
    }

    public function test_request_info_and_defer_move_nothing_and_are_on_the_record(): void
    {
        $admin = $this->admin();
        $request = $this->awaitingPayment();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DEFER, 'reason' => 'Waiting for the contract'])->assertOk()
            ->assertJsonPath('data.decision.status', RegistrationDecision::RECORDED)
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::REQUEST_INFO, 'reason' => 'Please send your CR'])->assertOk();

        $this->assertNotNull($request->refresh()->info_requested_at);
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertNotNull(AuditLog::query()->where('action', 'registration.decision.defer')->first());
    }

    // ── 6. The stranded application ───────────────────────────────────────────────────────────

    public function test_a_stranded_approved_application_is_repaired_by_a_waiver_without_signing_up_again(): void
    {
        $admin = $this->admin();
        // As Production's row stands: opened before the switches, approved, verified, unpaid.
        $request = $this->awaitingPayment('stranded@a.test', ['effective_policy' => null, 'reviewed_at' => now()->subHour(), 'reviewed_by' => $admin->id]);
        $id = (string) $request->getKey();

        $this->getJson("/api/v1/auth/registration/{$id}")->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->decide($admin, $request, $this->waiver($request))->assertOk();

        $this->getJson("/api/v1/auth/registration/{$id}")->assertOk()
            ->assertJsonPath('data.registration.state', 'active')
            ->assertJsonPath('data.registration.provisioned', true)
            ->assertJsonPath('data.registration.commercial.waived_by_admin', true);
        $this->assertSame(1, RegistrationRequest::count());

        $this->assertExactlyOneOfEach();

        $signIn = $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/login', ['email' => 'stranded@a.test', 'password' => 'secret1234'])->assertOk();
        $this->assertNotNull($signIn->json('data.user.account'), 'a person with a membership routes to their workspace');
    }
}
