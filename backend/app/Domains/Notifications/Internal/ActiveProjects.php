<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Internal;

use Illuminate\Support\Facades\DB;

/**
 * NOTIF-V2-INTERNAL-001 — «active projects only» (spec §5): an archived, completed or paused project raises no operational
 * notification and gets no follow-up task.
 *
 * ## Merge point
 * Builder N1 is adding a shared live-project scope on another branch. This class is the ONE adapter: when that scope
 * lands, `isLive()` and `liveIds()` delegate to it and nothing else in this batch changes. Until then the rule is the
 * project's own status: live = `active` or `onboarding`, and not soft-deleted.
 */
final class ActiveProjects
{
    public const LIVE_STATUSES = ['active', 'onboarding'];

    public static function isLive(string $tenantId, string $projectId): bool
    {
        return DB::table('projects')->where('tenant_id', $tenantId)->where('id', $projectId)->whereNull('deleted_at')
            ->whereIn('status', self::LIVE_STATUSES)->exists();
    }

    /** @return list<string> */
    public static function liveIds(string $tenantId): array
    {
        return DB::table('projects')->where('tenant_id', $tenantId)->whereNull('deleted_at')->whereIn('status', self::LIVE_STATUSES)
            ->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<string> the ones that are live
     */
    public static function filter(string $tenantId, array $ids): array
    {
        return $ids === [] ? [] : DB::table('projects')->where('tenant_id', $tenantId)->whereNull('deleted_at')
            ->whereIn('status', self::LIVE_STATUSES)->whereIn('id', $ids)->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }
}
