<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\CampaignObjective;
use App\Domains\Campaigns\Enums\MarketingPath;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a creative's numbers mean, and which of them the platform actually sent (§15.4, §15.5, §15.15).
 *
 * ## The two rules this class exists to keep
 *
 * **1. A metric nobody reported is not zero.** `creative_daily_metrics` stores video columns as NULL
 * when the provider does not report them, and this class carries that distinction all the way to the
 * response as `null` plus a `reported` map. A completion rate of 0% beside 40,000 impressions reads
 * as a catastrophic video; «Not provided» reads as what actually happened. SQL makes this easy to get
 * wrong — `SUM()` over all-NULL is NULL, but `COALESCE(SUM(x), 0)` silently invents the zero — so the
 * sums here are deliberately un-coalesced and the null is preserved.
 *
 * **2. A creative is judged by its campaign's objective.** An awareness video has no CPA, and
 * printing one for it is not a harmless extra column: it is a terrible number attached to content
 * that was never asked to sell, and it is what makes somebody switch off the top of their funnel.
 * `headline()` returns the metrics that mean something for the creative's marketing path, and
 * `comparable()` refuses to rank two creatives on one axis when they are doing different jobs.
 */
final class CreativeMetrics
{
    /**
     * Every column summed per creative. Keys are the response's own names.
     *
     * NOT wrapped in COALESCE: a null sum is the answer when the provider reported nothing, and this
     * is the one place in the aggregation where losing that distinction costs the reader the truth.
     */
    private const SUMS = [
        'spend' => 'spend',
        'impressions' => 'impressions',
        'clicks' => 'clicks',
        'conversions' => 'conversions',
        'revenue' => 'revenue',
        'add_to_cart' => 'add_to_cart',
        'checkout' => 'checkout',
        'purchases' => 'purchases',
        'landing_page_views' => 'landing_page_views',
        'engagements' => 'engagements',
        'reach' => 'reach',
        'video_views' => 'video_views',
        'video_views_2s' => 'video_views_2s',
        'video_views_3s' => 'video_views_3s',
        'video_views_6s' => 'video_views_6s',
        'video_p25' => 'video_p25',
        'video_p50' => 'video_p50',
        'video_p75' => 'video_p75',
        'video_p100' => 'video_p100',
        'video_completions' => 'video_completions',
    ];

    /**
     * Totals per creative for a window, with derived KPIs and a map of what was actually reported.
     *
     * @param  list<string>  $creativeIds
     * @return array<string, array<string, mixed>> creative id => figures
     */
    public function forCreatives(array $creativeIds, Carbon $from, Carbon $to): array
    {
        if ($creativeIds === []) {
            return [];
        }

        $select = ['creative_id'];
        foreach (self::SUMS as $alias => $column) {
            $select[] = "SUM({$column}) AS {$alias}";
        }
        // Frequency is an average of a ratio, not a sum: adding daily frequencies would produce a
        // number that grows with the length of the window and means nothing.
        $select[] = 'AVG(frequency) AS frequency';
        $select[] = 'AVG(video_avg_watch_seconds) AS video_avg_watch_seconds';
        $select[] = 'COUNT(DISTINCT metric_date) AS active_days';

        $rows = DB::table('creative_daily_metrics')
            ->whereIn('creative_id', $creativeIds)
            ->whereBetween('metric_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('creative_id')
            ->selectRaw(implode(', ', $select))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->creative_id] = $this->shape((array) $row);
        }

