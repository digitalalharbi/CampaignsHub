<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Jobs;

use App\Domains\Analytics\Services\AnalyticsReads;
use App\Domains\Analytics\Services\AnalyticsSnapshots;
use App\Domains\Metrics\Services\PeriodResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * ANALYTICS-DATA-001 §9 — pre-aggregate ONE project's heavy analytics sections for the default window.
 *
 * One job is one project, so one slow or failing project can never hold another's. It runs on the queue, never in a
 * request, retries nothing (the next scheduled pass is the retry) and writes only the stored snapshot rows of its own
 * tenant and project.
 */
final class BuildAnalyticsSnapshotJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId, public readonly string $projectId) {}

    public function handle(AnalyticsReads $reads, AnalyticsSnapshots $snapshots): void
    {
        $period = PeriodResolver::preset(PeriodResolver::PRESETS[array_search('last_30', PeriodResolver::PRESETS, true)]);
        $from = Carbon::parse($period['from']);
        $to = Carbon::parse($period['to']);

        foreach (AnalyticsReads::PRE_AGGREGATED as $section) {
            $snapshots->store($this->tenantId, $this->projectId, $section, $period['from'], $period['to'], $reads->compute($section, $this->tenantId, $this->projectId, $from, $to));
        }
    }
}
