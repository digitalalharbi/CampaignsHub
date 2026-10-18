<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Str;

/**
 * REPORT-SCOPE-HIERARCHY-001 — a new saved report names its ad accounts, so a test that creates one
 * needs an account the project actually shows. One account, one campaign, one day of spend.
 */
trait SeedsReportAccount
{
    /** @return array{account_ids: list<string>} the scope a saved report is created with */
    protected function reportScopeFor(string $projectId): array
    {
        $project = Project::withoutGlobalScopes()->findOrFail($projectId);
        $account = (string) Str::uuid();
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'project_id' => $projectId, 'name' => 'Seeded '.Str::random(4),
            'status' => 'active', 'objective' => 'sales',
        ]);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $projectId,
            'external_account_id' => $account, 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $campaign->id, 'provider' => 'meta', 'metric_key' => 'spend',
            'metric_date' => '2026-07-10', 'value' => 1,
        ]);

        return ['account_ids' => [$account]];
    }
}
