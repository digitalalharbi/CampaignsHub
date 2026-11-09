<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Support\AssetExpiry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TIKTOK-MEDIA-002 — fetch a creative's cover once and keep it in our own storage.
 *
 * The file name is a keyed hash of the creative (no provider id, no tenant id in the URL), the body must be an
 * image and small, and a failure leaves the creative's links exactly as they were.
 *
 * ## CREATIVE-PREVIEWS-001 — our copy is the only picture a browser is given for Meta and TikTok
 *
 * Owner, 2026-11-07: «حل مشكلة عدم ظهور المعاينة أو صورة الغلاف لمنصة تيك توك وميتا وباقي المنصات! باستثناء
 * سناب تظهر الصور والمعاينات.» Three things were true at once:
 *
 *   1. The cached copy was served at `/media/covers/…` by the API, and the SPA's origin never forwarded that
 *      path — its nginx answered every cover with `index.html`, so every cached cover was a broken image.
 *   2. Where no copy existed, the card hot-linked the provider's signed link: Meta's `oe=` and TikTok's
 *      `x-expires` grants, which die within hours (TikTok) to days (Meta), so most of them were dead.
 *   3. A capture that failed recorded nothing, so 890 TikTok creatives could not say why they had no cover.
 *
 * So: the reading order is OUR copy → for a platform whose links are short-lived grants, nothing but an
 * honest «pending / failed» state → for anyone else (Snapchat), their link exactly as before. A lapsed source is
 * renewed through the platform before it is fetched, a refusal backs off, and every outcome is recorded.
 */
final class CoverCache
{
    public const DISK = 'local';

    public const ROOT = 'media/covers';

    public const STATE_CACHED = 'cached';

    public const STATE_PENDING = 'pending';

    public const STATE_NONE = 'none';

    public const STATE_FAILED = 'failed';

    /**
     * The CDNs whose media links are short-lived signed grants: Meta's `scontent-*.fbcdn.net` (`oe=`) and its
     * `facebook.com` / Instagram image hosts, TikTok's `*-sign.tiktokcdn.com` / `ibyteimg.com` (`x-expires`).
     * Their links are COPIED, never handed to a browser. Snapchat's media host is deliberately absent — its link
     * is what draws today, and the Owner's report names it as the one platform that works.
     */
    private const COPY_ONLY_HOSTS = ['fbcdn.net', 'facebook.com', 'fbsbx.com', 'cdninstagram.com', 'tiktokcdn.com', 'tiktokcdn-us.com', 'tiktokcdn-eu.com', 'ibyteimg.com', 'byteimg.com', 'byteoversea.com'];

    /** CREATIVE-VIDEO-FRAME-COVER-001 — where our copy came from: the platform's still, or a frame taken from the film. */
    public const SOURCE_PROVIDER = 'provider';

    public const SOURCE_VIDEO_FRAME = 'video_frame';

    /** The reason a capture records when the platform named no still and nothing else could be tried. */
    public const STALE_NONE_VERDICT = 'provider_gave_none';

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif'];

    /** First try a quarter of an hour later, then ×4 each time, never more than a day apart. */
    private const BACKOFF_FIRST_MINUTES = 15;

    private const BACKOFF_CAP_MINUTES = 24 * 60;

    /** @return list<string> the provider CDN host suffixes whose links are copied (and whose films may be framed) */
    public static function copyOnlyHosts(): array
    {
        return self::COPY_ONLY_HOSTS;
    }

    /**
     * CREATIVE-VIDEO-FRAME-COVER-001 — the film's link, when a frame may be taken from it: https, a known CDN host.
     * Read from the row alone, like {@see CoverSourceRefresher::recoverable()}, so the backfill and the job agree.
     */
    public static function videoSourceOf(ExternalCreative $creative): ?string
    {
        $url = $creative->video_url;

        return is_string($url) && VideoFrameExtractor::allowed($url) ? $url : null;
    }

    /** A creative whose only picture can be a frame from its film: no still link at all, a playable film. */
    public static function frameOnly(ExternalCreative $creative): bool
    {
        return self::sourceOf($creative) === null && self::videoSourceOf($creative) !== null;
    }

    /** Whether this creative needs a (re)capture now: no cover yet, or the provider link changed — and not backing off. */
    public static function wanted(ExternalCreative $creative): bool
    {
        if (self::sourceOf($creative) === null) {
            // CREATIVE-COVERS-COVERAGE-001 — no link yet, but the platform can name one: ask it, at the backoff's pace.
            // CREATIVE-VIDEO-FRAME-COVER-001 — or the creative holds a playable film, whose own frame is the cover.
            return $creative->cover_path === null && (CoverSourceRefresher::recoverable($creative) || self::videoSourceOf($creative) !== null)
                && ! self::backingOff($creative);
        }

        // A frame we took stands in until the platform's own still is copied; a still link that has appeared since is tried.
        $stale = $creative->cover_path === null || $creative->cover_cached_at === null
            || $creative->cover_source === self::SOURCE_VIDEO_FRAME
            || ($creative->source_updated_at !== null && $creative->source_updated_at->greaterThan($creative->cover_cached_at));

        return $stale && ! self::backingOff($creative);
    }

