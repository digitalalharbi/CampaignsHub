<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\AdvanceRegistration;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * What an applicant must clear before a workspace exists (SIGNUP-002).
 *
 * The contract permits auto-activation ONLY under an explicit policy, so the policy is the thing
 * under test — not the happy path. Each case turns one gate on and proves the application stops
 * there and grants nothing while it waits.
 */
final class RegistrationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function request(array $overrides = []): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill(array_merge([
            'email' => 'applicant@a.test',
            'name' => 'Applicant',
            'tenant_name' => 'Applicant Co',
            'account_type' => 'brand',
            'requested_portal' => 'app',
            'plan_code' => 'growth',
            'phone' => '+966500000000',
            'password' => Hash::make('secret1234'),
            'state' => AccountState::EmailVerificationRequired->value,
        ], $overrides))->save();

        return $request->refresh();
    }

    private function advance(): AdvanceRegistration
    {
        return app(AdvanceRegistration::class);
    }

    private function assertNothingGranted(): void
    {
        $this->assertSame(0, Tenant::count(), 'no workspace may exist yet');
        $this->assertSame(0, Membership::count(), 'no membership may exist yet');
        $this->assertSame(0, User::count(), 'no sign-in account may exist yet');
    }

    // ── Auto-activate: the branch the contract allows, named ──────────────────────────────────

    /**
     * With every gate off, verifying the email is enough — which is what the product does today.
     * This is the auto-activate branch made explicit rather than the absence of a gate.
     */
    public function test_with_no_gates_configured_email_verification_provisions_the_workspace(): void
    {
        config(['accounts.registration.default' => [
            'requires_mobile' => false, 'requires_approval' => false, 'requires_payment' => false,
        ]]);

        $request = $this->advance()->emailVerified($this->request());

        $this->assertSame(AccountState::Active, $request->state);
        $this->assertTrue($request->isProvisioned());
        $this->assertSame(1, Membership::count());
    }

    // ── Each gate stops the application where it should ───────────────────────────────────────

    public function test_mobile_verification_holds_the_application_and_grants_nothing(): void
    {
        config(['accounts.registration.default' => ['requires_mobile' => true]]);

        $request = $this->advance()->emailVerified($this->request());

        $this->assertSame(AccountState::MobileVerificationRequired, $request->state);
        $this->assertFalse($request->isProvisioned());
        $this->assertNothingGranted();

        // …and clearing it lets the application through.
        $request = $this->advance()->mobileVerified($request);
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertTrue($request->isProvisioned());
    }

    public function test_approval_holds_the_application_and_grants_nothing(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $request = $this->advance()->emailVerified($this->request());

        $this->assertSame(AccountState::PendingApproval, $request->state);
        $this->assertNothingGranted();

        $request = $this->advance()->approved($request, null, 'Documents checked out.');
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertTrue($request->isProvisioned());
    }

    /**
     * The payment gate, which is the one that must never be satisfiable by a browser.
     *
     * Only `paymentConfirmed()` clears it, and that is called from a signed webhook or a
     * server-to-server check — never from a redirect back from a payment page.
     */
    public function test_payment_holds_the_application_and_only_a_confirmed_payment_clears_it(): void
    {
        config(['accounts.registration.default' => ['requires_payment' => true]]);

        $request = $this->advance()->emailVerified($this->request());

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->state);
        $this->assertNothingGranted();

        // Verifying the email AGAIN must not sneak past the payment gate.
        $request = $this->advance()->emailVerified($request);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->state);
        $this->assertNothingGranted();

        $request = $this->advance()->paymentConfirmed($request);
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertTrue($request->isProvisioned());
    }

    /** Every gate at once: the full gated path, stopping at each step in order. */
    public function test_the_full_gated_path_stops_at_every_step(): void
    {
        config(['accounts.registration.default' => [
            'requires_mobile' => true, 'requires_approval' => true, 'requires_payment' => true,
        ]]);

        $a = $this->advance();
        $request = $this->request();

        $request = $a->emailVerified($request);
        $this->assertSame(AccountState::MobileVerificationRequired, $request->state);

        $request = $a->mobileVerified($request);
        $this->assertSame(AccountState::PendingApproval, $request->state);

        $request = $a->approved($request);
        $this->assertSame(AccountState::ApprovedAwaitingPayment, $request->state);
        $this->assertNothingGranted();

        $request = $a->paymentConfirmed($request);
        $this->assertSame(AccountState::Active, $request->state);
        $this->assertSame(1, Membership::count());
    }

    // ── Rejection ─────────────────────────────────────────────────────────────────────────────

    public function test_a_rejected_application_never_advances_again(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $a = $this->advance();
        $request = $a->emailVerified($this->request());
        $request = $a->rejected($request, 'The company could not be verified.');

        $this->assertSame(AccountState::Rejected, $request->state);
        $this->assertSame('The company could not be verified.', $request->state_reason);

        // Anything arriving afterwards — a late webhook, a retried verification — changes nothing.
        $request = $a->paymentConfirmed($request);
        $request = $a->emailVerified($request);

        $this->assertSame(AccountState::Rejected, $request->state);
        $this->assertNothingGranted();
    }

    // ── Policy resolution ─────────────────────────────────────────────────────────────────────

    /** A plan is the most specific statement and overrides the account type, which overrides the default. */
    public function test_the_plan_overrides_the_account_type_which_overrides_the_default(): void
    {
        config([
            'accounts.registration.default' => ['requires_approval' => false],
            'accounts.registration.account_types.brand' => ['requires_approval' => true],
            'accounts.registration.plans.growth' => ['requires_approval' => false],
        ]);

        // brand says "review me", growth says "no need" — the plan wins.
        $request = $this->advance()->emailVerified($this->request());
        $this->assertSame(AccountState::Active, $request->state);
    }

    public function test_the_account_type_applies_when_the_plan_says_nothing(): void
    {
        config([
            'accounts.registration.default' => ['requires_approval' => false],
            'accounts.registration.account_types.agency' => ['requires_approval' => true],
        ]);

        $request = $this->advance()->emailVerified(
            $this->request(['account_type' => 'agency', 'plan_code' => 'unmentioned'])
        );

        $this->assertSame(AccountState::PendingApproval, $request->state);
        $this->assertNothingGranted();
    }

    // ── The portal the applicant asked for ────────────────────────────────────────────────────

    /** The requested portal is honoured at provisioning — because by then it has been approved. */
    public function test_the_requested_portal_becomes_the_membership(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);

        $a = $this->advance();
        $request = $a->emailVerified($this->request([
            'requested_portal' => 'agency', 'account_type' => 'agency',
        ]));
        $request = $a->approved($request);

        $this->assertSame('agency', Membership::firstOrFail()->portal->value);
    }
}
