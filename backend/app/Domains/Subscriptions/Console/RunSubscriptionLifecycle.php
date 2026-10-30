<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Console;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Subscriptions\Services\SubscriptionCheckout;
use App\Domains\Subscriptions\Services\SubscriptionLifecycle;
use Illuminate\Console\Command;

/**
 * The account that runs itself (PAY-003).
 *
 * Trials convert, renewals are charged, unpaid periods go past due, and grace that has run out ends
 * in suspension — daily, without anybody looking. This command is the only scheduled entry point;
 * everything it does is a method on `SubscriptionLifecycle`, so the same transitions are reachable
 * from a test without a clock.
 *
 * Safe to run twice: every step is driven by dates and settled payments, and a charge that already
 * exists is returned rather than re-opened.
 */
final class RunSubscriptionLifecycle extends Command
{
    protected $signature = 'subscriptions:lifecycle {--dry-run : Report what would happen without opening any charge}';

    protected $description = 'Convert due trials, charge renewals, mark past due, and suspend after grace.';

    public function handle(SubscriptionLifecycle $lifecycle, SubscriptionCheckout $checkout): int
    {
        /*
         * SCHEDULE-REGISTERED-001 — this command was scheduled and never registered, so in Production it has never run. Its first run
         * would act at once on every trial, renewal and grace period whose date passed meanwhile — cancelling, marking past due and
         * suspending real workspaces — and even `--dry-run` moves those states. So in Production it acts only once the Owner sets
         * SUBSCRIPTION_LIFECYCLE_ENABLED=true; until then it counts what it would act on and changes nothing.
         */
        if (app()->environment('production') && ! (bool) config('subscriptions.lifecycle_enabled')) {
            $this->warn('Lifecycle is not enabled in production (SUBSCRIPTION_LIFECYCLE_ENABLED); nothing was changed. Would act on:');
            foreach ($lifecycle->duePreview() as $what => $count) {
                $this->line(str_pad($what, 24).$count);
            }
            app(ScheduledRunRows::class)->report(0);

            return self::SUCCESS;
        }

        // A dry run passes no checkout, so the sweep moves states but opens no charge at the gateway.
        $result = $lifecycle->runDueWork($this->option('dry-run') ? null : $checkout);

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Every state the sweep moved — trials converted, renewals charged, accounts marked past due or
         * suspended. A dry run moves states without charging and still touches those rows, which is
         * why the count is not gated on the flag.
         *
         * Already computed, already printed to a terminal nobody watches, while
         * `scheduled_runs.rows_affected` stayed null.
         */
        app(ScheduledRunRows::class)->report((int) array_sum($result));

        foreach ($result as $what => $count) {
            $this->line(str_pad($what, 24).$count);
        }

        return self::SUCCESS;
    }
}
