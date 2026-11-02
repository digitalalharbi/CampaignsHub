<?php

declare(strict_types=1);

namespace App\Domains\Ops\Console;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * PERF-RECOVERY-001 — where a public live report spends its time. Read-only.
 *
 * Builds the live payload for the most recently opened active live links exactly as the public
 * endpoint does (the same service, no query filters) and prints wall time, query count, database
 * time and the heaviest statements. Statements are shown with their bindings stripped; a link is
 * named by the first 8 characters of its id, never by its token.
 */
final class ProfileLiveReportCommand extends Command
{
    protected $signature = 'ops:profile-live-report {--links=2 : Live links to profile} {--top=20 : Statements to print}';

    protected $description = 'Read-only: time, query count and heaviest queries of the public live report build.';

    public function handle(LiveReportService $live): int
    {
        $shares = ReportShare::withoutGlobalScopes()->get()
            ->filter(static fn (ReportShare $s): bool => $s->isActive() && $s->isLive())
            ->sortByDesc(static fn (ReportShare $s) => $s->updated_at)
            ->take(max(1, (int) $this->option('links')));

        foreach ($shares as $share) {
            $report = Report::withoutGlobalScopes()->find($share->report_id);

            if (! $report) {
                continue;
            }

            $stats = [];
            $total = 0;
            $count = 0;

            DB::listen(static function ($q) use (&$stats, &$total, &$count): void {
                $sql = preg_replace('/\s+/', ' ', $q->sql);
                $stats[$sql]['n'] = ($stats[$sql]['n'] ?? 0) + 1;
                $stats[$sql]['ms'] = ($stats[$sql]['ms'] ?? 0.0) + $q->time;
                $stats[$sql]['max'] = max($stats[$sql]['max'] ?? 0.0, $q->time);
                $total += $q->time;
                $count++;
            });

            $t = microtime(true);
            $payload = $live->build($share, [], (string) $report->currency);
            $wall = (microtime(true) - $t) * 1000;

            $this->line(sprintf(
                'LINK %s  wall %.0f ms · %d queries · db %.0f ms · json %d B · peak mem %d MB',
                substr((string) $share->id, 0, 8),
                $wall,
                $count,
                $total,
                strlen((string) json_encode($payload)),
                memory_get_peak_usage(true) / 1048576,
            ));

            uasort($stats, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
            $this->line(sprintf('  %-6s %-9s %-9s %s', 'runs', 'total ms', 'max ms', 'statement'));

            foreach (array_slice($stats, 0, max(1, (int) $this->option('top')), true) as $sql => $s) {
                $this->line(sprintf('  %-6d %-9.1f %-9.1f %s', $s['n'], $s['ms'], $s['max'], substr($sql, 0, 260)));
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
