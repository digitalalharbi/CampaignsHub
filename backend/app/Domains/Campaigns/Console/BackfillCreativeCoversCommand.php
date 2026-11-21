<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CoverSourceRefresher;
use App\Domains\Campaigns\Services\VideoFrameExtractor;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CREATIVE-PREVIEWS-001 — (re)cache every creative's cover into our own storage; a dry run unless told.
 *
 * The one entry point for covers, by hand and on the schedule. Without `--execute` it changes nothing and
 * prints, per platform, how many creatives there are, how many have a provider still, how many we already
 * hold a copy of, how many would be queued now, how many are backing off after a failure (and why), and
 * how many hold no link yet but can be asked for one (`recoverable`, CREATIVE-COVERS-COVERAGE-001), and how
 * many the platform gives no still for, and `via_video_frame` — how many have no copy yet and a film ffmpeg can take
 * a frame from (CREATIVE-VIDEO-FRAME-COVER-001, `cover_source = video_frame`). With `--execute` it queues one capture per creative that is
 * due, spaced `--per-minute` apart so a backfill of thousands is a steady trickle, not a burst.
 *
 * The CDN fetch itself is not an API call. When a stored link has lapsed, the capture asks the platform for
 * a fresh one through the connector — `PlatformHttp::budgeted`, the provider's own rate budget — and a
 * refusal backs that creative off (15 min, ×4, at most a day) instead of asking again every pass.
 *
 * Idempotent: a creative with a current copy is not selected, and a re-capture overwrites one file.
 */
final class BackfillCreativeCoversCommand extends Command
{
    protected $signature = 'creatives:backfill-covers
        {--provider= : Only this platform (meta, tiktok, snapchat, …)}
        {--limit=2000 : At most this many captures queued in one run}
        {--per-minute=120 : Captures released per minute}
        {--frames-per-minute=6 : Of those, captures that run ffmpeg on a film (CREATIVE-VIDEO-FRAME-COVER-001), released per minute}
        {--execute : Queue the captures; without it this is a dry run}';

    protected $description = 'Report (dry run) or queue cover captures for creatives whose cached cover is missing or stale.';

    public function handle(): int
    {
        $provider = $this->option('provider') ? strtolower((string) $this->option('provider')) : null;
        $execute = (bool) $this->option('execute');

        $this->report($provider);

        $limit = max(0, (int) $this->option('limit'));
        $due = $this->due($provider)->orderByDesc('last_active_at')->limit($limit);
        $perMinute = max(1, (int) $this->option('per-minute'));

        if (! $execute) {
            $this->line('Dry run: '.min($limit, $this->due($provider)->count())." cover capture(s) would be queued (limit {$limit}, {$perMinute}/min). Re-run with --execute to queue them.");

            return self::SUCCESS;
        }

        $framesPerMinute = max(1, (int) $this->option('frames-per-minute'));
        $queued = 0;
        $frames = 0;
        foreach ($due->cursor() as $creative) {
            // The SQL above narrows; the model's own rule decides, so the two cannot disagree about a row.
            if (! CoverCache::wanted($creative)) {
                continue;
            }
            // A capture that can only end in ffmpeg is paced on its own, much slower, clock.
            $delay = intdiv($queued, $perMinute);
            if (CoverCache::frameOnly($creative)) {
                $delay = max($delay, intdiv($frames, $framesPerMinute));
                $frames++;
            }
            CacheCreativeCoverJob::dispatch((string) $creative->getKey())->delay(Carbon::now()->addSeconds($delay * 60));
            $queued++;
        }

        app(ScheduledRunRows::class)->report($queued);
        $this->info("{$queued} cover capture(s) queued.");

        return self::SUCCESS;
    }

