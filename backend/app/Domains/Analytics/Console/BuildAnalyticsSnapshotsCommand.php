<?php

declare(strict_types=1);

namespace App\Domains\Analytics\Console;

use App\Domains\Analytics\Jobs\BuildAnalyticsSnapshotJob;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ANALYTICS-DATA-001 §9 — queue the pre-aggregation of the analytics sections, one job per ACTIVE project.
 *
 * Bounded by construction: only projects that hold a store (the heavy sections are about orders) and whose order
 * ledger changed recently are queued, at most `--limit` per pass, so a pass on the 1-vCPU host is a handful of queued
 * jobs and not a table scan of every tenant. The command reads ids only; the work is in the queue.
 */
final class BuildAnalyticsSnapshotsCommand extends Command
{
    protected $signature = 'analytics:build-snapshots {--limit=40 : most projects queued in one pass} {--days=2 : a project is active when an order or a sync landed this recently}';

    protected $description = 'Queue the pre-aggregation of the analytics sections for active projects with a store';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $since = now()->subDays(max(1, (int) $this->option('days')));

        $projects = DB::table('commerce_orders')
            ->where('updated_at', '>=', $since)
            ->select('tenant_id', 'project_id')
            ->groupBy('tenant_id', 'project_id')
            ->orderBy('tenant_id')->orderBy('project_id')
            ->limit($limit)
            ->get();

        foreach ($projects as $p) {
            BuildAnalyticsSnapshotJob::dispatch((string) $p->tenant_id, (string) $p->project_id);
        }

        app(ScheduledRunRows::class)->report($projects->count());

        $this->info('Queued '.$projects->count().' project(s).');

        return self::SUCCESS;
    }
}
