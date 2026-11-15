<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use App\Domains\Notifications\Followup\Console\MorningBriefCommand;
use App\Domains\Notifications\Followup\ReroutedReportCheck;
use App\Domains\Notifications\Internal\Console\InternalScanCommand;
use App\Domains\Notifications\Internal\Observers\BudgetAndProjectObservers;
use Illuminate\Support\ServiceProvider;

/**
 * NOTIF-V2-INTERNAL-001 / NOTIF-V2-FOLLOWUP-001 — wires builder P's parts into the shared engine without touching it:
 * the model observers that name budget / limit / project changes, the two scheduled commands, and the rerouted-report
 * pre-send check (tag `notifications.presend`, the same extension point builder Q's checks use).
 */
final class InternalNotificationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->tag([ReroutedReportCheck::class], 'notifications.presend');
        $this->app->scoped(InternalEvents::class);
    }

    public function boot(): void
    {
        BudgetAndProjectObservers::register();

        if ($this->app->runningInConsole()) {
            $this->commands([InternalScanCommand::class, MorningBriefCommand::class]);
        }
    }
}
