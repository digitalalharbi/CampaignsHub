<?php

declare(strict_types=1);

namespace App\Domains\Notifications\External\Charts;

/** No images: every chart falls back to its table (charts key off, renderer unavailable, tests). */
final class TableOnlyCharts implements ChartImages
{
    public function render(string $tenantId, string $cacheScope, array $specs): array
    {
        $out = [];
        foreach ($specs as $spec) {
            $out[(string) $spec['key']] = null;
        }

        return $out;
    }
}
