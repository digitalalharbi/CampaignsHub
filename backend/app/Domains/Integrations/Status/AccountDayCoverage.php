<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use App\Domains\Campaigns\Enums\CampaignStatus;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Support\AdPlatforms;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LIFECYCLE-6 (Owner spec 2026-11-15 §6) — did an ad account's data for a day arrive? Judged per (project, account),
 * from the SYNC RECORD, never from metric rows.
 *
 * ## The definition
 *
 * Day D is covered for an account when a successful sync (`success`, `no_data`, `partial_mapping` — the platform
 * answered) whose window includes D finished at least {@see settleHours()} after the END of D in the ACCOUNT's own
 * time zone (`external_accounts.timezone`; Riyadh when the platform never told us one). A day with zero spend, or
 * whose campaigns are paused, is a day the platform answered: covered, never a gap.
 *
 * The day that must have arrived (`required_day`) is the latest D whose end + settle window + one sync cycle
 * ({@see cycleMinutes()}) has passed, so the verdict is given after the sync cycle that should have covered it.
 *
 * ## The verdicts
 *
 * - `covered` — nothing to say.
 * - `no_coverage` — the required day is not covered.
 * - `last_sync_failed` — the latest finished run failed and no run has succeeded for longer than one cycle
 *   (§7أ of the notification rules: never on a single transient failure).
 * - `link_expired` — the platform refused the connection (`provider_connections.status = error`).
 * - `never_synced` — not one finished run on record: awaiting a first sync, which is a setup state, not an outage.
 *
 * Only LIVE projects are judged — N1's one shared scope ({@see LiveProjects}, active + paused): archived, deleted and
 * draft projects never are (PROJECT-LIFECYCLE-001 rule 5).
 */
final class AccountDayCoverage
{
    /** A run that reached the platform and read what it asked for — the same three `LinkHealth` counts. */
    public const SUCCEEDED = ['success', 'no_data', 'partial_mapping'];

    public const FALLBACK_TIMEZONE = 'Asia/Riyadh';

    public const COVERED = 'covered';

    public const NO_COVERAGE = 'no_coverage';

    public const LAST_SYNC_FAILED = 'last_sync_failed';

    public const LINK_EXPIRED = 'link_expired';

    public const NEVER_SYNCED = 'never_synced';

    /** The verdicts that are an alert. */
    public const ALERTING = [self::NO_COVERAGE, self::LAST_SYNC_FAILED, self::LINK_EXPIRED];

    /** Connection states that mean the platform refused the link. */
    public const EXPIRED_CONNECTION = ['error', 'expired'];

    /** Runs older than this are not read — the same month `LinkHealth` reads. */
    private const LOOKBACK_DAYS = 30;

    /** @param  array<string,mixed>|null  $threshold  a rule's threshold; `settle_hours` there wins over the setting */
    public static function settleHours(?array $threshold = null): int
    {
        $own = $threshold['settle_hours'] ?? null;

        return max(0, is_numeric($own) ? (int) $own : (int) config('integrations.data_coverage.settle_hours', 6));
    }

    public static function cycleMinutes(): int
    {
        return max(0, (int) config('integrations.data_coverage.cycle_minutes', 60));
    }

    /** The account's own zone, or Riyadh when it has none or an unreadable one. */
    public static function timezone(?string $zone): string
    {
        if ($zone === null || $zone === '') {
            return self::FALLBACK_TIMEZONE;
        }
        try {
            new \DateTimeZone($zone);

            return $zone;
        } catch (\Throwable) {
            return self::FALLBACK_TIMEZONE;
        }
    }

    /** The latest account-local day whose end + settle + one cycle has passed by `$now`. */
    public static function requiredDay(string $zone, Carbon $now, int $settleHours, int $cycleMinutes): string
    {
        return $now->copy()->setTimezone($zone)->subHours($settleHours)->subMinutes($cycleMinutes)->startOfDay()->subDay()->toDateString();
    }

