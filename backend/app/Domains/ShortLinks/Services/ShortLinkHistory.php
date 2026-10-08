<?php

declare(strict_types=1);

namespace App\Domains\ShortLinks\Services;

use App\Domains\ShortLinks\Models\ShortLinkHop;
use Illuminate\Support\Carbon;

/**
 * SHORT-LINK-HOPS-001 — the recorded follows, and an honest statement of what was not recorded.
 *
 * Every method here answers over `short_link_hops`, which began on the day the table did. The
 * counter on `short_links` covers all of time. The two therefore DISAGREE for any link that existed
 * before recording started, and the disagreement is the point: it is the difference between «this
 * link was followed 85 times» and «we can tell you when 12 of those happened».
 *
 * So nothing here returns a bare series. Every answer carries `recording_since` and the two totals,
 * and the surfaces are expected to say so. A curve drawn over a period nobody measured is the same
 * lie as a zero where a figure is unavailable, and this product does not tell it.
 */
final class ShortLinkHistory
{
    /**
     * The first moment this installation recorded, or null if it has recorded nothing yet.
     *
     * Read from the data rather than from the migration's date: a database restored from an older
     * dump, or a fresh tenant on an old installation, would make the migration date a claim about
     * history that the rows do not support.
     */
    public function recordingSince(): ?Carbon
    {
        $first = ShortLinkHop::query()->min('occurred_at');

        return $first === null ? null : Carbon::parse($first);
    }

    /**
     * Follows per day for one link, over a window, as `['2026-10-01' => 4, …]`.
     *
     * Days with no follows are ABSENT rather than zero. A caller drawing a line wants a zero there
     * and can fill it, but only for days inside the recorded period — which is why the caller is
     * given `recording_since` and told to do the filling itself rather than being handed a series
     * that has already decided an unmeasured day was a quiet one.
     *
     * @return array<string, int>
     */
    public function dailyFor(string $shortLinkId, Carbon $from, Carbon $to): array
    {
        /** @var array<int, object{day: string, follows: int}> $rows */
        $rows = ShortLinkHop::query()
            ->where('short_link_id', $shortLinkId)
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('date(occurred_at) as day, count(*) as follows')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[Carbon::parse($row->day)->toDateString()] = (int) $row->follows;
        }

        return $out;
    }

    /**
     * Follows per day across the whole workspace, as `['2026-10-01' => 11, …]`.
     *
     * The same shape and the same caveat as {@see dailyFor()}: absent days are unmeasured or quiet
     * and the caller is told where recording began so it can tell the two apart. Tenant-scoped by
     * the model, so this is the signed-in workspace's own links and no one else's.
     *
     * @return array<string, int>
     */
    public function dailyForTenant(Carbon $from, Carbon $to): array
    {
        /** @var array<int, object{day: string, follows: int}> $rows */
        $rows = ShortLinkHop::query()
            ->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw('date(occurred_at) as day, count(*) as follows')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[Carbon::parse($row->day)->toDateString()] = (int) $row->follows;
        }

        return $out;
    }

    /**
     * A day-by-day series between two dates, filled with zeroes ONLY from `$since` onward.
     *
     * The filling is the part worth having in one place. Every caller wants a continuous line and
     * every caller would be wrong to draw one across days nobody measured, so the rule lives here:
     * a day before recording began is not in the series at all.
     *
     * @param  array<string, int>  $daily
     * @return list<array{day: string, follows: int}>
     */
    public function fill(array $daily, Carbon $from, Carbon $to, ?Carbon $since): array
    {
        if ($since === null) {
            return [];
        }

        $series = [];
        $cursor = $from->copy()->startOfDay();
        $start = $since->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($to)) {
            if ($cursor->greaterThanOrEqualTo($start)) {
                $day = $cursor->toDateString();
                $series[] = ['day' => $day, 'follows' => $daily[$day] ?? 0];
            }

            $cursor->addDay();
        }

        return $series;
    }

    /**
     * How many follows were RECORDED for each link, keyed by link id.
     *
     * Deliberately not «how many clicks a link has» — that is the counter, and it is larger wherever
     * the link predates recording. The two are returned side by side so a surface can state the
     * gap instead of implying there is none.
     *
     * @param  list<string>  $shortLinkIds
     * @return array<string, int>
     */
    public function recordedTotals(array $shortLinkIds, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if ($shortLinkIds === []) {
            return [];
        }

        $query = ShortLinkHop::query()->whereIn('short_link_id', $shortLinkIds);

        if ($from !== null && $to !== null) {
            $query->whereBetween('occurred_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        }

        /** @var array<int, object{short_link_id: string, follows: int}> $rows */
        $rows = $query
            ->selectRaw('short_link_id, count(*) as follows')
            ->groupBy('short_link_id')
            ->get()
            ->all();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->short_link_id] = (int) $row->follows;
        }

        return $out;
    }
}
