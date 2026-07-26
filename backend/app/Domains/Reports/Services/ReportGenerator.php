<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Disclaimers\Services\DisclaimerResolver;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Reports\Models\Report;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Snapshots a report's data from the SAME metrics aggregation the dashboard/analytics use, so a
 * completed report is reproducible and exportable without recomputing. Writes the snapshot + KPIs +
 * a short auto-generated executive summary into report.data.
 */
final class ReportGenerator
{
    public function __construct(
        private readonly MetricsAggregator $agg,
        private readonly ReportTemplateEngine $template,
        private readonly CreativeRankingService $ranking,
        private readonly DisclaimerResolver $disclaimers,
    ) {}

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

        $objective = $report->campaign_objective ?: $this->inferObjective($campaigns);
        $providerList = array_values(array_map(fn ($p) => $p['provider'], $platforms));

        // Initialise the slide layout once (from the objective + connected platforms) if not authored yet.
        $config = $report->config;
        if (empty($config['slides'])) {
            $config = $this->template->defaultConfig($objective, $providerList);
            $report->forceFill(['config' => $config, 'campaign_objective' => $objective])->saveQuietly();
        }

        $topCreatives = $this->ranking->rank($objective, $campaigns);

        $data = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'currency' => $report->currency,
            'objective' => $objective,
            'platform_order' => $config['platform_order'] ?? $providerList,
            'metric_set' => $config['metric_set'] ?? $this->template->metricSet($objective),
            'kpis' => $totals,
            'previous' => $previous,
            'delta' => $delta,
            'timeseries' => $this->agg->timeseries($from, $to),
            'platform_series' => $this->agg->timeseriesByProvider($from, $to),
            'platforms' => $platforms,
            'best' => [
                'platform_by_roas' => collect($platforms)->sortByDesc('roas')->first()['provider'] ?? null,
                'platform_by_cpa' => collect($platforms)->filter(fn ($p) => $p['cpa'] !== null)->sortBy('cpa')->first()['provider'] ?? null,
                'platform_by_results' => collect($platforms)->sortByDesc('conversions')->first()['provider'] ?? null,
                'campaign' => $campaigns[0]['campaign_name'] ?? null,
            ],
            'campaigns' => $campaigns,
            'top_creatives' => $topCreatives,
            'creative_level' => 'campaign', // ad-level arrives once connectors provide it
            'platform_notes' => $this->platformNotes($platforms, $report->currency),
            'funnel' => $this->agg->funnel($from, $to),
            'budget' => $this->agg->budgetPacing($from, $to, Carbon::today()),
            'summary' => $this->executiveSummary($totals, $delta, $platforms, $campaigns, $report->currency),
            'slides' => $config['slides'] ?? [],
            // Effective disclaimer/methodology copy, snapshotted so a shared report is self-contained
            // and reproducible even if the org later edits its notes.
            'disclaimer' => $this->disclaimers->resolve(
                (string) $report->tenant_id,
                DB::table('projects')->where('id', $report->project_id)->value('client_workspace_id'),
                (string) $report->project_id,
            ),
        ];

        app(ProjectContext::class)->forget();

        return $data;
    }

    private function inferObjective(array $campaigns): string
    {
        // Revenue present → sales; else conversions → leads; else traffic.
        $revenue = array_sum(array_map(fn ($c) => (float) ($c['revenue'] ?? 0), $campaigns));
        $conv = array_sum(array_map(fn ($c) => (float) ($c['conversions'] ?? 0), $campaigns));

        return $revenue > 0 ? 'sales' : ($conv > 0 ? 'leads' : 'traffic');
    }

    /** Auto strengths/weaknesses per platform (suggestions — the user approves before a client sees them). */
    private function platformNotes(array $platforms, string $currency): array
    {
        $notes = [];
        $avgRoas = $this->avg($platforms, 'roas');
        $avgCpa = $this->avg($platforms, 'cpa');
        foreach ($platforms as $p) {
            $strengths = [];
            $weaknesses = [];
            if ($p['roas'] !== null && $avgRoas !== null && $p['roas'] >= $avgRoas) {
                $strengths[] = sprintf('ROAS أعلى من المتوسط (%s×).', number_format((float) $p['roas'], 2));
            } elseif ($p['roas'] !== null) {
                $weaknesses[] = sprintf('ROAS أقل من المتوسط (%s×).', number_format((float) $p['roas'], 2));
            }
            if ($p['cpa'] !== null && $avgCpa !== null && $p['cpa'] <= $avgCpa) {
                $strengths[] = sprintf('تكلفة نتيجة تنافسية (CPA %s %s).', number_format((float) $p['cpa']), $currency);
            } elseif ($p['cpa'] !== null) {
                $weaknesses[] = sprintf('تكلفة نتيجة مرتفعة (CPA %s %s).', number_format((float) $p['cpa']), $currency);
            }
            $notes[$p['provider']] = ['strengths' => $strengths, 'weaknesses' => $weaknesses];
        }

        return $notes;
    }

    private function avg(array $rows, string $key): ?float
    {
        $vals = array_filter(array_map(fn ($r) => $r[$key] ?? null, $rows), fn ($v) => $v !== null);

        return $vals === [] ? null : array_sum($vals) / count($vals);
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
