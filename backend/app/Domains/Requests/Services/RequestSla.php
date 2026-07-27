<?php

declare(strict_types=1);

namespace App\Domains\Requests\Services;

use App\Domains\Requests\Models\ExternalRequest;

/**
 * Backend SLA lifecycle. The counter is the source of truth here — the frontend only displays it.
 * When a request enters "waiting_client" (and the status policy pauses SLA) the clock stops; on the
 * client's reply it resumes and the accumulated paused time extends the due date.
 */
final class RequestSla
{
    /** Pause the SLA clock (idempotent). */
    public function pause(ExternalRequest $request): void
    {
        if ($request->sla_paused_at !== null) {
            return;
        }
        $request->forceFill(['sla_paused_at' => now()])->save();
    }

    /** Resume the SLA clock, pushing the due date out by however long it was paused. */
    public function resume(ExternalRequest $request): void
    {
        if ($request->sla_paused_at === null) {
            return;
        }
        $pausedSeconds = (int) $request->sla_paused_at->diffInSeconds(now());
        $request->forceFill([
            'sla_paused_seconds' => $request->sla_paused_seconds + $pausedSeconds,
            'sla_resumed_at' => now(),
            'sla_paused_at' => null,
            'sla_due_at' => optional($request->sla_due_at)->addSeconds($pausedSeconds),
        ])->save();
    }

    /** Mark a breach if the due date has passed while running (not paused, not terminal). */
    public function evaluate(ExternalRequest $request): bool
    {
        if ($request->sla_breached_at !== null || $request->sla_paused_at !== null || $request->sla_due_at === null) {
            return false;
        }
        if ($request->sla_due_at->isPast()) {
            $request->forceFill(['sla_breached_at' => now()])->save();

            return true;
        }

        return false;
    }

    /** Remaining seconds (negative when overdue); null when paused or unset. */
    public function remainingSeconds(ExternalRequest $request): ?int
    {
        if ($request->sla_due_at === null || $request->sla_paused_at !== null) {
            return null;
        }

        return (int) now()->diffInSeconds($request->sla_due_at, false);
    }
}
