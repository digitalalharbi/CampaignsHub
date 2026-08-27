<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;

/**
 * Ranks "top items" (campaigns today; ad-level once connectors provide it) by objective-appropriate
 * criteria and returns a human-readable REASON for each — never an opaque score.
 *
 * WHICH criterion belongs to which objective is no longer decided here: `RankingMetric` owns that, so
 * this report, the Pulse and the email digest cannot disagree about what «best» means. The caller
 * decides the level; this orders by the canonical metric and explains the result in words.
 */
final class CreativeRankingService
{
    /** @param list<array<string, mixed>> $items rows with metric keys (spend, roas, cpa, ctr, ...) */
    public function rank(string $objective, array $items, int $limit = 5): array
    {
        $items = array_values(array_filter($items, fn ($i) => (float) ($i['spend'] ?? 0) > 0));
        $avgCpa = $this->average($items, 'cpa');
        $avgCtr = $this->average($items, 'ctr');

        [$sortKey, $direction, $reason] = $this->strategy($objective);

        usort($items, function ($a, $b) use ($sortKey, $direction) {
            $va = $a[$sortKey] ?? null;
            $vb = $b[$sortKey] ?? null;
            // Nulls always sort last.
            if ($va === null && $vb === null) {
                return 0;
            }
            if ($va === null) {
                return 1;
            }
            if ($vb === null) {
                return -1;
            }

            return $direction === 'desc' ? $vb <=> $va : $va <=> $vb;
        });

        return array_map(fn ($i) => $i + ['reason' => $reason($i, $avgCpa, $avgCtr)], array_slice($items, 0, $limit));
    }

    /**
     * REPORT-WORST-CREATIVES-001 — the creatives spending money and returning least.
     *
     * A report that only lists winners tells a reader what to keep and never what to stop, and
     * stopping is the cheaper decision. This is `rank()` read from the other end, by the same
     * objective-aware metric — an awareness creative is judged on CPM, a sales one on ROAS — so
     * «worst» always means worst at the thing the money was spent to buy.
     *
     * A creative whose ranking metric is NULL is excluded rather than placed last. It sorts last in
     * `rank()` because an absence must not win, and by the same reasoning it must not lose either:
     * «the platform reported no ROAS for this creative» is not «this creative returned nothing», and
     * naming it the worst performer in a client's report would be a claim nobody measured.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    public function worst(string $objective, array $items, int $limit = 5): array
    {
        [$sortKey, $direction] = $this->strategy($objective);

        // Spending, and actually measured on the metric it is being judged by.
        $measured = array_values(array_filter(
            $items,
            fn ($i) => (float) ($i['spend'] ?? 0) > 0 && ($i[$sortKey] ?? null) !== null,
        ));

        if ($measured === []) {
            return [];
        }

        $avgCpa = $this->average($measured, 'cpa');
        $avgCtr = $this->average($measured, 'ctr');

        // The same order as `rank()`, reversed: worst is the far end of «best».
        usort($measured, function ($a, $b) use ($sortKey, $direction) {
            $cmp = ($a[$sortKey] ?? 0) <=> ($b[$sortKey] ?? 0);

            return $direction === 'desc' ? $cmp : -$cmp;
        });

        $reason = $this->weakness($objective);

        return array_map(
            fn ($i) => $i + ['reason' => $reason($i, $avgCpa, $avgCtr)],
            array_slice($measured, 0, $limit),
        );
    }

    /**
     * Why this creative is on the list — the figure that put it there, not a verdict.
     *
     * @return callable(array<string, mixed>, ?float, ?float): string
     */
    private function weakness(string $objective): callable
    {
        return match ($objective) {
            'awareness', 'video' => fn ($i) => sprintf('أعلى تكلفة ألف ظهور (CPM %s) في هذه الفترة.', $this->fmt($i['cpm'] ?? null)),
            'traffic' => fn ($i, $avgCpa, $avgCtr) => sprintf(
                'أقل CTR (%s)%s.',
                $this->pct($i['ctr'] ?? null),
                $avgCtr && ($i['ctr'] ?? 0) < $avgCtr ? ' دون متوسط الحملة' : '',
            ),
            'leads', 'app_installs' => fn ($i, $avgCpa) => sprintf(
                'أعلى تكلفة نتيجة (CPA %s)%s.',
                $this->fmt($i['cpa'] ?? null),
                $avgCpa && ($i['cpa'] ?? 0) > $avgCpa ? ' فوق متوسط الحملة' : '',
            ),
            default => fn ($i, $avgCpa) => sprintf(
                'أقل عائد على الإنفاق (ROAS %s×)%s.',
                $this->num($i['roas'] ?? null),
                $avgCpa && ($i['cpa'] ?? 0) > $avgCpa ? ' مع CPA فوق المتوسط' : '',
            ),
        };
    }

