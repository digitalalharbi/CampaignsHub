<?php

declare(strict_types=1);

namespace App\Domains\Ops\Services;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — how many rows the run actually touched.
 *
 * ## Why a service and not a return value
 *
 * `RecordScheduledRun` listens to the SCHEDULER, which is the property that makes it worth having:
 * a command added later is observed without anybody remembering that listener exists, and a command
 * that throws is still recorded. The scheduler hands it timing and an exit code and nothing else —
 * it cannot see inside a command, and an exit code is not a count.
 *
 * So the count comes the other way: a command that knows what it touched says so, and the listener
 * collects it at the end. Nothing is required of a command that has no meaningful count, which is
 * most of them.
 *
 * ## Silence is not zero
 *
 * `take()` returns NULL when nothing reported, and the column is nullable so it stays null. «Pruned
 * 0 sessions» and «this command does not count what it does» are different facts about a night's
 * run, and a surface that renders both as «0» tells an operator the sweep ran and found nothing when
 * it may not have swept at all. The same rule this product applies to a metric a platform never
 * sent.
 *
 * ## Reset, not accumulate across runs
 *
 * The scheduler runs commands in one process, one after another. Without a reset at `starting`, a
 * command that reports nothing would inherit the previous command's count and the ledger would
 * attribute one night's deletions to whatever ran next.
 */
final class ScheduledRunRows
{
    private ?int $rows = null;

    /** Called by a command that knows what it touched. Additive, so a command may report per batch. */
    public function report(int $rows): void
    {
        $this->rows = ($this->rows ?? 0) + max(0, $rows);
    }

    /** The count for the run that just ended, and null when nothing said anything. Clears as it reads. */
    public function take(): ?int
    {
        $rows = $this->rows;
        $this->rows = null;

        return $rows;
    }

    public function reset(): void
    {
        $this->rows = null;
    }
}
