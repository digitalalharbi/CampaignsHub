<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Support;

/**
 * EMAIL-PLAINTEXT-001 — the text/plain alternative of an HTML message.
 *
 * Derived from the HTML that is actually sent, never written separately: a second copy of the words
 * is a second place for a figure to drift, and a text part that disagrees with the HTML part is worse
 * than none. Links keep their address («label (url)») because a client that shows only text has no
 * other way to follow them.
 */
final class PlainText
{
    public static function fromHtml(string $html): string
    {
        // Head, styles and the hidden preheader are for the HTML renderer, not for a reader.
        $html = (string) preg_replace('~<(head|style|script|title)\b.*?</\1>~is', '', $html);
        $html = (string) preg_replace('~<(div|span|td)\b[^>]*display\s*:\s*none[^>]*>.*?</\1>~is', '', $html);

        $html = (string) preg_replace_callback(
            '~<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>~is',
            static function (array $m): string {
                $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $label === '' || $label === $url || str_starts_with($url, 'mailto:')
                    ? ($label !== '' ? $label : $url)
                    : $label.' ('.$url.')';
            },
            $html,
        );

        $html = (string) preg_replace('~<img\b[^>]*alt="([^"]*)"[^>]*>~i', '$1', $html);
        $html = (string) preg_replace('~<br\s*/?>~i', "\n", $html);
        $html = (string) preg_replace('~</(p|div|tr|h[1-6]|li|table)>~i', "\n", $html);
        $html = (string) preg_replace('~</t[dh]>~i', '  ', $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = (string) preg_replace('~[ \t]+~', ' ', $text);
        $text = (string) preg_replace('~ *\n *~', "\n", $text);
        $text = (string) preg_replace('~\n{3,}~', "\n\n", $text);

        return trim($text)."\n";
    }
}
