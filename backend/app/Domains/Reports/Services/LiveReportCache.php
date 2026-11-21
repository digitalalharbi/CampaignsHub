<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services;

use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\RefreshLiveReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Support\ReportDataFreshness;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PERF-RECOVERY-001 — a public live link answers from the last confirmed build of THAT link.
 *
 * Owner, item 7 of 2026-11-04: «اعزل المزامنة عن استجابة الصفحات واستخدم آخر بيانات مؤكدة عند
 * مناسبتها بصورة واضحة دون تزوير حداثة البيانات». A live build is 3 to 7 s on a quiet host and 9 to
 * 25 s while a sync sweep runs on the same CPU. This keeps the last COMPLETE build of a link and
 * answers from it while a newer one is prepared off the request path.
 *
 * ## What it never does
 *
 *   - It never answers before the gates. It is called by `PublicReportController::live()` after the
 *     throttle, the active check (revoked, paused, expired), the password and the live-mode check, and
 *     the access log is written after it on every read. A link switched off answers 404 whatever is
 *     stored for it.
 *   - It never re-stamps a build. The payload's `freshness_summary.generated_at` is the moment that
 *     build ran, and the footer prints it. The per-platform AGES are read again at serve time by the
 *     same rule ({@see ReportDataFreshness::reage()}), so a served copy can only read older.
 *   - It never serves a build older than `max_age_seconds`. Past that the read builds synchronously.
 *   - It never stores an error, an empty payload, or one too large to keep; a failed refresh keeps
 *     the previous build and records why.
 *   - It never lets two links share an answer: the key carries the share id, and the hash carries every
 *     input the build reads that an operator can change (see {@see key()}).
 *
 * Off unless `reports.live_cache.enabled` is set; with it off this class is never constructed.
 */
final class LiveReportCache
{
    /** Bump when the stored shape or the build's output changes in a way an old entry must not survive. */
    public const VERSION = 1;

    /** The query keys a client page sends. Anything else is answered fresh and never stored. */
    private const CACHEABLE_QUERY = ['from', 'to', 'providers', 'campaigns'];

    /** Columns that move on every view and say nothing about what the link shows. */
    private const SHARE_VOLATILE = ['view_count', 'last_viewed_at', 'updated_at'];

    public function __construct(
        private readonly LiveReportService $live,
        private readonly ReportDataFreshness $freshness,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('reports.live_cache.enabled', false);
    }

    /**
     * The payload to answer with, and how it was obtained.
     *
     * @param  array<string, mixed>  $query  the request's query string, as received
     * @return array{0: array<string, mixed>, 1: array{source: string, built_at: ?string, refreshing: bool, max_age_seconds: int}}
     */
    public function answer(ReportShare $share, Report $report, array $query, string $currency): array
    {
        $canonical = self::canonicalQuery($query);
        if ($canonical === null) {
            $built = $this->live->build($share, $query, $currency);

            return [$built, $this->delivery('fresh', $built, null, false)];
        }

        $key = $this->key($share, $report, $canonical, $currency);
        $now = Carbon::now();

        $entry = $this->read($key);
        if ($entry !== null && $this->age($entry, $now) <= $this->maxAge()) {
            $refreshing = $this->age($entry, $now) > $this->fresh() && $this->queueRefresh($share, $canonical, $currency, $key);

            return [$this->served($entry['payload'], $now), $this->delivery('last_confirmed', $entry['payload'], $entry['built_at'], $refreshing)];
        }

        return $this->buildNow($share, $query, $canonical, $currency, $key);
    }

    /**
     * The key for one link's answer to one question on one day, in one language.
     *
     * Tenant, project and share id are in the clear so an entry can be read for what it is; everything
     * else is hashed:
     *
     *   - the share row (settings, scope and its ceiling, section flags, form, mode, hide flags, generic
     *     name switches, password, expiry), all columns but the three that move on every view;
     *   - the report row (config, section choices, form, level, period, locale, status);
     *   - the project row (objective, result events and anything else the build reads from it);
     *   - every `project_integration_bindings` row of the project — the {@see BoundAccountVisibility}
     *     state, so a deselected account or a moved binding is a different key on the next read;
     *   - the canonical query (period and filters), the currency, the request language, today's date
     *     in UTC and in Riyadh (rolling windows and budget pacing anchor on «today»), and the app key
     *     (content keys are HMACs under it).
     *
     * @param  array<string, mixed>  $canonical
     */
    public function key(ReportShare $share, Report $report, array $canonical, string $currency): string
    {
        $projectId = (string) (((array) $share->scope)['project_id'] ?? '');
        $isUuid = Str::isUuid($projectId);

        $material = [
            'v' => self::VERSION,
            'share' => self::rowState($share, self::SHARE_VOLATILE),
            'report' => self::rowState($report, []),
            'project' => $isUuid ? self::rowState(Project::withoutGlobalScopes()->find($projectId), []) : null,
            'bindings' => $isUuid
                ? DB::table('project_integration_bindings')->where('project_id', $projectId)->orderBy('id')->get()->map(static fn (object $r): array => (array) $r)->all()
                : [],
            'query' => $canonical,
            'currency' => $currency,
            'locale' => app()->getLocale(),
            'day' => [Carbon::now()->utc()->toDateString(), Carbon::now(ReportDataFreshness::TIMEZONE)->toDateString()],
            'app_key' => hash('sha256', (string) config('app.key')),
        ];

        return sprintf(
            'live-report:v%d:%s:%s:%s:%s',
            self::VERSION,
            (string) $share->tenant_id,
            $projectId === '' ? '-' : $projectId,
            (string) $share->getKey(),
            hash('sha256', serialize($material)),
        );
    }

