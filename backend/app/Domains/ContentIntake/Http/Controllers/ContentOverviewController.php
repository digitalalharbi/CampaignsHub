<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Http\Controllers;

use App\Domains\Campaigns\Creative\CreativeRanking;
use App\Domains\Campaigns\Creative\RankingDirection;
use App\Domains\Campaigns\Creative\RankingMetric;
use App\Domains\Campaigns\Enums\ObjectiveFamily;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\Campaigns\Support\ObjectiveResult;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CONTENT-LIBRARY-V2-001 — the library's overview: a platform × objective matrix, the best and the weakest content of each
 * cell, and the fatigue indicators, over the SAME filtered, reach-bound scope the library lists.
 *
 * It adds no ranking and no fatigue rule of its own: rows are ordered by the one canonical {@see CreativeRanking} on the
 * objective's own metric, and the fatigue verdict is the one `CreativeFatigue` already gives the cards. «Why» is a measured
 * gap: the item's value against the average of the same objective + platform cell, and the share of the cell's measured
 * spend it carried (only when every row of the cell is in one comparable currency). A cell with fewer than MIN items is
 * counted but not ranked — no verdict from two points.
 */
final class ContentOverviewController extends Controller
{
    private const MAX_ROWS = 200;

    private const MIN = 3;

    public function __construct(private readonly CreativeRows $rows, private readonly CreativeRanking $ranking) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('campaigns.view'), 403);
        [$from, $to] = $this->rows->window(
            $request->filled('from') ? $request->string('from')->toString() : null,
            $request->filled('to') ? $request->string('to')->toString() : null,
        );

        $query = ExternalCreative::query();
        $this->rows->applyReach($query, $request->user());
        $this->rows->applyFilters($query, $request->only([
            'provider', 'providers', 'format', 'formats', 'status', 'statuses', 'campaign_ids', 'ad_set_ids', 'ad_ids',
            'project_ids', 'client_ids', 'search', 'kinds', 'objectives', 'paths',
        ]));
        $this->rows->applyPeriodActivity($query, $from, $to);
        $total = (clone $query)->count();
        $creatives = $this->rows->applySort($query, 'spend', $from, $to)->limit(self::MAX_ROWS)->get();
        $present = $this->rows->present($creatives, $from, $to, true, true);

        $projectOf = $creatives->pluck('project_id', 'id');
        $cells = [];
        $fatigue = ['improving' => 0, 'stable' => 0, 'watch' => 0, 'fatigued' => 0, 'insufficient_data' => 0];
        $flagged = [];
        foreach ($present as $r) {
            $r['project_id'] = (string) ($projectOf[$r['id']] ?? '');
            $family = ObjectiveResult::familyOf($r['objective'] ?? null);
            $cells[(string) $r['provider'].'|'.$family->value][] = $r + ['_family' => $family];
            $status = (string) ($r['fatigue']['status'] ?? 'insufficient_data');
            $fatigue[$status] = ($fatigue[$status] ?? 0) + 1;
            if (in_array($status, ['watch', 'fatigued'], true)) {
                $flagged[] = [
                    'id' => (string) $r['id'], 'name' => (string) $r['name'], 'provider' => (string) $r['provider'], 'status' => $status,
                    'signals' => array_slice((array) ($r['fatigue']['signals'] ?? []), 0, 4),
                    'frequency' => $r['metrics']['frequency'] ?? null, 'ctr' => $r['metrics']['ctr'] ?? null,
                    'reason_ar' => $r['fatigue']['note_ar'] ?? ($r['fatigue']['reason_ar'] ?? null),
                    'reason_en' => $r['fatigue']['note_en'] ?? ($r['fatigue']['reason_en'] ?? null),
                ];
            }
        }
        usort($flagged, static fn (array $a, array $b): int => [$a['status'] === 'fatigued' ? 0 : 1] <=> [$b['status'] === 'fatigued' ? 0 : 1]);

        $matrix = [];
        foreach ($cells as $key => $members) {
            [$provider, $familyValue] = explode('|', $key);
            $family = $members[0]['_family'];
            $cell = [
                'provider' => $provider, 'family' => $familyValue, 'count' => count($members),
                // The raw objective values of this cell — what the library's `objectives` filter accepts.
                'objectives' => array_values(array_unique(array_filter(array_map(static fn (array $m): ?string => $m['objective'] ?? null, $members)))),
                'fatigued' => count(array_filter($members, static fn (array $m): bool => ($m['fatigue']['status'] ?? null) === 'fatigued')),
                'watch' => count(array_filter($members, static fn (array $m): bool => ($m['fatigue']['status'] ?? null) === 'watch')),
                'metric' => null, 'average' => null, 'previous_average' => null, 'best' => null, 'worst' => null, 'ranked' => 0,
            ];
            if ($family !== ObjectiveFamily::Unknown && count($members) >= self::MIN) {
                $flat = array_map(static fn (array $m): array => array_merge($m, (array) ($m['metrics'] ?? [])), $members);
                $metric = $this->ranking->resolveMetric($flat, $family);
                if ($metric !== null) {
                    $ranked = $this->ranking->rank($flat, $family, $metric)['ranked'];
                    $cell['metric'] = $metric;
                    $cell['higher_is_better'] = RankingMetric::of($metric)->direction === RankingDirection::HigherIsBetter;
                    $cell['ranked'] = count($ranked);
                    $cell['average'] = $this->avg($ranked, $metric);
                    $cell['previous_average'] = $this->avg(array_map(static fn (array $m): array => (array) ($m['previous'] ?? []), $ranked), $metric);
                    if (count($ranked) >= self::MIN) {
                        $cell['best'] = $this->why($ranked[0], $ranked, $metric);
                        $cell['worst'] = $this->why($ranked[count($ranked) - 1], $ranked, $metric);
                    }
                }
            }
            $matrix[] = $cell;
        }
        usort($matrix, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return ApiResponse::success([
            'matrix' => $matrix,
            'fatigue' => $fatigue,
            'flagged' => array_slice($flagged, 0, 8),
            'read' => count($present),
            'in_scope' => $total,
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ], 'Content overview.');
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $ranked
     * @return array<string, mixed>
     */
    private function why(array $row, array $ranked, string $metric): array
    {
        $others = array_values(array_filter($ranked, static fn (array $r): bool => $r['id'] !== $row['id']));
        $avg = $this->avg($others, $metric);
        $value = (float) $row[$metric];
        // Spend is converted into each PROJECT's currency, so a share is only meaningful inside one project.
        $projects = array_unique(array_map(static fn (array $r): string => (string) ($r['project_id'] ?? ''), $ranked));
        $allSpend = ! array_any($ranked, static fn (array $r): bool => ! is_numeric($r['metrics']['spend'] ?? null));
        $spend = array_sum(array_map(static fn (array $r): float => is_numeric($r['metrics']['spend'] ?? null) ? (float) $r['metrics']['spend'] : 0.0, $ranked));
        $mine = is_numeric($row['metrics']['spend'] ?? null) ? (float) $row['metrics']['spend'] : null;

        return [
            'id' => (string) $row['id'], 'name' => (string) $row['name'], 'format' => $row['format'] ?? null, 'kind' => $row['kind'] ?? null,
            'value' => $value, 'average' => $avg,
            'delta_percent' => $avg !== null && $avg > 0 ? round(($value - $avg) / $avg * 100, 1) : null,
            // A share only where one project's currency makes the sum meaningful; otherwise it is withheld, not guessed.
            'spend_share' => count($projects) === 1 && $projects[0] !== '' && $allSpend && $spend > 0 && $mine !== null ? round($mine / $spend, 4) : null,
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function avg(array $rows, string $metric): ?float
    {
        $vals = array_values(array_filter(array_map(static fn (array $r) => $r[$metric] ?? null, $rows), 'is_numeric'));

        return $vals === [] ? null : round(array_sum(array_map('floatval', $vals)) / count($vals), 4);
    }
}
