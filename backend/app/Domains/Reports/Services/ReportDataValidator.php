<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Consistency gate over a report snapshot. Every export format is generated from the SAME snapshot, so
 * validating the snapshot once guarantees the interactive link, PDF, XLSX and CSV agree. Failures are
 * returned as structured issues (never silently zero-filled) so ExportReadinessGate can block export.
 *
 * A "warning" is informational and does not block; an "error" blocks export.
 */
final class ReportDataValidator
{
    /** Relative tolerance for float sum comparisons (rounding across currency conversion). */
    private const TOLERANCE = 0.01;

    /**
     * @param  array<string,mixed>  $data  the report snapshot (report.data)
     * @return list<array{level:string, code:string, metric:?string, message:string, total:mixed, detail:mixed}>
     */
    public function validate(array $data): array
    {
        $issues = [];
        $k = $data['kpis'] ?? [];
        $platforms = $data['platforms'] ?? [];
        $campaigns = $data['campaigns'] ?? [];

        // 1. Platform spend/revenue/conversions must reconcile to the Summary totals.
        foreach (['spend', 'revenue', 'conversions'] as $metric) {
            $sum = array_sum(array_map(fn ($p) => (float) ($p[$metric] ?? 0), $platforms));
            $total = (float) ($k[$metric] ?? 0);
            if (! $this->closeEnough($sum, $total)) {
                $issues[] = $this->issue('error', 'summary_mismatch', $metric,
                    "Sum of platform {$metric} does not match the summary total.", $total, round($sum, 2));
            }
        }

        // 2. Derived KPIs must follow their definitions.
        $spend = (float) ($k['spend'] ?? 0);
        $revenue = (float) ($k['revenue'] ?? 0);
        $conv = (float) ($k['conversions'] ?? 0);
        if ($spend > 0 && isset($k['roas']) && ! $this->closeEnough((float) $k['roas'], $revenue / $spend)) {
            $issues[] = $this->issue('error', 'roas_mismatch', 'roas', 'ROAS does not equal revenue ÷ spend.', $k['roas'], round($revenue / $spend, 3));
        }
        if ($conv > 0 && isset($k['cpa']) && ! $this->closeEnough((float) $k['cpa'], $spend / $conv)) {
            $issues[] = $this->issue('error', 'cpa_mismatch', 'cpa', 'CPA does not equal spend ÷ results.', $k['cpa'], round($spend / $conv, 2));
        }

        // 3. Impossible combinations — the exact class of bug that shipped a "0 spend / 307 results" PDF.
        if ($conv > 0 && $spend <= 0 && ! $this->hasOrganicSource($data)) {
            $issues[] = $this->issue('error', 'results_without_spend', 'conversions',
                'Results exist with zero spend and no organic/imported source declared.', $conv, $spend);
        }
        if ($revenue > 0 && $spend <= 0 && ! $this->hasOrganicSource($data)) {
            $issues[] = $this->issue('error', 'revenue_without_spend', 'revenue',
                'Revenue exists with zero spend and no explanation.', $revenue, $spend);
        }

        // 4. Per-platform sanity (same rule, catches a single bad platform hidden inside a healthy total).
        foreach ($platforms as $p) {
            $ps = (float) ($p['spend'] ?? 0);
            $pc = (float) ($p['conversions'] ?? 0);
            if ($pc > 0 && $ps <= 0 && ! $this->hasOrganicSource($data)) {
                $issues[] = $this->issue('error', 'platform_results_without_spend', (string) ($p['provider'] ?? '?'),
                    "Platform {$p['provider']} reports results with zero spend.", $pc, $ps);
            }
        }

        // 5. Currency coherence — a report is single-currency; mixed rows without conversion are unsafe.
        $currency = $data['currency'] ?? null;
        foreach ($campaigns as $c) {
            if (isset($c['currency']) && $currency !== null && $c['currency'] !== $currency && empty($c['converted'])) {
                $issues[] = $this->issue('error', 'mixed_currency', (string) ($c['campaign_name'] ?? '?'),
                    'Campaign currency differs from report currency without a documented conversion.', $currency, $c['currency']);
                break;
            }
        }

        // 6. Partial snapshot / freshness — informational warnings, do not block.
        if (($platforms === [] && $spend > 0) || ($campaigns === [] && $conv > 0)) {
            $issues[] = $this->issue('warning', 'partial_snapshot', null, 'Snapshot appears partial (totals present but breakdown missing).', $spend, count($platforms));
        }
        if (empty($data['generated_at']) && empty($data['period'])) {
            $issues[] = $this->issue('warning', 'missing_metadata', null, 'Report is missing period/generated-at metadata.', null, null);
        }

        return $issues;
    }

    /** True when nothing at "error" level remains. */
    public function passes(array $data): bool
    {
        foreach ($this->validate($data) as $issue) {
            if ($issue['level'] === 'error') {
                return false;
            }
        }

        return true;
    }

    private function hasOrganicSource(array $data): bool
    {
        // An explicit, documented source that legitimately yields results without ad spend.
        $src = $data['results_source'] ?? ($data['meta']['results_source'] ?? null);

        return in_array($src, ['organic', 'imported', 'crm'], true);
    }

    private function closeEnough(float $a, float $b): bool
    {
        $scale = max(1.0, abs($a), abs($b));

        return abs($a - $b) / $scale <= self::TOLERANCE;
    }

    /**
     * @return array{level:string, code:string, metric:?string, message:string, total:mixed, detail:mixed}
     */
    private function issue(string $level, string $code, ?string $metric, string $message, mixed $total, mixed $detail): array
    {
        return compact('level', 'code', 'metric', 'message', 'total', 'detail');
    }
}
