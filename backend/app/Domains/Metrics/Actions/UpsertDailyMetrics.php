<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Actions;

use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\DailyMetric;
use Illuminate\Support\Carbon;

/**
 * Idempotent bulk upsert into daily_metrics keyed by the natural key
 * (account, campaign, metric, date, attribution window). Re-running with the same or corrected
 * figures updates in place instead of duplicating — safe for re-sync and late-attribution reconciliation.
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

    /**
     * @param  iterable<NormalizedMetric>  $metrics
     * @return int number of rows written (inserted or updated)
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
            DailyMetric::withoutGlobalScopes()->upsert($chunk, self::UNIQUE_BY, self::UPDATE_COLUMNS);
            $written += count($chunk);
        }

        return $written;
    }
}
