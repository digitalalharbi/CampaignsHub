<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Console;

use App\Domains\Metrics\Fx\FxMonitor;
use App\Domains\Metrics\Fx\MoneyGuard;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * FX-ENGINE-001 — the daily safety net: in-app alerts for currencies in use with no rate or a stale one, and the money
 * guard's cheap scan of the last days of stored figures for any that did not pass through convert(). Exits FAILURE when
 * the guard found something, so the scheduler surfaces it; the alerts sweep is never a failure on its own.
 */
final class FxMonitorCommand extends Command
{
    protected $signature = 'fx:monitor';

    protected $description = 'Alert on currencies in use without a valid rate, and log money figures that bypassed conversion.';

    public function handle(FxMonitor $monitor, MoneyGuard $guard): int
    {
        $alerts = $monitor->sweep();
        // The rows this run touched: alerts raised and cleared (the guard only reads).
        app(ScheduledRunRows::class)->report($alerts['raised'] + $alerts['cleared']);
        $this->info("FX alerts: raised {$alerts['raised']}, cleared {$alerts['cleared']}, open {$alerts['open']}.");

        $check = $guard->check();
        if ($check['ok']) {
            $this->info('Money guard: every stored converted figure since '.$check['checked_from'].' has its rate.');

            return self::SUCCESS;
        }

        $this->error('Money guard: stored without a rate '.$check['stored_without_rate'].', unit unknown but converted '
            .$check['unit_unknown_but_converted'].', serializer bypasses '.array_sum($check['bypasses']).'.');

        return self::FAILURE;
    }
}
