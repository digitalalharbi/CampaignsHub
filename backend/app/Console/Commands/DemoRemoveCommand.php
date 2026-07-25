<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;

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

        $tenant = Tenant::where('slug', 'demo-agency')->first();
        $workspaces = 0;
        if ($tenant) {
            app(TenantContext::class)->setTenantId((string) $tenant->id);
            // Hard delete (force) so nothing lingers soft-deleted; DB cascade removes the demo
            // analytics project + its unified campaigns.
            $workspaces = ClientWorkspace::withTrashed()->where('slug', 'demo-store-analytics')->forceDelete();
            app(TenantContext::class)->forget();
        }

        $this->info("Removed demo analytics: {$metrics} metrics, {$runs} sync runs, {$workspaces} workspace(s).");

        return self::SUCCESS;
    }
}