    /**
     * Whether the failure back-off still holds this creative back.
     *
     * One exception, CREATIVE-VIDEO-FRAME-COVER-001: `provider_gave_none` is the verdict of a pipeline that had no
     * frame to try. Production 2026-11-09 held 900 TikTok and 48 Snapchat films with that reason and a back-off of up
     * to 16 hours, written before the frame fallback shipped, so the hourly run found nothing due. A film we can frame
     * overrides it. After a frame is tried the reason is a `frame_*` / `video_expired` word, so this cannot repeat.
     */
    public static function backingOff(ExternalCreative $creative): bool
    {
        if ($creative->cover_next_attempt_at === null || ! $creative->cover_next_attempt_at->isFuture()) {
            return false;
        }

        return ! ($creative->cover_path === null && $creative->cover_error === self::STALE_NONE_VERDICT && self::videoSourceOf($creative) !== null);
    }

    public static function sourceOf(ExternalCreative $creative): ?string
    {
        foreach ([$creative->thumbnail_url, $creative->asset_url] as $url) {
            if (is_string($url) && str_starts_with($url, 'https://')) {
                return $url;
            }
        }

        return null;
    }

    /** The app-relative URL the surfaces read, or null when nothing is cached. */
    public static function urlFor(ExternalCreative $creative): ?string
    {
        return self::urlForPath($creative->cover_path);
    }

    public static function urlForPath(mixed $coverPath): ?string
    {
        return is_string($coverPath) && $coverPath !== '' ? '/'.self::ROOT.'/'.$coverPath : null;
    }

    /**
     * CREATIVE-PREVIEWS-001 — may a browser be handed this link for a still?
     *
     * Our own root-relative paths and inline images, yes. A short-lived platform grant, known by its CDN host,
     * never — it is copied instead. Anyone else's link exactly as before: Snapchat's media link is what draws
     * today, and judging it by the `e=` it carries would change the one platform the Owner says works.
     */
    public static function hotlinkable(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }
        if ((str_starts_with($url, '/') && ! str_starts_with($url, '//')) || str_starts_with(strtolower($url), 'data:image/')) {
            return true;
        }

