<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingNotifier;
use App\Domains\Funding\Services\FundingSpendRefresher;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * FIN-LOOP-001 — reads every funded project's latest synced spend into its budget record.
 *
 * Scheduled after the half-hourly sync. Safe to run any number of times: each account-day is one row
 * that is replaced, never added again (F07). One project's failure is reported and the rest continue.
 */
final class FundingRefreshCommand extends Command
{
    protected $signature = 'funding:refresh';

    protected $description = 'Count the latest synced spend against each project\'s approved budget';

    public function handle(FundingSpendRefresher $refresher, FundingNotifier $notifier, TenantContext $context): int
    {
        $failed = 0;
        $rows = 0;

        ProjectFunding::withoutGlobalScopes()->where('payment_method', '!=', 'unset')->whereNull('voided_at')
            ->lazyById(200)->each(function (ProjectFunding $funding) use ($refresher, $notifier, $context, &$failed, &$rows): void {
                $context->setTenantId($funding->tenant_id);
                try {
                    $rows += $refresher->refresh($funding);
                    $notifier->evaluate($funding);
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                } finally {
                    $context->forget();
                }
            });

        app(ScheduledRunRows::class)->report($rows);
        $this->info("funding:refresh rows={$rows} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
