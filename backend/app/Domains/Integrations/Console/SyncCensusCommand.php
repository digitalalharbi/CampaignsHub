<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Services\AccountAssignment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * SYNC-CENSUS-001 — who actually syncs, set against who was explicitly selected. Read-only.
 *
 * For each provider: the ad accounts discovered, the ones actively assigned to a project that may sync
 * (the SAME rule the sweeps use — `AccountAssignment::scopeToAssigned`), and, over the window, the
 * accounts that had a metrics sync run and how many of those were NOT assigned. A non-zero «synced but
 * not assigned» is the defect this product must never have. Prints counts and short ids only.
 */
final class SyncCensusCommand extends Command
{
    protected $signature = 'integrations:sync-census {--hours=24 : Window of sync runs to read}';

    protected $description = 'Read-only: accounts discovered vs assigned vs actually synced, per provider, with any synced-but-unassigned account.';

    public function handle(AccountAssignment $assignment): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);

        $assigned = $assignment->scopeToAssigned(ExternalAccount::withoutGlobalScopes()->where('account_type', 'ad_account'))
            ->pluck('external_accounts.id')->map(static fn ($id): string => (string) $id)->flip();

        $discovered = ExternalAccount::withoutGlobalScopes()->where('account_type', 'ad_account')
            ->selectRaw('provider, count(*) n')->groupBy('provider')->pluck('n', 'provider');

        $runs = DB::table('metric_sync_runs')->where('created_at', '>=', $since)
            ->whereNotNull('external_account_id')
            ->selectRaw('provider, external_account_id, count(*) runs, sum(case when status = ? then 0 else 1 end) not_ok', ['success'])
            ->groupBy('provider', 'external_account_id')->get();

        $this->line(str_repeat('=', 78));
        $this->line("  SYNC CENSUS — read-only; last {$hours}h of metrics sync runs");
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  %-14s %10s %10s %10s %10s %12s', 'provider', 'discovered', 'assigned', 'synced', 'runs', 'UNASSIGNED'));

        $offenders = [];
        $providers = collect($discovered->keys())->merge($runs->pluck('provider'))->unique()->sort()->values();

        foreach ($providers as $provider) {
            $rows = $runs->where('provider', $provider);
            $synced = $rows->count();
            $totalRuns = (int) $rows->sum('runs');
            $bad = $rows->filter(fn ($r): bool => ! $assigned->has((string) $r->external_account_id));
            $assignedN = ExternalAccount::withoutGlobalScopes()->where('provider', $provider)->where('account_type', 'ad_account')
                ->whereIn('id', $assigned->keys()->all())->count();

            $this->line(sprintf('  %-14s %10d %10d %10d %10d %12d', $provider, (int) ($discovered[$provider] ?? 0), $assignedN, $synced, $totalRuns, $bad->count()));

            foreach ($bad as $r) {
                $offenders[] = sprintf('%s account %s: %d run(s), %d not ok', $provider, substr((string) $r->external_account_id, 0, 8), $r->runs, $r->not_ok);
            }
        }

        $this->line('');
        if ($offenders === []) {
            $this->line('  Every account that synced in the window is actively assigned to a project that may sync.');
        } else {
            $this->line('  SYNCED BUT NOT ASSIGNED (each needs an explanation):');
            foreach ($offenders as $o) {
                $this->line('   · '.$o);
            }
        }

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();
        $this->line(sprintf('  failed queue jobs in the window: %d', $failed));

        return self::SUCCESS;
    }
}
