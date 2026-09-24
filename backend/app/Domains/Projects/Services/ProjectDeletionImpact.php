<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Projects\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-DELETE-001 §7 — what deleting THIS project will actually reach, counted.
 *
 * ## Why a dialog cannot be «Are you sure?»
 *
 * Nobody can answer that question about a project, because a project is the middle of a chain and
 * the interesting part is which rungs move with it. An agency ending a retainer wants to know that
 * the client's reports go and the client's links stop; they also need to know — before pressing —
 * that the OTHER client on the same Snapchat authorisation keeps advertising. Both facts come from
 * here, so the dialog states what the code does rather than what a translation file claims.
 *
 * ## Counted straight from the tables, and only this project's rows
 *
 * Every count is filtered by tenant AND project. That is belt and braces on purpose: a global scope
 * that stops applying — the exact failure this programme has already paid for once — turns a summary
 * into a lie at the worst possible moment, in a dialog whose next button is irreversible.
 *
 * Shares have no `project_id` of their own; they hang off a report, so they are counted through it.
 * A share counted by tenant alone would tell one client's operator how many links the agency has.
 */
final class ProjectDeletionImpact
{
    /**
     * Tables that carry `project_id` directly and are worth stating to a person.
     *
     * Deliberately not every table in the schema. A number somebody cannot act on is noise in a
     * confirmation dialog, and the long tail — mappings, annotations, webhook receipts — moves with
     * the rows above it.
     *
     * @var array<string,string>
     */
    private const DIRECT = [
        'integration_bindings' => 'project_integration_bindings',
        'campaigns' => 'external_campaigns',
        'ad_sets' => 'external_ad_sets',
        'ads' => 'external_ads',
        'creatives' => 'external_creatives',
        'reports' => 'reports',
        'tasks' => 'tasks',
        'team_members' => 'project_memberships',
    ];

    /** @return array<string,mixed> */
    public function for(Project $project): array
    {
        $tenantId = (string) $project->tenant_id;
        $projectId = (string) $project->getKey();

        $counts = [];
        foreach (self::DIRECT as $label => $table) {
            $counts[$label] = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->count();
        }

        /*
         * Exports hang off a report, not off a project — same as shares.
         *
         * Counted through it rather than by tenant, because «how many generated files does this
         * agency hold» is a different question from the one this dialog asks.
         */
        $reportIds = DB::table('reports')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->select('id');

        $counts['report_exports'] = DB::table('report_exports')
            ->whereIn('report_id', clone $reportIds)
            ->count();

        // The daily grains, added up: three tables, one sentence — «this much measured history».
        $counts['metric_rows'] = collect(['daily_metrics', 'entity_daily_metrics', 'creative_daily_metrics'])
            ->sum(fn (string $table): int => DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->count());

        // Only the schedules that would still FIRE. A switched-off one is not an impact.
        $counts['report_schedules'] = DB::table('report_schedules')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->where('active', true)
            ->count();

        /*
         * Links that a client could open right now.
         *
         * Revoked and expired ones are excluded because the number exists to answer «how many people
         * outside this agency lose access today», and a link that already answers 404 costs nobody
         * anything.
         */
        $counts['active_shares'] = DB::table('report_shares')
            ->whereIn('report_id', clone $reportIds)
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();

        return [
            'project' => [
                'id' => $projectId,
                'name' => (string) $project->name,
                'status' => (string) $project->status,
                'client' => $project->clientWorkspace?->name,
            ],
            'counts' => $counts,
            /*
             * The two promises the dialog makes, stated by the code that keeps them.
             *
             * A project is one consumer of an authorisation that other projects also use, and it is
             * never the owner of the advertising account. Both are constants rather than computed
             * values because they are guarantees: if either ever becomes conditional, this is the
             * line that has to change, in sight of the tests that assert it.
             */
            'revokes_provider_authorisation' => false,
            'deletes_advertising_accounts' => false,
        ];
    }
}
