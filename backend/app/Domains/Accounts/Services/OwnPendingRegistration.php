<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Models\User;

/**
 * The signed-in person's OWN application that has not become a workspace yet (WORKSPACE-SWITCH-001).
 *
 * A session with no membership used to mean one thing to the router: «onboarding». Somebody whose application is
 * waiting on approval or on a payment has nothing to onboard into, and their next step is on the status screen.
 *
 * The match is the verified address on BOTH sides and nothing the request can supply. Either side unverified answers
 * null: an account that merely typed somebody's address must not be handed the key to that person's application. A
 * rejected, cancelled or expired application is not a next step, so it is not offered, and neither is one that has
 * already been provisioned. Finding nothing is the common answer and is not an error: most accounts with no membership
 * were invited, or removed, and have no application at all.
 */
final class OwnPendingRegistration
{
    /** @var list<AccountState> */
    private const WAITING = [
        AccountState::MobileVerificationRequired,
        AccountState::PendingApproval,
        AccountState::ApprovedAwaitingPayment,
        AccountState::PaymentPending,
    ];

    public function for(User $user): ?RegistrationRequest
    {
        if ($user->is_platform_admin || $user->email_verified_at === null) {
            return null;
        }

        return RegistrationRequest::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower((string) $user->email)])
            ->whereNull('tenant_id')
            ->whereNotNull('email_verified_at')
            ->whereIn('state', array_map(fn (AccountState $state) => $state->value, self::WAITING))
            ->latest('updated_at')
            ->first();
    }
}
