<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle;

use Illuminate\Contracts\Database\Query\Builder as QueryBuilderContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIFECYCLE-001 §2 — THE one source of «live projects» (rule 5).
 *
 * Every aggregation, alert, sync, notification, digest, email, automation, recommendation, task and picker that
 * selects projects reads this class (or the `live_projects` SQL view it mirrors, or `Project::live()`), never a
 * hand-written `status` filter. `LiveProjectsSourceGuardTest` fails on a hand-written one.
 *
 * - live = active + paused, not soft-deleted. Draft, archived and deleted are never live.
 * - running = active only: the narrower set for work that only makes sense while the project runs (syncing an
 *   account, a daily digest). Still from here, so the narrowing is one decision too.
 * - listed = draft + active + paused: the management lists and the «link an account to…» pickers, which must offer a
 *   project still being set up. Archived and deleted appear only under «المؤرشفة» / «المحذوفات».
 *
 * CLIENT STATE NEVER ENTERS HERE (rule 4): an «onboarding» or «paused» client's live project is live.
 */
final class LiveProjects
{
    /** @var list<string> */
    public const STATUSES = ['active', 'paused'];

    /** @var list<string> */
    public const RUNNING = ['active'];

    /** @var list<string> */
    public const LISTED = ['draft', 'active', 'paused'];

    /** The SQL view (same rows as {@see self::STATUSES}, not soft-deleted). */
    public const VIEW = 'live_projects';

    /**
     * Constrain a query over `projects` (Eloquent or query builder, optionally aliased/joined) to live projects.
     *
     * @template T of QueryBuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function constrain(QueryBuilderContract $query, string $table = 'projects'): QueryBuilderContract
    {
        return self::constrainTo($query, self::STATUSES, $table);
    }

    /**
     * @template T of QueryBuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function constrainRunning(QueryBuilderContract $query, string $table = 'projects'): QueryBuilderContract
    {
        return self::constrainTo($query, self::RUNNING, $table);
    }

    /**
     * @template T of QueryBuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function constrainListed(QueryBuilderContract $query, string $table = 'projects'): QueryBuilderContract
    {
        return self::constrainTo($query, self::LISTED, $table);
    }

    /**
     * `project_id IN (live project ids of this tenant)` for a table that only carries a project_id.
     *
     * @template T of QueryBuilderContract
     *
     * @param  T  $query
     * @return T
     */
    public static function whereProjectLive(QueryBuilderContract $query, string $column = 'project_id', ?string $tenantId = null): QueryBuilderContract
    {
        $query->whereIn($column, self::idsQuery($tenantId));

        return $query;
    }

    /** A sub-select of live project ids (optionally one tenant). Usable in whereIn(). */
    public static function idsQuery(?string $tenantId = null): Builder
    {
        return self::idsQueryFor(self::STATUSES, $tenantId);
    }

    public static function runningIdsQuery(?string $tenantId = null): Builder
    {
        return self::idsQueryFor(self::RUNNING, $tenantId);
    }

    /**
     * @return list<string>
     */
    public static function ids(?string $tenantId = null): array
    {
        return self::idsQuery($tenantId)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }

    /**
     * Of these project ids, the live ones (order kept).
     *
     * @param  iterable<mixed>  $projectIds
     * @return list<string>
     */
    public static function filter(iterable $projectIds): array
    {
        $ids = [];
        foreach ($projectIds as $id) {
            if ($id !== null && $id !== '') {
                $ids[] = (string) $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $live = self::idsQuery()->whereIn('id', array_values(array_unique($ids)))->pluck('id')->map(static fn ($id): string => (string) $id)->all();

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $live, true)));
    }

    public static function isLive(?string $projectId): bool
    {
        return $projectId !== null && $projectId !== '' && self::idsQuery()->where('id', $projectId)->exists();
    }

    public static function isRunning(?string $projectId): bool
    {
        return $projectId !== null && $projectId !== '' && self::runningIdsQuery()->where('id', $projectId)->exists();
    }

    /**
     * @param  list<string>  $statuses
     */
    private static function idsQueryFor(array $statuses, ?string $tenantId): Builder
    {
        $q = DB::table('projects')->select('projects.id')->whereNull('projects.deleted_at')->whereIn('projects.status', $statuses);
        if ($tenantId !== null) {
            $q->where('projects.tenant_id', $tenantId);
        }

        return $q;
    }

    /**
     * @template T of QueryBuilderContract
     *
     * @param  T  $query
     * @param  list<string>  $statuses
     * @return T
     */
    private static function constrainTo(QueryBuilderContract $query, array $statuses, string $table): QueryBuilderContract
    {
        $query->whereIn($table.'.status', $statuses);
        // Said even for Eloquent: `withoutGlobalScopes()` (common in console paths) also drops SoftDeletes.
        $query->whereNull($table.'.deleted_at');

        return $query;
    }
}
