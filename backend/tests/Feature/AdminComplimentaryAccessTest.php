<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\RegistrationCommercialGate;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\Models\SubscriptionInvoice;
use App\Domains\Subscriptions\Models\SubscriptionNotification;
use App\Domains\Subscriptions\Models\SubscriptionPayment;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\ApplySubscriptionPaymentEvent;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Scopes\TenantScope;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * ADMIN-FULL-CONTROL-001, part one — «دخول مباشر دون دفع», and an approve click that says what it did.
 *
 * Open-ended complimentary access is a decision of its own: confirmed, with a reason, audited, and recorded as complimentary
 * with no payment, invoice or provider transaction. It depends on no gateway, and while it stands nothing ever asks for money.
 */
final class AdminComplimentaryAccessTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(SubscriptionPlanSeeder::class);
        $this->assertingAcrossTenants();
        // No provider may be reached by anything here, configured or not.
        Http::fake();
    }

    private function admin(string $email = 'owner@platform.test'): User
    {
        $user = User::create(['name' => 'Owner', 'email' => $email, 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** The row exactly as the Owner's stands on Production: approved before the decision form existed, so no decision row at all. */
    private function stranded(string $email = 'smartcode@a.test', array $overrides = []): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill(array_merge([
            'email' => $email, 'name' => 'Owner', 'tenant_name' => 'SMARTCODE', 'account_type' => 'agency',
            'requested_portal' => 'agency', 'plan_code' => 'solo', 'billing_interval' => 'annual', 'phone' => self::aFreshSaudiNumber(),
            'password' => Hash::make('secret1234'),
            'state' => AccountState::ApprovedAwaitingPayment->value,
            'email_verified_at' => now()->subDay(), 'mobile_verified_at' => now()->subDay(),
            'effective_policy' => null, 'reviewed_at' => now()->subHour(), 'approved_at' => now()->subHour(),
        ], $overrides))->save();

        return $request->refresh();
    }

    private function comp(array $over = []): array
    {
        return $over + [
            'outcome' => RegistrationDecision::COMPLIMENTARY,
            'confirm_complimentary' => true,
            'reason' => 'The owner\'s own workspace',
        ];
    }

    private function decide(User $actor, RegistrationRequest $request, array $input): TestResponse
    {
        return $this->actingAs($actor, 'sanctum')->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision", $input);
    }

    private function subscriptionOf(RegistrationRequest $request): Subscription
    {
        return Subscription::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $request->refresh()->tenant_id)->firstOrFail();
    }

    // ── The decision ──────────────────────────────────────────────────────────────────────────

    public function test_a_stranded_application_enters_directly_with_no_payment_invoice_gateway_or_end_date(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        $this->decide($admin, $request, $this->comp())->assertOk()
            ->assertJsonPath('data.registration.state', 'active')
            ->assertJsonPath('data.registration.provisioned', true)
            ->assertJsonPath('data.registration.commercial.outcome', 'approve_complimentary')
            ->assertJsonPath('data.registration.commercial.scope', 'open_ended')
            ->assertJsonPath('data.registration.commercial.amount_due', '0.00');

        $subscription = $this->subscriptionOf($request);
        $this->assertSame('active', $subscription->status);
        $this->assertSame(RegistrationDecision::BASIS_COMPLIMENTARY, $subscription->commercial_basis);
        $this->assertNull($subscription->current_period_end, 'no end date');
        $this->assertNull($subscription->trial_ends_at);
        $this->assertNull($subscription->provider, 'no provider transaction');

        $this->assertSame(0, SubscriptionPayment::count(), 'recorded as complimentary, never as paid');
        $this->assertSame(0, SubscriptionInvoice::query()->withoutGlobalScopes()->count());
        Http::assertNothingSent();

        $entry = AuditLog::query()->where('action', 'registration.decision.made')->where('entity_id', (string) $request->getKey())->latest('created_at')->firstOrFail();
        $this->assertSame('The owner\'s own workspace', $entry->reason);
        $this->assertSame((int) $admin->getKey(), (int) $entry->user_id);
        $this->assertSame('approve_complimentary', $entry->after['outcome']);

        $this->assertSame(1, AuditLog::query()->where('action', 'subscription.started_by_decision')->count());

        // And it is a real way in, with the password chosen at application time.
        $signIn = $this->withHeaders($this->spaOrigin)->postJson('/api/v1/auth/login', ['email' => 'smartcode@a.test', 'password' => 'secret1234'])->assertOk();
        $this->assertNotNull($signIn->json('data.user.account'));
    }

    public function test_it_does_not_ask_whether_a_gateway_is_configured(): void
    {
        config(['subscriptions.default_provider' => 'nothing-configured', 'subscriptions.providers' => []]);
        $request = $this->stranded();

        $this->decide($this->admin(), $request, $this->comp())->assertOk()->assertJsonPath('data.registration.state', 'active');
    }

    public function test_it_needs_a_reason_and_an_explicit_yes(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        $this->decide($admin, $request, array_diff_key($this->comp(), ['reason' => 1]))->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->decide($admin, $request, array_diff_key($this->comp(), ['confirm_complimentary' => 1]))->assertStatus(422)->assertJsonValidationErrors('confirm_complimentary');
        $this->decide($admin, $request, $this->comp(['confirm_complimentary' => false]))->assertStatus(422);

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_the_preview_writes_nothing_and_says_it_has_no_end(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision/preview", ['outcome' => RegistrationDecision::COMPLIMENTARY, 'reason' => 'Preview only'])
            ->assertOk()
            ->assertJsonPath('data.terms.complimentary', true)
            ->assertJsonPath('data.terms.scope', 'open_ended')
            ->assertJsonPath('data.terms.amount_due', '0.00')
            ->assertJsonMissingPath('data.terms.renewal_on');

        $this->assertSame(0, RegistrationDecision::count());
        $this->assertSame(0, Tenant::query()->withoutGlobalScopes()->count());
    }

    public function test_only_a_platform_admin_may_grant_it(): void
    {
        $request = $this->stranded();

        // A real workspace owner, signed in, with every permission their own workspace can give them.
        $tenant = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active', 'account_type' => 'agency']);
        $owner = User::create(['name' => 'Tenant owner', 'email' => 'tenant-owner@a.test', 'password' => 'secret1234']);
        app(TenantContext::class)->setTenantId((string) $tenant->id);
        $this->grantMembership($owner, $tenant);
        $owner->roles()->attach(Role::create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'slug' => 'tenant-owner'])->id);
        app(TenantContext::class)->forget();

        $this->decide($owner, $request, $this->comp())->assertForbidden();
        app('auth')->forgetGuards();
        $this->postJson("/api/v1/admin/registrations/{$request->getKey()}/decision", $this->comp())->assertUnauthorized();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->refresh()->state);
        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_the_gate_clears_only_for_this_purchase_and_only_on_an_administrators_word(): void
    {
        $gate = app(RegistrationCommercialGate::class);
        $request = $this->stranded();

        $make = function (int $actorId, array $terms = []) use ($request): RegistrationDecision {
            RegistrationDecision::query()->delete();
            $decision = new RegistrationDecision;
            $decision->forceFill([
                'registration_request_id' => $request->getKey(), 'outcome' => RegistrationDecision::COMPLIMENTARY,
                'status' => RegistrationDecision::IN_FORCE, 'actor_id' => $actorId, 'reason' => 'Hand-written row',
                'terms' => $terms + ['plan_code' => 'solo', 'interval' => 'annual', 'complimentary' => true, 'scope' => 'open_ended'],
            ])->save();

            return $decision;
        };

        $admin = $this->admin();
        $make((int) $admin->getKey());
        $this->assertSame(RegistrationDecision::BASIS_COMPLIMENTARY, $gate->clearedBy($request));

        $stranger = User::create(['name' => 'Stranger', 'email' => 'stranger@a.test', 'password' => 'secret1234']);
        $make((int) $stranger->getKey());
        $this->assertNull($gate->clearedBy($request), 'a row an administrator did not take clears nothing');

        $make((int) $admin->getKey(), ['plan_code' => 'other-plan']);
        $this->assertNull($gate->clearedBy($request), 'scoped to the plan it names');

        $make((int) $admin->getKey(), ['scope' => 'one_period']);
        $this->assertNull($gate->clearedBy($request), 'it says open-ended or it clears nothing');

        // And the provisioner, which believes nobody, refuses on the same answer.
        $this->expectException(RuntimeException::class);
        app(ProvisionWorkspace::class)->execute($request);
    }

    public function test_a_settled_payment_leaves_nothing_to_concede(): void
    {
        $request = $this->stranded();
        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'registration_request_id' => $request->getKey(), 'purpose' => 'subscription', 'plan_code' => 'solo', 'billing_interval' => 'annual',
            'provider' => 'sandbox', 'amount' => '100.00', 'currency' => 'SAR', 'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'test:settled',
        ])->save();

        $this->decide($this->admin(), $request, $this->comp())->assertStatus(422);
    }

    public function test_the_applicant_is_told_once_in_plain_words(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        $this->decide($admin, $request, $this->comp())->assertOk();

        $message = SubscriptionNotification::query()->where('event', 'registration_approved_complimentary')->firstOrFail();
        $this->assertStringContainsString('دون دفع', (string) $message->subject);
        $this->assertSame(1, SubscriptionNotification::query()->where('event', 'registration_approved_complimentary')->count());
    }

    // ── While it stands, nothing asks for money ───────────────────────────────────────────────

    public function test_years_of_sweeps_never_ask_for_money_or_lapse_it(): void
    {
        $request = $this->stranded();
        $this->decide($this->admin(), $request, $this->comp())->assertOk();
        $tenantId = (string) $request->refresh()->tenant_id;

        $lifecycle = app(SubscriptionLifecycle::class);
        $before = AuditLog::query()->where('action', 'like', 'subscription.%')->count();

        foreach ([1, 13, 37, 61] as $months) {
            Carbon::setTestNow(now()->addMonths($months));
            $lifecycle->runDueWork(app(SubscriptionCheckout::class));
        }

        $subscription = $this->subscriptionOf($request);
        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->grace_ends_at);
        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame('active', Tenant::query()->withoutGlobalScopes()->whereKey($tenantId)->value('status'));
        $this->assertSame($before, AuditLog::query()->where('action', 'like', 'subscription.%')->count());
        Http::assertNothingSent();
    }

    public function test_a_stale_period_end_on_the_row_still_never_asks_for_money(): void
    {
        $request = $this->stranded();
        $this->decide($this->admin(), $request, $this->comp())->assertOk();

        // The defence is the basis, not the null: a date left on the row must not be able to start a charge.
        $subscription = $this->subscriptionOf($request);
        $subscription->forceFill(['current_period_end' => now()->subMonth()])->save();

        $lifecycle = app(SubscriptionLifecycle::class);
        $this->assertSame(0, $lifecycle->duePreview()['renewals_due']);
        $this->assertSame(0, $lifecycle->chargeDueRenewals(app(SubscriptionCheckout::class)));
        $this->assertSame(0, $lifecycle->markPastDue());

        $this->assertSame('active', $subscription->refresh()->status);
        $this->assertSame(0, SubscriptionPayment::count());
    }

    public function test_the_charge_itself_refuses(): void
    {
        $request = $this->stranded();
        $this->decide($this->admin(), $request, $this->comp())->assertOk();

        $this->expectException(RuntimeException::class);
        app(SubscriptionCheckout::class)->chargeSubscription($this->subscriptionOf($request));
    }

    public function test_it_is_not_counted_as_money_the_platform_is_owed(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();
        $this->decide($admin, $request, $this->comp())->assertOk();

        $revenue = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/revenue')->assertOk();
        $this->assertSame([], $revenue->json('data.committed_monthly'));

        $streams = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/revenue-streams')->assertOk();
        $this->assertStringNotContainsString('"subscriptions":1', (string) json_encode($streams->json('data')));

        $overview = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/overview')->assertOk();
        $this->assertStringNotContainsString('"subscriptions":1', (string) json_encode($overview->json('data')));
    }

    public function test_a_late_webhook_for_an_old_checkout_does_not_turn_it_into_a_billed_subscription(): void
    {
        $request = $this->stranded();
        $this->decide($this->admin(), $request, $this->comp())->assertOk();
        $subscriptionBefore = $this->subscriptionOf($request);

        $payment = new SubscriptionPayment;
        $payment->forceFill([
            'registration_request_id' => $request->getKey(), 'purpose' => 'subscription', 'plan_code' => 'solo', 'billing_interval' => 'annual',
            'provider' => 'sandbox', 'amount' => '100.00', 'currency' => 'SAR', 'status' => 'paid', 'paid_at' => now(), 'idempotency_key' => 'test:late',
        ])->save();

        // Exactly what `ApplySubscriptionPaymentEvent` does once the gateway has confirmed the money.
        $service = app(ApplySubscriptionPaymentEvent::class);
        $settle = new \ReflectionMethod($service, 'settle');
        $settle->invoke($service, $payment, null, null);

        $subscription = $subscriptionBefore->refresh();
        $this->assertSame(RegistrationDecision::BASIS_COMPLIMENTARY, $subscription->commercial_basis);
        $this->assertNull($subscription->current_period_end);
        $this->assertSame('refund_due', $payment->refresh()->status, 'the money is owed back, and the row says so');
    }

    public function test_the_customer_is_not_offered_a_paid_plan_change_while_it_stands(): void
    {
        $request = $this->stranded();
        $this->decide($this->admin(), $request, $this->comp())->assertOk();

        $owner = User::query()->where('email', 'smartcode@a.test')->firstOrFail();
        $other = SubscriptionPlan::query()->where('code', '!=', 'solo')->where('is_active', true)->where('contact_sales', false)->firstOrFail();
        $tenantId = (string) $request->refresh()->tenant_id;

        $this->actingAs($owner, 'sanctum')->withHeader('X-Tenant-Id', $tenantId)
            ->postJson('/api/v1/subscriptions/plan-change', ['plan_code' => $other->code, 'billing_interval' => 'monthly'])
            ->assertStatus(422);

        $this->assertSame(0, SubscriptionPayment::count());
        $this->assertSame('solo', $this->subscriptionOf($request)->plan?->code);
    }

    // ── The approve click that does nothing ───────────────────────────────────────────────────

    public function test_approving_an_already_approved_application_says_so_and_writes_nothing(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();
        $url = "/api/v1/admin/registrations/{$request->getKey()}/approve";

        foreach ([1, 2, 3] as $click) {
            $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk()
                ->assertJsonPath('data.no_change', true)
                ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');
        }

        $this->assertSame(0, AuditLog::query()->where('action', 'registration.approved')->count(), 'no line repeating what is already true');
        $this->assertSame(0, AuditLog::query()->where('action', 'registration.decision.made')->count());
        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_the_same_click_through_the_decision_endpoint_also_says_so(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'reason' => 'Approved again', 'access' => ['mode' => 'new_workspace']])->assertOk()
            ->assertJsonPath('data.no_change', true)
            ->assertJsonPath('data.decision', null);

        $this->assertSame(0, RegistrationDecision::count());
    }

    public function test_a_first_approval_is_still_recorded_and_the_second_click_is_the_no_op(): void
    {
        $admin = $this->admin();
        $request = $this->stranded('fresh@a.test', ['state' => AccountState::PendingApproval->value, 'reviewed_at' => null, 'approved_at' => null]);
        $url = "/api/v1/admin/registrations/{$request->getKey()}/approve";

        $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk()
            ->assertJsonPath('data.no_change', false)
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');
        $this->actingAs($admin, 'sanctum')->postJson($url)->assertOk()->assertJsonPath('data.no_change', true);

        $this->assertSame(1, AuditLog::query()->where('action', 'registration.approved')->count());
        $this->assertSame(1, RegistrationDecision::count());
    }

    public function test_a_real_change_of_terms_on_an_approved_row_is_not_swallowed_as_a_no_op(): void
    {
        $admin = $this->admin();
        $request = $this->stranded();

        // Narrowing the modules changes what the decision grants, even though the money does not change.
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'reason' => 'Paid media only', 'access' => ['mode' => 'new_workspace', 'modules' => ['paid_media']]])
            ->assertOk()->assertJsonPath('data.no_change', false);
        $this->assertSame(1, RegistrationDecision::count());

        // Going back from a discount to the full price is a decision as well.
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::DISCOUNT, 'discount_percent' => 25, 'reason' => 'Partner discount'])->assertOk();
        $this->decide($admin, $request, ['outcome' => RegistrationDecision::PAYMENT_REQUIRED, 'reason' => 'Discount withdrawn'])
            ->assertOk()->assertJsonPath('data.no_change', false);
        $this->assertSame(3, RegistrationDecision::count());
    }

    public function test_a_concession_on_an_approved_row_does_not_repeat_the_approved_line(): void
    {
        $request = $this->stranded();

        $this->decide($this->admin(), $request, $this->comp())->assertOk();

        $this->assertSame(0, AuditLog::query()->where('action', 'registration.approved')->count(), 'it was approved already; what changed is on registration.decision.made');
    }
}
