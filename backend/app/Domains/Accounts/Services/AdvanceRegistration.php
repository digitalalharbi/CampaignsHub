<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;

/**
 * Moves an application to the next thing it is actually waiting on (SIGNUP-002).
 *
 * One place decides "what now?", because the answer depends on the policy and on what has already
 * been satisfied, and working it out at each call site is how an account ends up skipping a step
 * nobody noticed it was owed. Every caller — email verification, mobile verification, an
 * administrator's approval, a payment webhook — records a FACT and then asks this what follows.
 *
 * It never activates anything itself. Reaching Active means `ProvisionWorkspace` ran, and that
 * refuses unless the conditions hold, so this class cannot grant access by getting its own logic
 * wrong.
 */
final class AdvanceRegistration
{
    public function __construct(
        private readonly RegistrationPolicy $policy,
        private readonly ProvisionWorkspace $provision,
        private readonly AuditLogger $audit,
        private readonly SubscriptionNotifier $notify,
    ) {}

    /** The applicant proved their email address. */
    public function emailVerified(RegistrationRequest $request): RegistrationRequest
    {
        if (! $request->emailIsVerified()) {
            $request->forceFill(['email_verified_at' => now()])->save();
        }

        return $this->advance($request->refresh());
    }

    /** The applicant proved their mobile number. */
    public function mobileVerified(RegistrationRequest $request): RegistrationRequest
    {
        if (! $request->mobileIsVerified()) {
            $request->forceFill(['mobile_verified_at' => now()])->save();
        }

        return $this->advance($request->refresh());
    }

    /**
     * An administrator approved the application.
     *
     * Approval does not activate — it removes the approval gate. Whether a workspace appears next
     * depends on whether payment is also required, which is exactly the distinction the two states
     * `PendingApproval` and `ApprovedAwaitingPayment` exist to keep apart.
     */
    public function approved(RegistrationRequest $request, ?int $reviewerId = null, ?string $note = null): RegistrationRequest
    {
        $request->forceFill([
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        /*
         * The applicant hears about it (NOTIF-SUB-001).
         *
         * Before this, a decision was visible only if the applicant thought to reload their status
         * page — an approval that nobody is told about is indistinguishable from one that never
         * happened.
         */
        $this->tell($request, 'registration_approved', ['reason' => $note ?? '']);

        $this->audit->log(
            action: 'registration.approved',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            after: ['note' => $note, 'reviewer' => $reviewerId],
        );

        return $this->advance($request->refresh(), approvalGranted: true);
    }

    /** An administrator refused it, with a reason the applicant will be shown. */
    public function rejected(RegistrationRequest $request, string $reason, ?int $reviewerId = null): RegistrationRequest
    {
        $request->forceFill([
            'state' => AccountState::Rejected->value,
            'state_reason' => $reason,
            'state_changed_at' => now(),
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
        ])->save();

        // A refusal always carries its reason: "rejected" with no explanation is a support ticket
        // nobody can answer either.
        $this->tell($request, 'registration_rejected', ['reason' => $reason]);

        $this->audit->log(
            action: 'registration.rejected',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            after: ['reason' => $reason, 'reviewer' => $reviewerId],
        );

        return $request->refresh();
    }

    /**
     * A payment was CONFIRMED — by a signed webhook or a server-to-server check, never by a browser
     * returning from a payment page.
     *
     * This is the only caller that may satisfy the payment gate, and it is why `PaymentPending`
     * exists as its own state: the gap between "the customer pressed pay" and this call is exactly
     * where an account must not be activated.
     */
    public function paymentConfirmed(RegistrationRequest $request): RegistrationRequest
    {
        $this->audit->log(
            action: 'registration.payment_confirmed',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
        );

        return $this->advance($request->refresh(), paymentConfirmed: true);
    }

    /**
     * Work out what this application is now waiting on, and move it there.
     *
     * The two flags are facts the CALLER has just established and that are not yet readable from the
     * row — approval has no column of its own beyond `reviewed_at`, and a confirmed payment lives in
     * the payment ledger rather than here. Passing them explicitly keeps this from guessing.
     */
    private function advance(
        RegistrationRequest $request,
        bool $approvalGranted = false,
        bool $paymentConfirmed = false,
    ): RegistrationRequest {
        // Already a workspace, or already finished with. Nothing to advance.
        if ($request->isProvisioned() || $request->state->isTerminal()) {
            return $request;
        }

        $policy = $this->policy->for($request);

        if (! $request->emailIsVerified()) {
            return $this->moveTo($request, AccountState::EmailVerificationRequired);
        }

        if ($policy['requires_mobile'] && ! $request->mobileIsVerified()) {
            return $this->moveTo($request, AccountState::MobileVerificationRequired);
        }

        // Approval counts as outstanding until it has actually been granted — `reviewed_at` alone
        // is not enough, because a rejection also sets it.
        $approved = $approvalGranted || $request->state === AccountState::ApprovedAwaitingPayment;
        if ($policy['requires_approval'] && ! $approved) {
            return $this->moveTo($request, AccountState::PendingApproval);
        }

        if ($policy['requires_payment'] && ! $paymentConfirmed) {
            return $this->moveTo($request, AccountState::ApprovedAwaitingPayment);
        }

        /*
         * Everything the policy asked for has been satisfied.
         *
         * The request must pass through ApprovedAwaitingPayment even when no payment is required,
         * because that is the state `ProvisionWorkspace` accepts — one gate, checked in one place,
         * rather than a second "is this allowed?" opinion here that could disagree with it.
         */
        $request = $this->moveTo($request, AccountState::ApprovedAwaitingPayment);
        $this->provision->execute($request);

        return $request->refresh();
    }

    /**
     * Tell the applicant, without letting a message break the decision it accompanies.
     *
     * A notifier that threw here would roll back an approval, and an application stuck in a queue
     * because a template was wrong is a worse failure than a message nobody received.
     */
    private function tell(RegistrationRequest $request, string $event, array $context): void
    {
        try {
            $this->notify->notifyApplicant($request, $event, $context + [
                'url' => rtrim((string) config('app.frontend_url', config('app.url')), '/')
                    .'/signup/status?request='.$request->getKey(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function moveTo(RegistrationRequest $request, AccountState $state): RegistrationRequest
    {
        if ($request->state === $state) {
            return $request;
        }

        $request->forceFill([
            'state' => $state->value,
            'state_changed_at' => now(),
        ])->save();

        return $request->refresh();
    }
}
