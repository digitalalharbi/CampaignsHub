<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

/**
 * Ranks "top items" (campaigns today; ad-level once connectors provide it) by objective-appropriate
 * criteria and returns a human-readable REASON for each — never an opaque score. Sales ranks by ROAS,
 * awareness by reach/CPM, traffic by CTR/CPC, leads by CPA, video by completion. The caller decides
 * the level; this only orders + explains.
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

    /** @return array{0:string,1:string,2:callable} sort key, direction, reason builder */
    private function strategy(string $objective): array
    {
        return match ($objective) {
            'awareness', 'video' => ['cpm', 'asc', fn ($i) => sprintf('أعلى مدى بأقل CPM (%s).', $this->fmt($i['cpm'] ?? null))],
            'traffic' => ['ctr', 'desc', fn ($i) => sprintf('أعلى CTR (%s) بتكلفة نقرة %s.', $this->pct($i['ctr'] ?? null), $this->fmt($i['cpc'] ?? null))],
            'leads', 'app_installs' => ['cpa', 'asc', fn ($i, $avgCpa) => sprintf('أقل تكلفة نتيجة (CPA %s)%s.', $this->fmt($i['cpa'] ?? null), $avgCpa && ($i['cpa'] ?? INF) < $avgCpa ? ' أقل من متوسط الحملة' : '')],
            default => ['roas', 'desc', fn ($i, $avgCpa) => sprintf('أعلى ROAS (%s×)%s.', $this->num($i['roas'] ?? null), $avgCpa && ($i['cpa'] ?? INF) < $avgCpa ? ' مع CPA أقل من المتوسط' : '')],
        };
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
