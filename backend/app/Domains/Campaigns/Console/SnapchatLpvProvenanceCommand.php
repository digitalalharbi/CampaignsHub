<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How much stored Snapchat ad-grain «landing page views» came from the old mapping — counts only.
 *
 * `SnapchatConnector::ENTITY_METRICS` filled the ad and ad-squad grain's `landing_page_views` from
 * `conversion_page_views`, the attributed PAGE_VIEW pixel count that `page_views` also stores. The fix
 * (#460) reads the delivery field for future syncs; stored rows keep the pixel value until their days
 * are re-fetched. A historical re-sync writes rows, so it is an Owner decision, and this command makes
 * that decision concrete without writing anything:
 *
 *   · how far back the scheduled sweep actually re-fetches, MEASURED from the recent Snapchat metric
 *     runs rather than assumed from the default;
 *   · the stored ad-grain rows carrying `landing_page_views`, by month and by grain, split at that
 *     boundary — rows inside it are rewritten by the ordinary sweep, rows outside it are not;
 *   · how many carry `landing_page_views = page_views`, the old mapping's own signature (both columns
 *     were filled from the same field). A row where they differ was written by the delivery mapping.
 *
 * Counts and dates only — never a metric value, an account or a name.
 */
final class SnapchatLpvProvenanceCommand extends Command
{
    protected $signature = 'content:lpv-provenance
        {--hours=48 : How far back to read Snapchat metric runs to measure the sweep\'s reach}';

    protected $description = 'Read-only: count stored Snapchat ad-grain landing-page-view rows from the old pixel mapping, by date, against the sweep\'s measured reach.';

    public function handle(): int
    {
        $hours = max(1, (int) ($this->option('hours') ?: 48));

        $runs = DB::table('metric_sync_runs')
            ->where('provider', 'snapchat')
            ->where('created_at', '>=', Carbon::now()->subHours($hours))
            ->selectRaw('COUNT(*) AS runs, MIN(window_start) AS earliest_start, MAX(window_end) AS latest_end')
            ->first();

        $earliest = $runs?->earliest_start === null ? null : Carbon::parse((string) $runs->earliest_start)->toDateString();

        $this->line('');
        $this->line('SNAPCHAT AD-GRAIN LANDING PAGE VIEWS — provenance of stored rows (counts only)');
        $this->line(sprintf(
            '  sweep reach, measured from %d Snapchat metric run(s) in the last %dh: earliest window start %s, latest window end %s',
            (int) ($runs->runs ?? 0),
            $hours,
            $earliest ?? 'none',
            $runs?->latest_end === null ? 'none' : Carbon::parse((string) $runs->latest_end)->toDateString(),
        ));
        $this->line('  rows ON or AFTER that start are rewritten by the ordinary sweep; rows BEFORE it keep what they hold.');

        $rows = DB::table('entity_daily_metrics')
            ->where('provider', 'snapchat')
            ->whereNotNull('landing_page_views')
            ->selectRaw("entity_type, to_char(metric_date, 'YYYY-MM') AS month, "
                .'COUNT(*) AS rows_found, MIN(metric_date) AS first_day, MAX(metric_date) AS last_day, '
                .'COUNT(*) FILTER (WHERE page_views IS NOT NULL AND landing_page_views = page_views) AS equals_page_views')
            ->groupBy('entity_type', 'month')
            ->orderBy('entity_type')
            ->orderBy('month')
            ->get();

        $this->line('');

        if ($rows->isEmpty()) {
            $this->line('  no stored Snapchat ad-grain row carries landing_page_views');

            return self::SUCCESS;
        }

        $this->line(sprintf('  %-8s %-8s %8s %22s %12s %12s', 'grain', 'month', 'rows', '= page_views (old map)', 'first day', 'last day'));

        foreach ($rows as $row) {
            $this->line(sprintf(
                '  %-8s %-8s %8d %22d %12s %12s',
                (string) $row->entity_type,
                (string) $row->month,
                (int) $row->rows_found,
                (int) $row->equals_page_views,
                Carbon::parse((string) $row->first_day)->toDateString(),
                Carbon::parse((string) $row->last_day)->toDateString(),
            ));
        }

        if ($earliest !== null) {
            $split = DB::table('entity_daily_metrics')
                ->where('provider', 'snapchat')
                ->whereNotNull('landing_page_views')
                ->selectRaw('COUNT(*) FILTER (WHERE metric_date < ?) AS outside_reach, '
                    .'COUNT(*) FILTER (WHERE metric_date < ? AND page_views IS NOT NULL AND landing_page_views = page_views) AS outside_reach_old_map, '
                    .'COUNT(*) FILTER (WHERE metric_date >= ?) AS inside_reach', [$earliest, $earliest, $earliest])
                ->first();

            $this->line('');
            $this->line(sprintf(
                '  outside the sweep\'s reach (before %s): %d row(s), %d with the old mapping\'s signature — these keep the pixel value without a deliberate re-sync',
                $earliest,
                (int) ($split->outside_reach ?? 0),
                (int) ($split->outside_reach_old_map ?? 0),
            ));
            $this->line(sprintf('  inside the sweep\'s reach: %d row(s) — rewritten by the ordinary sweep once #460 is deployed', (int) ($split->inside_reach ?? 0)));
        }

        return self::SUCCESS;
    }
}
