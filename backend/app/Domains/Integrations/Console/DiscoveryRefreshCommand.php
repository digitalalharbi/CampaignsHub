<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountDiscovery;
use Illuminate\Console\Command;
use Throwable;

/**
 * DISCOVERY-REFRESH-001 — the «تحديث الحسابات المتاحة» button, runnable by an operator.
 *
 * Discovery runs on connect and on that button, and nowhere else. A connector change that widens
 * what discovery can see (META-BUSINESS-ACCOUNTS-001 reads each Business Manager's owned and client
 * accounts) therefore reaches nothing until somebody signed in presses it — and Production showed
 * exactly that: Meta listing eight business accounts, the probe naming «Henka» among them, and both
 * installs still storing the person's own list, with no error recorded on either connection.
 *
 * It runs the SAME `AccountDiscovery::refresh` as the button: nothing is assigned to a project and
 * nothing syncs. Without `--apply` it only asks the provider and prints ids that would be added.
 * Ids only — an account or business name never reaches a workflow log.
 */
final class DiscoveryRefreshCommand extends Command
{
    protected $signature = 'integrations:discovery-refresh
        {--connection= : The provider connection (uuid, ours)}
        {--apply : Store what the provider lists. Without this the command only reports.}';

    protected $description = 'Re-run account discovery for one connection, exactly as the refresh button does.';

    public function handle(AdvertisingConnectorRegistry $registry, AccountDiscovery $discovery): int
    {
        $connection = ProviderConnection::withoutGlobalScopes()->find((string) $this->option('connection'));

        if ($connection === null) {
            $this->error('No connection with that id.');

            return self::FAILURE;
        }

        if ($connection->status !== 'connected') {
            $this->error("The connection is {$connection->status}, so there is nothing to read. It needs reconnecting.");

            return self::FAILURE;
        }

        $stored = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->pluck('external_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $this->line(sprintf('  connection %s [%s]   stored before: %d', $connection->getKey(), $connection->provider, count($stored)));

        if (! $this->option('apply')) {
            $connector = $registry->get($connection->provider);
            if (! $connector instanceof ApiAdvertisingConnector) {
                $this->error('This provider has no account discovery.');

                return self::FAILURE;
            }

            try {
                $bound = $connector->withConnection($connection);
                $listed = array_map(static fn (array $a): string => (string) $a['external_id'], $bound->listAdAccounts());
            } catch (Throwable $e) {
                $this->error('  The provider refused: '.$e->getMessage());

                return self::FAILURE;
            }

            $new = array_values(array_diff($listed, $stored));
            $this->line(sprintf('  listed by the provider: %d   would be added: %d%s', count($listed), count($new), $new === [] ? '' : ' — '.implode(', ', $new)));
            $warnings = method_exists($bound, 'lastDiscoveryWarnings') ? $bound->lastDiscoveryWarnings() : [];
            foreach ($warnings as $w) {
                $this->warn("  warning [{$w['customer']}]: {$w['reason']}");
            }
            $this->line('  DRY RUN — nothing stored. Tick apply to store them.');

            return self::SUCCESS;
        }

        try {
            $result = $discovery->refresh($connection);
        } catch (Throwable $e) {
            $this->error('  Discovery failed and was recorded on the connection: '.$e->getMessage());

            return self::FAILURE;
        }

        $after = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->pluck('external_id')
            ->map(fn ($id): string => (string) $id)
            ->all();
        $added = array_values(array_diff($after, $stored));

        $this->line(sprintf(
            '  APPLIED — discovered %d, created %d, access lost %d   stored after: %d%s',
            (int) ($result['discovered'] ?? 0),
            (int) ($result['created'] ?? 0),
            (int) ($result['access_lost'] ?? 0),
            count($after),
            $added === [] ? '' : ' — added '.implode(', ', $added),
        ));

        return self::SUCCESS;
    }
}