    /** @return Builder<ExternalCreative> creatives with a provider still, no current copy, and not backing off */
    private function due(?string $provider): Builder
    {
        return ExternalCreative::withoutGlobalScopes()
            ->when($provider !== null, fn (Builder $q) => $q->where('provider', $provider))
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w
                    ->where(fn (Builder $l) => $l->where('thumbnail_url', 'like', 'https://%')->orWhere('asset_url', 'like', 'https://%'))
                    ->where(fn (Builder $l) => $l->whereNull('cover_path')->orWhereNull('cover_cached_at')->orWhereColumn('source_updated_at', '>', 'cover_cached_at')))
                // CREATIVE-COVERS-COVERAGE-001 — no link at all, and a platform that can name one (CoverSourceRefresher::recoverable).
                ->orWhere(fn (Builder $w) => $w->whereRaw(self::RECOVERABLE))
                // CREATIVE-VIDEO-FRAME-COVER-001 — no still link, a playable film on a known CDN: its own frame.
                ->orWhere(fn (Builder $w) => $w->whereRaw(self::frameOnlySql())))
            ->where(fn (Builder $q) => $q->whereNull('cover_next_attempt_at')->orWhere('cover_next_attempt_at', '<=', Carbon::now())
                ->orWhereRaw(self::staleNoneSql()));
    }

    /** The SQL face of {@see CoverCache::backingOff()}'s exception: the old «provider gave none» verdict on a film we can frame. */
    private static function staleNoneSql(): string
    {
        return "(cover_path IS NULL AND COALESCE(cover_error, '') = '".CoverCache::STALE_NONE_VERDICT."' AND ".self::videoSql().')';
    }

    /**
     * The SQL face of {@see CoverSourceRefresher::recoverable()} — narrows only; the model's rule decides per row.
     * COALESCE: a NULL link makes LIKE answer NULL, and NOT NULL is still NULL.
     */
    private const RECOVERABLE = "(cover_path IS NULL AND is_demo = false AND external_campaign_id IS NOT NULL
        AND COALESCE(thumbnail_url, '') NOT LIKE 'https://%' AND COALESCE(asset_url, '') NOT LIKE 'https://%'
        AND ((provider = 'tiktok' AND COALESCE(raw->>'media', '') IN ('video', 'image', 'spark'))
          OR (provider = 'meta' AND (raw->'media_refs'->>'image_hash' IS NOT NULL OR raw->'media_refs'->>'video_id' IS NOT NULL))
          OR (provider = 'snapchat' AND COALESCE(format, '') NOT LIKE '%dynamic%' AND COALESCE(format, '') <> 'catalog')))";

    /** CREATIVE-VIDEO-FRAME-COVER-001 — a film on a known provider CDN (https), as SQL. Narrows only; the model decides. */
    private static function videoSql(): string
    {
        $hosts = implode('|', array_map(static fn (string $h): string => preg_quote($h, '/'), VideoFrameExtractor::hosts()));

        return "(COALESCE(video_url, '') ~* '^https://([a-z0-9-]+\\.)*({$hosts})([^a-z0-9.-]|$)')";
    }

    /** No still link at all and no copy yet, but a film we may frame. */
    private static function frameOnlySql(): string
    {
        return "(cover_path IS NULL AND is_demo = false AND COALESCE(thumbnail_url, '') NOT LIKE 'https://%' AND COALESCE(asset_url, '') NOT LIKE 'https://%' AND ".self::videoSql().')';
    }

    private function report(?string $provider): void
    {
        $now = Carbon::now()->toDateTimeString();
        // COALESCE: a NULL link makes LIKE answer NULL, and NOT NULL is still NULL — «no source» would count as nothing.
        $source = "(COALESCE(thumbnail_url, '') LIKE 'https://%' OR COALESCE(asset_url, '') LIKE 'https://%')";
        $stale = '(cover_path IS NULL OR cover_cached_at IS NULL OR source_updated_at > cover_cached_at)';
        $frameOnly = self::frameOnlySql();
        $wanted = "(({$source} AND {$stale}) OR ".self::RECOVERABLE." OR {$frameOnly})";
        $staleNone = self::staleNoneSql();

        $rows = DB::table('external_creatives')
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->selectRaw('provider, COUNT(*) AS creatives')
            ->selectRaw("SUM(CASE WHEN {$source} THEN 1 ELSE 0 END) AS with_source")
            ->selectRaw('SUM(CASE WHEN cover_path IS NOT NULL THEN 1 ELSE 0 END) AS cached')
            ->selectRaw("SUM(CASE WHEN {$wanted} AND (cover_next_attempt_at IS NULL OR cover_next_attempt_at <= ? OR {$staleNone}) THEN 1 ELSE 0 END) AS due", [$now])
            ->selectRaw("SUM(CASE WHEN {$wanted} AND cover_next_attempt_at > ? AND NOT {$staleNone} THEN 1 ELSE 0 END) AS backing_off", [$now])
            ->selectRaw('SUM(CASE WHEN '.self::RECOVERABLE.' THEN 1 ELSE 0 END) AS recoverable')
            // Every creative with no copy yet and a framable film: the ones ffmpeg can cover, whether the still is absent or dead.
            ->selectRaw('SUM(CASE WHEN cover_path IS NULL AND is_demo = false AND '.self::videoSql().' THEN 1 ELSE 0 END) AS via_video_frame')
            ->selectRaw("SUM(CASE WHEN NOT {$source} AND cover_path IS NULL AND NOT ".self::RECOVERABLE." AND NOT {$frameOnly} THEN 1 ELSE 0 END) AS provider_gives_none")
            ->groupBy('provider')
            ->orderBy('provider')
            ->get();

        $this->table(
            ['provider', 'creatives', 'with_source', 'cached', 'due', 'backing_off', 'recoverable', 'via_video_frame', 'provider_gives_none'],
            $rows->map(fn ($r): array => [$r->provider, (int) $r->creatives, (int) $r->with_source, (int) $r->cached, (int) $r->due, (int) $r->backing_off, (int) $r->recoverable, (int) $r->via_video_frame, (int) $r->provider_gives_none])->all(),
        );

        $reasons = DB::table('external_creatives')
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->whereNotNull('cover_error')
            ->selectRaw('provider, cover_error, COUNT(*) AS n')
            ->groupBy('provider', 'cover_error')
            ->orderBy('provider')->orderByDesc('n')
            ->get();
        if ($reasons->isNotEmpty()) {
            $this->table(['provider', 'last_failure', 'creatives'], $reasons->map(fn ($r): array => [$r->provider, $r->cover_error, (int) $r->n])->all());
        }
    }
}
