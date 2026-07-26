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
            // Structured two-column content: findings (left) + recommendations (right). Cards, not prose.
            'findings' => $this->tagAnnotations($this->findings($totals, $delta, $platforms, $campaigns, $report->currency), 'finding', $report),
            'recommendations' => $this->tagAnnotations($this->recommendations($platforms, $campaigns, $report->currency), 'recommendation', $report),
            'audience' => $config['audience'] ?? 'client',
            'slides' => $config['slides'] ?? [],
            // Effective disclaimer/methodology copy, snapshotted so a shared report is self-contained
            // and reproducible even if the org later edits its notes.
            'disclaimer' => $this->disclaimers->resolve(
                (string) $report->tenant_id,
                DB::table('projects')->where('id', $report->project_id)->value('client_workspace_id'),
                (string) $report->project_id,
            ),
        ];

        // Canonical-snapshot metadata: every export format renders from this exact data, and the
        // checksum lets the print pipeline verify it rendered the snapshot it was given.
        $data['data_version'] = 1;
        $data['tenant_id'] = (string) $report->tenant_id;
        $data['project_id'] = (string) $report->project_id;
        $data['timezone'] = $report->timezone;
        $data['attribution_window'] = $report->attribution_window;
        $data['data_source'] = $report->data_source;
        $data['mode'] = $report->config['mode'] ?? 'snapshot';
        $data['generated_at'] = Carbon::now()->toIso8601String();
        $data['checksum'] = ExportReadinessGate::checksum($data);

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

    /**
     * Left column: short, card-shaped findings (icon/title/detail/severity/platform/kpi) — derived from
     * the numbers, never fabricated. Max 5.
     *
     * @return list<array<string,mixed>>
     */
    private function findings(array $t, array $delta, array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        if ($platforms !== []) {
            $bestRoas = collect($platforms)->sortByDesc('roas')->first();
            if ($bestRoas && $bestRoas['roas'] !== null) {
                $out[] = ['severity' => 'positive', 'title' => "أعلى ROAS على {$bestRoas['provider']}", 'platform' => $bestRoas['provider'],
                    'kpi' => 'ROAS', 'value' => number_format((float) $bestRoas['roas'], 2).'×', 'detail' => 'أفضل عائد إنفاق خلال الفترة.'];
            }
            $bestCpa = collect($platforms)->filter(fn ($p) => $p['cpa'] !== null)->sortBy('cpa')->first();
            if ($bestCpa) {
                $out[] = ['severity' => 'positive', 'title' => "أقل تكلفة نتيجة على {$bestCpa['provider']}", 'platform' => $bestCpa['provider'],
                    'kpi' => 'CPA', 'value' => number_format((float) $bestCpa['cpa']).' '.$currency, 'detail' => 'تكلفة نتيجة تنافسية.'];
            }
            $worstRoas = collect($platforms)->filter(fn ($p) => $p['roas'] !== null && $p['spend'] > 0)->sortBy('roas')->first();
            if ($worstRoas && count($platforms) > 1) {
                $out[] = ['severity' => 'warning', 'title' => "{$worstRoas['provider']} دون المتوسط", 'platform' => $worstRoas['provider'],
                    'kpi' => 'ROAS', 'value' => number_format((float) $worstRoas['roas'], 2).'×', 'detail' => 'يحتاج مراجعة الاستهداف والمحتوى.'];
            }
        }
        $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
        if ($burner) {
            $out[] = ['severity' => 'critical', 'title' => "حملة «{$burner['campaign_name']}» تنفق دون تحويلات", 'platform' => $burner['provider'] ?? null,
                'kpi' => 'الإنفاق', 'value' => number_format((float) $burner['spend']).' '.$currency, 'detail' => 'مرشحة للإيقاف أو مراجعة التتبع.'];
        }
        if (isset($delta['revenue']) && $delta['revenue'] > 0.1) {
            $out[] = ['severity' => 'positive', 'title' => 'نمو الإيرادات مقابل الفترة السابقة', 'platform' => null,
                'kpi' => 'الإيرادات', 'value' => '+'.number_format((float) $delta['revenue'] * 100, 0).'%', 'detail' => 'اتجاه إيجابي في العائد.'];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Right column: actionable recommendations. Suggestions only — the user approves before a client
     * sees them (feature-flagged elsewhere). Max 5.
     *
     * @return list<array<string,mixed>>
     */
    private function recommendations(array $platforms, array $campaigns, string $currency): array
    {
        $out = [];
        $bestRoas = collect($platforms)->sortByDesc('roas')->first();
        if ($bestRoas && $bestRoas['roas'] !== null && $bestRoas['roas'] > 1) {
            $out[] = ['severity' => 'positive', 'title' => "زيادة ميزانية {$bestRoas['provider']} تدريجيًا", 'platform' => $bestRoas['provider'],
                'action' => 'scale', 'detail' => 'أعلى ROAS — وسّع بحذر مع مراقبة مرحلة التعلّم.', 'kpi' => 'ROAS'];
        }
        $worstCpa = collect($platforms)->filter(fn ($p) => $p['cpa'] !== null && $p['spend'] > 0)->sortByDesc('cpa')->first();
        if ($worstCpa && count($platforms) > 1) {
            $out[] = ['severity' => 'warning', 'title' => "تحسين استهداف {$worstCpa['provider']}", 'platform' => $worstCpa['provider'],
                'action' => 'optimize', 'detail' => 'أعلى تكلفة نتيجة — راجع الجمهور والإبداع والصفحة.', 'kpi' => 'CPA'];
        }
        $burner = collect($campaigns)->first(fn ($c) => ($c['spend'] ?? 0) > 3000 && ($c['conversions'] ?? 0) < 2);
        if ($burner) {
            $out[] = ['severity' => 'critical', 'title' => "إيقاف مؤقت ومراجعة «{$burner['campaign_name']}»", 'platform' => $burner['provider'] ?? null,
                'action' => 'pause', 'detail' => 'إنفاق دون تحويلات — تحقق من التتبع قبل الاستمرار.', 'kpi' => 'الإنفاق'];
        }
        $topConv = collect($campaigns)->sortByDesc('conversions')->first();
        if ($topConv && ($topConv['conversions'] ?? 0) > 0) {
            $out[] = ['severity' => 'positive', 'title' => "توسيع ما ينجح في «{$topConv['campaign_name']}»", 'platform' => $topConv['provider'] ?? null,
                'action' => 'expand', 'detail' => 'أعلى نتائج — كرّر الزوايا الرابحة على جماهير مشابهة.', 'kpi' => 'النتائج'];
        }

        return array_slice($out, 0, 5);
    }

    /**
     * Stamp each auto-generated finding/recommendation with a stable id + approval status. Findings are
     * factual observations (client-visible). Recommendations are AI-generated and start as `draft`;
     * only `approved` ones reach a client (approval ids live in report.config). Demo reports are
     * pre-approved so the demo client view is complete.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function tagAnnotations(array $items, string $type, Report $report): array
    {
        $approved = (array) ($report->config['approved_recommendations'] ?? []);

        return array_map(function ($item) use ($type, $report, $approved) {
            $id = substr(hash('sha256', $type.'|'.($item['title'] ?? '').'|'.($item['platform'] ?? '')), 0, 12);
            $isRec = $type === 'recommendation';
            $status = $isRec
                ? (in_array($id, $approved, true) || $report->is_demo ? 'approved' : 'draft')
                : 'reviewed';

            return $item + [
                'id' => $id,
                'type' => $type,
                'is_ai_generated' => true,
                'status' => $status,
                'evidence' => ['kpi' => $item['kpi'] ?? null, 'value' => $item['value'] ?? null, 'platform' => $item['platform'] ?? null],
            ];
        }, $items);
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
