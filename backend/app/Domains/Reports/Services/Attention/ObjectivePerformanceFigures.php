<?php

declare(strict_types=1);

namespace App\Domains\Reports\Services\Attention;

use App\Domains\Metrics\Services\ObjectivePerformance;
use Illuminate\Support\Carbon;

/**
 * The seam, answered by `ObjectivePerformance` directly — the same bounded, bindings-aware read the
 * objective sections of every report already use, so a finding and the objective table beside it
 * cannot disagree about which campaign is a lead campaign or which account is in scope.
 */
final class ObjectivePerformanceFigures implements AttentionFigures
{
    public function __construct(private readonly ObjectivePerformance $performance) {}

    public function byFamilyAndPlatform(Carbon $from, Carbon $to): array
    {
        return $this->performance->byFamilyAndPlatform($from, $to);
    }
}