    /**
     * The metric, its direction, and the sentence that explains the verdict — CREATIVE-RANK-001.
     *
     * The first two now come from `RankingMetric`, the one place that knows what an objective is
     * judged on and which way is better. This class decided both privately, and so did
     * `CreativePulse` and `DigestCreatives`, with three different metric sets between them — `leads`
     * in one of the three, `cpl` in none. «Best creative» was a different question depending on which
     * screen asked it.
     *
     * What stays here is the REASON: prose, in Arabic, naming the figure that produced the verdict.
     * That is a reporting concern and belongs to the report rather than to the ranking contract.
     *
     * The objective strings arriving here are the report's own vocabulary — `app_installs` rather
     * than `app` — so they are mapped to the canonical family instead of being assumed to match.
     *
     * @return array{0:string,1:string,2:callable} sort key, direction, reason builder
     */
    private function strategy(string $objective): array
    {
        $family = ObjectiveFamily::tryFrom($objective) ?? match ($objective) {
            'app_installs' => ObjectiveFamily::App,
            'conversions', 'purchases' => ObjectiveFamily::Sales,
            default => ObjectiveFamily::Sales,
        };

        $key = RankingMetric::forObjective($family)['primary'] ?? 'roas';
        $direction = RankingMetric::of($key)->direction === RankingDirection::LowerIsBetter ? 'asc' : 'desc';

        $reason = match ($family) {
            ObjectiveFamily::Awareness => fn ($i) => sprintf('أقل تكلفة ألف ظهور (CPM %s).', $this->fmt($i['cpm'] ?? null)),
            ObjectiveFamily::Video => fn ($i) => sprintf('أقل تكلفة مشاهدة (%s).', $this->fmt($i['cost_per_view'] ?? null)),
            ObjectiveFamily::Traffic => fn ($i) => sprintf('أعلى CTR (%s) بتكلفة نقرة %s.', $this->pct($i['ctr'] ?? null), $this->fmt($i['cpc'] ?? null)),
            ObjectiveFamily::Leads => fn ($i) => sprintf('أقل تكلفة عميل محتمل (CPL %s).', $this->fmt($i['cpl'] ?? null)),
            ObjectiveFamily::App => fn ($i) => sprintf('أقل تكلفة تثبيت (CPI %s).', $this->fmt($i['cpi'] ?? null)),
            ObjectiveFamily::Engagement => fn ($i) => sprintf('أعلى معدل تفاعل (%s).', $this->pct($i['engagement_rate'] ?? null)),
            default => fn ($i, $avgCpa = null) => sprintf('أعلى ROAS (%s×)%s.', $this->num($i['roas'] ?? null), $avgCpa && ($i['cpa'] ?? INF) < $avgCpa ? ' مع CPA أقل من المتوسط' : ''),
        };

        return [$key, $direction, $reason];
    }

    private function average(array $items, string $key): ?float
    {
        $vals = array_filter(array_map(fn ($i) => $i[$key] ?? null, $items), fn ($v) => $v !== null);

        return $vals === [] ? null : array_sum($vals) / count($vals);
    }

    private function fmt(?float $v): string
    {
        return $v === null ? '—' : number_format($v, $v < 10 ? 2 : 0);
    }

    private function num(?float $v): string
    {
        return $v === null ? '—' : number_format($v, 2);
    }

    private function pct(?float $v): string
    {
        return $v === null ? '—' : number_format($v * 100, 2).'%';
    }
}
