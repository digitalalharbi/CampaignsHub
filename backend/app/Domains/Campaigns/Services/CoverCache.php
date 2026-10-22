<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Services;

use App\Domains\Campaigns\Models\ExternalCreative;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * TIKTOK-MEDIA-002 — fetch a creative's cover once and keep it in our own storage.
 *
 * The reading order on every surface becomes: cached cover → the provider's fresh link → the honest absence.
 * The file name is a keyed hash of the creative (no provider id, no tenant id in the URL), the body must be an
 * image and small, and a failure leaves the creative exactly as it was — the provider link is still there.
 */
final class CoverCache
{
    public const DISK = 'local';

    public const ROOT = 'media/covers';

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    /** Whether this creative needs a (re)capture: no cover yet, or the provider link changed since it was taken. */
    public static function wanted(ExternalCreative $creative): bool
    {
        $source = self::sourceOf($creative);
        if ($source === null) {
            return false;
        }

        return $creative->cover_path === null || $creative->cover_cached_at === null || ($creative->source_updated_at !== null && $creative->source_updated_at->greaterThan($creative->cover_cached_at));
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
        return is_string($creative->cover_path) && $creative->cover_path !== '' ? '/media/covers/'.$creative->cover_path : null;
    }

    public function capture(ExternalCreative $creative): bool
    {
        $source = self::sourceOf($creative);
        if ($source === null) {
            return false;
        }

        try {
            $response = Http::timeout(15)->withOptions(['allow_redirects' => ['max' => 3]])->get($source);
        } catch (Throwable $e) {
            Log::info('Creative cover not cached: the provider link did not answer.', ['creative' => (string) $creative->getKey(), 'reason' => $e->getMessage()]);

            return false;
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $ext = self::TYPES[$type] ?? null;
        $body = $response->body();
        if (! $response->successful() || $ext === null || $body === '' || strlen($body) > self::MAX_BYTES) {
            Log::info('Creative cover not cached: not a small image.', ['creative' => (string) $creative->getKey(), 'status' => $response->status(), 'type' => $type, 'bytes' => strlen($body)]);

            return false;
        }

        $name = hash_hmac('sha256', (string) $creative->getKey(), (string) config('app.key')).'.'.$ext;
        $path = substr($name, 0, 2).'/'.$name;
        Storage::disk(self::DISK)->put(self::ROOT.'/'.$path, $body);

        $previous = $creative->cover_path;
        $creative->forceFill(['cover_path' => $path, 'cover_cached_at' => Carbon::now()])->save();
        if (is_string($previous) && $previous !== $path) {
            Storage::disk(self::DISK)->delete(self::ROOT.'/'.$previous);
        }

        return true;
    }
}
