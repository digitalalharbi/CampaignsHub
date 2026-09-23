<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

/**
 * Whether a browser would DRAW these bytes in an `<img>` — the one place that question is answered.
 *
 * ## What Production showed
 *
 * `content:census --fetch --raw` (run 35478164776) on three promoted Snapchat collection stills:
 * «still not an image (content type multipart/form-data; bytes: png image that decodes)». The files
 * are real PNGs; only the type the CDN declares for them is wrong. Every one of them draws on the
 * card — a browser sniffs an image by its leading bytes, not by the header — so the media was never
 * the defect. The instruments calling it one were.
 *
 * ## Why this is a class and not a condition
 *
 * Two readers judged the same bytes and disagreed with the browser in the same way:
 * `ContentDefectCensusCommand::judge()` and `ProbeInsightsCommand::reportMedia()`, each testing
 * `content-type begins with image/`. A rule about what a browser draws, written twice, is a rule that
 * drifts — and when it drifts it reports healthy media as broken, which is the expensive direction:
 * an operator goes looking for a sync fault that does not exist.
 *
 * ## The rule
 *
 * An allow-listed signature in the leading bytes — JPEG, PNG, GIF, WebP — AND a header that actually
 * decodes. The allow-list is deliberate: it is what these engines draw, and it is why an HTML error
 * page served with a 200, or a genuine multipart envelope, is still not an image. The declared type
 * is not consulted at all, because it is not what decides.
 */
final class DrawableImage
{
    /** The format an allow-listed signature names, or null when the bytes are not one. */
    public static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a') => 'gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'webp',
            default => null,
        };
    }

    /**
     * True when a browser would draw these bytes: an allow-listed signature whose header decodes.
     *
     * Both halves are needed. A signature alone can be four bytes of coincidence at the head of
     * something else; `getimagesizefromstring()` alone accepts formats these engines will not draw in
     * an `<img>`, and reads a truncated prefix as a failure for a file that is perfectly fine.
     */
    public static function draws(string $bytes): bool
    {
        return self::sniff($bytes) !== null && @getimagesizefromstring($bytes) !== false;
    }
}
