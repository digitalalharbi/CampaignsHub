<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Enums;

/**
 * Where an account is on the road from "someone filled in a form" to "a paying, working workspace"
 * (SIGNUP-001).
 *
 * The contract's rule, and the reason this enum exists at all: **filling in a registration form
 * opens nothing.** Before this, registering created a tenant, a workspace and a membership in one
 * transaction, so the only two states an account could be in were "does not exist" and "fully
 * operating". There was nowhere to put "waiting for approval", nothing for payment to move, and
 * nothing for the admin review queue to review.
 *
 * `ACTIVATING` below is the whole point. Everything outside it holds no membership and reaches no
 * portal — not because a menu hides it, but because the membership does not exist yet.
 */
enum AccountState: string
{
    /** The form is filled in and nothing has been granted. Every account starts here. */
    case Draft = 'draft';

    case EmailVerificationRequired = 'email_verification_required';

    case MobileVerificationRequired = 'mobile_verification_required';

    /** Waiting on a human at CampaignsHub, per the plan's approval policy. */
    case PendingApproval = 'pending_approval';

    /** Approved, and the money has not arrived. */
    case ApprovedAwaitingPayment = 'approved_awaiting_payment';

    /**
     * A payment has been STARTED and not yet confirmed by the provider.
     *
     * Distinct from `ApprovedAwaitingPayment` because returning from a payment page is not proof of
     * anything. This is where an account sits between "the customer pressed pay" and "a signed
     * webhook or a server-to-server check said it happened", and an account must never be activated
     * out of this state by the browser.
     */
    case PaymentPending = 'payment_pending';

    /** Operating. The ONLY state in which a membership and portal access exist. */
    case Active = 'active';

    /** Active, but a renewal failed. Still working, inside the grace period. */
    case PastDue = 'past_due';

    /** Switched off with the data intact — reversible by payment or by an administrator. */
    case Suspended = 'suspended';

    case Rejected = 'rejected';

    case Cancelled = 'cancelled';

    case Expired = 'expired';

    /**
     * The states in which the account may actually be used.
     *
     * `PastDue` is here deliberately: a failed renewal is a billing problem, and locking someone out
     * of their own data the moment a card expires loses the customer AND the payment. The grace
     * period is what `PastDue` means; `Suspended` is what comes after it.
     *
     * @return list<self>
     */
    public static function operational(): array
    {
        return [self::Active, self::PastDue];
    }

    public function isOperational(): bool
    {
        return in_array($this, self::operational(), true);
    }

    /**
     * States from which nothing more will happen without someone taking a new decision.
     *
     * @return list<self>
     */
    public static function terminal(): array
    {
        return [self::Rejected, self::Cancelled, self::Expired];
    }

    public function isTerminal(): bool
    {
        return in_array($this, self::terminal(), true);
    }

    /**
     * Where this state may legally go next.
     *
     * Written as data rather than as `if` statements scattered across controllers, because the
     * illegal transitions are the ones that matter and they have to be refused in one place:
     * `Draft → Active` skips verification, approval and payment in a single assignment, and it is
     * exactly what a well-meaning "just activate this account" helper does.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            // The gated path, in order. Each step may also be rejected outright.
            self::Draft => [self::EmailVerificationRequired, self::Rejected, self::Cancelled],
            self::EmailVerificationRequired => [
                self::MobileVerificationRequired, self::PendingApproval,
                self::ApprovedAwaitingPayment, self::Active, self::Rejected, self::Cancelled,
            ],
            self::MobileVerificationRequired => [
                self::PendingApproval, self::ApprovedAwaitingPayment, self::Active,
                self::Rejected, self::Cancelled,
            ],
            self::PendingApproval => [
                self::ApprovedAwaitingPayment, self::Active, self::Rejected, self::Cancelled,
            ],
            // Payment may be started, or an administrator may activate directly (a granted trial or
            // an exceptional period — a decision someone makes and the audit trail records).
            self::ApprovedAwaitingPayment => [
                self::PaymentPending, self::Active, self::Rejected, self::Cancelled, self::Expired,
            ],
            // Back to awaiting payment when a payment fails: it is a retry, not a rejection.
            self::PaymentPending => [
                self::Active, self::ApprovedAwaitingPayment, self::Cancelled, self::Expired,
            ],

            // Operating.
            self::Active => [self::PastDue, self::Suspended, self::Cancelled, self::Expired],
            self::PastDue => [self::Active, self::Suspended, self::Cancelled, self::Expired],
            // Reactivation after payment, or a direct administrative decision.
            self::Suspended => [self::Active, self::PaymentPending, self::Cancelled, self::Expired],

            // Terminal. A rejected or cancelled account starts again through registration; nothing
            // silently resurrects it.
            self::Rejected, self::Cancelled, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /** Customer-facing wording. Never exposes the internal token to a screen. */
    public function label(bool $ar): string
    {
        return match ($this) {
            self::Draft => $ar ? 'غير مكتمل' : 'Incomplete',
            self::EmailVerificationRequired => $ar ? 'بانتظار تأكيد البريد' : 'Awaiting email verification',
            self::MobileVerificationRequired => $ar ? 'بانتظار تأكيد الجوال' : 'Awaiting mobile verification',
            self::PendingApproval => $ar ? 'قيد المراجعة' : 'Under review',
            self::ApprovedAwaitingPayment => $ar ? 'معتمد — بانتظار الدفع' : 'Approved — awaiting payment',
            self::PaymentPending => $ar ? 'بانتظار تأكيد الدفع' : 'Awaiting payment confirmation',
            self::Active => $ar ? 'نشط' : 'Active',
            self::PastDue => $ar ? 'متأخر السداد' : 'Past due',
            self::Suspended => $ar ? 'موقوف' : 'Suspended',
            self::Rejected => $ar ? 'مرفوض' : 'Rejected',
            self::Cancelled => $ar ? 'ملغى' : 'Cancelled',
            self::Expired => $ar ? 'منتهٍ' : 'Expired',
        };
    }
}
