<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Content;

/**
 * THE external rendering mode (Owner, 2026-11-15): the approved template for the role/type, with every route back to the platform
 * removed and the calm vocabulary of spec §7. The templates themselves carry the switch (`external`: standalone shell, no button, no
 * link, no decision block, no linking block); this class prepares what they are fed and turns the finished HTML into the text part.
 */
final class ExternalMode
{
    /** Alarm colours of the digests, replaced by the calm amber outside the platform. */
    private const COLOURS = ['#b91c1c' => '#b45309', '#a31616' => '#8a4a07', '#c0392b' => '#e0a23a', '#f5bcbc' => '#fbe2ae', '#fef2f2' => '#fffbeb'];

    /**
     * The digest model for a recipient outside the platform: operational facts stay internal (spec §7) — the decision list, the
     * connection (linking) summary, the lead follow-up and every link are emptied — and a «problem» state reads as «being followed».
     *
     * @param  array<string, mixed>  $m  `OperationalDigestModel::project()`
     * @return array<string, mixed>
     */
    public static function digestModel(array $m, bool $ar): array
    {
        foreach (['issues', 'linking'] as $key) {
            $m[$key] = [];
        }
        $m['follow_up'] = null;
        $m['links'] = array_map(static fn ($v): string => '', (array) ($m['links'] ?? []));

        return self::calm($m, $ar);
    }

    public static function calm(mixed $v, bool $ar): mixed
    {
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $x) {
                $out[$k] = ($k === 'state' && $x === 'bad') ? 'watch' : (($k === 'tone' && $x === 'bad') ? 'watch' : self::calm($x, $ar));
            }

            return $out;
        }
        if (is_string($v)) {
            $v = self::COLOURS[strtolower($v)] ?? $v;

            return match ($v) {
                'مشكلة' => 'يحتاج متابعة', 'Problem' => 'Being followed', 'تجاوزت الميزانية' => 'يحتاج متابعة', 'Over budget' => 'Being followed', 'يُنفَق أسرع من الخطة' => 'الإنفاق أعلى من الوتيرة', 'Spending ahead of plan' => 'Spending above the usual pace',
                default => $v,
            };
        }

        return $v;
    }

    /** The plain-text part: the same message read line by line (every figure is already text in the markup). */
    public static function text(string $html, string $unsubscribeUrl, string $unsubscribeLabel): string
    {
        $body = (string) preg_replace('#<(style|head|title)[\s\S]*?</\1>|<!--[\s\S]*?-->#u', '', $html);
        $body = (string) preg_replace(['#<br\s*/?>#i', '#</(tr|div|p|table|h[1-6]|li)>#i'], "\n", $body);
        $body = (string) preg_replace('#</t[dh]>#i', ' ', $body);
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = array_values(array_filter(array_map(static fn (string $l): string => trim((string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $l)), explode("\n", $text)), static fn (string $l): bool => $l !== ''));

        return implode("\n", $lines)."\n\n".$unsubscribeLabel.': '.$unsubscribeUrl;
    }
}
