<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\ReportScope;
use Illuminate\Support\Str;

/**
 * REPORT-SOURCE-ISOLATION-001 — a stored report is read back inside the sources it was made for.
 *
 * ## Why a guard at READ time
 *
 * The generator now bounds the content section by the report's ad accounts. Copies stored before that hold the creatives of
 * accounts the report never named, and a stored copy is a client's document: it is not rewritten, deleted or regenerated on
 * anyone's behalf here. What can be done without touching it is to not SERVE the rows that are provably outside the report's
 * own sources. The stored `data` stays byte for byte what it was, so the evidence and any later repair keep their input.
 *
 * ## What it removes, and what it leaves
 *
 * Only a report whose scope names ad accounts is read this way; one that names none has no account ceiling to hold it to.
 * Every row anywhere in the document whose `id` or `creative_id` is a creative that exists, in the report's tenant, and does
 * NOT run under one of those accounts in the report's project is dropped: the roster, the ranked and weakest lists, the
 * objective and platform groups, and any evidence row that repeats one. A creative that no longer exists cannot be placed on
 * either side and is left as stored. Ids that are not creatives (campaigns, platforms) match nothing and are untouched.
 *
 * The sentences written about the removed rows (`ads_reading`) are dropped with them, the count of what ran is lowered by what
 * was removed, and `creatives_source_contained` states how many, so the page never claims more than it lists.
 */
final class ReportSourceContainment
{
    private const ID_KEYS = ['id', 'creative_id'];

    /**
     * @param  array<string, mixed>  $data  the stored snapshot, before any audience view
     * @return array<string, mixed>
     */
    public function apply(array $data, Report $report): array
    {
        $accounts = ReportScope::fromReport(is_array($report->scope) ? $report->scope : null, (string) $report->project_id)->accountIds;
        if ($accounts === [] || $data === []) {
            return $data;
        }

        $named = [];
        self::collect($data, $named);
        if ($named === []) {
            return $data;
        }

        $ids = array_keys($named);
        $existing = ExternalCreative::withoutGlobalScopes()
            ->where('external_creatives.tenant_id', $report->tenant_id)
            ->whereIn('external_creatives.id', $ids)
            ->pluck('external_creatives.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        if ($existing === []) {
            return $data;
        }

        $inside = ExternalCreative::withoutGlobalScopes()
            ->where('external_creatives.tenant_id', $report->tenant_id)
            ->where('external_creatives.project_id', $report->project_id)
            ->whereIn('external_creatives.id', $existing)
            ->tap(static fn ($q) => CreativeRows::whereRanUnderAccounts($q, $accounts))
            ->pluck('external_creatives.id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        $outside = array_flip(array_diff($existing, $inside));
        if ($outside === []) {
            return $data;
        }

        $rosterBefore = is_array($data['ads_roster'] ?? null) ? count($data['ads_roster']) : 0;
        $data = self::without($data, $outside);
        $removedFromRoster = $rosterBefore - (is_array($data['ads_roster'] ?? null) ? count($data['ads_roster']) : 0);

        // The reading was written about the lists as they were stored; with rows gone it describes ads the page no longer shows.
        if (array_key_exists('ads_reading', $data)) {
            $data['ads_reading'] = null;
        }
        if (is_numeric($data['creatives_in_scope'] ?? null)) {
            $data['creatives_in_scope'] = max(0, (int) $data['creatives_in_scope'] - $removedFromRoster);
        }
        $data['creatives_source_contained'] = count($outside);

        return $data;
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, true>  $named
     */
    private static function collect(array $node, array &$named): void
    {
        foreach (self::ID_KEYS as $key) {
            if (is_string($node[$key] ?? null) && Str::isUuid($node[$key])) {
                $named[$node[$key]] = true;
            }
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                self::collect($value, $named);
            }
        }
    }

    /**
     * @param  array<int|string, mixed>  $node
     * @param  array<string, int>  $outside
     * @return array<int|string, mixed>
     */
    private static function without(array $node, array $outside): array
    {
        $isList = array_is_list($node);
        $out = [];

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                if (self::names($value, $outside)) {
                    continue;
                }
                $value = self::without($value, $outside);
            }
            $out[$key] = $value;
        }

        return $isList ? array_values($out) : $out;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @param  array<string, int>  $outside
     */
    private static function names(array $row, array $outside): bool
    {
        foreach (self::ID_KEYS as $key) {
            if (is_string($row[$key] ?? null) && isset($outside[$row[$key]])) {
                return true;
            }
        }

        return false;
    }
}
