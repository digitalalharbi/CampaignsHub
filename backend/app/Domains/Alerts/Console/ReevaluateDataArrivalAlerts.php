<?php

declare(strict_types=1);

namespace App\Domains\Alerts\Console;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Services\DataArrivalJudge;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * LIFECYCLE-6 (Owner spec 2026-11-15 §6) — re-judge every OPEN «بيانات أمس ما وصلت من المنصة» alert, in every
 * workspace, by the account-day rule ({@see DataArrivalJudge}), and close the ones that are false.
 *
 * Safety contract (production protection):
 * - DRY RUN unless `--apply`. A dry run reads only.
 * - It only CLOSES alerts (status `resolved` + `closed_reason` + `closed_by`), never deletes, never touches anything else.
 *   The previous status and snooze are kept in the event's context, and `--undo --apply` reopens exactly the events
 *   this command closed.
 * - Idempotent: a second run finds nothing more to close.
 * - It prints COUNTS only — no workspace, project, account or alert id or name.
 */
final class ReevaluateDataArrivalAlerts extends Command
{
    public const MARK = 'alerts:reevaluate-data-arrival';

    protected $signature = 'alerts:reevaluate-data-arrival
        {--apply : Write the closures (without it nothing is written)}
        {--dry-run : Read only (the default; accepted for clarity)}
        {--undo : Reopen the alerts this command closed}';

    protected $description = 'Re-judge open data-arrival alerts by the account-day rule and close the false ones (dry run by default).';

    public function handle(DataArrivalJudge $judge): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $now = Carbon::now();
        $this->line($apply ? 'APPLY — closures are written.' : 'DRY RUN — nothing is written. Pass --apply to write.');

        return $this->option('undo') ? $this->undo($apply, $now) : $this->reevaluate($judge, $apply, $now);
    }

    private function reevaluate(DataArrivalJudge $judge, bool $apply, Carbon $now): int
    {
        $examined = 0;
        $kept = 0;
        $reasons = [];
        $tenants = [];
        AlertEvent::withoutGlobalScopes()
            ->whereIn('type', DataArrivalJudge::TYPES)
            ->whereIn('status', ['open', 'snoozed'])
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($judge, $apply, $now, &$examined, &$kept, &$reasons, &$tenants): void {
                foreach ($events as $event) {
                    $examined++;
                    $why = $judge->closeReason($event, $now);
                    if ($why === null) {
                        $kept++;

                        continue;
                    }
                    $reasons[$why] = ($reasons[$why] ?? 0) + 1;
                    $tenants[(string) $event->tenant_id] = true;
                    if ($apply) {
                        $event->forceFill([
                            'status' => 'resolved',
                            'resolved_at' => $now,
                            'snoozed_until' => null,
                            'context' => (array) $event->context + [
                                'closed_reason' => $why,
                                'closed_at' => $now->toIso8601String(),
                                'closed_by' => self::MARK,
                                'reopen_status' => (string) $event->status,
                                'reopen_snoozed_until' => $event->snoozed_until?->toIso8601String(),
                            ],
                        ])->save();
                    }
                }
            });

        ksort($reasons);
        $closed = array_sum($reasons);
        $this->line('examined: '.$examined);
        $this->line(($apply ? 'closed: ' : 'would close: ').$closed);
        foreach ($reasons as $why => $n) {
            $this->line('  '.$why.': '.$n);
        }
        $this->line('kept open (true by the rule): '.$kept);
        $this->line('workspaces with closures: '.count($tenants));

        return self::SUCCESS;
    }

    private function undo(bool $apply, Carbon $now): int
    {
        $n = 0;
        AlertEvent::withoutGlobalScopes()
            ->whereIn('type', DataArrivalJudge::TYPES)
            ->where('status', 'resolved')
            ->where('context->closed_by', self::MARK)
            ->orderBy('id')
            ->chunkById(200, function ($events) use ($apply, $now, &$n): void {
                foreach ($events as $event) {
                    $n++;
                    if (! $apply) {
                        continue;
                    }
                    $ctx = (array) $event->context;
                    $status = in_array($ctx['reopen_status'] ?? null, ['open', 'snoozed'], true) ? (string) $ctx['reopen_status'] : 'open';
                    $until = isset($ctx['reopen_snoozed_until']) && is_string($ctx['reopen_snoozed_until']) ? Carbon::parse($ctx['reopen_snoozed_until']) : null;
                    unset($ctx['closed_reason'], $ctx['closed_at'], $ctx['closed_by'], $ctx['reopen_status'], $ctx['reopen_snoozed_until']);
                    $ctx['reopened_by'] = self::MARK.' --undo';
                    $ctx['reopened_at'] = $now->toIso8601String();
                    $event->forceFill(['status' => $status, 'resolved_at' => null, 'snoozed_until' => $until, 'context' => $ctx])->save();
                }
            });
        $this->line(($apply ? 'reopened: ' : 'would reopen: ').$n);

        return self::SUCCESS;
    }
}
