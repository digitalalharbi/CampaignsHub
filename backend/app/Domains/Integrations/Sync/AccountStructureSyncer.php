<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Sync;

use App\Domains\Campaigns\Actions\ImportExternalCampaigns;
use App\Domains\Campaigns\Actions\ImportExternalStructure;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Enums\SyncRunStatus;
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
        private readonly AccountAssignment $assignment,
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
            'status' => SyncRunStatus::Running->value,
            'started_at' => Carbon::now(),
        ])->save();

        $connector = $this->registry->get($account->provider);

        if ($connector === null) {
            return $this->finish($run, SyncRunStatus::Failed->value, 0, "No connector is registered for provider '{$account->provider}'.");
        }

        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                return $this->finish($run, SyncRunStatus::Failed->value, 0, 'The ad account has no provider connection to sync through.');
            }

            // A clone per account — the registry hands out one instance per platform for the whole
            // process, and binding the shared one would carry this tenant's tokens into the next job.
            $connector = $connector->withConnection($connection);
        }

        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return $this->finish(
                $run,
                SyncRunStatus::Failed->value,
                0,
                'No credentials for '.$connector->label().' — nothing was fetched.',
            );
        }

        /*
         * PROJECT-INTEGRATION-ASSIGNMENT-001 — an unassigned account is not a failure, and it is
         * certainly not a licence to pick a project.
         *
         * `awaiting_assignment` is its own status because the operator's next move is different from
         * every other outcome here: nothing is broken, nothing needs retrying, somebody needs to say
         * which project this account feeds. Reporting it as `failed` sent them looking for a fault
         * that does not exist.
         */
        if ($projectId === null) {
            return $this->finish(
                $run,
                SyncRunStatus::AwaitingAssignment->value,
                0,
                'This account is not assigned to a project yet. Assign it to a project, then sync.',
            );
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
            $records === 0 && $problems !== [] => SyncRunStatus::Failed->value,
            $problems !== [] => SyncRunStatus::PartialMapping->value,
            // An account with no campaigns yet is not a failed read of it — INTEG-RUNTIME §8.
            $records === 0 => SyncRunStatus::NoData->value,
            default => SyncRunStatus::Success->value,
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
     * Where an account's structure is filed — the project somebody ASSIGNED it to.
     *
     * PROJECT-INTEGRATION-ASSIGNMENT-001. This used to read:
     *
     * ```php
     * Project::withoutGlobalScopes()->where('tenant_id', …)->orderBy('created_at')->value('id')
     * ```
     *
     * — the tenant's OLDEST project, picked because it was created first. A discovered account's
     * campaigns were therefore filed into a project nobody had chosen, and because the next sync
     * found a campaign already there, the arbitrary choice became permanent. With the live Snapchat
     * connection's 309 discovered accounts, one sweep would have put all 309 into one project.
     *
     * The binding table has recorded the deliberate act all along; nothing in this path read it.
     * Now `AccountAssignment` is the single answer, so the sweep, this syncer and the bind endpoint
     * cannot disagree about what «assigned» means.
     *
     * ## And there is no second answer
     *
     * This kept an «existing campaign wins» fallback, on the reasoning that a detached account should
     * not orphan campaigns already on somebody's surfaces. That reasoning was about DISPLAY and it was
     * being applied to WRITES: a detached account is one the customer has told us to stop reading, and
     * the worker refuses it before this method is ever reached. So the fallback could only fire in a
     * path that no longer exists — while leaving a second route by which data could enter a project
     * nobody had assigned it to.
     *
     * One rule, one source. Campaigns already filed are not moved or deleted; they simply stop
     * receiving new writes, which is exactly what detaching means.
     */
    private function projectIdFor(ExternalAccount $account): ?string
    {
        return $this->assignment->projectIdFor($account);
    }
}
