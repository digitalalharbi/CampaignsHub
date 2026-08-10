<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where the SPA lives — one reader, in one place (FRONTEND-URL-001).
 *
 * ## What this is, stated honestly
 *
 * A REFACTOR, not a defect fix. An earlier note in `RESUME_STATE` claimed that
 * `config('app.frontend_url')` did not exist and that eight call sites — including the Moyasar
 * payment callback — were silently falling back to `app.url` and sending people to the API host on
 * a split deployment. **That claim was wrong**, and it was wrong because it came from grepping
 * `config/*.php` for the key instead of asking the application what the key resolves to. On an
 * untouched tree it resolves to `FRONTEND_URL`, exactly like `brand.frontend_url`. No customer was
 * ever sent to the wrong host.
 *
 * ## What was genuinely true, and is what this closes
 *
 * The product read TWO different config paths for one fact: `app.frontend_url` at eight sites (the
 * payment callback, the registration approval link, the invoice share link, subscription
 * notifications, the sandbox checkout return, the platform payment settings return, attribution and
 * advance-registration) and `brand.frontend_url` at seven (the OAuth redirects, every outbound
 * email, the report share links). Only the second is declared, in `config/brand.php`.
 *
 * Two keys for one fact is a latent hazard rather than a live bug: today they agree because both
 * resolve from `FRONTEND_URL`, and the day the undeclared one stops resolving, eight surfaces fall
 * back to the API's own origin without anything failing loudly. There is one reader now, so a
 * config path cannot be mistyped at a call site that no longer names one.
 *
 * `app.url` remains the fallback: on a single-origin install the API and the SPA genuinely share a
 * host, and failing to a working same-origin link beats failing to nothing.
 */
final class Frontend
{
    /**
     * The SPA's origin, without a trailing slash.
     *
     * `app.url` remains the fallback: on a single-origin install the API and the SPA genuinely share
     * a host, and failing to a working same-origin link is better than failing to nothing.
     */
    public static function origin(): string
    {
        $configured = config('brand.frontend_url');

        $origin = is_string($configured) && $configured !== ''
            ? $configured
            : (string) config('app.url');

        return rtrim($origin, '/');
    }

    /** A path inside the SPA: `Frontend::url('/signup/status')`. */
    public static function url(string $path = ''): string
    {
        if ($path === '') {
            return self::origin();
        }

        return self::origin().'/'.ltrim($path, '/');
    }
}
