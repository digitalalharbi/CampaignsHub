<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Actions;

use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\DailyMetric;
use App\Support\UnchangedRows;
use Illuminate\Support\Carbon;

/**
 * Idempotent bulk upsert into daily_metrics keyed by the natural key
 * (account, campaign, metric, date, attribution window). Re-running with the same or corrected
 * figures updates in place instead of duplicating — safe for re-sync and late-attribution reconciliation.
 *
 * PERF-RECOVERY-001 — a row whose every updatable column already holds the offered value is not
 * written again ({@see UnchangedRows}), so `updated_at` is the moment a row's content last changed.
 * That a sweep ran and what it received is the run's to say (`metric_sync_runs`), not each row's.
 */
final class UpsertDailyMetrics
{
    private const UNIQUE_BY = DailyMetric::NATURAL_KEY;

    private const UPDATE_COLUMNS = [
        'unified_campaign_id', 'connection_id', 'provider', 'value',
        'original_currency', 'project_currency', 'original_amount', 'converted_amount', 'exchange_rate',
        // FX-001. On the update path too, so a re-sync that now HAS a rate replaces a withheld
        // figure — omitting these would leave the first, rateless answer in place forever.
        'rate_date', 'rate_source',
        'original_timezone', 'project_timezone', 'source_type', 'data_freshness_at',
        'sync_run_id', 'raw', 'is_demo', 'updated_at',
    ];

    /** Says when, not what: left out of the comparison that decides whether a row is already stored. */
    private const STAMPS = ['updated_at'];

    /**
     * @param  iterable<NormalizedMetric>  $metrics
     * @return int number of rows now stored as offered (inserted, updated, or already there)
     */
    public function handle(iterable $metrics, int $chunkSize = 500): int
    {
        $now = Carbon::now();
        $rows = [];
        foreach ($metrics as $m) {
            $rows[] = $m->toRow($now);
        }
        if ($rows === []) {
            return 0;
        }

        $written = 0;
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $written += count($chunk);
            $chunk = UnchangedRows::drop('daily_metrics', $chunk, self::UNIQUE_BY, array_values(array_diff(self::UPDATE_COLUMNS, self::STAMPS)));

            if ($chunk !== []) {
                DailyMetric::withoutGlobalScopes()->upsert($chunk, self::UNIQUE_BY, self::UPDATE_COLUMNS);
            }
        }

        return $written;
    }
}
