<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Enums\MarketingPath;
use Illuminate\Support\Carbon;

/**
 * §15.11 — what the dashboard says about the content, from the creatives the dashboard is already showing.
 *
 * ## It reads rows, not the database
 *
 * Every figure here comes from rows already shaped by `CreativeAnalysisController::present()`, which
 * is `CreativeMetrics` over `creative_daily_metrics` — the same service the library, the detail view,
 * the comparison and the client report read. This class does no SQL at all, which is the strongest
 * form of §15.17 available: there is no query here that could drift from the one behind the cards.
 * Change the pipeline and the dashboard moves with it, because it is the same numbers arranged
 * differently.
 *
 * ## Nothing is ranked across marketing paths
 *
 * «The best creative» is not a question with an answer when one creative was bought to be seen and
 * another to be bought from. So every ranking in this class is computed INSIDE a marketing path and
 * carries the path and the metric it used — including «best image» and «best video», which are the
 * two cards most likely to be read as a verdict on the whole account. An images-vs-videos comparison
 * that summed an awareness campaign's impressions into a sales campaign's ROAS would be the same
 * blended-CPA defect §14 exists to prevent, one level down.
 *
 * ## A winner needs enough evidence to be a winner
 *
 * A creative with 40 impressions and one lucky order has an untouchable ROAS and means nothing. Rank
 * only among creatives that cleared {@see self::MIN_IMPRESSIONS}; when none did — a new project, a
 * short window — answer from what there is and SAY it was thin, rather than either inventing
 * confidence or showing an empty card on a dashboard that has data.
 */
final class CreativePulse
{
    /** Below this, a ratio is noise. The same floor `CreativeFatigue` declines to judge below. */
    public const MIN_IMPRESSIONS = 1000.0;

    /** Movement smaller than this is ordinary variance, not growth or decline. */
    private const MIN_CHANGE = 0.10;

    /** How many rows a list section carries. The full count travels beside it — never a silent cut. */
    private const LIST_LIMIT = 6;

    public function __construct(private readonly CreativeMetrics $metrics) {}

