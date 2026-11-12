<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportObjectiveLens;
use App\Domains\Reports\Services\ReportTemplateEngine;

/**
 * REPORT-MULTI-OBJECTIVE-001 — the objectives a report is measured against, read ONE way by the live link, the saved report and the files.
 *
 * Master task 10: «هدف التقرير (قالب القياس)» accepts more than one objective, and the live link honours it as the saved report and its PDF do.
 *
 * Storage needs no migration: `reports.campaign_objective` keeps a single objective exactly as before, and a report measured against several
 * stores `campaign_objective = custom` (the mixed lens: no single headline, no ROAS crowned over traffic spend) and the list itself in
 * `config.objectives`. Every report written before this has no `config.objectives` and reads exactly as it did.
 */
final class ReportObjectives
{
    public const CONFIG_KEY = 'objectives';

    /** Several objectives are a mixed report: operational figures, no single headline. */
    public const MIXED = 'custom';

    /** @return list<string> the report templates the operator may choose */
    public static function allowed(): array
    {
        return array_keys(ReportObjectiveLens::TEMPLATE_MEMBERS);
    }

    /**
     * The report's objectives as stored: the list when several were chosen, else the single objective, else none.
     *
     * @return list<string>
     */
    public static function of(Report $report): array
    {
        $many = array_values(array_intersect(
            array_values(array_filter((array) (((array) ($report->config ?? []))[self::CONFIG_KEY] ?? []), 'is_string')),
            self::allowed(),
        ));
        if (count($many) >= 2) {
            return $many;
        }

        $one = (string) ($report->campaign_objective ?? '');

        return $one !== '' && $one !== self::MIXED ? [$one] : $many;
    }

    /**
     * What to write on a report for these objectives: `campaign_objective` and the `config` key.
     *
     * @param  list<string>  $objectives
     * @return array{campaign_objective: string|null, objectives: list<string>}
     */
    public static function toStore(array $objectives): array
    {
        $objectives = array_values(array_unique(array_intersect($objectives, self::allowed())));

        return match (count($objectives)) {
            0 => ['campaign_objective' => null, 'objectives' => []],
            1 => ['campaign_objective' => $objectives[0], 'objectives' => []],
            default => ['campaign_objective' => self::MIXED, 'objectives' => $objectives],
        };
    }

    /**
     * The KPI list the objectives present when nobody chose a list by hand — the union of each objective's preset.
     *
     * @return list<string>
     */
    public static function kpis(Report $report, ?string $destination = null): array
    {
        $objectives = self::of($report);

        return $objectives === [] ? [] : app(ReportTemplateEngine::class)->metricSetForMany($objectives, $destination);
    }
}
