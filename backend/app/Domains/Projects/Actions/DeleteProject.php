<?php

declare(strict_types=1);

namespace App\Domains\Projects\Actions;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Services\ProjectDeletionImpact;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-DELETE-001 §8 §9 — deleting a project without reaching past it.
 *
 * ## The whole risk is over-reach
 *
 * A project sits in the middle of a chain whose upper rungs are SHARED: one Snapchat authorisation
 * feeds several projects, and one organisation's accounts are split between clients. So the danger
 * in this action is never «did it delete enough» — it is «what else did it touch». Three things are
 * therefore explicitly NOT done here, and each of them is a thing a naive cascade would do:
 *
 *  - the provider connection is not revoked. It is not this project's to revoke; another client is
 *    advertising through it right now.
 *  - the external account is not deleted, nor its discovery. It belongs to the tenant's inventory
 *    and to the advertising platform, and nothing in CampaignsHub owns it.
 *  - a neighbouring project's bindings, reports, links and schedules are not read, let alone
 *    written. Every statement below is filtered by THIS project.
 *
 * ## What it does do, and why these three first
 *
 * The project row is soft-deleted, which is what removes it from every surface at once: the model's
 * `SoftDeletes` scope stacks under the tenant scope, so no list, no lookup and no project-scoped
 * route can reach it from the next request onwards. History is kept rather than destroyed — a client
 * that leaves is still a year of spend the agency is required to be able to account for.
 *
 * But soft-deleting the row does NOT close the paths that live outside the interface, and those are
 * the ones that make a deletion untrue:
 *
 *  - a client-facing LIVE link answers from a token, not from a session, and would keep serving.
 *  - a schedule fires from the queue and would email the client next Sunday, out of anybody's sight.
 *  - a binding is what makes an account's data arrive; left active, the deleted project keeps being
 *    synced into.
 *
 * All three are closed in the SAME transaction as the delete, because a deletion that half-happened
 * is worse than one that failed: the interface would say the project is gone while the link still
 * opens.
 *
 * ## Why the bindings are deactivated and not destroyed
 *
 * `is_active = false` is the product's existing vocabulary for «this account no longer feeds this
 * project», and it is what every sync path already reads. Destroying the row instead would lose the
 * fact that the account WAS bound, which is exactly the fact an audit of last quarter's spend needs.
 */
final class DeleteProject
{
    public function __construct(
        private readonly ProjectDeletionImpact $impact,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string,mixed> the impact, as measured immediately before the deletion
     */
    public function handle(Project $project, ?int $actorId): array
    {
        $tenantId = (string) $project->tenant_id;
        $projectId = (string) $project->getKey();

        // Measured BEFORE, so the audit record says what was actually there rather than what is left.
        $impact = $this->impact->for($project);

        DB::transaction(function () use ($project, $tenantId, $projectId): void {
            // 1. Stop the data arriving. This project's bindings only — never the connection.
            DB::table('project_integration_bindings')
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // 2. Stop the client-facing links answering. Revoked, not deleted: a link that was
            //    revoked on a date is a fact, and an absent row cannot say when access ended.
            DB::table('report_shares')
                ->whereIn('report_id', DB::table('reports')
                    ->where('tenant_id', $tenantId)
                    ->where('project_id', $projectId)
                    ->select('id'))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]);

            // 3. Stop the queue emailing a client about a project that no longer exists.
            DB::table('report_schedules')
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->where('active', true)
                ->update(['active' => false, 'next_run_at' => null, 'updated_at' => now()]);

            $project->delete();
        });

        /*
         * The audit keeps the ACTION and its shape, never a copy of the client's figures.
         *
         * «Preserve required security evidence» and «do not keep an unauthorised historical copy»
         * are the same instruction read from two sides: who deleted what, when, and how much of it
         * there was — and nothing anybody could reconstruct a client's performance from.
         */
        $this->audit->log(
            action: 'project.deleted',
            entityType: Project::class,
            entityId: $projectId,
            before: [
                'name' => $impact['project']['name'],
                'client' => $impact['project']['client'],
                'status' => $impact['project']['status'],
                'counts' => $impact['counts'],
            ],
            userId: $actorId,
        );

        return $impact;
    }
}
