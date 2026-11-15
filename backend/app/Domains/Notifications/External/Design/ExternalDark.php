<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Design;

/**
 * The dark palette of the external report (spec §11), over the existing digest components. Those components carry explicit
 * light colours inline (so no client can invert them into black-on-black), which means a dark variant is a colour map, not a
 * second template. The same map drives both ways a reader gets dark:
 *
 *   - `apply()` rewrites the finished HTML for a message composed in the dark theme (previews, a dark-only client);
 *   - `mediaCss()` is the `prefers-color-scheme: dark` block of the light message (Apple Mail, iOS Mail), written as
 *     attribute selectors on the inline colour — the alternate chart images swap by class in the same block.
 *
 * The dark band of the header is dark in both, so `#0b1a16` stays a background and only becomes a text colour as ink.
 */
final class ExternalDark
{
    /** Text colour (`color:`) light → dark. */
    public const TEXT = [
        '#0b1a16' => '#f1f5f3', '#3d4742' => '#cdd8d4', '#5b6b68' => '#a9bab5', '#8b9a97' => '#8ea09b',
        '#0a6b57' => '#7ee2c5', '#8a4a07' => '#fbbf6a', '#1d4ed8' => '#9db9ff', '#0b7a62' => '#34d3a8', '#b45309' => '#fbbf6a', '#0d8a6f' => '#34d3a8',
    ];

    /** Background and border colours light → dark. */
    public const SURFACE = [
        '#ffffff' => '#13221e', '#f7f5f0' => '#0a1411', '#faf8f3' => '#182a26', '#e8f5f1' => '#16332c', '#fffbeb' => '#33290f',
        '#e6e2d6' => '#2b3d38', '#e4e0d6' => '#2b3d38', '#eeeae0' => '#223530', '#cfeee3' => '#14463a', '#fbe2ae' => '#5a410f',
        '#dbeafe' => '#1e3a6e', '#eff6ff' => '#14233f', '#9ccfc0' => '#2f7a69',
    ];

    public static function apply(string $html): string
    {
        $html = (string) preg_replace_callback('/(?<![-\w])color:(#[0-9a-f]{6})/i', static fn (array $m): string => 'color:'.(self::TEXT[strtolower($m[1])] ?? $m[1]), $html);
        foreach (self::SURFACE as $light => $dark) {
            $html = str_replace(['background-color:'.$light, 'bgcolor="'.$light.'"', '1px solid '.$light], ['background-color:'.$dark, 'bgcolor="'.$dark.'"', '1px solid '.$dark], $html);
        }

        return $html;
    }

    /** The dark-mode block of a light message: colours, and the swap from the light chart image to its dark alternate. */
    public static function mediaCss(): string
    {
        $css = '@media (prefers-color-scheme: dark){';
        foreach (self::SURFACE as $light => $dark) {
            $css .= '[style*="background-color:'.$light.'"],[bgcolor="'.$light.'"]{background-color:'.$dark.' !important}';
        }
        foreach (self::TEXT as $light => $dark) {
            $css .= '[style*=" color:'.$light.'"],[style^="color:'.$light.'"]{color:'.$dark.' !important}';
        }
        $css .= '[style*="solid #e4e0d6"]{border-color:#2b3d38 !important}[style*="solid #eeeae0"]{border-color:#223530 !important}';
        $css .= '.ch-light-img{display:none !important;max-height:0 !important}.ch-dark-img{display:block !important;max-height:none !important}}';

        return $css;
    }
}
