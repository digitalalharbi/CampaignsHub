<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DATE-RANGE-001 §4 — which days of a period each account has NOT given us, read from what was recorded.
 *
 * ## What counts as «not given»
 *
 * An actively bound ad account owes one answer per complete day of the period. A day is answered when
 * EITHER a `daily_metrics` row exists for that account and day, OR a sync run that finished `success` or
 * `no_data` covers it (we asked, and the platform said there was nothing — a quiet account is not a broken
 * one). A day answered by neither is missing, and it is `sync_failed` when a run that FAILED covers it and
 * `no_rows` otherwise. Those two words are everything this reports: the backend never invents a cause, so
 * the screen cannot name one the sync history does not hold.
 *
 * ## What is NOT a gap
 *
 * - Today. It is not finished; the period carries its own «جاري — غير مكتمل» flag for that.
 * - Days before an account's first recorded row. An account connected on 1 November is not «missing» for
 *   October: it did not exist here yet.
 * - An account that has NEVER produced a row: that is «awaiting first sync», reported as `never_synced`,
 *   not as thirty missing days.
 *
 * ## Time zones
 *
 * The period is Riyadh time; each account's DATES are its own (`external_accounts.timezone`). An account
 * whose zone differs is flagged `timezone_differs` — the platform's «16 October» and ours are not the same
 * hours, and the tooltip says so rather than pretending the totals line up to the minute.
 */
final class PeriodCoverage
{
    /** A tooltip lists at most this many days per account; the count is always the full one. */
    public const LISTED_DAYS = 12;

    /**
     * @param  list<string>|null  $providers
     * @return array<string,mixed>
     */
    public function forProject(string $projectId, string $from, string $to, ?array $providers = null, ?Carbon $now = null): array
    {
        $today = PeriodResolver::today($now);
        $a = Carbon::parse($from, PeriodResolver::TIMEZONE)->startOfDay();
        $b = Carbon::parse($to, PeriodResolver::TIMEZONE)->startOfDay();
        $ongoing = $b->gte($today);
        // Only complete days are owed.
        $owedEnd = $b->lt($today) ? $b : $today->copy()->subDay();

        $accounts = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->where('b.project_id', $projectId)
            ->where('b.is_active', true)
            ->where('a.account_type', 'ad_account')
            ->when($providers !== null && $providers !== [], fn ($q) => $q->whereIn('a.provider', $providers))
            ->select('a.id', 'a.name', 'a.provider', 'a.timezone')
            ->orderBy('a.provider')->orderBy('a.name')
            ->get();

        $rows = [];

        foreach ($accounts as $acc) {
            $rows[] = $this->account($projectId, $acc, $a, $owedEnd);
        }

        $incomplete = array_values(array_filter($rows, static fn (array $r): bool => $r['missing_count'] > 0));

        return [
            'period' => ['from' => $a->toDateString(), 'to' => $b->toDateString(), 'ongoing' => $ongoing, 'timezone' => PeriodResolver::TIMEZONE],
            'ongoing' => $ongoing,
            'incomplete' => $incomplete !== [],
            'accounts' => $rows,
            'summary' => [
                'accounts_checked' => count($rows),
                'accounts_incomplete' => count($incomplete),
                'missing_days_total' => array_sum(array_column($rows, 'missing_count')),
                'accounts_other_timezone' => count(array_filter($rows, static fn (array $r): bool => $r['timezone_differs'])),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function account(string $projectId, object $acc, Carbon $from, Carbon $owedEnd): array
    {
        $tz = is_string($acc->timezone) && $acc->timezone !== '' ? $acc->timezone : null;
        $base = [
            'account_id' => (string) $acc->id,
            'name' => (string) $acc->name,
            'provider' => (string) $acc->provider,
            'timezone' => $tz,
            'timezone_differs' => $tz !== null && ! $this->sameOffset($tz, $from),
        ];

        $first = DB::table('daily_metrics')
            ->where('project_id', $projectId)->where('external_account_id', $acc->id)
            ->min('metric_date');

        if ($first === null) {
            return $base + ['never_synced' => true, 'missing_count' => 0, 'failed_count' => 0, 'missing_days' => [], 'failed_days' => [], 'truncated' => false];
        }

        $start = Carbon::parse((string) $first, PeriodResolver::TIMEZONE)->startOfDay()->max($from);

        if ($start->gt($owedEnd)) {
            return $base + ['never_synced' => false, 'missing_count' => 0, 'failed_count' => 0, 'missing_days' => [], 'failed_days' => [], 'truncated' => false];
        }

        $have = DB::table('daily_metrics')
            ->where('project_id', $projectId)->where('external_account_id', $acc->id)
            ->whereBetween('metric_date', [$start->toDateString(), $owedEnd->toDateString()])
            ->distinct()->pluck('metric_date')
            ->map(static fn ($d): string => substr((string) $d, 0, 10))->all();
        $answered = array_fill_keys($have, true);
        $failedCover = [];

        $runs = DB::table('metric_sync_runs')
            ->where('project_id', $projectId)->where('external_account_id', $acc->id)
            ->where('window_end', '>=', $start->toDateString())->where('window_start', '<=', $owedEnd->toDateString())
            ->get(['status', 'window_start', 'window_end']);

        foreach ($runs as $run) {
            $ok = in_array($run->status, ['success', 'no_data'], true);
            $bad = in_array($run->status, ['failed', 'partial_mapping'], true);

            if (! $ok && ! $bad) {
                continue;
            }

            $d = Carbon::parse((string) $run->window_start, PeriodResolver::TIMEZONE)->startOfDay()->max($start);
            $e = Carbon::parse((string) $run->window_end, PeriodResolver::TIMEZONE)->startOfDay()->min($owedEnd);

            for (; $d->lte($e); $d->addDay()) {
                $key = $d->toDateString();
                $ok ? $answered[$key] = true : $failedCover[$key] = true;
            }
        }

        $missing = [];
        $failed = [];

        for ($d = $start->copy(); $d->lte($owedEnd); $d->addDay()) {
            $key = $d->toDateString();

            if (isset($answered[$key])) {
                continue;
            }

            $missing[] = $key;

            if (isset($failedCover[$key])) {
                $failed[] = $key;
            }
        }

        return $base + [
            'never_synced' => false,
            'missing_count' => count($missing),
            'failed_count' => count($failed),
            'missing_days' => array_slice($missing, 0, self::LISTED_DAYS),
            'failed_days' => array_slice($failed, 0, self::LISTED_DAYS),
            'truncated' => count($missing) > self::LISTED_DAYS,
        ];
    }

    private function sameOffset(string $zone, Carbon $at): bool
    {
        try {
            return (new \DateTimeZone($zone))->getOffset($at->toDateTime()) === (new \DateTimeZone(PeriodResolver::TIMEZONE))->getOffset($at->toDateTime());
        } catch (\Throwable) {
            return true; // an unreadable zone name is not evidence of a difference
        }
    }
}
