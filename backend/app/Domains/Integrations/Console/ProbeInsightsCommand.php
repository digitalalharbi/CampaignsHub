<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * INTEG-RUNTIME §7 — asking the provider a question, and storing nothing.
 *
 * ## What this is for, and what `integrations:diagnose` cannot do
 *
 * The diagnosis reads what is already recorded. For the live Snapchat account it answered precisely:
 * the platform returned **0 rows**, every half hour, for the window 2026-08-11 → 2026-08-18. §7 says
 * a provider that really returned 0 is `NO_DATA` and not an error — but «really» is the whole
 * question. Zero rows for seven days across 89 discovered campaigns has two very different readings:
 *
 *   the account genuinely had no delivery in that window       → NO_DATA. Nothing to fix.
 *   the request cannot return rows for this account            → a defect, and a silent one.
 *
 * Nothing already stored separates them, because both look identical from the record. So this asks
 * the platform directly, over a window the caller chooses, and prints what comes back.
 *
 * ## Read-only, and read-only in a stronger sense than the diagnosis
 *
 * `integrations:diagnose` touches no network. This one does — it is the point — but it writes
 * NOTHING: no `MetricSyncRun`, no `DailyMetric`, no retained payload, no checkpoint on the account.
 * It uses the connection that already exists, so there is no re-authorisation and no token is
 * replaced. A probe that left rows behind would change the very state somebody is trying to read.
 */
final class ProbeInsightsCommand extends Command
{
    protected $signature = 'integrations:probe
        {account : The external account — ours, or the provider\'s own id}
        {--from= : Window start, YYYY-MM-DD (default: 30 days back)}
        {--to= : Window end, YYYY-MM-DD (default: yesterday)}
        {--rows=3 : How many returned rows to print}';

    protected $description = 'Read-only: ask the provider for insights over a window and print what came back. Stores nothing.';

    public function handle(AdvertisingConnectorRegistry $registry): int
    {
        $reference = (string) $this->argument('account');

        $account = ExternalAccount::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('id', $reference)->orWhere('external_id', $reference))
            ->where('account_type', 'ad_account')
            ->first();

        if ($account === null) {
            $this->error("No ad account matches '{$reference}'.");

            return self::FAILURE;
        }

        $from = $this->date('from', now()->subDays(30)->toDateString());
        $to = $this->date('to', now()->subDay()->toDateString());

        $connector = $registry->get($account->provider);

        if ($connector === null) {
            $this->error("No connector is registered for provider '{$account->provider}'.");

            return self::FAILURE;
        }

        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                $this->error('That account has no provider connection to ask through.');

                return self::FAILURE;
            }

            $connector = $connector->withConnection($connection);
        }

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            $this->error($connector->label().' has no credentials on this install; nothing was asked.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line('  INSIGHTS PROBE — nothing is stored by this command.');
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  %s  [%s]  provider id %s', $account->name ?: '(unnamed)', $account->provider, $account->external_id));
        $this->line(sprintf('  window %s → %s   account timezone %s', $from, $to, $account->timezone ?? 'NOT CAPTURED'));

        try {
            $result = $connector->syncInsights($account->external_id, $from, $to);
        } catch (Throwable $e) {
            $this->line('');
            $this->error('  The provider call threw: '.$e->getMessage());

            return self::SUCCESS;
        }

        if (! $result->success) {
            $this->line('');
            $this->error('  The provider refused: '.($result->message ?? 'no message given'));

            return self::SUCCESS;
        }

        $rows = $result->records;

        // The bodies the connector was handed, drained so nothing carries into a later call.
        $bodies = $connector instanceof ApiAdvertisingConnector ? $connector->takeRawResponses() : [];
        $rawRows = $connector instanceof ApiAdvertisingConnector ? $connector->takeRawInsightRows() : count($rows);

        $known = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->pluck('external_id')
            ->flip();

        $mapped = 0;
        foreach ($rows as $row) {
            if ($known->has((string) ($row['campaign_id'] ?? ''))) {
                $mapped++;
            }
        }

        $this->line('');
        $this->line(sprintf('  provider_raw_rows    %d', $rawRows));
        $this->line(sprintf('  parsed_rows          %d', count($rows)));
        $this->line(sprintf('  mapped_campaign_rows %d   (of %d campaigns discovered)', $mapped, $known->count()));
        $this->line(sprintf('  responses received   %d', count($bodies)));

        foreach (array_slice($rows, 0, max(0, (int) $this->option('rows'))) as $index => $row) {
            $this->line(sprintf('  row[%d] %s', $index, json_encode($row, JSON_UNESCAPED_UNICODE) ?: ''));
        }

        if ($rows === []) {
            $this->line('');
            $this->line('  The provider returned no rows. Its own last body, truncated:');
            foreach (array_slice($bodies, -2) as $body) {
                $this->line('  '.mb_substr(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 1500));
            }
        }

        return self::SUCCESS;
    }

    private function date(string $option, string $fallback): string
    {
        $value = $this->option($option);

        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : $fallback;
    }
}
