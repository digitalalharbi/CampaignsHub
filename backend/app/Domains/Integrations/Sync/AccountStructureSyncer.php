<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Sync;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * STRUCT-001 — campaigns → ad sets → ads → creatives, for one ad account.
 *
 * The metrics pipeline has always been able to fetch numbers; this is what gives those numbers
 * somewhere to hang. `AccountMetricsSyncer` skips any insight row for a campaign it has never
 * discovered and reports the run as `partial` — so until something discovers structure on a schedule,
 * a brand-new campaign's spend arrives and is thrown away, and the run says «partial» in a log nobody
 * reads. Structure runs BEFORE metrics in the sweep for exactly that reason.
 *
 * ## The four steps are not all-or-nothing
 *
 * Campaigns can succeed while ad sets fail on a rate limit. Each step records what it managed, and the
 * run is `partial` rather than `failed` when some of it landed — a run that reported total failure
 * because the last of four calls was throttled would send somebody looking for a broken connection
 * that is working.
 *
 * ## Nothing here is deleted
 *
 * A platform that stops returning an ad set has usually archived it, and the row carries its history —
 * its ads, its creatives, everything the reports point at. So rows are upserted and never removed;
 * `last_synced_at` simply stops moving, which is a fact, and deletion would not be one.
 */
final class AccountStructureSyncer
{
    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly ImportExternalCampaigns $importCampaigns,
        private readonly ImportExternalStructure $importStructure,
    ) {}

    /** @param array<string,mixed> $meta */
    public function sync(ExternalAccount $account, array $meta = []): IntegrationSyncRun
    {
        $projectId = $this->projectIdFor($account);

        $run = new IntegrationSyncRun;
        $run->forceFill([
            'tenant_id' => $account->tenant_id,
            'project_id' => $projectId,
            'provider_connection_id' => $account->provider_connection_id,
            'type' => 'structure',
            'status' => 'running',
            'started_at' => Carbon::now(),
        ])->save();

        $connector = $this->registry->get($account->provider);

        if ($connector === null) {
            return $this->finish($run, 'failed', 0, "No connector is registered for provider '{$account->provider}'.");
        }

        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                return $this->finish($run, 'failed', 0, 'The ad account has no provider connection to sync through.');
            }

            // A clone per account — the registry hands out one instance per platform for the whole
            // process, and binding the shared one would carry this tenant's tokens into the next job.
            $connector = $connector->withConnection($connection);
        }

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return $this->finish(
                $run,
                'awaiting_credentials',
                0,
                'No credentials for '.$connector->label().' — nothing was fetched.',
            );
        }

        if ($projectId === null) {
            return $this->finish($run, 'failed', 0, 'This workspace has no project to file the discovered structure under.');
        }

        $problems = [];
        $accountId = $account->external_id;

        // ── 1. Campaigns ──────────────────────────────────────────────────────────────────────
        $campaigns = $this->attempt(fn () => $connector->syncCampaigns($accountId), $problems, 'campaigns');
        $importedCampaigns = $campaigns === null ? 0 : $this->importCampaigns->execute($account, $campaigns, $projectId);

        // ── 2 & 3. Ad sets and ads, together, because an ad is placed by its ad set ────────────
        $adSets = $this->attempt(fn () => $connector->syncAdSets($accountId), $problems, 'ad sets');
        $ads = $this->attempt(fn () => $connector->syncAds($accountId), $problems, 'ads');

        $counts = $this->importStructure->execute(
            $account,
            $adSets?->records ?? [],
            $ads?->records ?? [],
        );

        if ($connector instanceof ApiAdvertisingConnector) {
            $this->retainRaw($account, $run, $connector->takeRawResponses(), $importedCampaigns + $counts['ad_sets'] + $counts['ads']);
        }

        $account->forceFill(['last_structure_synced_at' => Carbon::now()])->save();

        $records = $importedCampaigns + $counts['ad_sets'] + $counts['ads'] + $counts['creatives'];

        if ($counts['skipped'] > 0) {
            $problems[] = "{$counts['skipped']} row(s) named a parent that has not been discovered yet and were skipped.";
        }

        $status = match (true) {
            // Nothing landed and every call complained: this is a failure, not a quiet account.
            $records === 0 && $problems !== [] => 'failed',
            $problems !== [] => 'partial',
            default => 'success',
        };

        return $this->finish($run, $status, $records, $problems === [] ? null : implode(' ', $problems));
    }

    /**
     * Run one step, and let the other three carry on if it fails.
     *
     * @param  callable():SyncResult  $step
     * @param  list<string>  $problems
     */
    private function attempt(callable $step, array &$problems, string $what): ?SyncResult
    {
        try {
            $result = $step();
        } catch (Throwable $e) {
            $problems[] = ucfirst($what).': '.$e->getMessage();

            return null;
        }

        if (! $result->success) {
            $problems[] = ucfirst($what).': '.($result->message ?? 'the provider reported a failure.');

            return null;
        }

        return $result;
    }

    /**
     * Keep the platform's own bodies for this run (INTEG-RAW-001).
     *
     * The window columns stay null: structure is a statement about NOW, not about a date range, and a
     * fabricated window would make a re-derivation believe it was replaying a period.
     *
     * @param  list<array<string,mixed>>  $payloads
     */
    private function retainRaw(ExternalAccount $account, IntegrationSyncRun $run, array $payloads, int $rows): void
    {
        foreach ($payloads as $index => $payload) {
            IntegrationRawPayload::withoutGlobalScopes()->create([
                'tenant_id' => $account->tenant_id,
                'external_account_id' => $account->getKey(),
                'sync_run_id' => $run->getKey(),
                'provider' => $account->provider,
                'resource' => 'structure',
                'payload' => $payload,
                'normalised_rows' => $index === 0 ? $rows : 0,
                'fetched_at' => Carbon::now(),
            ]);
        }
    }

    private function finish(IntegrationSyncRun $run, string $status, int $records, ?string $error): IntegrationSyncRun
    {
        $run->forceFill([
            'status' => $status,
            'records' => $records,
            // The column is a plain string; a long chain of per-step complaints is trimmed rather than
            // allowed to fail the write that is recording the failure.
            'error' => $error === null ? null : mb_substr($error, 0, 250),
            'finished_at' => Carbon::now(),
        ])->save();

        return $run;
    }

    /**
     * Where an account's structure is filed.
     *
     * The project its campaigns already live in, so a re-sync never re-files anything. Only a first
     * discovery falls back to the workspace's own project, and an account with nowhere to file is
     * refused rather than filed somewhere arbitrary.
     */
    private function projectIdFor(ExternalAccount $account): ?string
    {
        $existing = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->value('project_id');

        if ($existing !== null) {
            return $existing;
        }

        return Project::withoutGlobalScopes()
            ->where('tenant_id', $account->tenant_id)
            ->when($account->client_workspace_id, fn ($q, $ws) => $q->orderByRaw('CASE WHEN client_workspace_id = ? THEN 0 ELSE 1 END', [$ws]))
            ->orderBy('created_at')
            ->value('id');
    }
}
