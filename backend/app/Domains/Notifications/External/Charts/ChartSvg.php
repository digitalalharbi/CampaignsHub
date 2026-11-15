<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

use App\Domains\Notifications\External\Design\DesignTokens as T;

/**
 * SVG for one chart spec (`ChartSpecs`), in one theme, drawn only with the shared design tokens (spec §11). Logical
 * size is the email card's inner width; the renderer rasterises it at 2x. Transparent ground, so the image sits on the
 * card in either theme; the dark variant changes only ink and track colours.
 */
final class ChartSvg
{
    public const WIDTH = 520;

    /** @param array<string, mixed> $spec @return array{svg: string, width: int, height: int}|null */
    public static function for(array $spec, string $theme): ?array
    {
        return match ($spec['kind'] ?? null) {
            'donut' => self::donut($spec, $theme),
            'trend' => self::trend($spec, $theme),
            'ring' => self::ring($spec, $theme),
            'icon' => self::icon($spec, $theme),
            default => null,
        };
    }

    /** @param array<string, mixed> $spec */
    private static function donut(array $spec, string $theme): ?array
    {
        $segments = array_values(array_filter((array) $spec['segments'], static fn (array $s): bool => (float) $s['value'] > 0));
        $total = array_sum(array_map(static fn (array $s): float => (float) $s['value'], $segments));
        if ($total <= 0) {
            return null;
        }
        $w = 220;
        $h = 190;
        $cx = $w / 2;
        $cy = 95;
        $r = 68;
        $circ = 2 * M_PI * $r;
        $offset = 0.0;
        $arcs = '';
        foreach ($segments as $s) {
            $len = $circ * ((float) $s['value'] / $total);
            $arcs .= sprintf('<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="26" stroke-dasharray="%.3f %.3f" stroke-dashoffset="%.3f" transform="rotate(-90 %s %s)"/>',
                $cx, $cy, $r, T::platform((string) $s['provider'], $theme), max(0.0, $len - 1.5), $circ, -$offset, $cx, $cy);
            $offset += $len;
        }
        $center = self::e((string) ($spec['center'] ?? ''));

        return ['svg' => self::wrap($w, $h, '<circle cx="'.$cx.'" cy="'.$cy.'" r="'.$r.'" fill="none" stroke="'.T::color('surface-secondary', $theme).'" stroke-width="26"/>'.$arcs
            .'<text x="'.$cx.'" y="'.($cy + 6).'" text-anchor="middle" font-size="20" font-weight="800" fill="'.T::color('text-primary', $theme).'">'.$center.'</text>'), 'width' => $w, 'height' => $h];
    }

    /** @param array<string, mixed> $spec */
    private static function trend(array $spec, string $theme): ?array
    {
        $values = array_values((array) $spec['values']);
        $present = array_filter($values, 'is_numeric');
        if (count($present) < 2) {
            return null;
        }
        $previous = array_values((array) ($spec['previous'] ?? []));
        $w = self::WIDTH;
        $h = 150;
        $pad = 12;
        $max = max(array_merge($present, array_filter($previous, 'is_numeric'), [1]));
        $n = count($values);
        $x = static fn (int $i, int $count): float => $pad + ($count <= 1 ? 0 : $i * ($w - 2 * $pad) / ($count - 1));
        $y = static fn (float $v): float => $h - $pad - ($v / $max) * ($h - 2 * $pad - 10);
        $line = static function (array $vals) use ($x, $y): string {
            $pts = [];
            foreach ($vals as $i => $v) {
                if (is_numeric($v)) {
                    $pts[] = sprintf('%.1f,%.1f', $x($i, count($vals)), $y((float) $v));
                }
            }

            return implode(' ', $pts);
        };
        $cur = $line($values);
        $area = sprintf('%.1f,%.1f ', $x(0, $n), $h - $pad).$cur.sprintf(' %.1f,%.1f', $x($n - 1, $n), $h - $pad);
        $brand = T::color('brand-600', $theme);
        $grid = '';
        foreach ([0.25, 0.5, 0.75] as $g) {
            $gy = $y($max * $g);
            $grid .= sprintf('<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="%s" stroke-width="1"/>', $pad, $gy, $w - $pad, $gy, T::color('border', $theme));
        }
        $prev = count(array_filter($previous, 'is_numeric')) >= 2
            ? '<polyline points="'.$line($previous).'" fill="none" stroke="'.T::color('text-muted', $theme).'" stroke-width="2" stroke-dasharray="4 4" opacity="0.55"/>' : '';

        return ['svg' => self::wrap($w, $h, $grid
            .sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-width="1"/>', $pad, $h - $pad, $w - $pad, $h - $pad, T::color('border-strong', $theme))
            .'<polygon points="'.$area.'" fill="'.$brand.'" opacity="0.14"/>'.$prev
            .'<polyline points="'.$cur.'" fill="none" stroke="'.$brand.'" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>'), 'width' => $w, 'height' => $h];
    }

    /** @param array<string, mixed> $spec */
    private static function ring(array $spec, string $theme): array
    {
        $ratio = max(0.0, min(1.0, (float) $spec['ratio']));
        $s = 112;
        $c = $s / 2;
        $r = 44;
        $circ = 2 * M_PI * $r;

        return ['svg' => self::wrap($s, $s,
            '<circle cx="'.$c.'" cy="'.$c.'" r="'.$r.'" fill="none" stroke="'.T::color('surface-secondary', $theme).'" stroke-width="12"/>'
            .sprintf('<circle cx="%s" cy="%s" r="%s" fill="none" stroke="%s" stroke-width="12" stroke-linecap="round" stroke-dasharray="%.3f %.3f" transform="rotate(-90 %s %s)"/>', $c, $c, $r, T::color('brand-600', $theme), $circ * $ratio, $circ, $c, $c)
            .'<text x="'.$c.'" y="'.($c + 6).'" text-anchor="middle" font-size="18" font-weight="700" fill="'.T::color('text-primary', $theme).'">'.self::e((string) $spec['label']).'</text>'), 'width' => $s, 'height' => $s];
    }

    /** A 16px stroke glyph from the product's icon set (`Icons`), drawn in a token colour. @param array<string, mixed> $spec */
    private static function icon(array $spec, string $theme): ?array
    {
        $paths = Icons::PATHS[(string) ($spec['name'] ?? '')] ?? null;
        if ($paths === null) {
            return null;
        }
        $color = T::color((string) ($spec['color'] ?? 'text-muted'), $theme);

        return ['svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="'.$color.'" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">'.$paths.'</svg>', 'width' => 16, 'height' => 16];
    }

    private static function wrap(int $w, int $h, string $body): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'" font-family="Noto Sans Arabic, IBM Plex Sans Arabic, Arial, sans-serif">'.$body.'</svg>';
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
