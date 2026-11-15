<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LIFECYCLE-6 — TEMPORARY adapter: which projects the data-arrival alert may speak about.
 *
 * The Owner spec (2026-11-15, rule 5) wants ONE shared «live projects» scope (active + paused) used by every alert,
 * sync and aggregation. Builder N1 owns that scope. Until it lands, this is the single place the data-arrival
 * rule ({@see AccountDayCoverage}) and its ops command exclude archived and (soft-)deleted projects. **When N1's
 * scope lands, replace the body of {@see constrain()} with it and delete nothing else** — every caller goes
 * through here.
 */
final class LiveProjectsAdapter
{
    /** Project states that are never alerted on. `deleted` is listed for the status N1 adds; today deletion is `deleted_at`. */
    public const NOT_LIVE = ['archived', 'deleted'];

    /** Narrows a query that joins `projects` (under `$alias`) to live projects. */
    public static function constrain(Builder $query, string $alias = 'projects'): Builder
    {
        return $query->whereNull($alias.'.deleted_at')->whereNotIn($alias.'.status', self::NOT_LIVE);
    }

    /**
     * The live ones among `$projectIds`, in one statement.
     *
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    public static function liveIds(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds, static fn (string $id): bool => $id !== '')));

        return $projectIds === [] ? [] : self::constrain(DB::table('projects')->whereIn('projects.id', $projectIds))
            ->pluck('projects.id')->map(static fn ($id): string => (string) $id)->all();
    }

    public static function isLive(?string $projectId): bool
    {
        if ($projectId === null || $projectId === '') {
            return false;
        }

        return self::constrain(DB::table('projects')->where('projects.id', $projectId))->exists();
    }
}
