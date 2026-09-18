<?php

declare(strict_types=1);

namespace App\Domains\Reports\Sections;

use App\Domains\Reports\Analytics\ObjectiveAnalyticsReader;
use App\Domains\Reports\Analytics\ObjectiveReportAnalytics;

/**
 * REPORT-OBJECTIVE-ANALYTICS-001 — objective-aware KPI blocks, best/weakest platform and content,
 * trend and contribution, as one report section.
 *
 * Absent (null) when no classified family has a single reported row in scope: a heading over nothing
 * reads as a failure, and the direct/blended block already says where unclassified money went.
 */
final class ObjectiveAnalyticsSection implements ReportSection
{
    public const KEY = 'objective_analytics';

    public function key(): string
    {
        return self::KEY;
    }

    public function build(ReportSectionInput $input): ?array
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
