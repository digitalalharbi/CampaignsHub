<?php

declare(strict_types=1);

namespace App\Domains\Ops\Listeners;

use App\Domains\Ops\Models\ScheduledRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Support\Facades\Log;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — one subscriber, every scheduled command.
 *
 * Listening to the scheduler rather than editing thirteen commands is the whole point:
 *
 *   - a command added later is observed without anybody remembering this file exists;
 *   - a command that THROWS is still recorded, which it would not be if the write lived at the end of
 *     its own handler — and a command that throws every night is exactly the one worth seeing.
 *
 * ## This listener may never break the run it is watching
 *
 * Observability that can take down the thing it observes is worse than none. Every write here is
 * wrapped: if the ledger is unwritable — a migration not yet run on a box, a full disk — the scheduled
 * work still happens and the failure is logged rather than thrown. The cost is that a missing row is
 * ambiguous, which is why the surface says «never observed» rather than «did not run».
 */
final class RecordScheduledRun
{
    public function starting(ScheduledTaskStarting $event): void
    {
        $command = $this->command($event->task);

        if ($command === null) {
            return;
        }

        $this->guarded(function () use ($command): void {
            ScheduledRun::create([
                'command' => $command,
                'started_at' => now(),
                // Overwritten the moment the task ends; a row left in this state IS the signal that a
                // run began and never reported back — a crash of the process itself, not of the task.
                'outcome' => 'running',
            ]);
        });
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        $this->close($event->task, ScheduledRun::COMPLETED, (int) round($event->runtime * 1000));
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        $this->close(
            $event->task,
            ScheduledRun::FAILED,
            null,
            $event->exception::class,
            mb_substr($event->exception->getMessage(), 0, 2000),
        );
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $this->close($event->task, ScheduledRun::SKIPPED, 0);
    }

    private function close(
        ScheduledEvent $task,
        string $outcome,
        ?int $durationMs = null,
        ?string $failureClass = null,
        ?string $failureMessage = null,
    ): void {
        $command = $this->command($task);

        if ($command === null) {
            return;
        }

        $this->guarded(function () use ($command, $outcome, $durationMs, $failureClass, $failureMessage): void {
            $run = ScheduledRun::query()
                ->where('command', $command)
                ->where('outcome', 'running')
                ->orderByDesc('started_at')
                ->first();

            /*
             * No open row — the process was restarted between starting and finishing, or this listener
             * was registered mid-flight. Record the outcome anyway rather than dropping it: an
             * un-paired failure is still the most important fact this table can hold.
             */
            $run ??= new ScheduledRun(['command' => $command, 'started_at' => now()]);

            $run->fill([
                'finished_at' => now(),
                'duration_ms' => $durationMs,
                'outcome' => $outcome,
                'failure_class' => $failureClass,
                'failure_message' => $failureMessage,
            ])->save();
        });
    }

    /**
     * The artisan signature, or null for a `Schedule::call()` closure.
     *
     * A closure has no command string to key on and is covered by its own tests — the same line
     * `ScheduledWorkInventoryTest` already draws, so the two agree about what «a scheduled command» is.
     */
    private function command(ScheduledEvent $task): ?string
    {
        $raw = $task->command;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        /*
         * The scheduler's command string is the full shell invocation — the PHP binary, artisan's path
         * and the signature, with arguments. Only the signature identifies the work.
         */
        if (preg_match("/'?artisan'?\s+'?([a-z0-9:_-]+)'?/i", $raw, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** @param  callable():void  $write */
    private function guarded(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            // Never let the observer break the observed.
            Log::warning('scheduled-run ledger write failed', ['error' => $e->getMessage()]);
        }
    }
}
