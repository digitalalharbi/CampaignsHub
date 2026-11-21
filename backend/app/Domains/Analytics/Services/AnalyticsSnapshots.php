<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Services;

use App\Domains\Analytics\Models\AnalyticsSnapshot;
use App\Domains\Analytics\Support\AnalyticsReadCache;
use Illuminate\Support\Carbon;

/**
 * ANALYTICS-DATA-001 — the stored, pre-aggregated heavy reads.
 *
 * One row per tenant, project, section and window. {@see fresh()} answers only while the row is younger than
 * MAX_AGE_MINUTES; an older row is ignored, never served, so a stalled job degrades to «computed live» and not to
 * «stale numbers». Both reads and writes name the tenant AND the project, and the unique index is on both.
 */
final class AnalyticsSnapshots
{
    public const MAX_AGE_MINUTES = 360;

    /** @return array<string, mixed>|null */
    public function fresh(string $tenantId, string $projectId, string $section, string $from, string $to, ?Carbon $now = null): ?array
    {
        $row = AnalyticsSnapshot::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('section', $section)
            ->where('period_from', $from)->where('period_to', $to)
            ->first(['payload', 'computed_at']);

        if ($row === null || $row->computed_at->lt(($now ?? Carbon::now())->copy()->subMinutes(self::MAX_AGE_MINUTES))) {
            return null;
        }

        return $row->payload + ['snapshot_at' => $row->computed_at->toIso8601String()];
    }

    /** @param  array<string, mixed>  $payload */
    public function store(string $tenantId, string $projectId, string $section, string $from, string $to, array $payload, ?Carbon $now = null): void
    {
        AnalyticsSnapshot::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenantId, 'project_id' => $projectId, 'section' => $section, 'period_from' => $from, 'period_to' => $to],
            ['payload' => $payload, 'computed_at' => $now ?? Carbon::now()],
        );
        AnalyticsReadCache::bump($tenantId, $projectId);
    }

    /**
     * Drop a project's stored sections after something they were built from changed through our own code (an
     * influencer link was minted): the next read computes, instead of serving a row that does not know.
     *
     * @param  list<string>  $sections
     */
    public function forget(string $tenantId, string $projectId, array $sections): void
    {
        AnalyticsSnapshot::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('project_id', $projectId)->whereIn('section', $sections)->delete();
        AnalyticsReadCache::bump($tenantId, $projectId);
    }
}
