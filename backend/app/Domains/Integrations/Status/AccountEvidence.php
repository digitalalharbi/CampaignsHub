<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use Illuminate\Support\Facades\DB;

/**
 * KARAM-SYNC-HONESTY-001 — the stored facts a status needs that no run records.
 *
 * A sync that succeeded and stored a figure it could not convert (no exchange rate yet) ends `success`,
 * and a sync of a campaign with no ad groups ends `no_data`: both are true, and both read as «all is
 * well» unless somebody asks what is actually stored. This asks, in three grouped queries for a whole
 * list of accounts, and writes nothing.
 */
final class AccountEvidence
{
    /**
     * @param  list<string>  $accountIds
     * @return array<string, array{withheld_rows:int, withheld_currency:?string, ad_sets:int, has_delivery:bool}>
     */
    public static function load(string $tenantId, array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $out = [];
        foreach ($accountIds as $id) {
            $out[$id] = ['withheld_rows' => 0, 'withheld_currency' => null, 'ad_sets' => 0, 'has_delivery' => false];
        }

        $withheld = DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $accountIds)
            ->where('is_demo', false)
            ->whereNull('value')
            ->whereNotNull('original_amount')
            ->whereNotNull('original_currency')
            ->groupBy('external_account_id', 'original_currency')
            ->selectRaw('external_account_id, original_currency, COUNT(*) AS n')
            ->orderByDesc('n')
            ->get();
        foreach ($withheld as $row) {
            $id = (string) $row->external_account_id;
            $out[$id]['withheld_rows'] += (int) $row->n;
            $out[$id]['withheld_currency'] ??= strtoupper((string) $row->original_currency);
        }

        $adSets = DB::table('external_ad_sets')
            ->join('external_campaigns', 'external_campaigns.id', '=', 'external_ad_sets.external_campaign_id')
            ->where('external_ad_sets.tenant_id', $tenantId)
            ->whereIn('external_campaigns.external_account_id', $accountIds)
            ->where('external_ad_sets.is_demo', false)
            ->groupBy('external_campaigns.external_account_id')
            ->selectRaw('external_campaigns.external_account_id AS account_id, COUNT(*) AS n')
            ->get();
        foreach ($adSets as $row) {
            $out[(string) $row->account_id]['ad_sets'] = (int) $row->n;
        }

        $delivered = DB::table('daily_metrics')
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $accountIds)
            ->where('is_demo', false)
            ->distinct()
            ->pluck('external_account_id');
        foreach ($delivered as $id) {
            $out[(string) $id]['has_delivery'] = true;
        }

        return $out;
    }
}