    /** The last account-local day a run finished at `$finishedAt` could have covered: its end + settle window had passed. */
    private static function lastSettledDay(string $finishedAt, string $zone, int $settleHours): string
    {
        return Carbon::parse($finishedAt, 'UTC')->setTimezone($zone)->subHours($settleHours)->startOfDay()->subDay()->toDateString();
    }

    /**
     * The actively bound ad accounts of LIVE projects, with what a verdict and an alert need to name them.
     *
     * @return list<object{project_id: string, project_name: string, external_account_id: string, provider: string, account_name: ?string, external_id: ?string, timezone: ?string, connection_id: ?string, connection_status: ?string}>
     */
    public function liveBindings(string $tenantId, ?string $projectId = null, ?string $connectionId = null, ?string $provider = null, ?string $accountId = null): array
    {
        $query = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->join('projects as p', 'p.id', '=', 'b.project_id')
            ->leftJoin('provider_connections as c', 'c.id', '=', 'a.provider_connection_id')
            ->where('b.tenant_id', $tenantId)
            ->where('b.is_active', true)
            ->where('a.account_type', 'ad_account')
            ->when($projectId !== null, fn ($q) => $q->where('b.project_id', $projectId))
            ->when($connectionId !== null, fn ($q) => $q->where('a.provider_connection_id', $connectionId))
            ->when($provider !== null, fn ($q) => $q->where('b.provider', $provider))
            ->when($accountId !== null, fn ($q) => $q->where('b.external_account_id', $accountId));
        LiveProjects::constrain($query, 'p');

        return array_values(array_filter($query
            ->orderBy('p.name')->orderBy('a.name')
            ->get(['b.project_id', 'p.name as project_name', 'b.external_account_id', 'b.provider', 'a.name as account_name', 'a.external_id', 'a.timezone', 'a.provider_connection_id as connection_id', 'c.status as connection_status'])
            ->all(), static fn (object $b): bool => in_array(AdPlatforms::canonical((string) $b->provider), AdPlatforms::ORDER, true)));
    }

