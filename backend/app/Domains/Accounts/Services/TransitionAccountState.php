<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Audit\AuditLogger;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The only thing that moves an account between states (SIGNUP-001).
 *
 * One writer, for three reasons that each cost something when violated:
 *
 *  1. **Illegal moves are refused.** `Draft → Active` skips email verification, mobile verification,
 *     approval and payment in a single assignment — and that is exactly what a well-meaning "just
 *     activate this account" helper does. The transition table lives on the enum and is enforced
 *     here, so there is no path that quietly bypasses it.
 *  2. **`status` and `account_state` stay consistent.** `status` is the operational switch the
 *     suspension middleware reads; `account_state` is the lifecycle position. Two columns updated in
 *     two places drift, and the drift shows up as an account that can sign in while its state says
 *     it is unpaid.
 *  3. **Every change is audited.** The contract requires an audit trail for every subscription,
 *     payment, approval and permission change. Writing the column directly anywhere else produces a
 *     state change nobody can account for afterwards.
 */
final class TransitionAccountState
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Move an account to `$next`, or refuse.
     *
     * `$reason` is customer-facing where the state is one the owner sees — a rejection, a
     * suspension — so it is written in words they can act on rather than an internal code.
     */
    public function execute(Tenant $tenant, AccountState $next, ?string $reason = null, ?string $actor = null): Tenant
    {
        $current = $this->stateOf($tenant);

        if ($current === $next) {
            // Idempotent on purpose: a webhook that arrives twice must not fail the second time, and
            // "already active" is the outcome the caller wanted either way.
            return $tenant;
        }

        if (! $current->canTransitionTo($next)) {
            throw new RuntimeException(
                "An account cannot move from {$current->value} to {$next->value}."
            );
        }

        return DB::transaction(function () use ($tenant, $current, $next, $reason, $actor): Tenant {
            $tenant->forceFill([
                'account_state' => $next->value,
                // Kept in step here and nowhere else. `status` answers "may this be used right now?",
                // which is a narrower question than the state and must not be inferred at read time.
                'status' => $this->operationalStatusFor($next),
                'state_reason' => $reason,
                'state_changed_at' => now(),
                // Stamped once, the first time the account becomes usable. Re-stamping on every
                // reactivation would lose the date the customer actually started.
                'activated_at' => $next->isOperational() && $tenant->activated_at === null
                    ? now()
                    : $tenant->activated_at,
            ])->save();

            $this->audit->log(
                action: 'account.state.'.$next->value,
                entityType: Tenant::class,
                entityId: (string) $tenant->getKey(),
                before: ['account_state' => $current->value],
                after: ['account_state' => $next->value, 'reason' => $reason, 'actor' => $actor],
            );

            return $tenant;
        });
    }

    /**
     * Establish an account's INITIAL state at creation (SIGNUP-001).
     *
     * Creation is not a transition — there is no "from" — so it does not go through the table above,
     * and this is the only other thing that may write the column. It exists for exactly two callers:
     *
     *   - the **auto-activate** policy, which the contract permits when a plan is explicitly
     *     configured for it and payment has been verified;
     *   - **demo seeding**, where walking a fixture through six states to reach the one it is meant
     *     to demonstrate would be theatre.
     *
     * `$why` is required and audited, because "this account skipped the gated path" is precisely the
     * thing someone will need to account for later. A caller that cannot say why should be using
     * `execute()` instead.
     */
    public function provision(Tenant $tenant, AccountState $state, string $why): Tenant
    {
        $tenant->forceFill([
            'account_state' => $state->value,
            'status' => $this->operationalStatusFor($state),
            'state_changed_at' => now(),
            'activated_at' => $state->isOperational() ? ($tenant->activated_at ?? now()) : null,
        ])->save();

        $this->audit->log(
            action: 'account.state.provisioned',
            entityType: Tenant::class,
            entityId: (string) $tenant->getKey(),
            after: ['account_state' => $state->value, 'reason' => $why],
        );

        return $tenant;
    }

    /**
     * The state of an account, defaulting to Draft for a row written before this existed.
     *
     * The model casts this column to the enum, so the common case is already an `AccountState`.
     * The string branch covers a raw value — a row read without the cast, or a fresh instance built
     * before the attribute is hydrated — rather than assuming one shape and crashing on the other.
     */
    public function stateOf(Tenant $tenant): AccountState
    {
        $raw = $tenant->account_state;

        if ($raw instanceof AccountState) {
            return $raw;
        }

        return AccountState::tryFrom((string) $raw) ?? AccountState::Draft;
    }

    /**
     * What `status` must say for a given state.
     *
     * Deliberately coarse — it has three answers, because it is the operational switch and not a
     * second copy of the lifecycle. Everything that is not operating reads as `inactive` to the
     * middleware, and the STATE says which kind of not-operating it is.
     */
    private function operationalStatusFor(AccountState $state): string
    {
        return match (true) {
            $state === AccountState::Active => 'active',
            // Past due still works — the grace period is the point of the state.
            $state === AccountState::PastDue => 'active',
            $state === AccountState::Suspended => 'suspended',
            default => 'inactive',
        };
    }
}
