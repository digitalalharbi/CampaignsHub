<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Platform\Services\ProductionReadiness;
use Illuminate\Console\Command;

/**
 * `php artisan production:check` — run this in the deploy pipeline BEFORE traffic moves.
 *
 * Exits non-zero on any failure, so a pipeline that runs it stops on a misconfiguration instead of
 * discovering it through a customer who paid and was never activated.
 *
 * `--warnings-as-failures` is for a pipeline that wants a gate on the honest-but-unfinished states
 * too (no mail provider, for instance). The default keeps them visible without blocking, because
 * those states are ones the product already reports truthfully.
 */
final class ProductionCheckCommand extends Command
{
    protected $signature = 'production:check {--json : Emit the report as JSON} {--warnings-as-failures}';

    protected $description = 'Check that this install is configured to take real money and real traffic';

    public function handle(ProductionReadiness $readiness): int
    {
        $report = $readiness->run();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->verdict($report);
        }

        $this->newLine();
        $this->line("  Environment: <options=bold>{$report['environment']}</>");
        $this->newLine();

        if ($report['findings'] === []) {
            $this->info('  Nothing to report — every check passed.');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($report['findings'] as $finding) {
            $tag = $finding['level'] === 'fail' ? '<fg=red;options=bold>FAIL</>' : '<fg=yellow;options=bold>WARN</>';
            $this->line("  {$tag}  <options=bold>{$finding['key']}</>");
            $this->line("        {$finding['message']}");
            $this->line("        <fg=gray>→ {$finding['fix']}</>");
            $this->newLine();
        }

        $this->line("  {$report['failures']} failing, {$report['warnings']} warning.");
        $this->newLine();

        return $this->verdict($report);
    }

    /** @param array{failures: int, warnings: int} $report */
    private function verdict(array $report): int
    {
        if ($report['failures'] > 0) {
            return self::FAILURE;
        }

        return $this->option('warnings-as-failures') && $report['warnings'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