        return $out;
    }

    /**
     * One row's figures: the raw sums, the derived KPIs, and what the provider actually reported.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        $num = static fn (string $key): ?float => $row[$key] === null ? null : (float) $row[$key];

        $spend = $num('spend');
        $impressions = $num('impressions');
        $clicks = $num('clicks');
        $conversions = $num('conversions');
        $revenue = $num('revenue');
        $videoViews = $num('video_views');

        $figures = [];
        foreach (array_keys(self::SUMS) as $key) {
            $figures[$key] = $num($key);
        }

        $figures['frequency'] = $num('frequency');
        $figures['video_avg_watch_seconds'] = $num('video_avg_watch_seconds');
        $figures['active_days'] = (int) ($row['active_days'] ?? 0);

        // Derived KPIs. Every one is null when its denominator is missing or zero — a ratio over
        // nothing is «there is nothing to divide», and 0 reads as «it costs nothing».
        $figures['ctr'] = $this->ratio($clicks, $impressions);
        $figures['cpc'] = $this->ratio($spend, $clicks);
        $figures['cpm'] = $impressions ? $this->ratio($spend, $impressions / 1000) : null;
        $figures['cpa'] = $this->ratio($spend, $conversions);
        $figures['roas'] = $this->ratio($revenue, $spend);
        $figures['conversion_rate'] = $this->ratio($conversions, $clicks);
        $figures['aov'] = $this->ratio($revenue, $conversions);
        $figures['cost_per_view'] = $this->ratio($spend, $videoViews);
        $figures['view_rate'] = $this->ratio($videoViews, $impressions);
        $figures['completion_rate'] = $this->ratio($num('video_p100') ?? $num('video_completions'), $videoViews);
        $figures['hook_rate'] = $this->ratio($num('video_views_3s'), $impressions);

        /*
         * The two headline metrics the sales path names but this service never produced.
         *
         * `MarketingPath::headlineMetrics()` asks the conversion path for `orders` and the traffic
         * path for `cost_per_lpv`. Neither key existed here, and an absent key reads as «no data» —
         * so a sales creative with 850 orders showed «Orders: No data» on the row that is supposed to
         * carry its most important figure, and two of the seven sales headlines were dead.
         *
         * `orders` is `conversions` under the name the marketing paths use, kept as an alias rather
         * than renamed: `conversions` is the column, the canonical metric and what every other
         * surface reads, and one concept with two names is better than a rename that leaves half the
         * system pointing at the old one.
         */
        $figures['orders'] = $conversions;
        $figures['cost_per_lpv'] = $this->ratio($spend, $num('landing_page_views'));

        /*
         * What the platform actually sent.
         *
         * The frontend needs this to tell «0» from «not reported», and it cannot infer it from the
         * value alone: a genuine zero and a missing metric both arrive as falsy in JavaScript.
         */
        $figures['reported'] = [];
        // `orders` shares `conversions`' answer, because it shares its column. Without this it is
        // absent from the map and renders as «no data» rather than «not provided» on an awareness
        // creative, which is the weaker of the two true statements.
        $figures['reported']['orders'] = $row['conversions'] !== null;
        foreach (array_keys(self::SUMS) as $key) {
            $figures['reported'][$key] = $row[$key] !== null;
        }
        $figures['reported']['frequency'] = $row['frequency'] !== null;
        $figures['reported']['video_avg_watch_seconds'] = $row['video_avg_watch_seconds'] !== null;

        return $figures;
    }

    /** A ratio, or null when there is nothing to divide by. Never 0 — see the class note. */
    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        if ($numerator === null || $denominator === null || $denominator == 0.0) {
            return null;
        }

        return round($numerator / $denominator, 4);
    }

    /**
     * The metrics that mean something for this creative, given the job its campaign was doing.
     *
     * Delegates to `MarketingPath::headlineMetrics()` rather than keeping a second list: the report
     * layouts read the same source, and two lists that disagree would mean a creative judged one way
     * on the dashboard and another in the client's report.
     *
     * @return list<string>
     */
    public function headline(?string $objective): array
    {
        $path = $this->pathFor($objective);

        $metrics = $path->headlineMetrics();

        // Video adds its own headline figures on any path — a video's hook and completion matter
        // whether it was bought for reach or for sales.
        return $path === MarketingPath::Awareness
            ? array_values(array_unique([...$metrics, 'video_views', 'view_rate', 'completion_rate', 'cost_per_view']))
            : $metrics;
    }

    public function pathFor(?string $objective): MarketingPath
    {
        $case = $objective === null ? null : CampaignObjective::tryFrom($objective);

        return $case?->path() ?? MarketingPath::Awareness;
    }

    /**
     * Whether two creatives may be ranked against each other on one axis, and why not when they cannot.
     *
     * §15.7 forbids declaring an overall «winner» between content serving different objectives. The
     * honest comparison is per-metric — best reach, best CTR, best CPA — and this returns the reason
     * so the UI can say it rather than silently dropping the verdict.
     *
     * @return array{comparable: bool, reason: string|null, reason_ar: string|null}
     */
    public function comparable(?string $objectiveA, ?string $objectiveB): array
    {
        $pathA = $this->pathFor($objectiveA);
        $pathB = $this->pathFor($objectiveB);

        if ($pathA === $pathB) {
            return ['comparable' => true, 'reason' => null, 'reason_ar' => null];
        }

        return [
            'comparable' => false,
            'reason' => "These creatives were bought for different jobs ({$pathA->value} vs {$pathB->value}), so one overall winner would be misleading. Compare them metric by metric instead.",
            'reason_ar' => 'المحتويان اشتُريا لهدفين مختلفين ('.$pathA->labels()['ar'].' مقابل '.$pathB->labels()['ar'].')، فإعلان فائز واحد سيكون مضلّلًا. قارنهما مؤشرًا بمؤشر.',
        ];
    }
}
