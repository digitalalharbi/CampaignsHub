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
        {--hours=48 : How far back to read Snapchat metric runs to measure the sweep\'s reach}
        {--runs=2 : How many of the latest Snapchat metric runs to read retained bodies from}';

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

        $this->deliveryFieldPresence(max(1, (int) ($this->option('runs') ?: 2)));
        $this->entityGrainRuns(max(1, (int) ($this->option('runs') ?: 2)), $earliest);

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

    /**
     * Does Snapchat send the fields at all, for this estate, NOW? Read from the retained bodies of the
     * latest runs (by `sync_run_id`, indexed; 5 bodies per query), counting per stat type how each
     * point carried `landing_page_views` (the delivery metric) and `conversion_page_views` (the pixel
     * event): key absent, JSON null, zero, positive. Counts only.
     */
    private function deliveryFieldPresence(int $runs): void
    {
        $runIds = DB::table('metric_sync_runs')
            ->where('provider', 'snapchat')
            ->orderByDesc('created_at')
            ->limit($runs)
            ->pluck('id')
            ->map(static fn (mixed $v): string => (string) $v)
            ->all();

        $this->line('');
        $this->line(sprintf('  retained bodies of the latest %d Snapchat metric run(s) — how each point carried the field', count($runIds)));

        /** @var array<string, array<string, array{absent: int, null: int, zero: int, positive: int}>> $tally type => field => state */
        $tally = [];
        $bodies = 0;
        $fields = ['landing_page_views', 'conversion_page_views'];

        DB::statement("SET statement_timeout = '20s'");

        try {
            foreach ($runIds as $runId) {
                $lastId = '00000000-0000-0000-0000-000000000000';
                do {
                    $chunk = DB::table('integration_raw_payloads')
                        ->where('sync_run_id', $runId)
                        ->where('resource', 'insights')
                        ->where('id', '>', $lastId)
                        ->orderBy('id')
                        ->limit(5)
                        ->get(['id', 'payload']);

                    foreach ($chunk as $body) {
                        $lastId = (string) $body->id;
                        $bodies++;
                        $decoded = json_decode((string) $body->payload, true);

                        if (is_array($decoded)) {
                            $this->walk($decoded, 'unknown', $fields, $tally);
                        }
                    }
                } while ($chunk->count() === 5 && $bodies < 5000);
            }
        } catch (\Throwable $e) {
            $this->line('    the read failed ('.class_basename($e).' '.$e->getCode().')');
        } finally {
            DB::statement('RESET statement_timeout');
        }

        $this->line(sprintf('    bodies read: %d', $bodies));

        if ($tally === []) {
            $this->line('    no point in those bodies');

            return;
        }

        ksort($tally);
        foreach ($tally as $type => $byField) {
            foreach ($fields as $field) {
                $t = $byField[$field] ?? ['absent' => 0, 'null' => 0, 'zero' => 0, 'positive' => 0];
                $this->line(sprintf(
                    '    %-10s %-22s key absent %d, JSON null %d, zero %d, positive %d',
                    $type, $field, $t['absent'], $t['null'], $t['zero'], $t['positive'],
                ));
            }
        }
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $fields
     * @param  array<string, array<string, array{absent: int, null: int, zero: int, positive: int}>>  $tally
     */
    private function walk(array $node, string $type, array $fields, array &$tally): void
    {
        $type = is_string($node['type'] ?? null) ? strtolower($node['type']) : $type;

        if (is_array($node['timeseries'] ?? null)) {
            foreach ($node['timeseries'] as $point) {
                $stats = is_array($point) && is_array($point['stats'] ?? null) ? $point['stats'] : null;

                if ($stats === null) {
                    continue;
                }

                foreach ($fields as $field) {
                    $state = match (true) {
                        ! array_key_exists($field, $stats) => 'absent',
                        $stats[$field] === null => 'null',
                        (float) $stats[$field] === 0.0 => 'zero',
                        default => 'positive',
                    };
                    $tally[$type][$field] ??= ['absent' => 0, 'null' => 0, 'zero' => 0, 'positive' => 0];
                    $tally[$type][$field][$state]++;
                }
            }
        }

        foreach ($node as $key => $child) {
            if (is_array($child) && $key !== 'timeseries') {
                $this->walk($child, $type, $fields, $tally);
            }
        }
    }

    /**
     * Did the ad-set and ad grain actually write, and what did it write, since the sweep's reach? The
     * latest runs' own meta (rows upserted per grain, the first refusal with every id masked), and the
     * stored rows inside the reach — all of them, and those carrying `landing_page_views`. Counts only.
     */
    private function entityGrainRuns(int $runs, ?string $earliest): void
    {
        $this->line('');
        $this->line('  entity grain in the latest run(s) — rows written and the first refusal (ids masked)');

        foreach (DB::table('metric_sync_runs')->where('provider', 'snapchat')->orderByDesc('created_at')->limit($runs)->get(['created_at', 'status', 'meta']) as $run) {
            $meta = is_string($run->meta) ? (array) json_decode($run->meta, true) : (array) $run->meta;
            $failure = $meta['entity_failure'] ?? null;
            $this->line(sprintf(
                '    %s  status %s  ad sets %s  ads %s  refusal %s',
                Carbon::parse((string) $run->created_at)->toDateTimeString(),
                (string) $run->status,
                array_key_exists('entity_ad_sets', $meta) ? (string) (int) $meta['entity_ad_sets'] : 'not recorded',
                array_key_exists('entity_ads', $meta) ? (string) (int) $meta['entity_ads'] : 'not recorded',
                is_string($failure) && $failure !== '' ? self::mask($failure) : 'none',
            ));
        }

        if ($earliest === null) {
            return;
        }

        /*
         * Estate-wide, by (project, account, grain): «not written» and «written under another project
         * or an account the project is not bound to» look identical from one project's side.
         */
        $inside = DB::table('entity_daily_metrics AS m')
            ->leftJoin('project_integration_bindings AS b', function ($join): void {
                $join->on('b.project_id', '=', 'm.project_id')
                    ->on('b.external_account_id', '=', 'm.external_account_id')
                    ->where('b.is_active', '=', true);
            })
            ->where('m.provider', 'snapchat')
            ->where('m.metric_date', '>=', $earliest)
            ->selectRaw('m.project_id, m.external_account_id, m.entity_type, (b.id IS NOT NULL) AS bound, '
                .'COUNT(*) AS rows_found, COUNT(m.landing_page_views) AS with_lpv, COUNT(m.page_views) AS with_page_views, MAX(m.updated_at) AS last_written')
            ->groupBy('m.project_id', 'm.external_account_id', 'm.entity_type', 'b.id')
            ->orderBy('m.project_id')->orderBy('m.external_account_id')->orderBy('m.entity_type')
            ->get();

        $this->line(sprintf('  stored entity rows ON or AFTER %s, estate-wide by project · account · grain:', $earliest));

        if ($inside->isEmpty()) {
            $this->line('    none');
        }

        foreach ($inside as $row) {
            $this->line(sprintf(
                '    project %s  account %s (%s)  %-6s rows %d, carrying landing_page_views %d, carrying page_views %d, last written %s',
                (string) $row->project_id,
                $row->external_account_id === null ? 'none' : (string) $row->external_account_id,
                $row->external_account_id === null ? 'no account' : ((bool) $row->bound ? 'bound to this project' : 'NOT bound to this project'),
                (string) $row->entity_type,
                (int) $row->rows_found,
                (int) $row->with_lpv,
                (int) $row->with_page_views,
                $row->last_written === null ? 'never' : Carbon::parse((string) $row->last_written)->toDateTimeString(),
            ));
        }
    }

    /** A provider message with every identifier and URL masked, cut to one line. */
    private static function mask(string $message): string
    {
        $masked = (string) preg_replace(['#https?://\S+#', '/[0-9a-f]{8}-[0-9a-f-]{27,}/i', '/\b\d{5,}\b/'], ['<url>', '<id>', '<n>'], $message);

        return mb_substr(str_replace(["\r", "\n"], ' ', $masked), 0, 200);
    }
}
