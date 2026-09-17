<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

use App\Domains\Reports\Models\ReportAttentionDecision;
use Illuminate\Support\Carbon;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the `recommendations` section's one server-side model.
 *
 * Every surface that shows the section gets its items from `items()` and its client cut from
 * `AttentionAudience::forClient()` with `decisions()`: the live link at request time, the snapshot at
 * generation (and again, against the CURRENT decisions, every time a client document is served).
 */
final class ReportAttention
{
    /**
     * Every finding for the window, judged against the previous window of equal length.
     *
     * @return list<array<string,mixed>>
     */
    public function items(AttentionFigures $figures, Carbon $from, Carbon $to, string $currency): array
    {
        $days = (int) round($from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay())) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        return (new AttentionFindings)->build(
            $figures->byFamilyAndPlatform($from, $to),
            $figures->byFamilyAndPlatform($prevFrom, $prevTo),
            $currency,
        );
    }

    /**
     * The operator's current decisions for a project, read without the ambient tenant: a shared
     * link has no session, so the tenant is stated explicitly rather than assumed.
     *
     * @return array<string,string>
     */
    public function decisions(string $tenantId, string $projectId): array
    {
        if ($tenantId === '' || $projectId === '') {
            return [];
        }

        return ReportAttentionDecision::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('decision', AttentionAudience::DECISIONS)
            ->pluck('decision', 'item_key')
            ->all();
    }
}
