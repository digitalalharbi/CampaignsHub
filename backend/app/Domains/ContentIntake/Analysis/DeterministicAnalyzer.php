<?php

declare(strict_types=1);

namespace App\Domains\ContentIntake\Analysis;

use App\Domains\ContentIntake\Models\ContentIntakeItem;

/** Facts read from the item itself — orientation, length, copy and link anatomy. No model, no estimate. */
final class DeterministicAnalyzer implements CreativeAnalyzer
{
    public function key(): string
    {
        return 'deterministic';
    }

    public function status(): string
    {
        return 'active';
    }

    public function analyze(ContentIntakeItem $item): array
    {
        $w = $item->width;
        $h = $item->height;
        $orientation = ($w && $h) ? ($h > $w * 1.03 ? 'vertical' : ($w > $h * 1.03 ? 'horizontal' : 'square')) : null;
        parse_str((string) parse_url((string) $item->destination_url, PHP_URL_QUERY), $q);

        return [
            'orientation' => $orientation,
            'resolution' => ($w && $h) ? $w.'x'.$h : null,
            'duration_seconds' => $item->duration_ms === null ? null : round($item->duration_ms / 1000, 1),
            'size_mb' => $item->size_bytes === null ? null : round($item->size_bytes / 1048576, 2),
            'headline_chars' => $item->headline === null ? null : mb_strlen($item->headline),
            'copy_chars' => $item->copy_text === null ? null : mb_strlen($item->copy_text),
            'has_cta' => trim((string) $item->cta) !== '',
            'has_destination' => trim((string) $item->destination_url) !== '',
            'utm_params' => array_values(array_filter(array_keys($q), static fn ($k): bool => str_starts_with((string) $k, 'utm_'))),
        ];
    }
}
