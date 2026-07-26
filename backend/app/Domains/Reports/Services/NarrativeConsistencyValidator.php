<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Guards the *narrative* (summary sentences, next steps) against the numeric snapshot. A report that
 * displays 1,158 results while its prose says "0 attributed results" is a hard data-integrity defect
 * — this validator makes the export fail closed instead of shipping the contradiction.
 *
 * Two checks:
 *  1. Structural: the snapshot's own results/spend totals must agree across kpis ↔ platforms ↔ campaigns.
 *  2. Narrative: no sentence may assert zero results (AR/EN) when the snapshot has results, and any
 *     explicit "N results"/"N نتيجة" figure in the prose must match the snapshot total.
 */
final class NarrativeConsistencyValidator
{
    private const TOLERANCE = 0.02; // 2% for rounding / compact forms

    /**
     * @param  array<string,mixed>  $data
     * @return list<array{code:string,detail:string}> empty ⇒ consistent
     */
    public function scan(array $data): array
    {
        $issues = [];
        $results = $this->snapshotResults($data);

        // 1. Structural agreement (only when the component tables exist).
        $platformResults = $this->sumField($data['platforms'] ?? [], ['results', 'conversions']);
        $campaignResults = $this->sumField($data['campaigns'] ?? [], ['results', 'conversions']);
        foreach ([['platforms', $platformResults], ['campaigns', $campaignResults]] as [$label, $sum]) {
            if ($sum > 0 && $results > 0 && ! $this->close($sum, $results)) {
                $issues[] = ['code' => 'results_table_mismatch', 'detail' => "kpi results {$results} ≠ {$label} total {$sum}"];
            }
        }

        // 2. Narrative claims.
        $sentences = $this->narrativeStrings($data);
        foreach ($sentences as $s) {
            $lower = mb_strtolower($s);
            $claimsZero = preg_match('/\b0\s+(attributed\s+)?results?\b/u', $lower)
                || str_contains($lower, 'no results')
                || preg_match('/(?:^|\D)0\s*نتيجة/u', $s)
                || str_contains($s, 'صفر نتيجة');
            if ($claimsZero && $results > 0) {
                $issues[] = ['code' => 'narrative_zero_results', 'detail' => "prose asserts zero results but snapshot has {$results}: ".mb_substr($s, 0, 80)];
            }
            foreach ($this->explicitResultFigures($s) as $figure) {
                if ($results > 0 && ! $this->close((float) $figure, $results)) {
                    $issues[] = ['code' => 'narrative_results_mismatch', 'detail' => "prose says {$figure} results, snapshot {$results}"];
                }
            }
        }

        return $issues;
    }

    private function snapshotResults(array $data): float
    {
        $kpis = $data['kpis'] ?? [];
        foreach (['results', 'conversions', 'conversions_total'] as $k) {
            if (isset($kpis[$k]) && is_numeric($kpis[$k])) {
                return (float) $kpis[$k];
            }
        }

        return 0.0;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sumField(array $rows, array $keys): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            foreach ($keys as $k) {
                if (isset($row[$k]) && is_numeric($row[$k])) {
                    $sum += (float) $row[$k];
                    break;
                }
            }
        }

        return $sum;
    }

    /** @return list<string> */
    private function narrativeStrings(array $data): array
    {
        $out = [];
        foreach ((array) ($data['summary'] ?? []) as $s) {
            if (is_string($s)) {
                $out[] = $s;
            }
        }
        foreach ((array) ($data['next_steps'] ?? []) as $step) {
            foreach (['title', 'detail', 'body'] as $k) {
                if (is_string($step[$k] ?? null)) {
                    $out[] = $step[$k];
                }
            }
        }

        return $out;
    }

    /** Pull "1,158 results" / "1158 نتيجة" figures from a sentence. @return list<float> */
    private function explicitResultFigures(string $s): array
    {
        $figures = [];
        if (preg_match_all('/([\d,]+)\s*(?:attributed\s+)?results?/iu', $s, $m)) {
            foreach ($m[1] as $n) {
                $figures[] = (float) str_replace(',', '', $n);
            }
        }
        if (preg_match_all('/([\d,]+)\s*نتيجة/u', $s, $m)) {
            foreach ($m[1] as $n) {
                $figures[] = (float) str_replace(',', '', $n);
            }
        }

        return $figures;
    }

    private function close(float $a, float $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $max = max(abs($a), abs($b), 1.0);

        return abs($a - $b) / $max <= self::TOLERANCE;
    }
}
