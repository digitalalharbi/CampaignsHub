<?php

declare(strict_types=1);

namespace App\Domains\Reports\Console;

use App\Domains\Audit\AuditLogger;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Support\ReportScheduleKey;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CONS-SCHEDULE-ONE — one report per schedule: the copies are switched off, never deleted.
 *
 * A group is every schedule with the same {@see ReportScheduleKey} (same project, same report, same moment, same
 * language, same recipients and formats). Of its ACTIVE rows the oldest stays; each other active row is paused with
 * `disabled_reason = duplicate_schedule`, `disabled_at` and `duplicate_of` (the one kept), and shows as «نسخة غير مجدولة».
 * Its settings, its past reports and its delivery ledger are untouched. Every row in the group gets its key, which arms
 * the partial unique index against a new copy.
 *
 * Idempotent: a second run finds at most one active row per group and changes nothing. `--dry-run` writes nothing and
 * prints counts only (no names, no tenants, no addresses), so its output can be pasted anywhere.
 *
 * Undo one copy: resume it from the schedules screen (refused while its twin is active, which is the point), or
 * `UPDATE report_schedules SET active = true, schedule_key = NULL, disabled_reason = NULL, disabled_at = NULL,
 * duplicate_of = NULL WHERE id = '…'` — every disable is also in the audit log with its before state.
 */
final class DedupeReportSchedulesCommand extends Command
{
    protected $signature = 'reports:dedupe-schedules {--dry-run : Count what would change and write nothing}';

    protected $description = 'Keep one active schedule per report; switch the duplicate copies off (flag + reason + time, nothing deleted).';

    public function handle(AuditLogger $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $now = Carbon::now();

        /** @var Collection<string, Collection<int, ReportSchedule>> $groups */
        $groups = ReportSchedule::withoutGlobalScopes()->orderBy('created_at')->orderBy('id')->get()
            ->groupBy(fn (ReportSchedule $s) => ReportScheduleKey::for($s));

        $duplicateGroups = 0;
        $toDisable = 0;
        $toKey = 0;
        $tenants = [];

        foreach ($groups as $key => $rows) {
            $active = $rows->where('active', true)->values();
            $keep = $active->first();
            $extra = $active->slice(1);
            $unkeyed = $rows->filter(fn (ReportSchedule $s) => $s->schedule_key !== $key);

            if ($extra->isNotEmpty()) {
                $duplicateGroups++;
                $toDisable += $extra->count();
                $tenants[(string) $keep->tenant_id] = true;
            }
            $toKey += $unkeyed->count();

            if ($dry || ($extra->isEmpty() && $unkeyed->isEmpty())) {
                continue;
            }

            DB::transaction(function () use ($extra, $rows, $keep, $key, $now, $audit): void {
                // Off first, keyed second: the index covers active keyed rows, so the copies must stop being active before
                // they carry the key their twin carries.
                foreach ($extra as $copy) {
                    $before = ['active' => true, 'next_run_at' => $copy->next_run_at?->toIso8601String(), 'schedule_key' => $copy->schedule_key];
                    $copy->forceFill([
                        'active' => false,
                        'next_run_at' => null,
                        'disabled_reason' => ReportSchedule::DUPLICATE_REASON,
                        'disabled_at' => $now,
                        'duplicate_of' => $keep->getKey(),
                    ])->save();
                    $audit->log('report_schedule.duplicate_disabled', 'report_schedule', (string) $copy->getKey(),
                        before: $before, after: ['active' => false, 'disabled_reason' => ReportSchedule::DUPLICATE_REASON, 'duplicate_of' => (string) $keep->getKey()],
                        reason: 'reports:dedupe-schedules', tenantId: (string) $copy->tenant_id);
                }
                foreach ($rows as $row) {
                    if ($row->schedule_key !== $key) {
                        $row->forceFill(['schedule_key' => $key])->save();
                    }
                }
            });
        }

        $this->line(($dry ? '[dry-run] ' : '').sprintf(
            'schedules: %d · groups: %d · groups with copies: %d · copies %s: %d · workspaces affected: %d · rows %s: %d',
            $groups->flatten()->count(), $groups->count(), $duplicateGroups,
            $dry ? 'to switch off' : 'switched off', $toDisable, count($tenants),
            $dry ? 'to key' : 'keyed', $toKey,
        ));
        $this->line('Nothing is deleted. A switched-off copy keeps its settings, reports and delivery history.');

        return self::SUCCESS;
    }
}
