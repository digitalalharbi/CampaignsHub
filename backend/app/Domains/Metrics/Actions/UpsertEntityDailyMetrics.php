<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Actions;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Services\ReportingCurrency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * METRICS-BACKBONE-001 — provider ad-squad and ad rows become `entity_daily_metrics`.
 *
 * ## Money goes through the same service as everything else
 *
 * `creative_daily_metrics` was written before FX-001 and bypassed `ReportingCurrency` entirely, so
 * production stored USD figures with no record of the currency and the cards rendered them under a
 * hard-coded «SAR» — a wrong number wearing the right one's label. This table is built after that
 * was fixed, and it converts through the same service on the same rule: the row's own currency,
 * then the ACCOUNT's, and only then the reporting currency. Treating an unlabelled figure as
 * already converted IS the defect, so the reporting currency is the last resort and never the first.
 *
 * ## An entity we have not discovered is SKIPPED, never created
 *
 * The structure sweep owns identity; this owns numbers. A stats row naming an ad squad the sweep has
 * not seen is counted and dropped — inventing one would produce a row with a provider id and nothing
 * else, and the Analytics drill-down would show that placeholder as a real ad squad.
 */
final class UpsertEntityDailyMetrics
{
    /** Columns a provider row may carry. Money is handled separately — see `MONEY`. */
    private const MEASURES = [
        'impressions', 'reach', 'frequency', 'clicks', 'landing_page_views', 'engagements',
        'video_views', 'video_views_2s', 'video_views_5s', 'video_views_15s',
        'video_p25', 'video_p50', 'video_p75', 'video_p100', 'video_watch_seconds',
        'conversions', 'purchases', 'add_to_cart', 'checkout',
        'leads', 'sign_ups', 'installs', 'app_opens', 'page_views',
    ];

    /**
     * The money columns this table has, named here as well as catalogued.
     *
     * `metric_definitions.is_currency` stays the product's authority on what money is, but an empty
     * or unseeded catalogue would answer false for everything — and the failure mode of that is
     * storing an unconverted figure as though it were already in the project's currency, which is
     * exactly the defect this class was written to avoid repeating.
     */
    private const MONEY = ['spend', 'revenue'];

    public function __construct(private readonly ReportingCurrency $currency) {}

    /**
     * @param  list<array<string,mixed>>  $rows  canonical rows; provider entity id in `entity_id`
     * @param  array<string,array{id:string,project_id:string,tenant_id:string,campaign_id:?string,ad_set_id:?string}>  $known
     *                                                                                                                          provider entity id => the entity the structure sweep already discovered
     * @return array{upserted:int,skipped:int}
     */
    public function execute(
        ExternalAccount $account,
        string $entityType,
        array $rows,
        array $known,
        ?string $syncRunId = null,
    ): array {
        if ($rows === []) {
            return ['upserted' => 0, 'skipped' => 0];
        }

        $now = Carbon::now();
        $payload = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $providerId = (string) ($row['entity_id'] ?? '');
            $date = (string) ($row['date'] ?? '');
            $entity = $known[$providerId] ?? null;

            if ($entity === null || $date === '') {
                $skipped++;

                continue;
            }

            $reporting = $this->currency->forProject($entity['project_id']);
            $source = strtoupper((string) ($row['currency'] ?? $account->currency ?? $reporting));

            $measures = [];
            foreach (self::MEASURES as $key) {
                // ABSENT stays absent. A metric the platform does not report has not reported zero
                // of it, and the column default is null precisely so the two stay distinguishable.
                if (array_key_exists($key, $row) && $row[$key] !== null) {
                    $measures[$key] = $row[$key];
                }
            }

            $money = [];
            foreach (self::MONEY as $key) {
                if (! array_key_exists($key, $row) || $row[$key] === null) {
                    continue;
                }

                $amount = (float) $row[$key];
                $converted = $this->currency->normalise($amount, $source, $reporting, Carbon::parse($date));

                // Null when no rate could be vouched for — NOT zero, and not the unconverted figure
                // wearing the project's currency.
                $measures[$key] = $converted['value'];
                $money["{$key}_original"] = $amount;
                $money['original_currency'] = $source;
                $money['project_currency'] = $reporting;
            }

            $payload[] = [
                'id' => (string) Str::uuid(),
                'tenant_id' => $entity['tenant_id'],
                'project_id' => $entity['project_id'],
                'external_account_id' => $account->id,
                'provider' => $account->provider,
                'entity_type' => $entityType,
                'entity_id' => $entity['id'],
                'external_entity_id' => $providerId,
                'external_campaign_id' => $entity['campaign_id'] ?? null,
                'external_ad_set_id' => $entity['ad_set_id'] ?? null,
                'metric_date' => $date,
                'attribution_window' => (string) ($row['attribution_window'] ?? 'default'),
                'is_demo' => false,
                'sync_run_id' => $syncRunId,
                'created_at' => $now,
                'updated_at' => $now,
                ...$measures,
                ...$money,
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            DB::table('entity_daily_metrics')->upsert(
                $chunk,
                ['entity_type', 'entity_id', 'metric_date', 'attribution_window'],
                [
                    ...self::MEASURES, ...self::MONEY,
                    'spend_original', 'revenue_original', 'original_currency', 'project_currency',
                    'external_campaign_id', 'external_ad_set_id', 'sync_run_id', 'updated_at',
                ],
            );
        }

        return ['upserted' => count($payload), 'skipped' => $skipped];
    }
}
