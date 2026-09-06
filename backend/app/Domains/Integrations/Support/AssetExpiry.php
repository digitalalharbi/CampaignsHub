<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use Illuminate\Support\Carbon;

/**
 * AD-MEDIA-RECOVERY-001 — the moment a provider's media link stops working, read off the link itself.
 *
 * ## The gap this closes
 *
 * `external_creatives.asset_expires_at` has existed since the table did. `CreativePresenter::assetExpired()`
 * reads it, the `expired` state and its sentence — «انتهت صلاحية رابط المنصة — يحتاج مزامنة جديدة» —
 * are written and tested, and `ImportExternalStructure` writes the column from the connector's
 * `asset_expires_at`.
 *
 * **No connector has ever emitted one.** So the column is always null, `assetExpired()` is always
 * false, and the `expired` state is unreachable in production. A media URL that has gone stale is
 * therefore served to the browser as `available`, the `<img>` is refused by the CDN, and the reader
 * gets a broken rectangle with no explanation — the one outcome this requirement forbids by name,
 * arrived at through the state that was built to prevent it.
 *
 * ## Both providers already state it
 *
 * They put the expiry in the URL, because that is how a signed CDN grant works — the timestamp is
 * part of what the signature covers, so it cannot be wrong without the link being invalid anyway:
 *
 *   Meta      `…/v/t45.1600-4/x.jpg?_nc_ohc=…&oh=00_Af…&oe=66E1F2A3`   `oe`, HEX seconds
 *   Snapchat  `…/media/me-2.jpg?sig=abc123&e=1790000000`               `e`,  decimal seconds
 *
 * Nothing is guessed and nothing is defaulted: a link with no expiry parameter returns null, which
 * is «this link states no expiry», not «this link never expires». The difference matters — the first
 * is a fact about the URL and the second would be a promise this product cannot keep.
 */
final class AssetExpiry
{
    /**
     * The expiry a signed media URL states, or null when it states none.
     *
     * Bounded on both sides deliberately. A value in the past is kept — a link that expired
     * yesterday is exactly what the `expired` state exists to report — but a value that is not a
     * plausible epoch second is discarded rather than turned into a date in the year 55000, because
     * a wrong expiry silently hides a working asset and that is worse than no expiry at all.
     */
    public static function fromUrl(?string $url): ?Carbon
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($query === '') {
            return null;
        }

        parse_str($query, $params);

        // Meta states `oe` in hex; Snapchat states `e` in decimal. Meta first: an `e` alongside an
        // `oe` on a Meta URL is not the expiry, and the more specific key is the safer read.
        foreach ([['oe', 16], ['e', 10]] as [$key, $base]) {
            $raw = $params[$key] ?? null;

            if (! is_string($raw) || trim($raw) === '') {
                continue;
            }

            $seconds = $base === 16
                ? (ctype_xdigit($raw) ? (int) hexdec($raw) : null)
                : (ctype_digit($raw) ? (int) $raw : null);

            if ($seconds === null) {
                continue;
            }

            /*
             * A plausible epoch second: 2010 to 2100. Anything outside is a parameter that happens to
             * be named `e` and means something else — a variant, a version, an experiment id — and
             * reading it as a date would expire a perfectly good asset or push it a thousand years out.
             */
            if ($seconds < 1262304000 || $seconds > 4102444800) {
                continue;
            }

            return Carbon::createFromTimestampUTC($seconds);
        }

        return null;
    }
}
