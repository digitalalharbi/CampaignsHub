<?php

declare(strict_types=1);

namespace App\Domains\Requests\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Requests\Services\RequestNotifier;
use Illuminate\Console\Command;

/**
 * Scheduled SLA evaluator. For every RUNNING request (not paused, not archived, not in a terminal status)
 * it fires a single warning as the deadline approaches and a single breach when it passes — each guarded by
 * a persisted marker (sla_warned_at / sla_breached_at) so repeated runs never duplicate notifications.
 */
final class EvaluateSla extends Command
{
    protected $signature = 'requests:evaluate-sla';

    protected $description = 'Warn on approaching SLA and detect breaches (idempotent)';

    public function handle(RequestNotifier $notifier): int
    {
        $warnAt = now()->addHours((int) config('requests.sla_warning_hours', 4));
        $warned = 0;
        $breached = 0;

        $due = ExternalRequest::query()
            ->whereNull('archived_at')
            ->whereNull('sla_paused_at')
            ->whereNotNull('sla_due_at')
            ->whereHas('status', fn ($q) => $q->where('is_terminal', false))
            ->cursor();

        foreach ($due as $req) {
            // Breach: due date passed and not yet flagged.
            if ($req->sla_breached_at === null && $req->sla_due_at->isPast()) {
                $req->forceFill(['sla_breached_at' => now()])->save();
                $notifier->notify($req, 'request.sla_breached', "SLA breached: {$req->reference}");
                $breached++;

                continue; // a breached request needs no warning
            }
            // Warning: within the threshold, running, not breached, not already warned.
            if ($req->sla_warned_at === null && $req->sla_breached_at === null && $req->sla_due_at->lte($warnAt)) {
                $req->forceFill(['sla_warned_at' => now()])->save();
                $notifier->notify($req, 'request.sla_warning', "SLA approaching: {$req->reference}");
                $warned++;
            }
        }

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Both halves are rows this run CHANGED: a warning stamps `sla_warned_at` and a breach stamps
         * `sla_breached_at`. The requests it merely read are not rows it touched.
         *
         * The number was already computed and already printed to a terminal nobody is watching at
         * 04:00, while `scheduled_runs.rows_affected` stayed null — so the ops page could say the run
         * SUCCEEDED without being able to say whether it did anything.
         */
        app(ScheduledRunRows::class)->report($warned + $breached);

        $this->info("SLA evaluated — {$warned} warning(s), {$breached} breach(es).");

        return self::SUCCESS;
    }
}
