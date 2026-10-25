<?php

declare(strict_types=1);

namespace App\Domains\Reports\Support;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;

/**
 * REPORT-KPI-VISIBILITY-001 — the client's reusable «which metrics do my reports hide» template.
 *
 * Kept on the client (`settings.report_template.hidden_metrics`), because the choice is about WHO reads the report, and every project
 * of that client starts from it. A report copies the list when it is made; editing the template later never rewrites a report that
 * already exists. No template means the product's client preset.
 */
final class ReportMetricTemplate
{
    /** @return list<string>|null the client's saved list, or null when none was ever saved */
    public static function forProject(Project $project): ?array
    {
        $client = ClientWorkspace::query()->find($project->client_workspace_id);
        $saved = ((array) (((array) ($client?->settings ?? []))['report_template'] ?? []))['hidden_metrics'] ?? null;

        return ReportMetricVisibility::isChosen($saved) ? ReportMetricVisibility::normalise($saved) : null;
    }

    /** What a NEW client-facing report in this project starts from. @return list<string> */
    public static function startingPoint(Project $project): array
    {
        return self::forProject($project) ?? ReportMetricVisibility::clientPreset();
    }

    /** @param  list<string>  $hidden */
    public static function save(Project $project, array $hidden): void
    {
        $client = ClientWorkspace::query()->findOrFail($project->client_workspace_id);
        $settings = (array) ($client->settings ?? []);
        $settings['report_template'] = ['hidden_metrics' => ReportMetricVisibility::normalise($hidden)];
        $client->update(['settings' => $settings]);
    }
}
