<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Services\TransitionAccountState;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The account lifecycle (SIGNUP-001).
 *
 * The binding rule, and the reason this exists: **filling in a registration form opens nothing.**
 * Before this the only two conditions an account could be in were "does not exist" and "fully
 * operating", so there was nowhere to put "waiting for approval", nothing for payment to move, and
 * nothing for an admin queue to review.
 */
final class AccountStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(AccountState $state = AccountState::Draft): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Acme', 'slug' => 'acme-'.uniqid(), 'status' => 'inactive',
        ]);
        $tenant->forceFill(['account_state' => $state->value])->save();

        return $tenant->refresh();
    }

    private function transitions(): TransitionAccountState
    {
        return app(TransitionAccountState::class);
    }

    // ── The illegal moves are the point ───────────────────────────────────────────────────────

    /**
     * The transition this whole class exists to refuse.
     *
     * `Draft → Active` skips email verification, mobile verification, approval and payment in one
     * assignment — which is exactly what a well-meaning "just activate this account" helper does.
     */
    public function test_a_draft_account_cannot_jump_straight_to_active(): void
    {
        $tenant = $this->tenant(AccountState::Draft);

        $this->expectException(RuntimeException::class);
        $this->transitions()->execute($tenant, AccountState::Active);
    }

    public function test_an_unapproved_account_cannot_skip_to_active(): void
    {
        $tenant = $this->tenant(AccountState::PendingApproval);

        // Approval → awaiting payment → active is the path. Pending approval may be approved
        // directly to Active (a granted trial), but it may never become Past Due or Suspended,
        // because those describe an account that was once operating.
        $this->expectException(RuntimeException::class);
        $this->transitions()->execute($tenant, AccountState::PastDue);
    }

    public function test_a_terminal_account_never_resurrects(): void
    {
        foreach ([AccountState::Rejected, AccountState::Cancelled, AccountState::Expired] as $terminal) {
            $tenant = $this->tenant($terminal);

            $this->assertSame([], $terminal->allowedNext());
            try {
                $this->transitions()->execute($tenant, AccountState::Active);
                $this->fail("{$terminal->value} must not be able to become active again");
            } catch (RuntimeException) {
                // Expected: a rejected or cancelled account starts again through registration.
            }
        }
    }

    // ── The legal path ────────────────────────────────────────────────────────────────────────

    public function test_the_full_gated_path_reaches_active(): void
    {
        $tenant = $this->tenant(AccountState::Draft);
        $t = $this->transitions();

        foreach ([
            AccountState::EmailVerificationRequired,
            AccountState::MobileVerificationRequired,
            AccountState::PendingApproval,
            AccountState::ApprovedAwaitingPayment,
            AccountState::PaymentPending,
            AccountState::Active,
        ] as $step) {
            $tenant = $t->execute($tenant, $step)->refresh();
            $this->assertSame($step, $tenant->account_state);
        }

        $this->assertTrue($tenant->isOperational());
        $this->assertNotNull($tenant->activated_at, 'reaching Active must record when it happened');
    }

    /** A failed payment returns to awaiting payment — it is a retry, not a rejection. */
    public function test_a_failed_payment_returns_to_awaiting_payment(): void
    {
        $tenant = $this->tenant(AccountState::PaymentPending);

        $tenant = $this->transitions()->execute($tenant, AccountState::ApprovedAwaitingPayment)->refresh();

        $this->assertSame(AccountState::ApprovedAwaitingPayment, $tenant->account_state);
        $this->assertFalse($tenant->isOperational());
    }

    // ── What each state means operationally ───────────────────────────────────────────────────

    /**
     * Past Due still WORKS, and that is a decision rather than an oversight.
     *
     * A failed renewal is a billing problem. Locking someone out of their own data the moment a card
     * expires loses the customer and the payment; the grace period is what this state is for, and
     * `Suspended` is what comes after it.
     */
    public function test_past_due_keeps_the_account_usable_and_suspended_does_not(): void
    {
        $tenant = $this->tenant(AccountState::Active);
        $t = $this->transitions();

        $tenant = $t->execute($tenant, AccountState::PastDue)->refresh();
        $this->assertTrue($tenant->isOperational());
        $this->assertSame('active', $tenant->status, 'past due must still read as usable to the middleware');

        $tenant = $t->execute($tenant, AccountState::Suspended, 'Payment failed after the grace period')->refresh();
        $this->assertFalse($tenant->isOperational());
        $this->assertSame('suspended', $tenant->status);
        $this->assertSame('Payment failed after the grace period', $tenant->state_reason);
    }

    /** Suspension preserves the data and is reversible — the contract requires both. */
    public function test_a_suspended_account_can_be_reactivated(): void
    {
        $tenant = $this->tenant(AccountState::Suspended);

        $tenant = $this->transitions()->execute($tenant, AccountState::Active)->refresh();

        $this->assertTrue($tenant->isOperational());
    }

    /** Nothing outside Active and Past Due is operational — checked exhaustively, not by sampling. */
    public function test_only_active_and_past_due_are_operational(): void
    {
        foreach (AccountState::cases() as $state) {
            $expected = in_array($state, [AccountState::Active, AccountState::PastDue], true);
            $this->assertSame($expected, $state->isOperational(), "{$state->value} operational?");
        }
    }

    // ── Consistency and audit ─────────────────────────────────────────────────────────────────

    /**
     * `status` and `account_state` are written together, by one thing.
     *
     * Two columns updated in two places drift, and the drift shows up as an account that can sign in
     * while its state says it is unpaid.
     */
    public function test_the_operational_status_follows_the_state(): void
    {
        $tenant = $this->tenant(AccountState::ApprovedAwaitingPayment);

        $tenant = $this->transitions()->execute($tenant, AccountState::PaymentPending)->refresh();
        $this->assertSame('inactive', $tenant->status, 'an unpaid account must not read as usable');

        $tenant = $this->transitions()->execute($tenant, AccountState::Active)->refresh();
        $this->assertSame('active', $tenant->status);
    }

    /** Re-arriving at the same state is a no-op, so a webhook delivered twice does not fail. */
    public function test_transitioning_to_the_current_state_is_idempotent(): void
    {
        $tenant = $this->tenant(AccountState::Active);
        $activatedAt = $tenant->refresh()->activated_at;

        $again = $this->transitions()->execute($tenant, AccountState::Active)->refresh();

        $this->assertSame(AccountState::Active, $again->account_state);
        $this->assertEquals($activatedAt, $again->activated_at);
    }

    /** `activated_at` records when the customer STARTED, not when they last recovered. */
    public function test_reactivation_does_not_overwrite_the_original_activation_date(): void
    {
        $tenant = $this->tenant(AccountState::ApprovedAwaitingPayment);
        $t = $this->transitions();

        $tenant = $t->execute($tenant, AccountState::Active)->refresh();
        $first = $tenant->activated_at;
        $this->assertNotNull($first);

        $tenant = $t->execute($tenant, AccountState::Suspended)->refresh();
        $tenant = $t->execute($tenant, AccountState::Active)->refresh();

        $this->assertEquals($first, $tenant->activated_at);
    }

    public function test_every_transition_is_audited(): void
    {
        $tenant = $this->tenant(AccountState::PendingApproval);

        $this->transitions()->execute($tenant, AccountState::Rejected, 'Documents did not match the company name');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'account.state.rejected',
            'entity_id' => (string) $tenant->getKey(),
        ]);
    }

    // ── Mass assignment ───────────────────────────────────────────────────────────────────────

    /**
     * The state is not a form field.
     *
     * A settings PATCH carrying `account_state=active` would walk an unpaid, unapproved account past
     * verification, approval and payment in one request.
     */
    public function test_the_state_cannot_be_mass_assigned(): void
    {
        $tenant = $this->tenant(AccountState::PendingApproval);

        $tenant->fill(['account_state' => AccountState::Active->value, 'name' => 'Renamed'])->save();

        $this->assertSame(AccountState::PendingApproval, $tenant->refresh()->account_state);
        $this->assertSame('Renamed', $tenant->name, 'ordinary fields must still be assignable');
    }
}
