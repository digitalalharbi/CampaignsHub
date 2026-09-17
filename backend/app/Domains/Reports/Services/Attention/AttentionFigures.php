<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

use Illuminate\Support\Carbon;

/**
 * REPORT-RECOMMENDATION-BLOCKS-001 — the seam the attention findings read their figures through.
 *
 * The objective mapping a finding is judged under is being deepened in its own lane
 * (`report-objective-analytics`, around `ObjectivePerformance`). The detector must not care which
 * version of that mapping is live: it needs, per objective family and platform, the SUMS a ratio is
 * derived from, for a window. Anything that can answer that honestly can stand behind this seam.
 */
interface AttentionFigures
{
    /**
     * @return list<array{family: string, provider: string, spend: float, impressions: float, clicks: float, landing_page_views: float, results: float, revenue: float, spend_withheld_rows: int, revenue_withheld_rows: int}>
     */
    public function byFamilyAndPlatform(Carbon $from, Carbon $to): array;
}
