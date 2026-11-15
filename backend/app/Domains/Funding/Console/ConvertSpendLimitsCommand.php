<?php

declare(strict_types=1);

namespace App\Domains\Funding\Console;

use App\Domains\Funding\Services\ConvertSpendLimits;
use Illuminate\Console\Command;

/**
 * ONE-BUDGET-001 — `funding:convert-spend-limits` links every spend limit to its project's approved budget (or makes an
 * active project-scope limit the budget of a project that has none). Dry run unless `--apply`; idempotent; deletes nothing.
 */
final class ConvertSpendLimitsCommand extends Command
{
    protected $signature = 'funding:convert-spend-limits {--apply : write the links (without it, only count)}';

    protected $description = 'Link spend limits to the approved project budget (dry run by default; idempotent; deletes nothing).';

    public function handle(ConvertSpendLimits $convert): int
    {
        $apply = (bool) $this->option('apply');
        $r = $convert->run($apply);

        $this->info(($apply ? 'Applied' : 'Dry run').': '.json_encode($r));

        return self::SUCCESS;
    }
}
