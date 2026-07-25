<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Models\ReportRecipient;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

/** php artisan demo:remove — delete ONLY demo analytics data (is_demo rows + the demo store workspace). */
final class DemoRemoveCommand extends Command
{
    protected $signature = 'demo:remove';

    protected $description = 'Remove demo analytics data only (is_demo metrics/runs + demo store). Never touches real data.';

    public function handle(): int
    {
        if (! App::environment(['local', 'testing', 'demo'])) {
            $this->error('demo:remove is disabled outside local/testing/demo.');

            return self::FAILURE;
        }

        $metrics = DailyMetric::withoutGlobalScopes()->where('is_demo', true)->delete();
        $runs = MetricSyncRun::withoutGlobalScopes()->where('is_demo', true)->delete();

        // Delete demo report export files, then the rows (reports themselves also cascade with the
        // demo workspace below, but this clears exports/schedules/recipients + any stray is_demo rows).
        foreach (ReportExport::withoutGlobalScopes()->where('is_demo', true)->get() as $ex) {
            if ($ex->path) {
                Storage::disk($ex->disk)->delete($ex->path);
            }
        }
        ReportExport::withoutGlobalScopes()->where('is_demo', true)->delete();
        ReportRecipient::withoutGlobalScopes()->where('is_demo', true)->delete();
        ReportSchedule::withoutGlobalScopes()->where('is_demo', true)->delete();
        $reports = Report::withoutGlobalScopes()->where('is_demo', true)->delete();

        $tenant = Tenant::where('slug', 'demo-agency')->first();
        $workspaces = 0;
        if ($tenant) {
            app(TenantContext::class)->setTenantId((string) $tenant->id);
            // Hard delete (force) so nothing lingers soft-deleted; DB cascade removes the demo
            // analytics project + its unified campaigns.
            $workspaces = ClientWorkspace::withTrashed()->where('slug', 'demo-store-analytics')->forceDelete();
            app(TenantContext::class)->forget();
        }

        $this->info("Removed demo: {$metrics} metrics, {$runs} sync runs, {$reports} reports, {$workspaces} workspace(s).");

        return self::SUCCESS;
    }
}
