<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

/**
 * Allowed status transitions for external requests. Enforced in the backend — a client or a crafted
 * request can never jump illogically (e.g. new → completed) without the explicit restore path.
 */
final class RequestStatusMachine
{
    /** @var array<string, list<string>> */
    private const FORWARD = [
        'new' => ['under_review', 'qualified', 'rejected', 'cancelled'],
        'under_review' => ['waiting_client', 'qualified', 'on_hold', 'rejected', 'cancelled'],
        'waiting_client' => ['under_review', 'qualified', 'cancelled'],
        /*
         * REQ-JOURNEY-001 — «عرض» and «تسليم» take their place in the journey.
         *
         * Both are INSERTIONS, not replacements. `qualified → approved` and `in_progress → completed`
         * are still here, because a small request often needs no quote and plenty of work is finished
         * without a separate hand-over. Removing the direct paths would have forced every request
         * through steps most of them do not have, which is the opposite of simpler.
         */
        'qualified' => ['quoted', 'approved', 'waiting_client', 'on_hold', 'rejected', 'cancelled'],
        'quoted' => ['approved', 'waiting_client', 'on_hold', 'rejected', 'cancelled'],
        'approved' => ['in_progress', 'on_hold', 'cancelled'],
        'in_progress' => ['delivered', 'waiting_client', 'completed', 'on_hold', 'cancelled'],
        'delivered' => ['completed', 'in_progress', 'waiting_client', 'cancelled'],
        /*
         * A hold returns to where the work actually was.
         *
         * Not to `new`: resuming a paused job by sending it back to the top of the inbox loses every
         * decision already made about it, and an operator would simply stop using the hold.
         */
        'on_hold' => ['under_review', 'qualified', 'quoted', 'approved', 'in_progress', 'cancelled'],
        'completed' => ['archived'],
        'rejected' => ['archived'],
        'cancelled' => ['archived'],
        'archived' => [], // only via the dedicated restore action (requests.update + not here)
    ];

    /** @return list<string> */
    public function allowedFrom(string $status): array
    {
        return self::FORWARD[$status] ?? [];
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->allowedFrom($from), true);
    }
}
