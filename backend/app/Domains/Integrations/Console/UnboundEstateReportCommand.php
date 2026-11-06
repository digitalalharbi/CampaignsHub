<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — how much of the discovered estate nobody ever linked.
 *
 *     php artisan integrations:unbound-estate
 *
 * READ-ONLY. Authorising a platform catalogued every account the sign-in could reach; only the ones
 * somebody chose for a project are ever synced. This counts, per provider, the discovered accounts that
 *
 *   - have no binding of any kind, active or not (they were never linked to a project), and
 *   - have no stored figure and no sync run (nothing was ever read from them),
 *
 * which is the set a prune COULD remove without touching a number anybody has seen. An account that was
 * linked once and later removed is not in it: its history is the project's.
 *
 * There is deliberately no deleting path in this command. `--apply` is recognised only to refuse:
 * removing discovered rows from Production is the Owner's decision, taken on this report, and the
 * statement that does it is written when that decision exists — not shipped ahead of it behind a flag.
 */
final class UnboundEstateReportCommand extends Command
{
    protected $signature = 'integrations:unbound-estate {--apply : Refused — pruning needs the Owner\'s authorisation and is not implemented here}';

    protected $description = 'Report (read-only) how many discovered accounts were never linked to a project, per provider';

    public function handle(): int
    {
        if ($this->option('apply')) {
            $this->error('Refused: pruning discovered accounts needs the Owner\'s authorisation. This command only reports.');

            return self::FAILURE;
        }

        $rows = DB::table('external_accounts as a')
            ->selectRaw('a.provider')
            ->selectRaw('COUNT(*) AS discovered')
            ->selectRaw('COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM project_integration_bindings b WHERE b.external_account_id = a.id AND b.is_active)) AS bound')
            ->selectRaw('COUNT(*) FILTER (WHERE NOT EXISTS (SELECT 1 FROM project_integration_bindings b WHERE b.external_account_id = a.id)
                AND NOT EXISTS (SELECT 1 FROM daily_metrics m WHERE m.external_account_id = a.id)
                AND NOT EXISTS (SELECT 1 FROM metric_sync_runs r WHERE r.external_account_id = a.id)) AS prunable')
            ->groupBy('a.provider')
            ->orderBy('a.provider')
            ->get();

        $this->table(
            ['provider', 'discovered', 'actively bound', 'never linked, no data (prunable)'],
            $rows->map(static fn (object $r): array => [(string) $r->provider, (int) $r->discovered, (int) $r->bound, (int) $r->prunable])->all(),
        );

        $this->line(sprintf(
            'TOTAL discovered=%d bound=%d prunable=%d',
            (int) $rows->sum('discovered'), (int) $rows->sum('bound'), (int) $rows->sum('prunable'),
        ));
        $this->info('Nothing was changed. Pruning needs the Owner\'s authorisation.');

        return self::SUCCESS;
    }
}
