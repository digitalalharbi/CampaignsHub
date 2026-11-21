<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ConnectionRemoval;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * INTEG-SOURCE-REMOVAL-001 — hide connections that were started and never finished.
 *
 * A connection is swept only when ALL of these hold: it is more than 24 hours old, nothing is selected on it,
 * it has never synced, AND no binding (even a disabled one), metric, sync run or entity mapping references any
 * of its accounts. Anything that ever held data is left for a person to remove deliberately.
 *
 * Reports by default. `--apply` changes anything only while `INTEGRATIONS_ABANDONED_SWEEP_ENABLED` is true, so
 * the daily schedule is inert until an operator has read this preview on Production.
 */
final class SweepAbandonedConnectionsCommand extends Command
{
    protected $signature = 'integrations:sweep-abandoned {--apply : Hide them (only while INTEGRATIONS_ABANDONED_SWEEP_ENABLED is true)}';

    protected $description = 'Hide authorisations that were started and never finished (soft hide, never a delete)';

    public function handle(ConnectionRemoval $removal): int
    {
        $candidates = ProviderConnection::withoutGlobalScopes()
            ->whereNull('removed_at')
            ->where('created_at', '<', now()->subHours(ConnectionRemoval::ABANDONED_AFTER_HOURS))
            ->get()
            ->filter(fn (ProviderConnection $c): bool => $removal->isAbandoned($c) && ! $removal->isReferenced($c));

        if ($candidates->isEmpty()) {
            $this->info('No abandoned connection.');

            return self::SUCCESS;
        }

        foreach ($candidates as $connection) {
            $this->line(sprintf('  %s  %-10s created %s', $connection->getKey(), $connection->provider, (string) $connection->created_at));
        }

        if (! $this->option('apply') || ! (bool) config('integrations.abandoned_sweep_enabled')) {
            app(ScheduledRunRows::class)->report(0);
            $this->info('Reporting only: pass --apply with INTEGRATIONS_ABANDONED_SWEEP_ENABLED=true to hide them.');

            return self::SUCCESS;
        }

        foreach ($candidates as $connection) {
            $removal->remove($connection, 'abandoned_sweep');
        }

        app(ScheduledRunRows::class)->report($candidates->count());
        $this->info("Hid {$candidates->count()} abandoned connection(s).");

        return self::SUCCESS;
    }
}
