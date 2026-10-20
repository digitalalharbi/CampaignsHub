<?php

declare(strict_types=1);

namespace App\Domains\Commerce\Console;

use App\Domains\Audit\AuditLogger;
use App\Domains\Commerce\Models\CommerceAbandonedCart;
use App\Domains\Commerce\Models\CommerceCustomer;
use App\Domains\Commerce\Models\CommerceOrder;
use App\Domains\Commerce\Models\CommerceOrderItem;
use App\Domains\Commerce\Models\CommerceProduct;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * SALLA-ONBOARD-001 — what a merchant who uninstalled the app is promised: «we keep your data 30 days, then purge».
 *
 * `app.uninstalled` revokes the tokens, stops the sync and stamps `uninstalled_at`. This is the other half: it
 * removes the store's synced data (orders and their lines, products, customers, abandoned carts), its bindings
 * to projects, its store accounts, and the dead credential and connection, for every Salla connection uninstalled
 * longer than the retention period ago. A reinstall inside the period clears the stamp and nothing is removed.
 *
 * Only the store's own commerce rows go. The workspace, its projects, its users and anything synced from an ad
 * platform are untouched. It writes one audit entry per connection with COUNTS only — no order, no customer.
 */
final class PurgeUninstalledStoresCommand extends Command
{
    protected $signature = 'commerce:purge-uninstalled {--days=30 : Retention period} {--pretend : Say what would go and remove nothing}';

    protected $description = 'Remove the synced data of Salla stores uninstalled longer than the retention period ago';

    public function handle(AuditLogger $audit): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = Carbon::now()->subDays($days);

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('provider', 'salla')
            ->where('status', 'revoked')
            ->whereNotNull('uninstalled_at')
            ->where('uninstalled_at', '<', $cutoff)
            ->get();

        $purged = 0;
        foreach ($connections as $connection) {
            $accountIds = ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->getKey())->pluck('id')->all();
            $counts = [
                'orders' => CommerceOrder::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->count(),
                'products' => CommerceProduct::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->count(),
                'customers' => CommerceCustomer::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->count(),
                'carts' => CommerceAbandonedCart::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->count(),
            ];

            if ($this->option('pretend')) {
                $this->line("would purge connection {$connection->getKey()}: ".json_encode($counts));

                continue;
            }

            DB::transaction(function () use ($connection, $accountIds): void {
                $orderIds = CommerceOrder::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->pluck('id')->all();
                CommerceOrderItem::withoutGlobalScopes()->whereIn('commerce_order_id', $orderIds)->delete();
                CommerceOrder::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->delete();
                CommerceProduct::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->delete();
                CommerceAbandonedCart::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->delete();
                CommerceCustomer::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->delete();
                ProjectIntegrationBinding::withoutGlobalScopes()->whereIn('external_account_id', $accountIds)->delete();
                ExternalAccount::withoutGlobalScopes()->whereIn('id', $accountIds)->delete();
                $credentialId = $connection->credential_id;
                $connection->delete();
                if ($credentialId !== null) {
                    IntegrationCredential::withoutGlobalScopes()->whereKey($credentialId)->delete();
                }
            });

            $audit->log(
                action: 'salla.store.purged_after_uninstall',
                entityType: ProviderConnection::class,
                entityId: (string) $connection->getKey(),
                after: $counts + ['retention_days' => $days],
            );
            $purged++;
        }

        // The count the ops page shows for this run: connections purged, not a bare «succeeded».
        app(ScheduledRunRows::class)->report($purged);

        $this->info(($this->option('pretend') ? 'would purge ' : 'purged ').$purged.' store connection(s) uninstalled more than '.$days.' days ago.');

        return self::SUCCESS;
    }
}