        return ! self::isCopyOnlyHost($url);
    }

    /** The still for a row read straight from the table (no model): our copy, else a link that may be shown. */
    public static function displayUrlFor(mixed $coverPath, ?string $providerUrl): ?string
    {
        return self::urlForPath($coverPath) ?? (self::hotlinkable($providerUrl) ? $providerUrl : null);
    }

    public static function isCopyOnlyHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::COPY_ONLY_HOSTS as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** The honest state a card shows: cached, pending, the provider gives none, or failed (with `cover_error`). */
    public static function stateOf(ExternalCreative $creative): string
    {
        return match (true) {
            self::urlFor($creative) !== null => self::STATE_CACHED,
            self::sourceOf($creative) === null => self::STATE_NONE,
            $creative->cover_state === self::STATE_FAILED => self::STATE_FAILED,
            default => self::STATE_PENDING,
        };
    }

    public function capture(ExternalCreative $creative): bool
    {
        $source = self::sourceOf($creative);
        $renewed = false;
        $reason = null;
        if ($source === null && CoverSourceRefresher::recoverable($creative)) {
            /*
             * CREATIVE-COVERS-COVERAGE-001 — a creative the sweep left without any link (a Spark post past the old
             * cap or under an unnamed identity, a video the Asset Library refused once, a Snapchat film whose still
             * is a separate call) is asked for one through its connector before it is called «none».
             */
            $source = $this->renew($creative);
            $renewed = true;
            $reason = $source === null ? self::STALE_NONE_VERDICT : null;
        } elseif ($source !== null && self::lapsed($source)) {
            $source = $this->renew($creative);
            $renewed = true;
            $reason = $source === null ? 'link_expired' : null;
        }

        $body = $ext = null;
        $origin = self::SOURCE_PROVIDER;
        if ($source !== null) {
            [$body, $ext, $reason] = $this->fetch($source);

            // A CDN that refuses a link it signed is a link that lapsed before its stated time: ask the platform once.
            if ($body === null && ! $renewed && in_array($reason, ['http_403', 'http_404', 'http_410'], true)) {
                $fresh = $this->renew($creative);
                $renewed = true;
                if ($fresh !== null) {
                    [$body, $ext, $reason] = $this->fetch($fresh);
                }
            }
        }

        if ($body === null || $ext === null) {
            /*
             * CREATIVE-VIDEO-FRAME-COVER-001 — the platform gave no still, or its link is dead even after a renewal:
             * the film itself is the source. One frame, taken on the server, stored exactly like any other copy.
             * Not when a frame is already what we hold (re-taking the same frame is not an upgrade).
             */
            $video = self::videoSourceOf($creative);
            if ($video !== null && $creative->cover_source !== self::SOURCE_VIDEO_FRAME) {
                if (self::lapsed($video) && ! $renewed) {
                    $this->renew($creative);
                    $renewed = true;
                    $video = self::videoSourceOf($creative);
                }
                if ($video !== null && ! self::lapsed($video)) {
                    [$frame, $frameReason] = app(VideoFrameExtractor::class)->extract($video);
                    if ($frame !== null) {
                        [$body, $ext, $origin, $reason] = [$frame, 'jpg', self::SOURCE_VIDEO_FRAME, null];
                    } else {
                        $reason = $frameReason;
                    }
                } else {
                    $reason = 'video_expired';
                }
            }
        }

        if ($body === null || $ext === null) {
            if ($source === null && $reason === null) {
                // Nothing to copy, nothing to ask for, no film: the platform gives none.
                $creative->forceFill(['cover_state' => self::STATE_NONE, 'cover_error' => null, 'cover_next_attempt_at' => null])->save();

                return false;
            }

            return $this->failed($creative, $reason ?? 'unknown');
        }

        $name = hash_hmac('sha256', (string) $creative->getKey(), (string) config('app.key')).'.'.$ext;
        $path = substr($name, 0, 2).'/'.$name;
        Storage::disk(self::DISK)->put(self::ROOT.'/'.$path, $body);

        $previous = $creative->cover_path;
        $creative->forceFill([
            'cover_path' => $path,
            'cover_source' => $origin,
            'cover_cached_at' => Carbon::now(),
            'cover_state' => self::STATE_CACHED,
            'cover_error' => null,
            'cover_attempts' => 0,
            'cover_next_attempt_at' => null,
        ])->save();
        if (is_string($previous) && $previous !== $path) {
            Storage::disk(self::DISK)->delete(self::ROOT.'/'.$previous);
        }

        return true;
    }

    private static function lapsed(string $url): bool
    {
        return AssetExpiry::fromUrl($url)?->isPast() ?? false;
    }

    /** A fresh source from the platform, or null. The row's links are updated by the refresher itself. */
    private function renew(ExternalCreative $creative): ?string
    {
        if (app(CoverSourceRefresher::class)->refresh($creative) === null) {
            return null;
        }
        $source = self::sourceOf($creative);

        return $source === null || self::lapsed($source) ? null : $source;
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} body, extension, failure reason */
    private function fetch(string $source): array
    {
        try {
            $response = Http::timeout(15)->withOptions(['allow_redirects' => ['max' => 3]])->get($source);
        } catch (Throwable) {
            return [null, null, 'unreachable'];
        }

        if (! $response->successful()) {
            return [null, null, 'http_'.$response->status()];
        }

        $body = $response->body();
        if ($body === '') {
            return [null, null, 'empty'];
        }
        if (strlen($body) > self::MAX_BYTES) {
            return [null, null, 'too_large'];
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $ext = self::TYPES[$type] ?? self::sniff($body);

        return $ext === null ? [null, null, 'not_image'] : [$body, $ext, null];
    }

    /** A CDN that labels an image `application/octet-stream` (or nothing) still sent an image: read its first bytes. */
    private static function sniff(string $body): ?string
    {
        return match (true) {
            str_starts_with($body, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($body, "\x89PNG") => 'png',
            str_starts_with($body, 'GIF8') => 'gif',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    private function failed(ExternalCreative $creative, string $reason): bool
    {
        $attempts = (int) $creative->cover_attempts + 1;
        $minutes = (int) min(self::BACKOFF_CAP_MINUTES, self::BACKOFF_FIRST_MINUTES * (4 ** min($attempts - 1, 8)));
        $creative->forceFill([
            'cover_state' => $creative->cover_path === null ? self::STATE_FAILED : self::STATE_CACHED,
            'cover_error' => substr($reason, 0, 64),
            'cover_attempts' => min($attempts, 65535),
            'cover_next_attempt_at' => Carbon::now()->addMinutes($minutes),
        ])->save();
        Log::info('Creative cover not cached.', ['creative' => (string) $creative->getKey(), 'provider' => $creative->provider, 'reason' => $reason, 'attempt' => $attempts]);

        return false;
    }
}
