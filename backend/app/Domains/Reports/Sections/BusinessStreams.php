<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Reports\Support\ReportScope;
use Illuminate\Support\Carbon;

/**
 * Advanced segmentation by operator-defined business streams — REPORT-SECTION-STREAMS-001.
 *
 * ## What replaced Direct against Blended
 *
 * A client report used to split its figures into «direct» and «blended» performance. That split is
 * the agency's buying methodology written into the client's document, and it led with it. It is now
 * gone from every client surface. A client who needs a split gets one only where an operator enabled
 * advanced segmentation, and then in the operator's own neutral words: «المبيعات عبر الإنترنت»,
 * «التوعية في الفروع», «الاستحواذ», «إعادة الاستهداف», or anything else — each mapped to platforms
 * and/or ad accounts. Never to campaigns, and never with a campaign name.
 *
 * ## Truth rules
 *
 * Each stream is the report's own engine narrowed further, so its figures are the same sums every
 * other section reads — ratios are recomputed from the stream's own sums, never averaged. A mapping
 * can only NARROW the report's ceiling: a platform or account outside the report's scope matches
 * nothing rather than widening the report. A platform or an ad account belongs to at most one stream
 * (refused on save), so streams never double-count; their sum is called the total only when
 * `coversTotal()` confirms every in-scope account is mapped.
 */
final class BusinessStreams
{
    /**
     * @param  list<array{key: string, label: string, providers: list<string>, account_ids: list<string>}>  $streams
     * @param  list<string>|null  $providerCeiling  the report's platforms; null means unbounded
     * @param  list<string>|null  $accountCeiling  the report's ad accounts; null means unbounded
     * @return list<array<string, mixed>>
     */
    public function build(MetricsAggregator $engine, array $streams, ?array $providerCeiling, ?array $accountCeiling, Carbon $from, Carbon $to, ?float $totalSpend): array
    {
        $out = [];

        foreach ($streams as $stream) {
            $narrowed = $engine;

            if ($stream['providers'] !== []) {
                $narrowed = $narrowed->forProviders($this->within($stream['providers'], $providerCeiling));
            } elseif ($providerCeiling !== null && $providerCeiling !== []) {
                $narrowed = $narrowed->forProviders($providerCeiling);
            }

            if ($stream['account_ids'] !== []) {
                $narrowed = $narrowed->forAccounts($this->within($stream['account_ids'], $accountCeiling));
            } elseif ($accountCeiling !== null && $accountCeiling !== []) {
                $narrowed = $narrowed->forAccounts($accountCeiling);
            }

            $totals = array_filter($narrowed->totals($from, $to), static fn ($v): bool => ! is_array($v));
            $spend = is_numeric($totals['spend'] ?? null) ? (float) $totals['spend'] : null;

            $out[] = [
                'key' => $stream['key'],
                'label' => $stream['label'],
                'figures' => $totals,
                'share_of_spend' => $spend !== null && $totalSpend !== null && $totalSpend > 0 ? round($spend / $totalSpend, 4) : null,
            ];
        }

        return $out;
    }

    /**
     * Whether every account with figures in the report's scope is mapped to some stream, directly or
     * through its whole platform. Only then is the sum of the streams the report's total.
     *
     * @param  list<array{key: string, label: string, providers: list<string>, account_ids: list<string>}>  $streams
     */
    public function coversTotal(MetricsAggregator $engine, array $streams, Carbon $from, Carbon $to): bool
    {
        if ($streams === []) {
            return false;
        }

        $providers = array_merge(...array_map(static fn (array $s): array => $s['providers'], $streams));
        $accounts = array_merge(...array_map(static fn (array $s): array => $s['account_ids'], $streams));

        foreach ($engine->byAccount($from, $to) as $row) {
            $mapped = in_array($row['provider'] ?? null, $providers, true)
                || ($row['account_id'] !== null && in_array($row['account_id'], $accounts, true));
            if (! $mapped) {
                return false;
            }
        }

        return true;
    }

    /**
     * The mapped members the ceiling allows; the impossible id when none are, so an out-of-scope
     * mapping matches nothing instead of lifting the filter.
     *
     * @param  list<string>  $asked
     * @param  list<string>|null  $ceiling
     * @return list<string>
     */
    private function within(array $asked, ?array $ceiling): array
    {
        if ($ceiling === null || $ceiling === []) {
            return $asked;
        }

        $kept = array_values(array_intersect($asked, $ceiling));

        return $kept === [] ? [ReportScope::IMPOSSIBLE] : $kept;
    }
}
