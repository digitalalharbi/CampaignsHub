<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\RefreshesCreativeMedia;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AD-MEDIA-RECOVERY-002 — re-sign the media of creatives whose platform links have expired or are about to.
 *
 * The structure sweep refreshes a creative only when an ad the account still lists points at it, so a
 * creative behind a paused or archived ad stayed `expired` for good. This selects exactly the dying
 * links, groups them by the ad account that owns them, and asks each connector for those creatives by
 * id. Only actively assigned accounts are asked (isolation); a refusal is recorded as a failed
 * `media_refresh` run naming the account's refusal, never swallowed.
 */
final class RefreshExpiredCreativeMediaCommand extends Command
{
    protected $signature = 'integrations:refresh-expired-media
        {--within=6 : Refresh links expiring within this many hours (already-expired links included)}
        {--limit=2000 : At most this many creatives per run}';

    protected $description = 'Re-fetch, by creative id, the media of creatives whose platform links have expired or are about to.';

    public function handle(AdvertisingConnectorRegistry $registry, AccountAssignment $assignment, ImportExternalStructure $import): int
    {
        $horizon = Carbon::now()->addHours(max(0, (int) $this->option('within')));
        $limit = max(1, (int) $this->option('limit'));

        $rows = DB::table('external_creatives AS c')
            ->join('external_campaigns AS e', 'e.id', '=', 'c.external_campaign_id')
            ->whereNotNull('c.asset_expires_at')
            ->where('c.asset_expires_at', '<=', $horizon)
            ->where('c.is_demo', false)
            ->orderBy('c.asset_expires_at')
            ->limit($limit)
            ->get(['c.id', 'c.external_creative_id', 'e.external_account_id']);

        $refreshed = 0;

        foreach ($rows->groupBy('external_account_id') as $accountId => $creatives) {
            $account = ExternalAccount::withoutGlobalScopes()->find($accountId);

            if ($account === null || ! $assignment->isActivelyAssigned($account)) {
                continue;
            }

            $connector = $registry->get($account->provider);

            if (! $connector instanceof RefreshesCreativeMedia || ! $connector instanceof ApiAdvertisingConnector) {
                continue;
            }

            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                continue;
            }

            $connector = $connector->withConnection($connection);

            if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
                continue;
            }

            $run = new IntegrationSyncRun;
            $run->forceFill([
                'tenant_id' => $account->tenant_id,
                'project_id' => $assignment->projectIdFor($account),
                'provider_connection_id' => $account->provider_connection_id,
                'type' => 'media_refresh',
                'status' => SyncRunStatus::Running->value,
                'started_at' => Carbon::now(),
            ])->save();

            try {
                $fresh = $connector->refreshCreativeMedia(
                    (string) $account->external_id,
                    $creatives->pluck('external_creative_id')->map(static fn (mixed $v): string => (string) $v)->all(),
                );
            } catch (Throwable $e) {
                $run->forceFill([
                    'status' => SyncRunStatus::Failed->value,
                    'error' => ProviderErrorText::forStorage($e->getMessage()),
                    'finished_at' => Carbon::now(),
                ])->save();
                $this->line(sprintf('  %s account %s: refused — %d creative(s) stay as they are', $account->provider, $account->getKey(), $creatives->count()));

                continue;
            }

            $written = 0;
            foreach ($creatives as $c) {
                $creative = $fresh[(string) $c->external_creative_id] ?? null;
                $row = is_array($creative) ? ExternalCreative::withoutGlobalScopes()->find($c->id) : null;

                if ($row !== null && $import->refreshMedia($row, $creative)) {
                    $written++;
                }
            }

            $run->forceFill([
                'status' => SyncRunStatus::Success->value,
                'records' => $written,
                'finished_at' => Carbon::now(),
            ])->save();

            $refreshed += $written;
            $this->line(sprintf('  %s account %s: %d of %d creative(s) refreshed', $account->provider, $account->getKey(), $written, $creatives->count()));
        }

        $this->info(sprintf('Refreshed the media of %d creative(s) (%d selected).', $refreshed, $rows->count()));
        app(ScheduledRunRows::class)->report($refreshed);

        return self::SUCCESS;
    }
}
