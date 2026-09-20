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

        return $section['families'] === [] ? null : $section;
    }
}
