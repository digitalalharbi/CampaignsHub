<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Status;

use App\Domains\Campaigns\Enums\CampaignStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * LINK-HEALTH-YESTERDAY-001 — the ONE rule every surface uses to decide whether a link needs anyone's attention
 * (`docs/OWNER_NOTIFICATIONS_RULES_2026-11-12.md` §7أ, the decisive criterion).
 *
 * A link alert exists only when YESTERDAY's data did not arrive: by the morning (Riyadh, after {@see GRACE_HOUR}), no
 * successful sync covered the whole of yesterday. Nothing else is a link alert — not an access token's hourly expiry, not
 * a failed run that a later run made good, not a «stale» reading of a seven-day window. The alerts engine filters every
 * connection-type breach through {@see forConnection()} / {@see forProjectProvider()}, so the alert email, the bell, the
 * Recommendations page («تتبع وبيانات», «ما يحتاج قرارًا الآن») and the morning digest all read the same answer.
 *
 * And «no data» is three different things that are never mixed, each with its own words ({@see STATES}):
 *   1. `not_synced`  — the link: yesterday's data did not arrive;
 *   2. `not_running` — the campaigns ended or are paused (their platform status);
 *   3. `no_spend`    — the link is healthy and a campaign is active, but the platform spent nothing.
 */
final class LinkHealth
{
    public const TIMEZONE = 'Asia/Riyadh';

    /**
     * Before this hour the day that must have arrived is the day BEFORE yesterday: syncs run every 30 minutes and the
     * full re-read at 03:50, so at 00:10 nobody can yet expect yesterday to be complete.
     */
    public const GRACE_HOUR = 6;

    /** The rule types that speak about a link. Every one of them goes through this rule. */
    public const TYPES = ['sync_failure', 'sync_outage', 'measurement_outage', 'token_expiry'];

    /** A run that reached the platform and read what it asked for (`DataFreshnessService` counts the same three). */
    private const SUCCEEDED = ['success', 'no_data', 'partial_mapping'];

    /** Days missing at which the alert escalates to critical. */
    public const ESCALATE_AFTER_DAYS = 2;

    /** @var array<string, array{ar: string, en: string}> */
    public const STATES = [
        'not_synced' => ['ar' => 'بيانات أمس ما وصلت من المنصة', 'en' => 'Yesterday’s data did not arrive from the platform'],
        'not_running' => ['ar' => 'الحملات منتهية أو موقوفة في المنصة', 'en' => 'The campaigns are ended or paused on the platform'],
        'no_spend' => ['ar' => 'الربط سليم والحملة فعّالة، لكن المنصة ما صرفت', 'en' => 'The link is healthy and a campaign is active, but the platform spent nothing'],
    ];

    /** The local day that must have arrived by `$now`: yesterday, or the day before it before {@see GRACE_HOUR}. */
    public static function requiredDay(Carbon $now): Carbon
    {
        $local = $now->copy()->setTimezone(self::TIMEZONE);

        return $local->copy()->startOfDay()->subDays($local->hour < self::GRACE_HOUR ? 2 : 1);
    }

    public static function text(string $state, bool $ar): string
    {
        return self::STATES[$state][$ar ? 'ar' : 'en'] ?? $state;
    }

    /**
     * One connection: did a successful sync (ad metrics, or a website/store run) cover the required day?
     *
     * @return array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int}
     */
    public function forConnection(string $connectionId, Carbon $now): array
    {
        return $this->memo['c|'.$connectionId.'|'.$now->format('U.u')] ??= $this->readConnection($connectionId, $now);
    }

