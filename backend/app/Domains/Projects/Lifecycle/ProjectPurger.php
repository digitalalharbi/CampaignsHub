<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use App\Domains\Audit\AuditLogger;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PROJECT-LIFECYCLE-001 §4 (builder N2) — the permanent delete of ONE soft-deleted project.
 *
 * Refuses unless the project is in the trash (status `deleted`, soft-deleted, not yet purged) AND either
 *  - manual: the actor holds `projects.purge` in the project's own workspace, or
 *  - automatic: `purge_after` is set and has passed (rows soft-deleted before the lifecycle have NULL and are never
 *    auto-purged).
 *
 * Then, in one transaction, follows {@see ProjectPurgePlan}: deletes the project's data rows, detaches the client-level
 * records, leaves every log and every shared/ad-platform row untouched, and keeps the `projects` row as a tombstone
 * (`purged_at`). Every statement is `WHERE tenant_id = ? AND project_id = ?`. It makes NO provider API call: the ad
 * platforms, the connection and the ad accounts are not this project's to touch. The audit row keeps the name snapshot
 * and the per-table counts (never a copy of the data).
 */
final class ProjectPurger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Whether this project may be purged now, and why not. Read-only.
     */
    public function refusal(Project $project, ?User $actor, bool $automatic): ?string
    {
        $row = Project::withoutGlobalScopes()->whereKey($project->getKey())->first();
        if ($row === null) {
            return 'not_found';
        }
        if (ProjectStatus::normalise($row->status) !== ProjectStatus::Deleted || $row->deleted_at === null) {
            return 'not_in_trash';
        }
        if ($row->purged_at !== null) {
            return 'already_purged';
        }
        if ($automatic) {
            return $row->purge_after !== null && $row->purge_after->isPast() ? null : 'retention_not_passed';
        }
        if ($actor === null || ! $this->holdsPurge($actor, (string) $row->tenant_id)) {
            return 'forbidden';
        }

        return null;
    }

    /**
     * @return array<string,int> table => rows deleted/detached (what WOULD be on a dry run)
     *
     * @throws InvalidLifecycleTransition when {@see self::refusal()} names a reason
     */
    public function purge(Project $project, ?User $actor, bool $automatic, bool $dryRun = false): array
    {
        if (($why = $this->refusal($project, $actor, $automatic)) !== null) {
            throw new InvalidLifecycleTransition('Permanent delete refused: '.$why.'.');
        }
        $tenantId = (string) $project->tenant_id;
        $projectId = (string) $project->getKey();

        $counts = [];
        foreach (ProjectPurgePlan::TABLES as $table => $strategy) {
            if (in_array($strategy, [ProjectPurgePlan::PURGE, ProjectPurgePlan::DETACH], true)) {
                $counts[$table] = $this->scoped($table, $tenantId, $projectId)->count();
            }
        }
        if ($dryRun) {
            return $counts;
        }

        DB::transaction(function () use ($project, $actor, $automatic, $tenantId, $projectId, &$counts): void {
            $row = Project::withoutGlobalScopes()->whereKey($projectId)->lockForUpdate()->firstOrFail();
            if ($row->purged_at !== null) {
                throw new InvalidLifecycleTransition('Permanent delete refused: already_purged.');
            }

            foreach (ProjectPurgePlan::TABLES as $table => $strategy) {
                if ($strategy === ProjectPurgePlan::PURGE) {
                    $counts[$table] = $this->scoped($table, $tenantId, $projectId)->delete();
                } elseif ($strategy === ProjectPurgePlan::DETACH) {
                    $counts[$table] = $this->scoped($table, $tenantId, $projectId)->update(['project_id' => null]);
                }
            }

            // Tombstone: not a status change (it stays `deleted`); the data stamps only, outside the status writer.
            DB::table('projects')->where('id', $projectId)->where('tenant_id', $tenantId)->update([
                'purged_at' => Carbon::now(), 'purged_by' => $actor?->getKey(), 'meta' => null, 'updated_at' => Carbon::now(),
            ]);

            $this->audit->log(
                action: 'project.purged',
                entityType: Project::class,
                entityId: $projectId,
                before: ['name' => $row->name, 'status' => $row->status, 'client_workspace_id' => $row->client_workspace_id],
                after: ['automatic' => $automatic, 'counts' => array_filter($counts), 'name_snapshot' => $row->name],
                reason: $automatic ? 'retention_passed' : 'manual_purge',
                userId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
                tenantId: $tenantId,
            );
            $project->setRawAttributes(Project::withoutGlobalScopes()->whereKey($projectId)->firstOrFail()->getAttributes(), true);
        });

        Log::info('lifecycle.project_purged', ['project_id' => $projectId, 'tenant_id' => $tenantId, 'automatic' => $automatic, 'rows' => array_sum($counts)]);

        return $counts;
    }

    private function scoped(string $table, string $tenantId, string $projectId): Builder
    {
        return DB::table($table)->where('tenant_id', $tenantId)->where('project_id', $projectId);
    }

    /** `projects.purge` in THIS workspace (the bound tenant must be the project's; a key held elsewhere does not count). */
    private function holdsPurge(User $actor, string $tenantId): bool
    {
        return (string) app(TenantContext::class)->tenantId() === $tenantId && $actor->hasPermission('projects.purge');
    }
}