    /**
     * The background half: rebuild one key, store it if it is a real answer, record why if not.
     *
     * Called by {@see RefreshLiveReportJob}. A link that was revoked, paused or expired meanwhile is
     * dropped and its entry forgotten; a link whose settings or bindings moved since the read that
     * asked for this is skipped, because its next read is a different key and builds for itself.
     *
     * @param  array<string, mixed>  $canonical
     * @return string what happened: stored, dropped, superseded, failed, not_stored
     */
    public function refreshNow(string $shareId, array $canonical, string $currency, string $key, string $locale): string
    {
        $share = ReportShare::withoutGlobalScopes()->find($shareId);
        $report = $share === null ? null : Report::withoutGlobalScopes()->find($share->report_id);
        if ($share === null || $report === null || ! $share->isActive() || ! $share->isLive()) {
            Cache::forget($key);

            return 'dropped';
        }

        app()->setLocale($locale);
        if ($this->key($share, $report, $canonical, $currency) !== $key) {
            return 'superseded';
        }

        $started = Carbon::now();
        try {
            $payload = $this->live->build($share, $canonical, $currency);
        } catch (Throwable $e) {
            $this->recordFailure($key, $shareId, $e::class.': '.$e->getMessage());

            return 'failed';
        }

        if (! $this->store($key, $shareId, $started, $payload)) {
            $this->recordFailure($key, $shareId, 'not stored: empty, too large or not serialisable');

            return 'not_stored';
        }

        return 'stored';
    }

    /** Kept beside the entry for as long as the entry may be served, and written to the log. */
    public function recordFailure(string $key, string $shareId, string $reason): void
    {
        $reason = mb_substr($reason, 0, 300);
        Log::warning('live-report refresh kept the previous build', ['share_id' => $shareId, 'key' => $key, 'reason' => $reason]);
        Cache::put(self::failureKey($key), ['at' => Carbon::now()->utc()->toIso8601ZuluString(), 'share_id' => $shareId, 'reason' => $reason], $this->maxAge());
    }

    /** @return array{at: string, share_id: string, reason: string}|null */
    public function lastFailure(string $key): ?array
    {
        $found = Cache::get(self::failureKey($key));

        return is_array($found) ? $found : null;
    }

    /**
     * The read with no usable entry: build now, with one builder per key at a time.
     *
     * A second reader of the same cold key waits (up to `wait_seconds`) for the first one's build and
     * is answered from it, rather than running the same 3 to 25 s build beside it on one CPU. If the
     * wait runs out it builds for itself: a reader is never refused for want of a lock.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $canonical
     * @return array{0: array<string, mixed>, 1: array{source: string, built_at: ?string, refreshing: bool, max_age_seconds: int}}
     */
    private function buildNow(ReportShare $share, array $query, array $canonical, string $currency, string $key): array
    {
        $lock = Cache::lock('live-report:build:'.$key, 120);

        try {
            $lock->block((int) config('reports.live_cache.wait_seconds', 20));
        } catch (LockTimeoutException) {
            $started = Carbon::now();
            $built = $this->live->build($share, $query, $currency);
            $this->store($key, (string) $share->getKey(), $started, $built);

            return [$built, $this->delivery('fresh', $built, null, false)];
        }

        try {
            // Another reader may have finished this build while we waited for the lock.
            $now = Carbon::now();
            $entry = $this->read($key);
            if ($entry !== null && $this->age($entry, $now) <= $this->fresh()) {
                return [$this->served($entry['payload'], $now), $this->delivery('last_confirmed', $entry['payload'], $entry['built_at'], false)];
            }

            $started = Carbon::now();
            $built = $this->live->build($share, $query, $currency);
            $this->store($key, (string) $share->getKey(), $started, $built);

            return [$built, $this->delivery('fresh', $built, null, false)];
        } finally {
            $lock->release();
        }
    }

    /**
     * Ask for a background rebuild. `ShouldBeUnique` holds one lock per key for at most the bound, so
     * a hundred stale reads of one link queue one job; the lock is released when that job ends, and
     * expires on its own if the worker dies.
     *
     * @param  array<string, mixed>  $canonical
     */
    private function queueRefresh(ReportShare $share, array $canonical, string $currency, string $key): bool
    {
        RefreshLiveReportJob::dispatch((string) $share->getKey(), $canonical, $currency, $key, app()->getLocale());

        return true;
    }

