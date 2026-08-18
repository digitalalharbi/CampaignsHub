<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

/**
 * INTEG-RUNTIME §7 — reading the four numbers back out of a run that predates the counters.
 *
 * ## Why this exists at all
 *
 * The counters on `metric_sync_runs` measure a sync as it happens, which does nothing for the run
 * that already happened — and the run that already happened is the one the customer is asking about.
 * What survives from it is the provider's own body, kept in `integration_raw_payloads` since
 * INTEG-RAW-001. So the diagnosis is not a guess reconstructed from a status: it is a re-read of what
 * the platform actually sent, counted again.
 *
 * This walks a stored body and reports two things — how many data records are in it, and which
 * campaign ids they name. With those, `mapped_campaign_rows` is a set intersection against
 * `external_campaigns`, and the question «did the provider send nothing, or did we fail to place what
 * it sent?» has an answer instead of a theory.
 *
 * ## It knows the shapes, and says so when it does not
 *
 * Each platform nests its rows differently and there is no generic walk that is honest about all six:
 * a recursive «count the arrays» would return a number for any body at all, including the wrong one.
 * An unknown provider therefore returns null — «this cannot be read» — rather than a plausible zero.
 */
final class InsightPayloadRows
{
    /**
     * Records in one stored insights body, and the campaign ids they name.
     *
     * @param  array<string,mixed>  $body  one response, exactly as the platform sent it
     * @return array{rows:int, campaign_ids:list<string>}|null null when the shape is not one we read
     */
    public static function of(string $provider, array $body): ?array
    {
        return match ($provider) {
            'snapchat' => self::snapchat($body),
            'meta', 'meta_ads' => self::flat($body, 'data', 'campaign_id'),
            'tiktok' => self::tiktok($body),
            'linkedin' => self::linkedin($body),
            'google', 'google_ads' => self::google($body),
            'x' => self::x($body),
            default => null,
        };
    }

    /**
     * `timeseries_stats[].timeseries_stat` — the AD ACCOUNT, with its campaigns nested underneath.
     *
     * SNAP-BREAKDOWN-001. This read `timeseries_stat.timeseries` and `timeseries_stat.id`, matching
     * the connector's own wrong assumption — so when the diagnosis recovered a past run's counts from
     * the stored body it confirmed the same zero, from the same mistake, and looked like corroboration.
     * With `breakdown=campaign` the day points live at `breakdown_stats.campaign[].timeseries`, and
     * the campaign id is on that entry, not on the series.
     */
    private static function snapchat(array $body): ?array
    {
        if (! array_key_exists('timeseries_stats', $body)) {
            return null;
        }

        $rows = 0;
        $ids = [];

        foreach ((array) $body['timeseries_stats'] as $wrapper) {
            $series = (array) (((array) $wrapper)['timeseries_stat'] ?? []);
            $breakdown = (array) ($series['breakdown_stats'] ?? []);

            if (isset($breakdown['campaign']) && is_array($breakdown['campaign'])) {
                foreach ($breakdown['campaign'] as $entry) {
                    $campaign = (array) $entry;
                    $rows += count((array) ($campaign['timeseries'] ?? []));

                    $id = (string) ($campaign['id'] ?? '');
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }

                continue;
            }

            // No breakdown: the series is the entity, and its id is what the rows belong to.
            $rows += count((array) ($series['timeseries'] ?? []));

            $id = (string) ($series['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return ['rows' => $rows, 'campaign_ids' => array_values(array_unique($ids))];
    }

    /** `data.list[]`, with the campaign id under `dimensions`. */
    private static function tiktok(array $body): ?array
    {
        $list = $body['data']['list'] ?? null;

        if (! is_array($list)) {
            return null;
        }

        $ids = [];
        foreach ($list as $row) {
            $dimensions = (array) (((array) $row)['dimensions'] ?? []);
            $id = (string) ($dimensions['campaign_id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return ['rows' => count($list), 'campaign_ids' => array_values(array_unique($ids))];
    }

    /** `elements[]`, with the campaign id inside a `pivotValues` URN. */
    private static function linkedin(array $body): ?array
    {
        if (! array_key_exists('elements', $body)) {
            return null;
        }

        $elements = (array) $body['elements'];
        $ids = [];

        foreach ($elements as $row) {
            foreach ((array) (((array) $row)['pivotValues'] ?? []) as $urn) {
                if (preg_match('/sponsoredCampaign:(\d+)/', (string) $urn, $m) === 1) {
                    $ids[] = $m[1];
                }
            }
        }

        return ['rows' => count($elements), 'campaign_ids' => array_values(array_unique($ids))];
    }

    /** `results[]` from the GAQL stream, with the campaign id under `campaign.id`. */
    private static function google(array $body): ?array
    {
        if (! array_key_exists('results', $body)) {
            return null;
        }

        $results = (array) $body['results'];
        $ids = [];

        foreach ($results as $row) {
            $campaign = (array) (((array) $row)['campaign'] ?? []);
            $id = (string) ($campaign['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return ['rows' => count($results), 'campaign_ids' => array_values(array_unique($ids))];
    }

    /** `data[]` — one entity per campaign; X's record unit is the entity, not the day. */
    private static function x(array $body): ?array
    {
        return self::flat($body, 'data', 'id');
    }

    /**
     * The common shape: a top-level list, one record per row, campaign id on the record.
     *
     * @param  array<string,mixed>  $body
     * @return array{rows:int, campaign_ids:list<string>}|null
     */
    private static function flat(array $body, string $key, string $idField): ?array
    {
        if (! array_key_exists($key, $body)) {
            return null;
        }

        $rows = (array) $body[$key];
        $ids = [];

        foreach ($rows as $row) {
            $id = (string) (((array) $row)[$idField] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return ['rows' => count($rows), 'campaign_ids' => array_values(array_unique($ids))];
    }
}
