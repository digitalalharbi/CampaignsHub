<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\ProviderIdentity;
use Illuminate\Console\Command;

/**
 * INTEG-PROVIDER-IDENTITY-001 — backfill WHICH provider login each existing connection was authorised with.
 *
 * Dry-run by default: it lists what would be asked of which provider and calls nothing. With `--apply` it
 * asks each provider ONCE per connection, through the stored token (refreshed by the vault when due), and
 * records the display name / email the provider returns. A provider that refuses is reported and left
 * alone; there is no retry loop. Tokens are never printed.
 *
 * Google only returns an email on a connection that was authorised with the `email` scope, so older Google
 * connections report `refused` until they are reconnected once.
 */
final class IdentityRefreshCommand extends Command
{
    protected $signature = 'integrations:identity-refresh {--connection= : One connection id} {--provider= : One provider key} {--apply : Ask the providers and record the answer; without this nothing is called}';

    protected $description = 'Record which provider login each connection was authorised with';

    public function handle(ProviderIdentity $identity): int
    {
        $connections = ProviderConnection::withoutGlobalScopes()
            ->whereNull('removed_at')
            ->where('status', 'connected')
            ->when($this->option('connection'), fn ($q, $id) => $q->where('id', (string) $id))
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider', (string) $p))
            ->orderBy('provider')->orderBy('created_at')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No matching connection.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        foreach ($connections as $connection) {
            $known = $connection->provider_identity_label !== null || $connection->provider_identity_email !== null;

            if (! $apply) {
                $this->line(sprintf('  %s  %-10s %s', $connection->getKey(), $connection->provider, $known ? 'already recorded' : 'would be asked'));

                continue;
            }

            $this->line(sprintf('  %s  %-10s %s', $connection->getKey(), $connection->provider, $identity->refresh($connection)));
        }

        if (! $apply) {
            $this->info('Reporting only. Pass --apply to ask the providers.');
        }

        return self::SUCCESS;
    }
}