    /** @return array{built_at: string, payload: array<string, mixed>}|null */
    private function read(string $key): ?array
    {
        $raw = Cache::get($key);
        if (! is_array($raw) || ($raw['v'] ?? null) !== self::VERSION || ! is_string($raw['built_at'] ?? null) || ! is_string($raw['payload'] ?? null)) {
            return null;
        }

        $decoded = base64_decode($raw['payload'], true);
        $inflated = $decoded === false ? false : @gzuncompress($decoded);
        $payload = $inflated === false ? false : @unserialize($inflated, ['allowed_classes' => [\stdClass::class]]);

        return is_array($payload) && self::isAnswer($payload) ? ['built_at' => $raw['built_at'], 'payload' => $payload] : null;
    }

    private function store(string $key, string $shareId, Carbon $builtAt, mixed $payload): bool
    {
        if (! is_array($payload) || ! self::isAnswer($payload)) {
            return false;
        }

        $serialised = serialize($payload);
        // Arrays, scalars and stdClass only — anything else would not come back as itself.
        if (preg_match('/(?:^|[;{}])[OC]:\d+:"(?!stdClass")/', $serialised) === 1) {
            return false;
        }

        $compressed = gzcompress($serialised, 6);
        $encoded = $compressed === false ? '' : base64_encode($compressed);
        if ($encoded === '' || strlen($encoded) > (int) config('reports.live_cache.max_bytes', 1_000_000)) {
            return false;
        }

        $ttl = $this->maxAge() + 60;
        if (! $this->admit($shareId, $key, $ttl)) {
            return false;
        }

        Cache::put($key, ['v' => self::VERSION, 'built_at' => $builtAt->copy()->utc()->toIso8601ZuluString(), 'payload' => $encoded], $ttl);
        Cache::forget(self::failureKey($key));

        return true;
    }

    /**
     * At most `max_variants` stored answers per link at once — the memory bound. A ninth filter
     * combination is answered fresh and not kept; entries leave on their own after the bound.
     */
    private function admit(string $shareId, string $key, int $ttl): bool
    {
        $indexKey = 'live-report:index:'.$shareId;
        $now = Carbon::now()->getTimestamp();
        $index = array_filter((array) Cache::get($indexKey, []), static fn (mixed $expires): bool => is_int($expires) && $expires > $now);

        if (! array_key_exists($key, $index) && count($index) >= (int) config('reports.live_cache.max_variants', 8)) {
            return false;
        }

        $index[$key] = $now + $ttl;
        Cache::put($indexKey, $index, $ttl);

        return true;
    }

    /**
     * A stored build as it is answered now: the same figures, the same `generated_at`, and the
     * per-platform ages read against the clock of this request.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function served(array $payload, Carbon $now): array
    {
        if (is_array($payload['freshness'] ?? null)) {
            $payload['freshness'] = $this->freshness->reage($payload['freshness'], $now);

            if (is_array($payload['freshness_summary'] ?? null)) {
                // The worst verdict follows the re-read ages; the oldest confirmation and the build time do not move.
                $payload['freshness_summary']['worst_status'] = $this->freshness->summary($payload['freshness'], $now)['worst_status'];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{source: string, built_at: ?string, refreshing: bool, max_age_seconds: int}
     */
    private function delivery(string $source, array $payload, ?string $storedAt, bool $refreshing): array
    {
        $generated = $payload['freshness_summary']['generated_at'] ?? null;

        return [
            'source' => $source,
            // The build's own clock where it states one, else when it was stored; never the request's.
            'built_at' => is_string($generated) ? $generated : $storedAt,
            'refreshing' => $refreshing,
            'max_age_seconds' => $this->maxAge(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private static function isAnswer(array $payload): bool
    {
        return $payload !== [] && is_array($payload['period'] ?? null) && array_key_exists('totals', $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null null when the query carries anything a client page does not send
     */
    private static function canonicalQuery(array $query): ?array
    {
        unset($query['password']);
        foreach ($query as $name => $value) {
            if (! in_array($name, self::CACHEABLE_QUERY, true)) {
                return null;
            }
            $flat = is_array($value) ? $value : [$value];
            if (! array_is_list($flat) || array_filter($flat, static fn (mixed $v): bool => ! is_scalar($v) && $v !== null) !== []) {
                return null;
            }
        }
        ksort($query);

        return $query;
    }

    /**
     * @param  list<string>  $skip
     * @return array<string, mixed>|null
     */
    private static function rowState(?Model $row, array $skip): ?array
    {
        if ($row === null) {
            return null;
        }
        $attributes = array_diff_key($row->getAttributes(), array_flip($skip));
        ksort($attributes);

        return $attributes;
    }

    /** @param array{built_at: string} $entry */
    private function age(array $entry, Carbon $now): int
    {
        return (int) Carbon::parse($entry['built_at'])->diffInSeconds($now, false);
    }

    private function fresh(): int
    {
        return max(0, (int) config('reports.live_cache.fresh_seconds', 120));
    }

    private function maxAge(): int
    {
        return max(1, (int) config('reports.live_cache.max_age_seconds', 900));
    }

    private static function failureKey(string $key): string
    {
        return 'live-report:refresh-failed:'.$key;
    }
}
