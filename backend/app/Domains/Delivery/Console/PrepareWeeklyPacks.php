<?php

declare(strict_types=1);

namespace App\Domains\Delivery\Console;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Services\DeliveryPackService;
use App\Domains\Delivery\Services\DeliverySettings;
use App\Domains\Metrics\Services\ReportingTimezone;
use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * WD-SCHEDULE-001 — prepare last week's delivery packs without anybody having to remember.
 *
 * It only PREPARES: a draft pack and its queued report. Nothing is approved or sent — the Owner still
 * reviews the observations and decides what the client receives, so a Monday-morning run can never
 * tell a client something unreviewed.
 *
 * Runs daily and prepares each client on ITS day (DELIVERY-SETTINGS-001: the first day of its week, or the 1st for a monthly cadence).
 *
 * Opt-in per client (`settings.delivery.weekly`), because an automatic weekly report for a client the
 * agency has not agreed one with is an unrequested deliverable. Asking again for the same week returns
 * the existing pack, so the run is safe to repeat and to overlap with a manual preparation.
 */
final class PrepareWeeklyPacks extends Command
{
    protected $signature = 'delivery:prepare-weekly
        {--date= : Treat this YYYY-MM-DD as today — the week BEFORE it is prepared}
        {--client= : One client workspace id only}';

    protected $description = 'Prepare last week’s draft delivery packs for the clients that opted in.';

    public function handle(DeliveryPackService $packs, TenantContext $tenants): int
    {
        $anchor = $this->option('date') !== null ? Carbon::parse((string) $this->option('date')) : null;
        $today = ($anchor ?? Carbon::now(ReportingTimezone::DEFAULT))->copy()->startOfDay();

        $workspaces = ClientWorkspace::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->when($this->option('client') !== null, fn ($q) => $q->whereKey((string) $this->option('client')))
            ->get()
            ->filter(fn (ClientWorkspace $w): bool => data_get($w->settings, 'delivery.weekly') === true);
        // Runs every day; a client is prepared only on ITS day (its week's first day, or the 1st for monthly).
        // A manual `--client` run is an explicit request and ignores the day.
        $workspaces = $workspaces->filter(fn (ClientWorkspace $w): bool => $this->option('client') !== null || DeliverySettings::isPrepareDay(DeliverySettings::for($w), $today));

        $prepared = 0;
        foreach ($workspaces as $workspace) {
            $tenants->setTenantId((string) $workspace->tenant_id);

            $projects = Project::query()->where('client_workspace_id', $workspace->getKey())->where('status', 'active')->get();
            foreach ($projects as $project) {
                $packs->prepare($project, $anchor);
                $prepared++;
            }
        }

        // The packs this sweep ensured — what the ops page shows as the run's rows.
        app(ScheduledRunRows::class)->report($prepared);
        $this->line(sprintf('  %d client(s) opted in, %d pack(s) ensured.', $workspaces->count(), $prepared));

        return self::SUCCESS;
    }
}
