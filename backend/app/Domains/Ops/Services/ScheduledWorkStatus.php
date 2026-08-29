<?php

declare(strict_types=1);

namespace App\Domains\Ops\Services;

use App\Domains\Ops\Models\ScheduledRun;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — what the automation actually did, said honestly.
 *
 * ## The three states, and why they may never be collapsed
 *
 *   - **observed** — this command has run, and the last run says what happened.
 *   - **never_observed** — it is scheduled and this ledger holds nothing for it. That is NOT «it did
 *     not run»: the ledger may be younger than the deploy, and a write is deliberately allowed to fail
 *     without breaking the run it watches. It is «we cannot say», which is a different sentence and the
 *     only honest one.
 *   - **overdue** — it HAS run before, and its last run is older than the cadence it is scheduled at.
 *     This is the one that catches a scheduler that silently stopped, and it can only be said about a
 *     command with a history to compare against. Saying it about a never-observed command would be
 *     inventing an outage out of an absence of evidence.
 *
 * The list is built from the SCHEDULER, not from the ledger. Reading the ledger alone would answer
 * «which commands have run», and a command that has never run once — the exact failure worth
 * catching — would simply not appear.
 */
final class ScheduledWorkStatus
{
    /**
     * How far back a streak is counted.
     *
     * Bounded because the answer an operator needs is «is this broken now», and a command failing
     * fifty times is not a different decision from one failing ten. An unbounded scan would also grow
     * with the ledger for a number that stops being informative long before that.
     */
    private const STREAK_DEPTH = 20;

    public function __construct(private readonly Schedule $schedule) {}

    /**
     * Every scheduled command with the last thing known about it.
     *
     * @return list<array<string,mixed>>
     */
    public function all(?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $out = [];

        foreach ($this->schedule->events() as $event) {
            $command = $this->signature($event->command);

            if ($command === null) {
                continue;
            }

            $last = ScheduledRun::query()
                ->where('command', $command)
                ->whereIn('outcome', [ScheduledRun::COMPLETED, ScheduledRun::FAILED, ScheduledRun::SKIPPED])
                ->orderByDesc('started_at')
                ->first();

            $out[$command] = [
                'command' => $command,
                'expression' => $event->expression,
                'state' => $last === null ? 'never_observed' : 'observed',
                'last_outcome' => $last?->outcome,
                'last_started_at' => $last?->started_at?->toIso8601String(),
                'last_duration_ms' => $last?->duration_ms,
                'failure_class' => $last?->failure_class,
                'failure_message' => $last?->failure_message,
                /*
                 * Only ever said about a command with a history. `null` means «not a question we can
                 * answer», and the surface must render that as its own thing rather than as «fine».
                 */
                'overdue' => $last === null ? null : $this->overdue($event->expression, $last->started_at, $now),
                /*
                 * AUTOMATION-FIRST-OPERATIONS-001 — «failed once» and «failing every night» are
                 * different problems, and the console showed the same thing for both.
                 *
                 * One failure beside a scheduler that has otherwise been fine is a transient the next
                 * run may clear; the same failure four nights running is a broken command nobody has
                 * looked at. An operator triages those in opposite orders, and the last run alone
                 * cannot tell them apart.
                 *
                 * Counted from the most recent run backwards and stopping at the first success, so a
                 * command that failed last week and has run cleanly since reads as 0 rather than
                 * carrying its history forever.
                 */
                'consecutive_failures' => $this->consecutiveFailures($command),
            ];
        }

        ksort($out);

        return array_values($out);
    }

    /**
     * How many times in a row this command has failed, most recent first.
     *
     * Stops at the first `completed`. A `skipped` run neither breaks the streak nor extends it: the
     * overlap guard refusing a second copy says nothing about whether the command works, and reading
     * it either way would put a number on the screen that means something else.
     */
    private function consecutiveFailures(string $command): int
    {
        $recent = ScheduledRun::query()
            ->where('command', $command)
            ->whereIn('outcome', [ScheduledRun::COMPLETED, ScheduledRun::FAILED])
            ->orderByDesc('started_at')
            ->limit(self::STREAK_DEPTH)
            ->pluck('outcome');

        $streak = 0;
        foreach ($recent as $outcome) {
            if ($outcome !== ScheduledRun::FAILED) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /**
     * Is the last run older than this schedule could plausibly allow?
     *
     * Deliberately coarse. The cadence comes from the cron expression's own shape rather than from a
     * cron parser: the question is «has this obviously stopped», and a precise next-due calculation
     * would produce confident-looking alarms around DST, month boundaries and a scheduler that is
     * merely a few minutes late.
     */
    private function overdue(string $expression, ?CarbonInterface $lastStart, CarbonInterface $now): bool
    {
        if ($lastStart === null) {
            return false;
        }

        $minutes = $lastStart->diffInMinutes($now);

        return match (true) {
            str_starts_with($expression, '* '), str_contains($expression, '/5 ') => $minutes > 60,
            // Hourly-ish.
            preg_match('/^\d+ \* /', $expression) === 1 => $minutes > 60 * 6,
            // Anything rarer than hourly: a day and a half without a run is not a late scheduler.
            default => $minutes > 60 * 36,
        };
    }

    /** The artisan signature inside the scheduler's full shell invocation. */
    private function signature(?string $raw): ?string
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return preg_match("/'?artisan'?\s+'?([a-z0-9:_-]+)'?/i", $raw, $m) === 1 ? $m[1] : null;
    }
}
