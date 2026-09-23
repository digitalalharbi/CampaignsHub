<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * AD-MEDIA-RECOVERY-001 — media this product hosts is served BY the product, with ranges.
 *
 * These files used to sit in `public/` and be handed out by whatever web server happened to be in
 * front of the app. In every environment this project actually runs, that server is PHP's built-in
 * one — `php artisan serve` is the CMD of `deploy/backend.production.Dockerfile` and of the E2E
 * gate's backend alike — and it does not implement HTTP Range:
 *
 *   curl -H 'Range: bytes=0-1023' …/demo/creative-sample.mp4 → 200, Content-Length: 27649
 *
 * A still image survives that. A film does not: WebKit will not start a video it cannot seek, so it
 * reports `MediaError 2` (NETWORK) and the card sits blank — the owner's «no real preview», and the
 * failure CI's webkit leg reported on #494 as «the browser could not decode the film».
 *
 * Symfony's `BinaryFileResponse` answers a Range with a 206 and the right `Content-Range`, so
 * serving the file through Laravel makes the seek work wherever the app runs. The URL is unchanged
 * («/demo/…»), because `CreativePresenter::safe()` stores a root-relative path deliberately and a
 * stored path must not be rewritten by a change to how it is served.
 */
final class AppMediaController
{
    /**
     * The only directory this route will read, resolved once so no request can escape it.
     *
     * It mirrors the URL prefix: «/demo/x» is `storage/app/media/demo/x`, so the stored root-relative
     * path and the file on disk keep the same shape and neither has to be rewritten to find the other.
     */
    private const ROOT = 'media/demo';

    /** Extensions this product hosts. An unknown one is not served rather than guessed at. */
    private const TYPES = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function show(Request $request, string $path): BinaryFileResponse
    {
        $root = realpath(storage_path('app/'.self::ROOT));
        $file = $root === false ? false : realpath($root.'/'.$path);

        /*
         * Resolved with `realpath` and compared as a PREFIX — a name is not a permission.
         *
         * `..` in a path, a symlink pointing out of the directory, and a URL-encoded separator all
         * end in the same place: a request naming a file the product never meant to publish. The
         * comparison is on the resolved path, so every one of them fails the same way.
         */
        if ($root === false || $file === false || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            abort(404);
        }

        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;
        if ($type === null) {
            abort(404);
        }

        $response = response()->file($file, ['Content-Type' => $type, 'Accept-Ranges' => 'bytes']);
        $response->setAutoLastModified();

        // `prepare()` is what reads the Range header and turns the answer into a 206.
        $response->prepare($request);

        return $response;
    }
}
