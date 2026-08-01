<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language this request is answered in (I18N-001).
 *
 * Until this existed the API had no `lang/` directory at all, so every message it produced was the
 * English string written at the call site — «These credentials do not match our records.» in front of
 * an Arabic sign-in form, and Laravel's own English validation text under every Arabic field label.
 * The interface was translated and the answers were not, which is worst precisely when something has
 * gone wrong and the person needs to read what it says.
 *
 * Arabic is the DEFAULT rather than a negotiated alternative: this is an Arabic-first product, and a
 * caller that says nothing (a webhook, a curl, a mobile client shipped without the header) should get
 * the product's own language rather than the framework's.
 */
final class SetLocale
{
    /** The languages the application actually has translations for. Anything else is not offered. */
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(self::resolve($request));

        return $next($request);
    }

    /**
     * The best supported language for this request.
     *
     * `Accept-Language` is a ranked list with quality weights (`ar-SA,ar;q=0.9,en;q=0.8`), not a
     * single tag, and the SPA is not the only thing that sends it — a browser sends whatever the
     * operating system is set to. Taking the first tag blindly would answer `en-GB,ar;q=0.9` in
     * English for someone whose product language is Arabic, so the ranking is honoured, and the
     * region subtag is dropped because `ar-SA` and `ar-EG` read the same messages.
     */
    public static function resolve(Request $request): string
    {
        $default = self::normalise((string) config('app.locale')) ?? 'ar';

        $header = trim((string) $request->header('Accept-Language', ''));
        if ($header === '') {
            return $default;
        }

        $ranked = [];

        foreach (explode(',', $header) as $index => $part) {
            $segments = explode(';', trim($part));
            $tag = self::normalise(trim($segments[0]));

            if ($tag === null) {
                continue;
            }

            $quality = 1.0;
            foreach (array_slice($segments, 1) as $parameter) {
                if (str_starts_with(trim($parameter), 'q=')) {
                    $quality = (float) substr(trim($parameter), 2);
                }
            }

            // Keep the FIRST occurrence of a language at its own weight; a later duplicate with a
            // lower weight is the same language and must not displace it.
            $ranked[$tag] ??= ['quality' => $quality, 'position' => $index];
        }

        if ($ranked === []) {
            return $default;
        }

        uasort($ranked, fn (array $a, array $b) => [$b['quality'], $a['position']] <=> [$a['quality'], $b['position']]);

        return (string) array_key_first($ranked);
    }

    /** `ar-SA` → `ar`; anything unsupported → null, so it is skipped rather than guessed at. */
    private static function normalise(string $tag): ?string
    {
        $language = mb_strtolower(explode('-', str_replace('_', '-', $tag))[0]);

        return in_array($language, self::SUPPORTED, true) ? $language : null;
    }
}
