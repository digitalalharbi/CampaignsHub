<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use App\Domains\Projects\Lifecycle\LiveProjects;

/**
 * NOTIF-V2-INTERNAL-001 — «active projects only» (spec §5): an archived or deleted project raises no operational
 * notification and gets no follow-up task.
 *
 * RELEASE-INTEGRATION — the adapter now delegates to PROJECT-LIFECYCLE-001's one live scope (`LiveProjects`: active + paused,
 * not soft-deleted). The project-lifecycle owner spec puts alerts, sync and aggregation on that set, so a paused project's
 * operational notices keep arriving like its alerts do.
 */
final class ActiveProjects
{
    public static function isLive(string $tenantId, string $projectId): bool
    {
        return LiveProjects::idsQuery($tenantId)->where('projects.id', $projectId)->exists();
    }

    /** @return list<string> */
    public static function liveIds(string $tenantId): array
    {
        return LiveProjects::ids($tenantId);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> the ones that are live
     */
    public static function filter(string $tenantId, array $ids): array
    {
        return $ids === [] ? [] : LiveProjects::idsQuery($tenantId)->whereIn('projects.id', $ids)
            ->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }
}
