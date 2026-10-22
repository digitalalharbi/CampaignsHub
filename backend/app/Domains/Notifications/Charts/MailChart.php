<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Charts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * EMAIL-DECISION-REPORT-001 — static chart images for email, drawn server-side and served by token.
 *
 * Gmail and Outlook render no SVG and no script, so a chart in an email is a PNG behind an `<img>`.
 * The figures never travel in the URL: the mailable stores the chart's specification in the cache
 * under an opaque token and the message carries only `/mail/charts/{token}.png`. The route draws the
 * PNG with GD on request and answers 404 for a token it does not know (the cache expires with the
 * message's useful life). Every image is paired with a readable fallback — the figures as text and
 * an `alt` — so a client that blocks images loses the shape and keeps the numbers.
 *
 * Where GD is not loaded (`available()` is false) the mailable embeds nothing: the email is exactly
 * what it was, and never a broken image.
 */
final class MailChart
{
    public const TTL_DAYS = 60;

    /** The brand palette the share card and the lockup use — no other colour draws in an email. */
    public const COLOURS = ['#0d8a6f', '#e8a33d', '#3d6bd8', '#b45309', '#8b5cf6', '#0b7285', '#b91c1c', '#5b6b68'];

    public static function available(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor') && function_exists('imagepng');
    }

    /**
     * Store a specification and return its token, or null when nothing can be drawn from it.
     *
     * @param  array<string, mixed>  $spec  `['kind' => 'trend', 'values' => [...]]` or `['kind' => 'donut', 'segments' => [['label' => …, 'value' => …], …]]`
     */
    public static function store(array $spec): ?string
    {
        if (! self::available() || ! self::drawable($spec)) {
            return null;
        }
        $token = Str::lower(Str::random(32));
        Cache::put('mail-chart:'.$token, $spec, now()->addDays(self::TTL_DAYS));

        return $token;
    }

    /** @return array<string, mixed>|null */
    public static function spec(string $token): ?array
    {
        $spec = Cache::get('mail-chart:'.$token);

        return is_array($spec) ? $spec : null;
    }

    public static function url(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/mail/charts/'.$token.'.png';
    }

    /** @param  array<string, mixed>  $spec */
    public static function drawable(array $spec): bool
    {
        if (($spec['kind'] ?? null) === 'trend') {
            $values = array_values(array_filter((array) ($spec['values'] ?? []), 'is_numeric'));

            return count($values) >= 2 && count(array_unique(array_map('floatval', $values))) > 1;
        }
        if (($spec['kind'] ?? null) === 'donut') {
            $sum = 0.0;
            foreach ((array) ($spec['segments'] ?? []) as $segment) {
                $sum += max(0.0, (float) ($segment['value'] ?? 0));
            }

            return $sum > 0.0;
        }

        return false;
    }

    /**
     * Draw the PNG for a specification. The caller has checked `available()`.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function render(array $spec): string
    {
        return ($spec['kind'] ?? '') === 'donut' ? self::donut($spec) : self::trend($spec);
    }

    /** @param  array<string, mixed>  $spec */
    private static function trend(array $spec): string
    {
        $values = array_map('floatval', array_values(array_filter((array) ($spec['values'] ?? []), 'is_numeric')));
        $w = max(120, min(640, (int) ($spec['width'] ?? 320)));
        $h = max(32, min(240, (int) ($spec['height'] ?? 72)));
        // Drawn at twice the size for a sharp image on high-density screens; the <img> states the CSS size.
        $scale = 2;
        $img = imagecreatetruecolor($w * $scale, $h * $scale);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imageantialias($img, true);

        [$r, $g, $b] = self::rgb((string) ($spec['colour'] ?? self::COLOURS[0]));
        $line = imagecolorallocate($img, $r, $g, $b);
        $fill = imagecolorallocatealpha($img, $r, $g, $b, 100);

        $min = min($values);
        $max = max($values);
        $span = $max - $min ?: 1.0;
        $n = count($values);
        $pad = 4 * $scale;
        $x = static fn (int $i): int => (int) round($pad + ($i / ($n - 1)) * ($w * $scale - 2 * $pad));
        $y = static fn (float $v): int => (int) round($h * $scale - $pad - (($v - $min) / $span) * ($h * $scale - 2 * $pad));

        // The area under the line, then the line itself, then the endpoint — the same shape the dashboard draws.
        $polygon = [];
        foreach ($values as $i => $v) {
            $polygon[] = $x($i);
            $polygon[] = $y($v);
        }
        $polygon[] = $x($n - 1);
        $polygon[] = $h * $scale - $pad;
        $polygon[] = $x(0);
        $polygon[] = $h * $scale - $pad;
        imagefilledpolygon($img, $polygon, $fill);
        imagesetthickness($img, 2 * $scale);
        for ($i = 1; $i < $n; $i++) {
            imageline($img, $x($i - 1), $y($values[$i - 1]), $x($i), $y($values[$i]), $line);
        }
        imagefilledellipse($img, $x($n - 1), $y($values[$n - 1]), 6 * $scale, 6 * $scale, $line);

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** @param  array<string, mixed>  $spec */
    private static function donut(array $spec): string
    {
        $size = max(64, min(320, (int) ($spec['size'] ?? 120)));
        $scale = 2;
        $px = $size * $scale;
        $img = imagecreatetruecolor($px, $px);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);

        $segments = array_values(array_filter((array) ($spec['segments'] ?? []), static fn ($s): bool => (float) ($s['value'] ?? 0) > 0));
        $total = array_sum(array_map(static fn ($s): float => (float) $s['value'], $segments));
        $cx = $cy = intdiv($px, 2);
        $outer = $px - 2 * $scale;
        $start = -90.0;
        foreach ($segments as $i => $segment) {
            $sweep = 360.0 * (float) $segment['value'] / $total;
            [$r, $g, $b] = self::rgb((string) ($segment['colour'] ?? self::COLOURS[$i % count(self::COLOURS)]));
            $colour = imagecolorallocate($img, $r, $g, $b);
            // A single segment is a full ring; GD's arc wants start < end within one turn.
            $end = $start + ($sweep >= 360.0 ? 359.99 : $sweep);
            imagefilledarc($img, $cx, $cy, $outer, $outer, (int) round($start), (int) round($end), $colour, IMG_ARC_PIE);
            $start = $end;
        }
        // The hole: transparent, so the card's own background shows through on every client.
        imagealphablending($img, false);
        imagefilledellipse($img, $cx, $cy, (int) round($outer * 0.58), (int) round($outer * 0.58), imagecolorallocatealpha($img, 0, 0, 0, 127));

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            $hex = '0d8a6f';
        }

        return [(int) hexdec(substr($hex, 0, 2)), (int) hexdec(substr($hex, 2, 2)), (int) hexdec(substr($hex, 4, 2))];
    }
}
