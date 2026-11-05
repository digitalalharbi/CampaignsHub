<?php

declare(strict_types=1);

namespace App\Domains\Accounts\Services;

use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationDecision;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Audit\AuditLogger;
use App\Domains\Subscriptions\Notifications\SubscriptionNotifier;
use App\Support\Frontend;
use Illuminate\Support\Facades\DB;

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
        private readonly RegistrationCommercialGate $gate,
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
    public function approved(RegistrationRequest $request, ?int $reviewerId = null, ?string $note = null, bool $notify = true): RegistrationRequest
    {
        $request->forceFill([
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => $note,
            'approved_at' => now(),
        ])->save();

        /*
         * The applicant hears about it (NOTIF-SUB-001).
         *
         * Before this, a decision was visible only if the applicant thought to reload their status
         * page — an approval that nobody is told about is indistinguishable from one that never
         * happened.
         */
        if ($notify) {
            $this->tell($request, 'registration_approved', ['reason' => $note ?? '']);
        }

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
     * Ask again what this application is waiting on, with no new fact.
     *
     * For an application opened with a gate already off, and for an explicit re-evaluation. It can grant nothing the
     * row does not already justify: `ProvisionWorkspace` still checks every gate.
     */
    public function reconcile(RegistrationRequest $request): RegistrationRequest
    {
        return $this->advance($request->refresh());
    }

    /**
     * Re-fix this application to the platform's verification policy as it stands now (SIGNUP-VERIFY-POLICY-001).
     *
     * The authorized way to move somebody already in the queue, one application at a time, with a reason. Facts already
     * proved stay proved, and approval and payment are not touched.
     */
    public function reevaluateVerification(RegistrationRequest $request, array $snapshot, string $reason, ?int $actorId): RegistrationRequest
    {
        $before = $request->effective_policy;

        $request->forceFill(['effective_policy' => $snapshot])->save();

        $this->audit->log(
            action: 'registration.verification_reevaluated',
            entityType: RegistrationRequest::class,
            entityId: (string) $request->getKey(),
            before: ['effective_policy' => $before, 'state' => $request->state->value],
            after: ['effective_policy' => $snapshot],
            reason: $reason,
            userId: $actorId,
        );

        $wasAtEmailGate = $request->state === AccountState::EmailVerificationRequired;
        $request = $this->reconcile($request);

        // Sent back to a gate nobody is holding a challenge for: send one, or the applicant waits for nothing. Issued
        // here because the normal route into this state is opening the application, which sends its own.
        if (! $wasAtEmailGate && $request->state === AccountState::EmailVerificationRequired) {
            try {
                app(RegistrationVerificationService::class)->send($request, 'email');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $request;
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

        if ($policy['requires_email'] && ! $request->emailIsVerified()) {
            return $this->moveTo($request, AccountState::EmailVerificationRequired);
        }

        if ($policy['requires_mobile'] && ! $request->mobileIsVerified()) {
            return $this->moveTo($request, AccountState::MobileVerificationRequired);
        }

        // Approval counts as outstanding until it has actually been granted — `reviewed_at` alone
        // is not enough, because a rejection also sets it.
        $approved = $approvalGranted || $request->approved_at !== null || $request->state === AccountState::ApprovedAwaitingPayment;
        if ($policy['requires_approval'] && ! $approved) {
            return $this->moveTo($request, AccountState::PendingApproval);
        }

        if ($policy['requires_payment'] && ! $paymentConfirmed && $this->gate->clearedBy($request) === null) {
            // A charge already opened stays in its own state: asking again says nothing about the money.
            return $request->state === AccountState::PaymentPending
                ? $request
                : $this->moveTo($request, AccountState::ApprovedAwaitingPayment);
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

        $request = $request->refresh();

        // A decision taken while a verification was still open activates here, so the applicant hears about it here.
        $decision = $this->gate->inForce($request);
        if ($decision !== null && in_array($decision->outcome, [
            RegistrationDecision::WAIVER, RegistrationDecision::TRIAL, RegistrationDecision::EXTERNAL_SETTLEMENT, RegistrationDecision::JOIN_EXISTING,
        ], true)) {
            $this->tellDecision($request, $decision);
        }

        return $request;
    }

    /**
     * Tell the applicant what was decided, once, and only after it has really been committed.
     *
     * The wording follows where the application actually is. «Approved» alone said «your workspace is ready» to somebody
     * still owing a payment, which is what an applicant was shown while the account said awaiting payment.
     */
    public function tellDecision(RegistrationRequest $request, RegistrationDecision $decision): void
    {
        $terms = (array) $decision->terms;
        $money = ['amount' => (string) ($terms['amount_due'] ?? ''), 'currency' => (string) ($terms['currency'] ?? '')];
        $provisioned = $request->isProvisioned();

        [$event, $context] = match (true) {
            ! $provisioned && $request->state !== AccountState::ApprovedAwaitingPayment && $request->state !== AccountState::PaymentPending => ['registration_approved_steps_remain', []],
            $decision->outcome === RegistrationDecision::WAIVER => ['registration_approved_waived', [
                'amount' => (string) ($terms['renewal_amount'] ?? ''), 'currency' => $money['currency'], 'date' => (string) ($terms['renewal_on'] ?? ''),
            ]],
            $decision->outcome === RegistrationDecision::TRIAL => ['registration_approved_trial', [
                'amount' => (string) ($terms['renewal_amount'] ?? ''), 'currency' => $money['currency'], 'date' => (string) ($terms['renewal_on'] ?? ''),
            ]],
            $decision->outcome === RegistrationDecision::DISCOUNT => ['registration_approved_discount', $money + ['days' => (int) ($terms['discount_percent'] ?? 0)]],
            $provisioned => ['registration_approved', []],
            default => ['registration_approved', $money],
        };

        DB::afterCommit(fn () => $this->tell($request, $event, $context, $decision->getKey().':'.$event));
    }

    /**
     * Tell the applicant, without letting a message break the decision it accompanies.
     *
     * A notifier that threw here would roll back an approval, and an application stuck in a queue
     * because a template was wrong is a worse failure than a message nobody received.
     */
    private function tell(RegistrationRequest $request, string $event, array $context, ?string $occasion = null): void
    {
        try {
            $this->notify->notifyApplicant($request, $event, $context + [
                'url' => Frontend::origin()
                    .'/signup/status?request='.$request->getKey(),
            ], occasion: $occasion);
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

        $request = $request->refresh();

        /*
         * Arriving at the mobile gate SENDS the code (PHONE-VERIFY-001).
         *
         * Without this the applicant landed on a screen that said «أدخل الرمز المرسل إلى جوالك» with
         * no code sent and no obvious way to ask for one — the challenge was only issued if they
         * happened to press "resend", which is an odd thing to press for a message you never had.
         *
         * Issued here rather than by the controller because this is the moment the gate becomes the
         * one they are waiting on, and there is more than one route to it: email verification,
         * an administrator's approval, and a payment that cleared before the phone did.
         */
        if ($state === AccountState::MobileVerificationRequired && $request->phone !== null) {
            $this->issueMobileChallenge($request);
        }

        return $request;
    }

    /**
     * Send the mobile code, without letting a delivery failure undo the state change.
     *
     * The same reasoning as `tell()` above: an application stuck because an SMS provider was
     * unreachable is a worse failure than a message nobody received — and the applicant can always
     * ask for another code from their status page.
     */
    private function issueMobileChallenge(RegistrationRequest $request): void
    {
        try {
            /*
             * Resolved from the container at the moment of use, not injected.
             *
             * `RegistrationVerificationService` takes an `AdvanceRegistration` of its own — it has to,
             * because answering a challenge is what advances an application — so constructor injection
             * here is a cycle the container resolves by building each of them forever. A lazy lookup
             * breaks it without either class pretending it does not depend on the other.
             */
            app(RegistrationVerificationService::class)->send($request, 'mobile');
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
