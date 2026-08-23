<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Actions;

use Illuminate\Support\Facades\DB;

/**
 * CREATIVE-MONEY-TRUTH-001 — give the already-stored creative money its provenance back.
 *
 * ## Why this is an action and not four lines inside the migration
 *
 * The migration's docblock claims this is idempotent and that it never invents a currency. Those are
 * exactly the claims worth being wrong about: it rewrites real stored figures on a live database,
 * and a migration's body cannot be re-run in a test to check either one. Here it can.
 *
 * ## What it does
 *
 * Rows written before the currency columns existed hold an UNCONVERTED provider figure in `spend`
 * and `revenue` — Snapchat reports in the ad account's currency, and nothing recorded which that
 * was. The amounts are not discarded: they move to `*_original`, which is what they always were,
 * and the converted columns become NULL so every surface says «conversion unavailable» instead of
 * printing the number under the project's currency.
 *
 * `original_currency` is filled ONLY where it can be known — the creative's project must bind
 * exactly one account of that provider. Where a project binds several, the row's currency is
 * genuinely ambiguous and stays NULL, because a guessed currency is the same class of defect this
 * whole change exists to remove.
 */
final class BackfillCreativeMoneyProvenance
{
    /** @return array{moved:int,currencies:int} */
    public function execute(): array
    {
        /*
         * Guarded on `original_currency IS NULL` AND an untouched original, so a row this has
         * already converted is never processed twice — the second pass would move a NULL over a
         * real figure and lose it.
         */
        $moved = DB::update(
            'UPDATE creative_daily_metrics
                SET spend_original = NULLIF(spend, 0),
                    revenue_original = NULLIF(revenue, 0),
                    spend = NULL,
                    revenue = NULL
              WHERE original_currency IS NULL
                AND spend_original IS NULL
                AND revenue_original IS NULL
                AND (spend IS NOT NULL OR revenue IS NOT NULL)'
        );

        $currencies = DB::update(
            'UPDATE creative_daily_metrics AS m
                SET original_currency = u.currency
               FROM (
                    SELECT c.id AS creative_id, MIN(a.currency) AS currency
                      FROM external_creatives c
                      JOIN external_campaigns ec
                        ON ec.project_id = c.project_id
                      JOIN external_accounts a
                        ON a.id = ec.external_account_id
                       AND a.provider = c.provider
                     WHERE a.currency IS NOT NULL
                  GROUP BY c.id
                    HAVING COUNT(DISTINCT a.currency) = 1
               ) AS u
              WHERE m.creative_id = u.creative_id
                AND m.original_currency IS NULL
                AND (m.spend_original IS NOT NULL OR m.revenue_original IS NOT NULL)'
        );

        return ['moved' => $moved, 'currencies' => $currencies];
    }
}
