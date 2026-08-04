<?php

declare(strict_types=1);

namespace App\Domains\Requests\Journey;

/**
 * REQ-UNIFY-001 — the ONE place that says which journey stage means which status.
 *
 * ## The problem
 *
 * A request carried two independent models of the same journey. `journey_stage` is the detailed
 * internal machine (20 stages: contact verification, proposal sent, payment pending, onboarding,
 * client review…). `status_id` is the short list an operator and a client actually read (13 statuses).
 *
 * `RequestJourneyService::transition()` advanced the stage and never touched the status. Nothing
 * advanced the stage when the status changed. So a request could sit on stage «paid» with status
 * «under review» — two truthful-looking answers to «where is this?», disagreeing, with no way for
 * anybody reading either one to know the other existed.
 *
 * ## The fix, and why it is a mapping rather than a merge
 *
 * Deleting one column would be the tidy answer and the wrong one. The stages carry distinctions the
 * status list deliberately does not — «awaiting client approval» and «payment pending» are both
 * «عرض سعر مُرسل» to the person reading the board, and collapsing them would lose the detail the
 * payment flow depends on. Meanwhile a thirteen-item status list is what fits on a board and in a
 * client's head.
 *
 * So both stay, and this class makes them **incapable of disagreeing**: the stage is the master, the
 * status is derived from it, and the derivation lives in one function that both sides call. Several
 * stages map to one status on purpose — that is the compression that makes the short list short.
 *
 * ## Direction
 *
 * Stage → status, never the reverse. A status change cannot uniquely determine a stage (which of
 * «proposal sent» and «awaiting approval» does «عرض سعر مُرسل» mean?), and guessing would be inventing
 * a fact about the request. `RequestStatusMachine` therefore still governs direct status moves; what
 * this guarantees is that a JOURNEY move never leaves the status behind.
 */
final class StageStatusMap
{
    /**
     * Stage value => `request_statuses.key`.
     *
     * @var array<string, string>
     */
    private const MAP = [
        // Before it is really a request yet — all of it reads as «new».
        'draft' => 'new',
        'contact_verification' => 'new',
        'submitted' => 'new',

        'under_review' => 'under_review',
        'waiting_for_information' => 'waiting_client',
        'qualified' => 'qualified',

        // The quote and everything that hangs off it. Three internal stages, one thing the reader
        // needs to know: a price has gone out and we are waiting on an answer.
        'proposal_sent' => 'quoted',
        'awaiting_client_approval' => 'quoted',
        'payment_pending' => 'quoted',
        'payment_failed' => 'quoted',

        // Approved and being set up — «معتمد» until the work itself starts.
        'paid' => 'approved',
        'onboarding' => 'approved',

        'in_progress' => 'in_progress',
        'client_review' => 'delivered',

        'on_hold' => 'on_hold',
        'completed' => 'completed',
        'rejected' => 'rejected',
        'cancelled' => 'cancelled',
        'refunded' => 'cancelled',
        'archived' => 'archived',
    ];

    /**
     * The status key a stage means, or null if the stage is unknown.
     *
     * Null rather than a default: a stage this map has never heard of is a stage somebody added
     * without deciding what it means to the reader, and silently calling it «new» would put live work
     * back at the top of the inbox. `RequestJourneyService` treats null as «leave the status alone»,
     * which keeps the request where it was until somebody makes the decision.
     */
    public static function statusFor(RequestStage $stage): ?string
    {
        return self::MAP[$stage->value] ?? null;
    }

    /**
     * Every stage that maps to a status, for the test that proves no stage was left undecided.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
