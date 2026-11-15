<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Digest;

use App\Domains\Funding\Services\ProjectBudgetReader;
use App\Domains\Metrics\Fx\FxEngine;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Notifications\Services\DigestRecommendations;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The digest figures from the SAME reads the screens make (spec §16 «message == screen»):
 * `MetricsAggregator` scoped to one project (totals, byProvider, byCampaign, timeseries — the analytics and live-report
 * reads), `ProjectBudgetReader` (builder H's one budget source, its pace from the last three complete days) and the
 * approved recommendations. Every money figure goes through `FxEngine::convert` — the ONLY conversion source — from the
 * rows' own currency to the recipient's display currency on the window's last day, BEFORE anything is added. A missing
 * rate withholds the figure (null) and is reported in `fx` so the pre-send check holds the message for review.
 */
final class MetricsDigestDataSource implements DigestDataSource
{
    public function __construct(
        private readonly MetricsAggregator $metrics,
        private readonly FxEngine $fx,
        private readonly ProjectBudgetReader $budgets,
        private readonly DigestRecommendations $recommendations,
    ) {}

    public function project(string $tenantId, string $projectId, string $from, string $to, string $displayCurrency): array
    {
        $f = Carbon::parse($from)->startOfDay();
        $t = Carbon::parse($to)->endOfDay();
        $days = (int) $f->copy()->diffInDays($t->copy()->startOfDay()) + 1;
        $pf = $f->copy()->subDays($days);
        $pt = $f->copy()->subSecond();
        $scope = $this->metrics->acrossProjects()->forProjects([$projectId]);

        $basis = $scope->currencyBasis($f, $t);
        $source = $basis['bases'] === 0 ? ReportingCurrency::DEFAULT : $basis['currency'];
        $fxRows = [];
        $money = function (mixed $amount, ?string $currency = null, ?string $date = null) use (&$fxRows, $source, $displayCurrency, $to): ?float {
            if (! is_numeric($amount)) {
                return null;
            }
            $from = $currency ?? $source;
            if ($from === null) {
                $fxRows['mixed'] = ['from' => 'mixed', 'to' => $displayCurrency, 'rate' => null, 'rate_date' => null, 'kind' => null, 'withheld' => true];

                return null;
            }
            $c = $this->fx->convert((float) $amount, $from, $displayCurrency, $date ?? $to);
            if ($from !== $displayCurrency) {
                $fxRows[$from.'>'.$displayCurrency] = ['from' => $from, 'to' => $displayCurrency, 'rate' => $c->rate, 'rate_date' => $c->rateDate, 'kind' => $c->kind, 'withheld' => $c->withheld];
            }

            return $c->converted === null ? null : round($c->converted, 2);
        };

        $current = $scope->totals($f, $t);
        $previous = $scope->totals($pf, $pt);
        $coverage = (array) ($current['coverage'] ?? []);
        $complete = ($coverage['state'] ?? 'complete') === 'complete';

        $campaigns = $scope->byCampaign($f, $t);
        $prevCampaigns = $scope->byCampaign($pf, $pt);

        $objectives = [];
        foreach ($this->groupByFamily($campaigns) as $family => $rows) {
            $prev = $this->groupByFamily($prevCampaigns)[$family] ?? [];
            $objectives[] = $this->objectiveRow((string) $family, $rows, $prev, $money);
        }

        $prevPlatforms = [];
        foreach ($scope->byProvider($pf, $pt) as $row) {
            $prevPlatforms[(string) $row['provider']] = $money($row['spend'] ?? null);
        }
        $platforms = array_map(fn (array $row): array => [
            'provider' => (string) $row['provider'],
            'spend' => $money($row['spend'] ?? null),
            'previous_spend' => $prevPlatforms[(string) $row['provider']] ?? null,
        ], $scope->byProvider($f, $t));
        usort($platforms, static fn (array $a, array $b): int => ($b['spend'] ?? 0) <=> ($a['spend'] ?? 0));

        $series = fn (array $points): array => array_values(array_map(fn (array $p): array => ['date' => (string) $p['date'], 'spend' => $money($p['spend'] ?? null, null, (string) $p['date'])], $points));

        $top = array_slice(array_values(array_filter($campaigns, static fn (array $c): bool => (float) ($c['spend'] ?? 0) > 0)), 0, 3);

        return [
            'complete' => $complete,
            'missing' => $complete ? [] : array_values(array_unique(array_merge((array) ($coverage['failed_contributors'] ?? []), (array) ($coverage['stale_contributors'] ?? []), (array) ($coverage['partial_contributors'] ?? [])))),
            'currency' => $displayCurrency,
            'spend' => $money($current['spend'] ?? null),
            'previous_spend' => $money($previous['spend'] ?? null),
            'objectives' => $objectives,
            'platforms' => $platforms,
            'series' => $series($scope->timeseries($f, $t)),
            'previous_series' => $series($scope->timeseries($pf, $pt)),
            'campaigns' => array_map(fn (array $c): array => [
                'name' => (string) ($c['client_display_name'] ?? '') !== '' ? (string) $c['client_display_name'] : (string) ($c['campaign_name'] ?? ''),
                'objective' => (string) ($c['result_family'] ?? 'unknown'),
                'provider' => (string) ($c['provider'] ?? ''),
                'spend' => $money($c['spend'] ?? null),
                'result' => is_numeric($c['result'] ?? null) ? (float) $c['result'] : null,
                'result_key' => $c['result_key'] ?? null,
                'cost_per_result' => $money($c['cost_per_result'] ?? null),
            ], $top),
            'budget' => $this->budget($projectId, $displayCurrency, $to),
            'suggestions' => $this->suggestions($tenantId, $projectId, $f, $t),
            'achievements' => [],
            'fx' => array_values($fxRows),
            'data_project_ids' => [$projectId],
        ];
    }

    /** @param list<array<string, mixed>> $campaigns @return array<string, list<array<string, mixed>>> */
    private function groupByFamily(array $campaigns): array
    {
        $out = [];
        foreach ($campaigns as $c) {
            $family = (string) ($c['result_family'] ?? 'unknown');
            $family = $family === 'engagement' || $family === 'video' ? 'awareness' : $family;
            $out[$family][] = $c;
        }

        return $out;
    }

    /**
     * One objective's figures, from its own campaigns only. A result is summed only within one result key; the cost per
     * result is that group's spend over that group's result — never an average of costs, never across objectives.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $prev
     * @return array<string, mixed>
     */
    private function objectiveRow(string $family, array $rows, array $prev, callable $money): array
    {
        $sum = static function (array $rows, string $key): ?float {
            $vals = array_filter(array_map(static fn (array $r) => $r[$key] ?? null, $rows), 'is_numeric');

            return $vals === [] ? null : (float) array_sum($vals);
        };
        $resultKey = $rows[0]['result_key'] ?? null;
        $same = array_values(array_filter($rows, static fn (array $r): bool => ($r['result_key'] ?? null) === $resultKey));
        $prevSame = array_values(array_filter($prev, static fn (array $r): bool => ($r['result_key'] ?? null) === $resultKey));
        $spend = $sum($same, 'spend');
        $result = $sum($same, 'result');
        $pSpend = $sum($prevSame, 'spend');
        $pResult = $sum($prevSame, 'result');
        $revenue = $family === 'sales' ? $sum($same, 'revenue') : null;

        $cost = $spend !== null && $result !== null && $result > 0 ? $money($spend / $result) : null;

        return [
            'objective' => $family,
            'result_key' => $resultKey,
            'spend' => $money($spend),
            'result' => $result,
            'cost_per_result' => $cost,
            'revenue' => $money($revenue),
            'roas' => $revenue !== null && $spend !== null && $spend > 0 ? round($revenue / $spend, 2) : null,
            'previous_result' => $pResult,
            'previous_cost_per_result' => $pSpend !== null && $pResult !== null && $pResult > 0 ? $money($pSpend / $pResult) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function budget(string $projectId, string $display, string $to): array
    {
        try {
            $b = $this->budgets->forProject($projectId, Carbon::parse($to)->addDay());
        } catch (Throwable) {
            return ['has_budget' => false];
        }
        if (($b['has_budget'] ?? false) !== true) {
            return ['has_budget' => false];
        }
        $conv = fn ($v): ?float => is_numeric($v) ? $this->fx->convert((float) $v, (string) $b['currency'], $display, $to)->converted : null;

        return [
            'has_budget' => true,
            'currency' => $display,
            'original_currency' => (string) $b['currency'],
            'approved' => $conv($b['approved']),
            'spent' => $conv($b['spent']),
            'remaining' => $conv($b['remaining']),
            'approved_original' => (float) $b['approved'],
            'used_ratio' => $b['used_ratio'],
            'pace_per_day' => $conv($b['pace_per_day']),
            'run_out_date' => $b['run_out_date'],
            'cover_days' => $b['cover_days'],
            'period_end' => $b['period_end'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function suggestions(string $tenantId, string $projectId, Carbon $from, Carbon $to): array
    {
        return array_map(static fn (array $r): array => ['text' => trim($r['title'].($r['body'] ? ' — '.$r['body'] : '')), 'approved' => true],
            $this->recommendations->forProject($tenantId, $projectId, $from, $to));
    }
}
