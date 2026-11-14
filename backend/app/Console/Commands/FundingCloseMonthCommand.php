<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Funding\Models\ProjectFunding;
use App\Domains\Funding\Services\FundingMonth;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * FIN-LOOP-001 part 3 — fixes last month's summary for every funded project, once.
 *
 * Runs daily. A month is fixed only once it has settled (`FundingMonth::SETTLE_DAYS` after it ends)
 * and every spend day has a rate; until then each run leaves it live and tries again the next day.
 * A month already fixed is left as it is.
 */
final class FundingCloseMonthCommand extends Command
{
    protected $signature = 'funding:close-month {--month= : YYYY-MM, a closed month (defaults to last month)}';

    protected $description = 'Fix the monthly budget summary of every funded project for a closed month';

    public function handle(FundingMonth $months, TenantContext $context): int
    {
        $month = (string) ($this->option('month') ?: Carbon::today('Asia/Riyadh')->subMonthNoOverflow()->format('Y-m'));
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $this->error('month must be YYYY-MM');

            return self::INVALID;
        }

        $fixed = 0;
        $failed = 0;
        ProjectFunding::withoutGlobalScopes()->lazyById(200)->each(function (ProjectFunding $funding) use ($months, $context, $month, &$fixed, &$failed): void {
            $context->setTenantId($funding->tenant_id);
            try {
                $fixed += $months->freeze($funding, $month) ? 1 : 0;
            } catch (Throwable $e) {
                $failed++;
                report($e);
            } finally {
                $context->forget();
            }
        });

        app(ScheduledRunRows::class)->report($fixed);
        $this->info("funding:close-month {$month} fixed={$fixed} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
