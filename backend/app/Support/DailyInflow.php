<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * UX-COMPACT-CHARTS-001 — how many records arrived per day, over the rows a list is already scoped and filtered to.
 *
 * Only a flow can be a trend: when a record arrived is history, while «new» or «needs attention» are states with no past.
 * A day with no row is a real zero — these are the workspace's own records, not a provider's report that may be missing.
 */
final class DailyInflow
{
    /** @return list<array{date: string, count: int}> oldest first, every day of the window present */
    public static function series(Builder $query, string $column, int $days = 14): array
    {
        $start = Carbon::now()->utc()->startOfDay()->subDays($days - 1);
        $counts = (clone $query)->reorder()->toBase()
            ->select(DB::raw("DATE({$column}) as day"), DB::raw('COUNT(*) as n'))
            ->where($column, '>=', $start)
            ->groupBy('day')
            ->pluck('n', 'day');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $day, 'count' => (int) ($counts[$day] ?? 0)];
        }

        return $out;
    }
}