    /**
     * @param  list<array<string, mixed>>  $rows  presented creatives, with `previous` attached
     * @return array<string, mixed>
     */
    public function build(array $rows, Carbon $from, Carbon $to): array
    {
        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();

        $withMetrics = array_values(array_filter($rows, static fn (array $r): bool => is_array($r['metrics'] ?? null)));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'previous_period' => [
                'from' => $prevTo->copy()->subDays($days - 1)->toDateString(),
                'to' => $prevTo->toDateString(),
            ],
            'totals' => [
                'creatives' => count($rows),
                'with_metrics' => count($withMetrics),
                // Named rather than subtracted in the UI: a creative the platform sent no figures for
                // in this window is a fact about the window, not a zero to be charted.
                'without_metrics' => count($rows) - count($withMetrics),
            ],
            'evidence' => [
                'min_impressions' => self::MIN_IMPRESSIONS,
                'min_change' => self::MIN_CHANGE,
            ],
            'best_by_objective' => $this->bestByObjective($withMetrics),
            'best_image' => $this->bestOfKind($withMetrics, 'image'),
            'best_video' => $this->bestOfKind($withMetrics, 'video'),
            'fastest_growing' => $this->moving($withMetrics, improved: true),
            'declining' => $this->moving($withMetrics, improved: false),
            'fatigue' => $this->fatigue($rows),
            'spend_by_kind' => $this->spendByKind($rows),
            'image_vs_video' => $this->imageVsVideo($withMetrics),
            'best_platform' => $this->bestPlatform($withMetrics),
            'freshness' => $this->freshness($rows),
        ];
    }

    // ---- rankings -----------------------------------------------------------------------------

    /**
     * The best creative for each objective present — each judged on its own path's decisive metric.
     *
     * By OBJECTIVE rather than by path, because that is the word on the campaign and the word the
     * operator filtered by; the metric still comes from the path, since that is what decides whether
     * a number is good.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function bestByObjective(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[(string) ($row['objective'] ?? '__none__')][] = $row;
        }

        $out = [];
        foreach ($groups as $objective => $members) {
            $axis = $this->decisive((string) ($members[0]['path'] ?? MarketingPath::Awareness->value), $members);
            $best = $this->best($members, $axis);

            if ($best === null) {
                continue;
            }

            $out[] = [
                'objective' => $objective === '__none__' ? null : $objective,
                'path' => $members[0]['path'] ?? null,
                'creatives' => count($members),
                'spend' => $this->sum($members, 'spend'),
            ] + $best;
        }

        // Ordered by the money each objective actually carries, so the reader meets the objective
        // most of the budget went to first rather than whichever sorted first alphabetically.
        usort($out, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));

        return $out;
    }

    /**
     * The best image, or the best video — one answer per marketing path, ordered by spend.
     *
     * Not one answer overall. «Best video» across an awareness path and a sales path has to pick one
     * metric for both, and whichever it picks makes the other path's video look bad for doing its
     * job. The heaviest-spending path is first, so the dashboard still leads with a single card.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function bestOfKind(array $rows, string $kind): array
    {
        $ofKind = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ($r['preview']['kind'] ?? null) === $kind,
        ));

        $byPath = [];
        foreach ($ofKind as $row) {
            $byPath[(string) ($row['path'] ?? MarketingPath::Awareness->value)][] = $row;
        }

        $out = [];
        foreach ($byPath as $path => $members) {
            $best = $this->best($members, $this->decisive($path, $members));

            if ($best === null) {
                continue;
            }

            $out[] = [
                'kind' => $kind,
                'path' => $path,
                'creatives' => count($members),
                'spend' => $this->sum($members, 'spend'),
            ] + $best;
        }

        usort($out, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));

        return $out;
    }

    /**
     * Which metric decides «best» on this path, and which direction wins.
     *
     * The conversion path prefers ROAS and falls back to cost per order, because a lead campaign
     * reports no revenue at all — ranking it by a metric every candidate is missing would return
     * «no answer» for a path that has a perfectly good one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{key: string, higher_wins: bool}
     */
    private function decisive(string $path, array $rows): array
    {
        return match ($path) {
            MarketingPath::Traffic->value => ['key' => 'ctr', 'higher_wins' => true],
            MarketingPath::Conversion->value => $this->anyReported($rows, 'roas')
                ? ['key' => 'roas', 'higher_wins' => true]
                : ['key' => 'cpa', 'higher_wins' => false],
            default => ['key' => 'cpm', 'higher_wins' => false],
        };
    }

    /**
     * The winner on one axis, with how thin the evidence behind it is.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{key: string, higher_wins: bool}  $axis
     * @return array<string, mixed>|null
     */
    private function best(array $rows, array $axis): ?array
    {
        $candidates = array_values(array_filter(
            $rows,
            fn (array $r): bool => is_numeric($r['metrics'][$axis['key']] ?? null),
        ));

        if ($candidates === []) {
            return null;
        }

        $evidenced = array_values(array_filter($candidates, fn (array $r): bool => $this->evidenced($r)));
        $pool = $evidenced === [] ? $candidates : $evidenced;

        $winner = null;
        foreach ($pool as $row) {
            $value = (float) $row['metrics'][$axis['key']];
            if ($winner === null) {
                $winner = $row;

                continue;
            }
            $best = (float) $winner['metrics'][$axis['key']];
            if ($axis['higher_wins'] ? $value > $best : $value < $best) {
                $winner = $row;
            }
        }

        /** @var array<string, mixed> $winner */
        return [
            'metric' => $axis['key'],
            'higher_wins' => $axis['higher_wins'],
            'value' => (float) $winner['metrics'][$axis['key']],
            'candidates' => count($candidates),
            'evidenced' => count($evidenced),
            // True when NOTHING cleared the floor, so the card can say the ranking is provisional
            // instead of presenting a fluke as a finding.
            'low_evidence' => $evidenced === [],
            'creative' => $winner,
        ];
    }

    /**
     * What moved, and by how much, against the period immediately before.
     *
     * Each creative is measured on its OWN path's metric, so a list of «fastest growing» can hold an
     * awareness creative whose CPM fell beside a sales creative whose ROAS rose. Both improved; they
     * did not improve at the same thing, which is why every row names its metric.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function moving(array $rows, bool $improved): array
    {
        $moves = [];

        foreach ($rows as $row) {
            $axis = $this->decisive((string) ($row['path'] ?? MarketingPath::Awareness->value), [$row]);
            $now = $row['metrics'][$axis['key']] ?? null;
            $before = $row['previous'][$axis['key']] ?? null;

            if (! is_numeric($now) || ! is_numeric($before) || (float) $before == 0.0) {
                continue;
            }

            // The floor applies to the CURRENT period only: a creative that has just started
            // delivering properly is exactly the growth this section exists to surface.
            if (! $this->evidenced($row)) {
                continue;
            }

            $change = ((float) $now - (float) $before) / abs((float) $before);
            $gain = $axis['higher_wins'] ? $change : -$change;

            if (abs($gain) < self::MIN_CHANGE || ($gain > 0) !== $improved) {
                continue;
            }

            $moves[] = [
                'metric' => $axis['key'],
                'higher_wins' => $axis['higher_wins'],
                'current' => (float) $now,
                'previous' => (float) $before,
                'change' => round($change, 4),
                'improvement' => round($gain, 4),
                'creative' => $row,
            ];
        }

        usort($moves, static fn (array $a, array $b): int => abs($b['improvement']) <=> abs($a['improvement']));

        return $this->capped($moves);
    }

    // ---- states -------------------------------------------------------------------------------

    /**
     * The fatigue picture: how many are in each state, who they are, and where money is still going.
     *
     * The alert is deliberately not «this is fatigued» — that is the badge on the card. It is
     * «this is fatigued AND it is still spending», which is the only version of the fact that names
     * something to do.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function fatigue(array $rows): array
    {
        $counts = [
            CreativeFatigue::IMPROVING => 0,
            CreativeFatigue::STABLE => 0,
            CreativeFatigue::WATCH => 0,
            CreativeFatigue::FATIGUED => 0,
            CreativeFatigue::INSUFFICIENT => 0,
        ];

        $buckets = [CreativeFatigue::FATIGUED => [], CreativeFatigue::WATCH => [], CreativeFatigue::INSUFFICIENT => []];

        foreach ($rows as $row) {
            $status = (string) ($row['fatigue']['status'] ?? CreativeFatigue::INSUFFICIENT);
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            if (array_key_exists($status, $buckets)) {
                $buckets[$status][] = $row;
            }
        }

        $spend = static fn (array $r): float => is_numeric($r['metrics']['spend'] ?? null) ? (float) $r['metrics']['spend'] : 0.0;

        foreach ($buckets as $status => $members) {
            usort($members, static fn (array $a, array $b): int => $spend($b) <=> $spend($a));
            $buckets[$status] = $members;
        }

        $alerts = array_values(array_filter(
            $buckets[CreativeFatigue::FATIGUED],
            static fn (array $r): bool => $spend($r) > 0.0,
        ));

        return [
            'counts' => $counts,
            'fatigued' => $this->capped($buckets[CreativeFatigue::FATIGUED]),
            'watch' => $this->capped($buckets[CreativeFatigue::WATCH]),
            'insufficient_data' => $this->capped($buckets[CreativeFatigue::INSUFFICIENT]),
            'alerts' => $this->capped(array_map(static fn (array $r): array => [
                'creative' => $r,
                'spend' => $spend($r),
                'signals' => $r['fatigue']['signals'] ?? [],
                'note_ar' => $r['fatigue']['note_ar'] ?? null,
                'note_en' => $r['fatigue']['note_en'] ?? null,
            ], $alerts)),
            'spend_at_risk' => array_sum(array_map($spend, $alerts)),
        ];
    }

    // ---- distributions ------------------------------------------------------------------------

    /**
     * Where the money went, by what the content IS — images, videos, carousels.
     *
     * Spend is the one figure that may be summed across objectives without lying: an awareness riyal
     * and a sales riyal are the same riyal. What must never be summed across them is a RESULT, which
     * is why this section reports spend and counts and stops there.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function spendByKind(array $rows): array
    {
        $kinds = [];

        foreach ($rows as $row) {
            $kind = (string) ($row['preview']['kind'] ?? 'other');
            $kinds[$kind] ??= ['kind' => $kind, 'spend' => null, 'creatives' => 0, 'spend_not_reported' => 0];
            $kinds[$kind]['creatives']++;

            $spend = $row['metrics']['spend'] ?? null;
            if (is_numeric($spend)) {
                $kinds[$kind]['spend'] = ($kinds[$kind]['spend'] ?? 0.0) + (float) $spend;
            } else {
                $kinds[$kind]['spend_not_reported']++;
            }
        }

        $total = array_sum(array_map(static fn (array $k): float => (float) ($k['spend'] ?? 0), $kinds));

        $out = array_values(array_map(static function (array $k) use ($total): array {
            // A share of nothing is not 0% — it is undefined, and a pie chart drawn from it would
            // show four zero-width slices as though the split had been measured.
            $k['share'] = $total > 0 && $k['spend'] !== null ? round($k['spend'] / $total, 4) : null;

            return $k;
        }, $kinds));

        usort($out, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));

        return $out;
    }

    /**
     * Images against videos — inside each marketing path, never across them.
     *
     * Both sides come through `CreativeMetrics::aggregate()`, so the CTR in this section is derived
     * by the same code as the CTR on a card. The comparison is offered only where BOTH sides exist:
     * a path that ran no video has no image-versus-video question to answer, and printing the image
     * column alone beside an empty one reads as videos performing at zero.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function imageVsVideo(array $rows): array
    {
        $paths = [];
        foreach ($rows as $row) {
            $kind = (string) ($row['preview']['kind'] ?? 'other');
            if ($kind !== 'image' && $kind !== 'video') {
                continue;
            }
            $paths[(string) ($row['path'] ?? MarketingPath::Awareness->value)][$kind][] = $row['metrics'];
        }

        $out = [];
        foreach ($paths as $path => $sides) {
            if (! isset($sides['image']) || ! isset($sides['video'])) {
                continue;
            }

            $case = MarketingPath::tryFrom($path) ?? MarketingPath::Awareness;

            $out[] = [
                'path' => $path,
                'headline_metrics' => $case->headlineMetrics(),
                'image' => $this->metrics->aggregate($sides['image']),
                'video' => $this->metrics->aggregate($sides['video']),
            ];
        }

        return $out;
    }

    /**
     * For a creative that runs on more than one platform, which platform ran it best (§15.11).
     *
     * Only grouped creatives can answer this: two DIFFERENT creatives on two platforms differ in
     * more than the platform, so naming a winner between them would credit the platform for the
     * content. A group is the same asset, which is what makes the comparison about placement.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bestPlatform(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groupId = $row['group_id'] ?? null;
            if ($groupId === null) {
                continue;
            }
            $groups[(string) $groupId][] = $row;
        }

        $out = [];
        foreach ($groups as $groupId => $members) {
            if (count($members) < 2) {
                continue;
            }

            $axis = $this->decisive((string) ($members[0]['path'] ?? MarketingPath::Awareness->value), $members);
            $best = $this->best($members, $axis);

            if ($best === null) {
                continue;
            }

            /*
             * A tie is an answer, and it is «the platform made no difference».
             *
             * Found by opening the demo agency: the same film ran on TikTok and Snapchat at CPMs of
             * 25.6532 and 25.6538, and the card crowned TikTok — beside two numbers that both print
             * as «25.65 SAR». A winner decided at a precision the reader cannot see is a verdict
             * invented by rounding, and on this card in particular it is one somebody could move a
             * budget on.
             *
             * So the tolerance is RELATIVE and generous: half a percent apart is the same result.
             * Two platforms differing by 0.5% of a CPM did not perform differently; they were
             * measured on slightly different days.
             */
            $top = $best['value'];
            $tolerance = abs($top) * 0.005;
            $sharing = 0;
            foreach ($members as $member) {
                $value = $member['metrics'][$axis['key']] ?? null;
                if (is_numeric($value) && abs((float) $value - $top) <= $tolerance) {
                    $sharing++;
                }
            }

            $out[] = [
                'group_id' => $groupId,
                'name' => $members[0]['name'] ?? null,
                'path' => $members[0]['path'] ?? null,
                'metric' => $axis['key'],
                'higher_wins' => $axis['higher_wins'],
                'winner' => $sharing > 1 ? null : ($best['creative']['provider'] ?? null),
                'tied' => $sharing > 1,
                'low_evidence' => $best['low_evidence'],
                'platforms' => array_map(fn (array $r): array => [
                    'creative_id' => $r['id'],
                    'provider' => $r['provider'],
                    'value' => is_numeric($r['metrics'][$axis['key']] ?? null) ? (float) $r['metrics'][$axis['key']] : null,
                    'spend' => is_numeric($r['metrics']['spend'] ?? null) ? (float) $r['metrics']['spend'] : null,
                    'evidenced' => $this->evidenced($r),
                ], $members),
            ];
        }

        usort($out, static fn (array $a, array $b): int => count($b['platforms']) <=> count($a['platforms']));

        return $this->capped($out);
    }

    /**
     * When each platform last answered, and what the figures behind this page are missing.
     *
     * A dashboard that cannot say how old its numbers are invites them to be read as live. This
     * reports the last sync per provider and counts what is absent rather than smoothing over it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function freshness(array $rows): array
    {
        $providers = [];
        $quality = [
            'previews_withheld' => 0, 'previews_expired' => 0, 'previews_unavailable' => 0,
            'without_metrics' => 0, 'insufficient_data' => 0, 'never_synced' => 0,
        ];

        foreach ($rows as $row) {
            $provider = (string) ($row['provider'] ?? 'unknown');
            $providers[$provider] ??= [
                'provider' => $provider, 'creatives' => 0, 'with_metrics' => 0,
                'without_metrics' => 0, 'last_synced_at' => null,
            ];
            $providers[$provider]['creatives']++;

            if (is_array($row['metrics'] ?? null)) {
                $providers[$provider]['with_metrics']++;
            } else {
                $providers[$provider]['without_metrics']++;
                $quality['without_metrics']++;
            }

            $synced = $row['freshness']['last_synced_at'] ?? null;
            if ($synced === null) {
                $quality['never_synced']++;
            } elseif ($providers[$provider]['last_synced_at'] === null || $synced > $providers[$provider]['last_synced_at']) {
                $providers[$provider]['last_synced_at'] = $synced;
            }

            match ((string) ($row['preview']['state'] ?? '')) {
                'withheld' => $quality['previews_withheld']++,
                'expired' => $quality['previews_expired']++,
                'unavailable' => $quality['previews_unavailable']++,
                default => null,
            };

            if (($row['fatigue']['status'] ?? null) === CreativeFatigue::INSUFFICIENT) {
                $quality['insufficient_data']++;
            }
        }

        $providers = array_values($providers);
        usort($providers, static fn (array $a, array $b): int => $b['creatives'] <=> $a['creatives']);

        $last = null;
        foreach ($providers as $provider) {
            if ($provider['last_synced_at'] !== null && ($last === null || $provider['last_synced_at'] > $last)) {
                $last = $provider['last_synced_at'];
            }
        }

        return ['last_synced_at' => $last, 'providers' => $providers, 'quality' => $quality];
    }

    // ---- helpers ------------------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function evidenced(array $row): bool
    {
        $impressions = $row['metrics']['impressions'] ?? null;

        return is_numeric($impressions) && (float) $impressions >= self::MIN_IMPRESSIONS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function anyReported(array $rows, string $key): bool
    {
        foreach ($rows as $row) {
            if (is_numeric($row['metrics'][$key] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $rows */
    private function sum(array $rows, string $key): ?float
    {
        $total = null;
        foreach ($rows as $row) {
            if (is_numeric($row['metrics'][$key] ?? null)) {
                $total = ($total ?? 0.0) + (float) $row['metrics'][$key];
            }
        }

        return $total;
    }

    /**
     * A list section: the rows shown, and how many there were.
     *
     * `total` is always the honest count. A section that shows six of nineteen fatigued creatives and
     * does not say nineteen is a section that reads as «six», which is the shape of under-reporting
     * that makes a dashboard trusted for the wrong reason.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{items: list<array<string, mixed>>, total: int, shown: int}
     */
    private function capped(array $rows): array
    {
        $items = array_slice($rows, 0, self::LIST_LIMIT);

        return ['items' => $items, 'total' => count($rows), 'shown' => count($items)];
    }
}
