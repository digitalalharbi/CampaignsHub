<?php

declare(strict_types=1);

namespace App\Domains\Subscriptions\Seats;

use App\Domains\Ops\Services\ScheduledRunRows;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `seats:reconcile` — stamps released ad-account seats close to when they were released, so the 30-day replacement window starts
 * then rather than at the workspace's next connection. Locks each tenant row like a confirmation does. Read the bindings, write
 * seats and history only; never touches a binding, a connection or a subscription.
 */
final class ReconcileAdAccountSeats extends Command
{
    protected $signature = 'seats:reconcile';

    protected $description = 'Reconcile ad-account seats with the active bindings (PRICING-SEATS-001)';

    public function handle(AdAccountSeats $seats): int
    {
        $before = DB::table('ad_account_seat_events')->count();
        $ids = DB::table('ad_account_seats')->distinct()->pluck('tenant_id')
            ->merge(DB::table('project_integration_bindings')->where('is_active', true)->distinct()->pluck('tenant_id'))
            ->unique()->values();

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $seats): void {
                DB::table('tenants')->where('id', $id)->lockForUpdate()->first();
                $tenant = Tenant::withoutGlobalScopes()->find($id);
                if ($tenant !== null) {
                    $seats->reconcile($tenant);
                }
            });
        }
        // Rows touched = seat events written (claimed, released, reactivated); history is append-only, so the difference is exact.
        $written = DB::table('ad_account_seat_events')->count() - $before;
        app(ScheduledRunRows::class)->report($written);
        $this->info('Reconciled '.$ids->count().' workspace(s); '.$written.' seat event(s) written.');

        return self::SUCCESS;
    }
}
