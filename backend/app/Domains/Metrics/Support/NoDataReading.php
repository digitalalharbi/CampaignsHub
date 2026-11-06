<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Support;

use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;

/**
 * META-NO-DATA-READING-001 — what a run with nothing stored is actually saying.
 *
 * Production, 2026-11-05: a Meta account read `no_data` (raw 0, parsed 0, stored 0) on every half-hour
 * sweep, and the run could not say which of two things that was — «the campaigns did not deliver in these
 * days» or «the provider's answer held nothing we could read». The retained body settled it (`{"data":[]}`,
 * with figures stored up to five days before the window), but only for somebody who went and read it.
 *
 * Two readings, and they are not the same event:
 *
 * - {@see self::NO_DELIVERY} — the provider answered with an EMPTY LIST. This is the only thing `no_data`
 *   means. It is stated with the last day this account does hold a figure for, because «it stopped on the
 *   28th» and «it has never delivered» are different sentences to an operator.
 * - {@see self::UNREAD_ROWS} — the provider sent rows and none of them became a metric row. That is never
 *   `no_data`; the syncer files it `partial_mapping` / `unread_rows`, and this reads an older run that was
 *   filed the old way.
 *
 * A third case never reaches here: an answer that is not a list at all is a failed read.
 *
 * Nothing is inferred beyond what is stored. This does not say WHY delivery stopped, and a `null` last
 * day means «no figure is stored», not «the account never ran».
 */
final class NoDataReading
{
    public const NO_DELIVERY = 'no_delivery';

    public const UNREAD_ROWS = 'unread_rows';

    /** Which reading the counts support, or null when the provider's rows were never counted. */
    public static function of(?int $rawRows, ?int $parsedRows): ?string
    {
        if ($rawRows === null) {
            return null;
        }

        return $rawRows > 0 && (int) $parsedRows === 0 ? self::UNREAD_ROWS : self::NO_DELIVERY;
    }

    /** The last day this account holds a real (non-demo) figure for, as `Y-m-d`. */
    public static function lastDeliveryDate(string $externalAccountId): ?string
    {
        $date = DailyMetric::withoutGlobalScopes()
            ->where('external_account_id', $externalAccountId)
            ->where('is_demo', false)
            ->max('metric_date');

        return $date === null ? null : Carbon::parse((string) $date)->toDateString();
    }

    /**
     * The reading of one run, from what the run recorded — or, for a run older than the record, from its
     * counts and from what is stored now.
     *
     * @return array{reading:?string, last_delivery_date:?string, sentence:?string}
     */
    public static function for(MetricSyncRun $run): array
    {
        $status = (string) $run->getRawOriginal('status');
        $raw = $run->provider_raw_rows === null ? null : (int) $run->provider_raw_rows;
        $parsed = $run->parsed_rows === null ? null : (int) $run->parsed_rows;
        $unread = self::of($raw, $parsed) === self::UNREAD_ROWS;

        if ($status !== SyncRunStatus::NoData->value && ! ($status === SyncRunStatus::PartialMapping->value && $unread)) {
            return ['reading' => null, 'last_delivery_date' => null, 'sentence' => null];
        }

        if ($unread) {
            return [
                'reading' => self::UNREAD_ROWS,
                'last_delivery_date' => null,
                'sentence' => "PROVIDER ROWS NOT READ — the provider returned {$raw} row(s) and none became a metric row. This is not «no delivery».",
            ];
        }

        $meta = (array) ($run->meta ?? []);
        $last = array_key_exists('last_delivery_date', $meta)
            ? ($meta['last_delivery_date'] === null ? null : (string) $meta['last_delivery_date'])
            : self::lastDeliveryDate((string) $run->external_account_id);
        $recorded = array_key_exists('no_data_reading', $meta);

        return [
            'reading' => self::NO_DELIVERY,
            'last_delivery_date' => $last,
            'sentence' => 'NO DELIVERY IN THE WINDOW — the provider answered with an empty list'
                .($raw === null ? ' (rows not counted on this run)' : ' (raw 0)')
                .'; '.($last === null
                    ? 'no figure has ever been stored for this account — «never delivered» is what is stored, not what was proven'
                    : "last day with a stored figure: {$last}".self::gap($last, $run))
                .($recorded ? '' : ' [derived now; this run predates the recorded reading]').'.',
        ];
    }

    private static function gap(string $last, MetricSyncRun $run): string
    {
        if ($run->window_start === null) {
            return '';
        }

        $days = (int) Carbon::parse($last)->startOfDay()->diffInDays(Carbon::parse($run->window_start)->startOfDay(), false);

        return $days > 0 ? " ({$days} day(s) before the window start)" : ' (inside or after this window: an earlier, wider run stored it)';
    }
}
