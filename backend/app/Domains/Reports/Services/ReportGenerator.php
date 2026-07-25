<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;

/**
 * Snapshots a report's data from the SAME metrics aggregation the dashboard/analytics use, so a
 * completed report is reproducible and exportable without recomputing. Writes the snapshot + KPIs +
 * a short auto-generated executive summary into report.data.
 */
final class ReportGenerator
{
    public function __construct(private readonly MetricsAggregator $agg) {}

    public function generate(Report $report): array
    {
        app(TenantContext::class)->setTenantId((string) $report->tenant_id);
        app(ProjectContext::class)->setProjectId((string) $report->project_id);

        $to = $report->period_end ? Carbon::parse($report->period_end) : Carbon::today();
        $from = $report->period_start ? Carbon::parse($report->period_start) : $to->copy()->subDays(29);

        $totals = $this->agg->totals($from, $to);
        $len = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($len - 1);
        $previous = $this->agg->totals($prevFrom, $prevTo);
        $delta = [];
        foreach ($totals as $k => $v) {
            $p = $previous[$k] ?? null;
            $delta[$k] = is_numeric($v) && is_numeric($p) && $p != 0 ? round(($v - $p) / abs($p), 4) : null;
        }

        $platforms = $this->agg->byProvider($from, $to);
        $campaigns = $this->agg->byCampaign($from, $to);

        $data = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => $report->currency,
            'kpis' => $totals,
            'previous' => $previous,
            'delta' => $delta,
            'timeseries' => $this->agg->timeseries($from, $to),
            'platforms' => $platforms,
            'campaigns' => $campaigns,
            'funnel' => $this->agg->funnel($from, $to),
            'budget' => $this->agg->budgetPacing($from, $to, Carbon::today()),
            'summary' => $this->executiveSummary($totals, $delta, $platforms, $campaigns, $report->currency),
        ];

        app(ProjectContext::class)->forget();

        return $data;
    }

    /** A few plain-language findings derived from the numbers (not fabricated). */
    private function executiveSummary(array $t, array $delta, array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        $out[] = sprintf(
            'إجمالي الإنفاق %s %s بعائد ROAS %s خلال الفترة.',
            number_format((float) ($t['spend'] ?? 0)),
            $currency,
            $t['roas'] !== null ? number_format((float) $t['roas'], 2).'×' : '—',
        );
        if ($platforms !== []) {
            $bestRoas = collect($platforms)->sortByDesc('roas')->first();
            $bestCpa = collect($platforms)->filter(fn ($p) => $p['cpa'] !== null)->sortBy('cpa')->first();
            if ($bestRoas) {
                $out[] = sprintf('أعلى ROAS من منصة %s (%s×).', $bestRoas['provider'], number_format((float) $bestRoas['roas'], 2));
            }
            if ($bestCpa) {
                $out[] = sprintf('أقل تكلفة نتيجة (CPA) على %s بـ %s %s.', $bestCpa['provider'], number_format((float) $bestCpa['cpa']), $currency);
            }
        }
        $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
        if ($burner) {
            $out[] = sprintf('تنبيه: حملة «%s» تنفق دون تحويلات تُذكر — يُنصح بمراجعتها.', $burner['campaign_name'] ?? '—');
        }

        return $out;
    }
}
