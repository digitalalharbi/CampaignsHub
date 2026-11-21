<?php

declare(strict_types=1);

namespace App\Domains\Reports\Analytics;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — objective-aware KPI blocks, best/weakest platform and content,
 * trend and contribution, as one report section.
 *
 * Absent (null) when no classified family has a single reported row in scope: a heading over nothing
 * reads as a failure.
 *
 * It travels under `objective_analytics`, which `ReportSectionRegistry` assigns to the
 * `objective_breakdown` section — so the operator's switch, the form and every surface's resolver
 * treat it exactly as they treat the rest of that section.
 */
final class ObjectiveAnalyticsSection
{
    public const KEY = 'objective_analytics';

    /** @return array<string,mixed>|null */
    public function build(ObjectiveAnalyticsInput $input): ?array
    {
        $reader = new ObjectiveAnalyticsReader($input->projectIds, $input->campaignIds, $input->providers, $input->accountIds);

        $section = (new ObjectiveReportAnalytics)->build(
            $reader->byObjectiveProvider($input->from, $input->to),
            $reader->byObjectiveDay($input->from, $input->to),
            $input->content,
            $input->from,
            $input->to,
        );

        if ($section['families'] === []) {
            return null;
        }

        return self::withMovement($section, $this->previous($reader, $input));
    }

    /**
     * OBJECTIVE-CARDS-PARITY-001 (Owner 2026-11-22) — the objective cards read like the summary's KPI cards: each figure beside its
     * movement against the equal window just before. The previous window is built by the same reader and the same analytics (its
     * KPIs only: no content, no days), so the two figures are one definition apart by nothing.
     *
     * @return array<string, array<string, float>> family → kpi key → previous value (reported figures only)
     */
    private function previous(ObjectiveAnalyticsReader $reader, ObjectiveAnalyticsInput $input): array
    {
        $days = max(1, (int) $input->from->copy()->startOfDay()->diffInDays($input->to->copy()->startOfDay()) + 1);
        $prevTo = $input->from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);

        $before = (new ObjectiveReportAnalytics)->build($reader->byObjectiveProvider($prevFrom, $prevTo), [], [], $prevFrom, $prevTo);

        $out = [];
        foreach ($before['families'] ?? [] as $block) {
            foreach ((array) ($block['kpis'] ?? []) as $kpi) {
                if (($kpi['state'] ?? null) === 'reported' && is_numeric($kpi['value'] ?? null)) {
                    $out[(string) $block['family']][(string) $kpi['key']] = (float) $kpi['value'];
                }
            }
        }

        return $out;
    }

    /**
     * A movement is stated only where both windows reported the figure and the earlier one is not zero — never a 100 % rise out of
     * nothing, never a fall to a figure nobody sent.
     *
     * @param  array<string, mixed>  $section
     * @param  array<string, array<string, float>>  $previous
     * @return array<string, mixed>
     */
    public static function withMovement(array $section, array $previous): array
    {
        foreach ($section['families'] as $i => $block) {
            foreach ((array) ($block['kpis'] ?? []) as $j => $kpi) {
                $before = $previous[(string) ($block['family'] ?? '')][(string) ($kpi['key'] ?? '')] ?? null;
                $now = $kpi['value'] ?? null;
                $section['families'][$i]['kpis'][$j]['delta'] = ($kpi['state'] ?? null) === 'reported' && is_numeric($now) && $before !== null && $before != 0.0
                    ? round(((float) $now - $before) / abs($before), 4)
                    : null;
            }
        }

        return $section;
    }
}
