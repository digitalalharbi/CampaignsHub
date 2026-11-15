<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

/**
 * Turns chart specifications into email images (spec §11). Returns, per spec key, the PNG bytes (light and dark) or null
 * when the chart could not be drawn — the composer then shows the table equivalent. Implementations must never throw.
 */
interface ChartImages
{
    /**
     * @param  list<array<string, mixed>>  $specs  each with `key`, `kind` and its data (see `ChartSvg`)
     * @return array<string, array{light: string, dark: string, width: int, height: int, ms: float}|null>
     */
    public function render(string $tenantId, string $cacheScope, array $specs): array;
}
