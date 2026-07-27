<?php

declare(strict_types=1);

namespace App\Domains\Requests\Journey;

/**
 * The full professional lifecycle of an external request, modelled as an explicit state machine.
 *
 * This is an ADDITIVE journey layer that sits alongside the legacy status catalog (request_statuses +
 * RequestStatusMachine). It is intentionally richer: it spans intake (draft → submitted), qualification,
 * commercial (proposal → approval → payment) and delivery (onboarding → in_progress → completed) phases,
 * plus the off-ramps (rejected, cancelled, payment_failed, refunded, on_hold, archived).
 *
 * The transition map is the single source of truth for what may follow what — a crafted request or a
 * client can never jump the journey illogically. Persisted on external_requests.journey_stage.
 */
enum RequestStage: string
{
    case Draft = 'draft';
    case ContactVerification = 'contact_verification';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case WaitingForInformation = 'waiting_for_information';
    case Qualified = 'qualified';
    case ProposalSent = 'proposal_sent';
    case AwaitingClientApproval = 'awaiting_client_approval';
    case PaymentPending = 'payment_pending';
    case Paid = 'paid';
    case Onboarding = 'onboarding';
    case InProgress = 'in_progress';
    case ClientReview = 'client_review';
    case Completed = 'completed';
    case Archived = 'archived';

    // Off-ramps.
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case PaymentFailed = 'payment_failed';
    case Refunded = 'refunded';
    case OnHold = 'on_hold';

    /**
     * Directed transition map: stage => stages that may immediately follow it.
     *
     * @return array<string, list<string>>
     */
    public static function transitionMap(): array
    {
        return [
            self::Draft->value => [self::ContactVerification->value, self::Submitted->value, self::Cancelled->value],
            self::ContactVerification->value => [self::Submitted->value, self::Cancelled->value],
            self::Submitted->value => [self::UnderReview->value, self::Rejected->value, self::Cancelled->value, self::OnHold->value],
            self::UnderReview->value => [self::WaitingForInformation->value, self::Qualified->value, self::Rejected->value, self::Cancelled->value, self::OnHold->value],
            self::WaitingForInformation->value => [self::UnderReview->value, self::Qualified->value, self::Cancelled->value, self::OnHold->value],
            self::Qualified->value => [self::ProposalSent->value, self::Rejected->value, self::Cancelled->value, self::OnHold->value],
            self::ProposalSent->value => [self::AwaitingClientApproval->value, self::Rejected->value, self::Cancelled->value, self::OnHold->value],
            self::AwaitingClientApproval->value => [self::PaymentPending->value, self::Rejected->value, self::Cancelled->value, self::OnHold->value],
            self::PaymentPending->value => [self::Paid->value, self::PaymentFailed->value, self::Cancelled->value, self::OnHold->value],
            self::PaymentFailed->value => [self::PaymentPending->value, self::Cancelled->value, self::OnHold->value],
            self::Paid->value => [self::Onboarding->value, self::Refunded->value, self::OnHold->value],
            self::Onboarding->value => [self::InProgress->value, self::Cancelled->value, self::OnHold->value],
            self::InProgress->value => [self::ClientReview->value, self::WaitingForInformation->value, self::Completed->value, self::OnHold->value],
            self::ClientReview->value => [self::InProgress->value, self::Completed->value, self::OnHold->value],
            self::Completed->value => [self::Archived->value],
            self::Rejected->value => [self::Archived->value],
            self::Cancelled->value => [self::Archived->value],
            self::Refunded->value => [self::Archived->value, self::Cancelled->value],
            // On-hold may resume into any active working stage, or terminate.
            self::OnHold->value => [
                self::UnderReview->value, self::WaitingForInformation->value, self::Qualified->value,
                self::ProposalSent->value, self::AwaitingClientApproval->value, self::PaymentPending->value,
                self::Onboarding->value, self::InProgress->value, self::ClientReview->value,
                self::Cancelled->value, self::Archived->value,
            ],
            self::Archived->value => [], // terminal: only the dedicated restore path (outside this machine) reopens it
        ];
    }

    /**
     * Stages that may immediately follow this one.
     *
     * @return list<RequestStage>
     */
    public function allowedNext(): array
    {
        return array_map(
            static fn (string $value): RequestStage => RequestStage::from($value),
            self::transitionMap()[$this->value] ?? [],
        );
    }

    public function canTransitionTo(RequestStage $to): bool
    {
        return in_array($to->value, self::transitionMap()[$this->value] ?? [], true);
    }

    /** Static form: whether a move from one stage to another is permitted by the map. */
    public static function canTransition(RequestStage $from, RequestStage $to): bool
    {
        return $from->canTransitionTo($to);
    }

    /** The archived end-state — no further transitions except the external restore path. */
    public function isTerminal(): bool
    {
        return $this === self::Archived;
    }

    /** Key transitions that should raise a client/team notification with a deep action_url. */
    public function isNotifiable(): bool
    {
        return in_array($this, [
            self::ProposalSent, self::AwaitingClientApproval, self::PaymentPending,
            self::Paid, self::InProgress, self::ClientReview, self::Completed,
        ], true);
    }

    /**
     * Coupled payment_status for stages that move money, or null when the stage does not change it.
     * The Billing domain remains the system of record; this is the request-side reflection only.
     */
    public function paymentStatus(): ?string
    {
        return match ($this) {
            self::PaymentPending => 'pending',
            self::Paid => 'paid',
            self::PaymentFailed => 'failed',
            self::Refunded => 'refunded',
            default => null,
        };
    }

    /** Human-readable label (English). */
    public function label(): string
    {
        return ucwords(str_replace('_', ' ', $this->value));
    }

    /** @return list<string> All stage values. */
    public static function values(): array
    {
        return array_map(static fn (RequestStage $s): string => $s->value, self::cases());
    }
}