    /**
     * Reads many connections in two statements (a sweep's whole breach list), so the rule costs two queries per sweep rather
     * than two per connection. Each connection's successful runs of the last month decide.
     *
     * @param  list<string>  $connectionIds
     */
    public function prime(array $connectionIds, Carbon $now): void
    {
        $ids = array_values(array_unique(array_filter($connectionIds)));
        if ($ids === []) {
            return;
        }
        $best = [];
        $runs = DB::table('metric_sync_runs')->whereIn('connection_id', $ids)->whereIn('status', self::SUCCEEDED)
            ->whereNotNull('finished_at')->whereNotNull('window_end')->where('finished_at', '>=', $now->copy()->subDays(30))
            ->get(['connection_id', 'window_start', 'window_end', 'finished_at']);
        foreach ($runs as $run) {
            $day = self::coveredDay($run);
            $id = (string) $run->connection_id;
            if ($day !== null && (! isset($best[$id]) || $day > $best[$id])) {
                $best[$id] = $day;
            }
        }
        $required = self::requiredDay($now)->toDateString();
        $short = array_values(array_filter($ids, static fn (string $id): bool => ($best[$id] ?? '') < $required));
        if ($short !== []) {
            $finished = DB::table('integration_sync_runs')->whereIn('provider_connection_id', $short)->whereIn('status', self::SUCCEEDED)
                ->groupBy('provider_connection_id')->selectRaw('provider_connection_id, MAX(finished_at) AS finished')->pluck('finished', 'provider_connection_id');
            foreach ($finished as $id => $at) {
                if ($at !== null) {
                    $day = Carbon::parse((string) $at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()->subDay()->toDateString();
                    $best[(string) $id] = max($best[(string) $id] ?? '', $day);
                }
            }
        }
        // Every primed connection is decided here — runs older than a month count as none, which is what they are.
        foreach ($ids as $id) {
            $this->memo['c|'.$id.'|'.$now->format('U.u')] = $this->reading(($best[$id] ?? '') !== '' ? $best[$id] : null, $now);
        }
    }

    /** @var array<string, array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int}> One sweep (one `\$now`) reads each link once. */
    private array $memo = [];

    /** @return array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int} */
    private function readConnection(string $connectionId, Carbon $now): array
    {
        $ad = $this->lastAdDay(fn ($q) => $q->where('connection_id', $connectionId));
        // A website or store run is read only when the ad runs did not already cover the day.
        $other = $ad !== null && $ad >= self::requiredDay($now)->toDateString() ? null : $this->lastOtherDay($connectionId);

        return $this->reading(max((string) $ad, (string) $other) ?: null, $now);
    }

    /**
     * One platform within one project (a project-level outage names platforms, not connections).
     *
     * @return array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int}
     */
    public function forProjectProvider(string $projectId, string $provider, Carbon $now): array
    {
        return $this->memo['p|'.$projectId.'|'.$provider.'|'.$now->format('U.u')] ??= $this->reading($this->lastAdDay(fn ($q) => $q->where('project_id', $projectId)->where('provider', $provider)), $now);
    }

    /**
     * Why a project shows no spend for a day — one of the three {@see STATES}, never a blend. Null when the project has no
     * active ad-platform binding at all (that is «nothing is linked», said by the caller).
     */
    public function noDataState(string $tenantId, string $projectId, Carbon $day, Carbon $now): ?string
    {
        $providers = DB::table('project_integration_bindings')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)->where('is_active', true)
            ->distinct()->pluck('provider')->map(static fn ($p): string => (string) $p)->all();
        if ($providers === []) {
            return null;
        }

        // The day asked about may not be due yet (asked at 02:00 about yesterday): judge against what is due.
        $due = self::requiredDay($now);
        $judged = $day->copy()->setTimezone(self::TIMEZONE)->startOfDay();
        $judged = $judged->greaterThan($due) ? $due : $judged;
        foreach ($providers as $provider) {
            $last = $this->lastAdDay(fn ($q) => $q->where('project_id', $projectId)->where('provider', $provider));
            if ($last === null || $last < $judged->toDateString()) {
                return 'not_synced';
            }
        }

        $running = DB::table('unified_campaigns')
            ->where('tenant_id', $tenantId)->where('project_id', $projectId)
            ->where('status', CampaignStatus::Active->value)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $judged->toDateString()))
            ->exists();

        return $running ? 'no_spend' : 'not_running';
    }

    /**
     * The newest local day a successful ad-metrics run covered COMPLETELY: inside its window, and over before the run
     * finished (a run at 14:00 covers yesterday, not today).
     *
     * @param  callable(Builder): mixed  $scope
     */
    private function lastAdDay(callable $scope): ?string
    {
        $query = DB::table('metric_sync_runs')->whereIn('status', self::SUCCEEDED)->whereNotNull('finished_at')->whereNotNull('window_end');
        $scope($query);
        $best = null;
        foreach ($query->orderByDesc('finished_at')->limit(50)->get(['window_start', 'window_end', 'finished_at']) as $run) {
            $covered = self::coveredDay($run);
            if ($covered !== null) {
                $best = $best === null ? $covered : max($best, $covered);
            }
        }

        return $best;
    }

    /** The last local day one run covered completely: inside its window and over before it finished. Null if none. */
    private static function coveredDay(object $run): ?string
    {
        $done = Carbon::parse((string) $run->finished_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()->subDay()->toDateString();
        $covered = min(substr((string) $run->window_end, 0, 10), $done);

        return $run->window_start !== null && $covered < substr((string) $run->window_start, 0, 10) ? null : $covered;
    }

    /** A website or store run has no window: it read up to the moment it finished, so it covers the day before that. */
    private function lastOtherDay(string $connectionId): ?string
    {
        $finished = DB::table('integration_sync_runs')
            ->where('provider_connection_id', $connectionId)->whereIn('status', self::SUCCEEDED)
            ->max('finished_at');

        return $finished === null ? null
            : Carbon::parse((string) $finished, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()->subDay()->toDateString();
    }

    /** @return array{arrived: bool, required_day: string, last_day: ?string, missing_days: ?int} */
    private function reading(?string $lastDay, Carbon $now): array
    {
        $required = self::requiredDay($now);
        $arrived = $lastDay !== null && $lastDay >= $required->toDateString();

        return [
            'arrived' => $arrived,
            'required_day' => $required->toDateString(),
            'last_day' => $lastDay,
            'missing_days' => $lastDay === null ? null
                : ($arrived ? 0 : (int) Carbon::parse($lastDay, self::TIMEZONE)->diffInDays($required)),
        ];
    }
}