    /**
     * One verdict per binding, from two statements whatever the number of bindings.
     *
     * @param  list<object>  $bindings  rows of {@see liveBindings()}
     * @return list<array<string,mixed>>
     */
    public function judge(array $bindings, Carbon $now, ?int $settleHours = null): array
    {
        if ($bindings === []) {
            return [];
        }
        $settle = $settleHours ?? self::settleHours();
        $cycle = self::cycleMinutes();
        $runs = DB::table('metric_sync_runs')
            ->whereIn('external_account_id', array_values(array_unique(array_map(static fn (object $b): string => (string) $b->external_account_id, $bindings))))
            ->whereIn('project_id', array_values(array_unique(array_map(static fn (object $b): string => (string) $b->project_id, $bindings))))
            // A success proves coverage only once it finished; a failure counts from when it was recorded, finished or not.
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereIn('status', self::SUCCEEDED)->whereNotNull('finished_at'))->orWhere('status', 'failed'))
            ->selectRaw('project_id, external_account_id, status, window_start, window_end, COALESCE(finished_at, started_at, created_at) AS finished_at')
            ->whereRaw('COALESCE(finished_at, started_at, created_at) BETWEEN ? AND ?', [$now->copy()->subDays(self::LOOKBACK_DAYS), $now])
            ->orderByRaw('COALESCE(finished_at, started_at, created_at)')
            ->get()
            ->groupBy(static fn (object $r): string => $r->project_id.'|'.$r->external_account_id);

        $out = [];
        foreach ($bindings as $b) {
            $out[] = $this->verdict($b, $runs->get($b->project_id.'|'.$b->external_account_id, collect())->all(), $now, $settle, $cycle);
        }

        return $out;
    }

    /**
     * @param  list<object>  $runs  this binding's finished runs, oldest first
     * @return array<string,mixed>
     */
    private function verdict(object $b, array $runs, Carbon $now, int $settle, int $cycle): array
    {
        $zone = self::timezone(is_string($b->timezone ?? null) ? $b->timezone : null);
        $required = self::requiredDay($zone, $now, $settle, $cycle);
        $covered = false;
        $lastDay = null;
        $lastSuccess = null;
        $latest = null;
        foreach ($runs as $run) {
            $latest = $run;
            if (! in_array($run->status, self::SUCCEEDED, true)) {
                continue;
            }
            $lastSuccess = $run;
            $start = substr((string) $run->window_start, 0, 10);
            $end = min(substr((string) $run->window_end, 0, 10), self::lastSettledDay((string) $run->finished_at, $zone, $settle));
            if ($end < $start) {
                continue;
            }
            $lastDay = $lastDay === null ? $end : max($lastDay, $end);
            if ($start <= $required && $required <= $end) {
                $covered = true;
            }
        }
        $failedLatest = $latest !== null && $latest->status === 'failed';
        $successStale = $lastSuccess === null || Carbon::parse((string) $lastSuccess->finished_at, 'UTC')->lessThan($now->copy()->subMinutes($cycle));

        $verdict = match (true) {
            in_array((string) ($b->connection_status ?? ''), self::EXPIRED_CONNECTION, true) => self::LINK_EXPIRED,
            $latest === null => self::NEVER_SYNCED,
            ! $covered => self::NO_COVERAGE,
            $failedLatest && $successStale => self::LAST_SYNC_FAILED,
            default => self::COVERED,
        };

        $success = $lastSuccess === null ? null : [
            'finished_at' => Carbon::parse((string) $lastSuccess->finished_at, 'UTC')->toIso8601String(),
            'window_start' => substr((string) $lastSuccess->window_start, 0, 10),
            'window_end' => substr((string) $lastSuccess->window_end, 0, 10),
        ];

        $reason = self::reason($verdict, $required, $zone, $settle, $success);
        if ($verdict === self::NO_COVERAGE && $failedLatest) {
            $reason['reason_ar'] .= ' آخر محاولة مزامنة فشلت.';
            $reason['reason_en'] .= ' The latest sync attempt failed.';
        }

        return [
            'project_id' => (string) $b->project_id,
            'project_name' => (string) ($b->project_name ?? ''),
            'account_id' => (string) $b->external_account_id,
            'account_name' => (string) ($b->account_name ?? ''),
            'account_external_id' => (string) ($b->external_id ?? ''),
            'provider' => (string) $b->provider,
            'connection_id' => $b->connection_id !== null ? (string) $b->connection_id : null,
            'timezone' => $zone,
            'settle_hours' => $settle,
            'verdict' => $verdict,
            'required_day' => $required,
            'last_day' => $lastDay,
            'missing_days' => $verdict !== self::NO_COVERAGE ? 0
                : ($lastDay === null ? null : max(0, (int) Carbon::parse($lastDay, 'UTC')->diffInDays(Carbon::parse($required, 'UTC')))),
            'last_success' => $success,
            'last_failed_at' => $failedLatest ? Carbon::parse((string) $latest->finished_at, 'UTC')->toIso8601String() : null,
        ] + $reason;
    }

    /**
     * PROJECT-LIFECYCLE-001 (N4, finishing N3's deferral) — every bound ad platform of a project, judged by THIS rule (per
     * account, in the account's own time zone, after the settle window and one cycle), in the reading shape the digest's
     * «ملخص الربط» has always drawn. A platform has arrived when none of its accounts has an alerting verdict; a binding still
     * awaiting its first sync is a setup state, not a gap (the same as the alert).
     *
     * @return array<string, array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int}>
     */
    public function projectReadings(string $tenantId, string $projectId, Carbon $now): array
    {
        $byProvider = [];
        foreach ($this->judge($this->liveBindings($tenantId, $projectId), $now) as $v) {
            $byProvider[(string) $v['provider']][] = $v;
        }

        $out = [];
        foreach ($byProvider as $provider => $verdicts) {
            $out[$provider] = self::providerReading($verdicts);
        }

        return $out;
    }

    /**
     * Why a project shows no ad spend for `$day` — one of `LinkHealth::STATES`, never a blend — read through THIS rule. Null
     * when the project has no actively bound ad account of a live project («nothing is linked», said by the caller).
     */
    public function noDataState(string $tenantId, string $projectId, Carbon $day, Carbon $now): ?string
    {
        $verdicts = $this->judge($this->liveBindings($tenantId, $projectId), $now);
        if ($verdicts === []) {
            return null;
        }

        $judged = $day->toDateString();
        foreach ($verdicts as $v) {
            // The day asked about may not be due yet for this account: it is judged against what is due.
            $due = min($judged, (string) $v['required_day']);
            if (in_array($v['verdict'], self::ALERTING, true) && ($v['last_day'] === null || $v['last_day'] < $due)) {
                return 'not_synced';
            }
        }

        $running = DB::table('unified_campaigns')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('status', CampaignStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $judged))
            ->exists();

        return $running ? 'no_spend' : 'not_running';
    }

    /**
     * @param  non-empty-list<array<string,mixed>>  $verdicts  one platform's accounts
     * @return array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int}
     */
    private static function providerReading(array $verdicts): array
    {
        $alerting = array_values(array_filter($verdicts, static fn (array $v): bool => in_array($v['verdict'], self::ALERTING, true)));
        $pool = $alerting === [] ? $verdicts : $alerting;
        $days = array_values(array_filter(array_map(static fn (array $v): ?string => $v['last_day'], $pool), static fn (?string $d): bool => $d !== null));
        $missing = array_values(array_filter(array_map(static fn (array $v): ?int => $v['missing_days'], $pool), static fn (?int $m): bool => $m !== null));

        return [
            'arrived' => $alerting === [],
            'required_day' => (string) max(array_map(static fn (array $v): string => (string) $v['required_day'], $pool)),
            'last_day' => $days === [] ? null : min($days),
            'missing_days' => $alerting === [] ? 0 : ($missing === [] ? null : max($missing)),
        ];
    }

    /**
     * The verdict in the reader's words — what the record shows, never a guessed cause.
     *
     * @param  array{finished_at: string, window_start: string, window_end: string}|null  $success
     * @return array{reason_ar: string, reason_en: string}
     */
    public static function reason(string $verdict, string $day, string $zone, int $settle, ?array $success): array
    {
        $since = $success === null ? null : Carbon::parse($success['finished_at'])->setTimezone(self::FALLBACK_TIMEZONE)->format('Y-m-d H:i');

        return match ($verdict) {
            self::NO_COVERAGE => [
                'reason_ar' => 'ما نجحت أي مزامنة تغطي يوم '.$day.' بعد انتهائه بتوقيت الحساب ('.$zone.') ومهلة '.$settle.' ساعات.',
                'reason_en' => 'No successful sync covered '.$day.' after it ended in the account time zone ('.$zone.') plus the '.$settle.'-hour settle window.',
            ],
            self::LAST_SYNC_FAILED => [
                'reason_ar' => 'آخر مزامنة فشلت'.($since !== null ? '، وآخر مزامنة ناجحة '.$since.' بتوقيت الرياض.' : '، ولا مزامنة ناجحة مسجلة.'),
                'reason_en' => 'The latest sync failed'.($since !== null ? '; the last successful sync finished '.$since.' Riyadh time.' : '; no successful sync is on record.'),
            ],
            self::LINK_EXPIRED => [
                'reason_ar' => 'المنصة رفضت صلاحية الربط، فالمزامنة متوقفة.',
                'reason_en' => 'The platform refused the connection, so syncing has stopped.',
            ],
            default => ['reason_ar' => '', 'reason_en' => ''],
        };
    }
}
