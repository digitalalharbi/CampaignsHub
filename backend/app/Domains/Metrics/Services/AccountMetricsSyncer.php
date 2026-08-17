<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * SYNC-001 — the pipeline that turns a connector's insights into normalized daily metrics, and records
 * an auditable run for every attempt.
 *
 * The honesty contract this class enforces:
 *  - A connector without real credentials is NOT run and NOT marked failed. The run is recorded with
 *    status `awaiting_credentials` and a message saying exactly that, so an operator can tell "we never
 *    tried" apart from "we tried and it broke".
 *  - Every outcome — success, partial, failure — leaves a MetricSyncRun row with its window, counts and
 *    error text. A sync that produced nothing says so instead of silently looking healthy.
 *  - Metrics go through the SAME UpsertDailyMetrics action the rest of the system uses, so a synced
 *    figure and a seeded figure are indistinguishable downstream and the upsert stays idempotent.
 */
final class AccountMetricsSyncer
{
    /** `integrations:sync` runs every thirty minutes (routes/console.php) — this is that, named. */
    private const SWEEP_INTERVAL_MINUTES = 30;

    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly UpsertDailyMetrics $upsert,
        private readonly InsightRowNormaliser $normaliser,
        private readonly AccountAssignment $assignment,
    ) {}

    /**
     * Sync one ad account's insights for a window. Always returns the recorded run.
     *
     * @param  array<string,mixed>  $meta  extra context stored on the run (e.g. ['demo' => true])
     */
    public function sync(ExternalAccount $account, Carbon $from, Carbon $to, array $meta = []): MetricSyncRun
    {
        $connector = $this->registry->get($account->provider);

        $run = new MetricSyncRun;
        $run->forceFill([
            'tenant_id' => $account->tenant_id,
            'project_id' => $this->projectIdFor($account),
            'connection_id' => $account->provider_connection_id,
            'external_account_id' => $account->id,
            'provider' => $account->provider,
            'status' => 'running',
            'window_start' => $from->toDateString(),
            'window_end' => $to->toDateString(),
            'attempts' => 1,
            'started_at' => Carbon::now(),
            'meta' => $meta,
        ])->save();

        /*
         * RUNTIME-100 §15 — an unassigned account is not synced, and its refusal is its own status.
         *
         * Not `failed`: nothing broke. Somebody has authorised us to SEE this account and has not
         * said which project it feeds, and the operator's next move — choose a project — is entirely
         * different from the next move for a provider error. `AccountStructureSyncer` reports the
         * same state under the same name.
         */
        if ($run->project_id === null) {
            return $this->finish(
                $run,
                'awaiting_assignment',
                0,
                'This account is not assigned to a project yet, so nothing was fetched. Assign it to a project first.',
                $account,
            );
        }

        if ($connector === null) {
            return $this->finish($run, 'failed', 0, "No connector is registered for provider '{$account->provider}'.", $account);
        }

        /*
         * Bind the account's own connection before asking the connector anything (INTEG-OAUTH-001).
         *
         * The registry hands out ONE instance per platform for the whole process, so an unbound
         * connector has no tokens and — worse — a connector bound in place would carry one tenant's
         * connection into the next tenant's job. `withConnection()` returns a clone for exactly that
         * reason, and this is the only place in the sync path that binds one.
         */
        if ($connector instanceof ApiAdvertisingConnector) {
            $connection = ProviderConnection::withoutGlobalScopes()->find($account->provider_connection_id);

            if ($connection === null) {
                return $this->finish($run, 'failed', 0, 'The ad account has no provider connection to sync through.', $account);
            }

            $connector = $connector->withConnection($connection);
        }

        // Never pretend to call a platform we have no credentials for.
        if ($connector->status() === ConnectorStatus::AwaitingCredentials) {
            return $this->finish(
                $run,
                'awaiting_credentials',
                0,
                'No credentials for '.$connector->label().' — nothing was fetched. Add credentials to enable this sync.',
                $account,
            );
        }

        try {
            $result = $connector->syncInsights($account->external_id, $from->toDateString(), $to->toDateString());
        } catch (Throwable $e) {
            return $this->finish($run, 'failed', 0, $e->getMessage(), $account);
        }

        if (! $result->success) {
            return $this->finish($run, 'failed', 0, $result->message ?? 'The provider reported a failed sync.', $account);
        }

        [$upserted, $skipped] = $this->ingest($account, $result->records);

        /*
         * Keep what the platform said, beside what we made of it (INTEG-RAW-001).
         *
         * Written after the ingest so `normalised_rows` can record how many metrics came OUT of this
         * payload — a body with four hundred rows that produced zero metrics is the signature of a
         * mapping bug, and that comparison is invisible from either half alone.
         */
        if ($connector instanceof ApiAdvertisingConnector) {
            $this->retainRaw($account, $run, $connector->takeRawResponses(), $from, $to, $upserted);
        }

        // "Partial" is a real outcome: the provider answered, but some rows could not be mapped.
        $status = $skipped > 0 ? 'partial' : 'success';
        $message = $skipped > 0
            ? "{$skipped} record(s) could not be mapped to a known campaign and were skipped."
            : null;

        return $this->finish(
            $run,
            $upserted === 0 && $skipped === 0 ? 'partial' : $status,
            $upserted,
            $upserted === 0 && $skipped === 0 ? 'The provider returned no insight rows for this window.' : $message,
            $account,
        );
    }

    /**
     * Every metric a connector may report and this pipeline will carry (PIPELINE-METRICS-001).
     *
     * Read from `MetricsAggregator::readKeys()` rather than written out here, and that is the whole
     * point. This used to be a literal list of seven — `spend, impressions, clicks, conversions,
     * revenue, reach, video_views` — while the aggregator read eighteen. So a connector could map
     * `add_to_cart` perfectly, from the platform's own correct field, and the figure was DROPPED on
     * this line before it ever reached storage. Nothing failed, nothing was logged: the funnel simply
     * had no add-to-cart stage, on every platform, forever.
     *
     * Two lists describing one thing is how that happens, so there is now one. A key the engine reads
     * is a key the syncer carries, by construction.
     *
     * DERIVED metrics are deliberately absent and must stay absent. `readKeys()` returns only the
     * additive keys — the ones `metric_definitions.is_additive` marks true — because `frequency`,
     * `roas`, `ctr`, `cpa` and the rest are computed FROM sums and are meaningless when summed
     * themselves. A stored daily `frequency` added across thirty days is not a frequency; it is a
     * number with no referent. They are computed at read time, and a zero denominator yields null
     * rather than 0.
     *
     * @return list<string>
     */
    private static function carriedKeys(): array
    {
        return MetricsAggregator::readKeys();
    }

    /**
     * Map provider insight rows onto normalized metrics.
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array{0:int,1:int} [upserted metric rows, skipped records]
     */
    private function ingest(ExternalAccount $account, array $records): array
    {
        // Mapping lives in its own class (FX-001): this method is pipeline plumbing, and what a row
        // MEANS — including which currency its money is in — is a separate question with its own tests.
        [$metrics, $skipped] = $this->normaliser->normalise($account, $records, self::carriedKeys());

        if ($metrics !== []) {
            $this->upsert->handle($metrics);
        }

        return [count($metrics), $skipped];
    }

    /**
     * Store the platform's own bodies for this run.
     *
     * One row per response — not one concatenated blob — because a window that needed three paginated
     * calls is three answers, and a dispute about one of them should not require unpicking the others.
     *
     * @param  list<array<string,mixed>>  $payloads
     */
    private function retainRaw(
        ExternalAccount $account,
        MetricSyncRun $run,
        array $payloads,
        Carbon $from,
        Carbon $to,
        int $normalisedRows,
    ): void {
        foreach ($payloads as $index => $payload) {
            IntegrationRawPayload::withoutGlobalScopes()->create([
                'tenant_id' => $account->tenant_id,
                'external_account_id' => $account->id,
                'sync_run_id' => $run->getKey(),
                'provider' => $account->provider,
                'resource' => 'insights',
                'window_start' => $from->toDateString(),
                'window_end' => $to->toDateString(),
                'payload' => $payload,
                // The count belongs to the run, so it is attributed to the first payload rather than
                // divided by a guess about which call produced which row.
                'normalised_rows' => $index === 0 ? $normalisedRows : 0,
                'fetched_at' => Carbon::now(),
            ]);
        }
    }

    private function finish(MetricSyncRun $run, string $status, int $upserted, ?string $error, ?ExternalAccount $account = null): MetricSyncRun
    {
        $run->forceFill([
            'status' => $status,
            'metrics_upserted' => $upserted,
            'finished_at' => Carbon::now(),
            'error' => $error,
        ])->save();

        if ($account !== null) {
            $this->checkpoint($account, $status);
        }

        return $run;
    }

    /**
     * Which project this run belongs to — METRICS-RUN-PROJECT-001.
     *
     * ## The half of ASSIGN-PROJECT-001 that survived its own fix
     *
     * `AccountStructureSyncer` had exactly this method and exactly this bug, and it was fixed. This
     * copy was not, and it ended:
     *
     * ```php
     * return Project::withoutGlobalScopes()->where('tenant_id', …)->orderBy('created_at')->value('id');
     * ```
     *
     * `MetricSyncRun` carries `BelongsToProject`, and `SyncRunController` lists runs project-scoped.
     * So a metrics run for an account assigned to client B — doing exactly what it was told — was
     * filed under client A's oldest project for as long as it had no campaigns yet, which is
     * precisely the window a FIRST sync runs in. Client A's operator then read sync history naming
     * client B's provider, account and row counts, and `DataFreshnessService` computed A's freshness
     * from B's runs.
     *
     * Two copies of one rule, one of them corrected. That is what let it survive a review of the fix,
     * and is why the answer now comes from `AccountAssignment` — the single place that knows.
     *
     * There is no second answer, deliberately. An «existing campaign wins» fallback reads as harmless
     * — it only keeps work where it already is — but it is a second route by which data can enter a
     * project nobody assigned it to, and it can only fire for an account the worker has already
     * refused. One rule, one source, and nothing that was filed before is moved or deleted.
     */
    private function projectIdFor(ExternalAccount $account): ?string
    {
        return $this->assignment->projectIdFor($account);
    }

    /**
     * RUNTIME-100 §30 — write the account's checkpoint from the outcome, once, in one place.
     *
     * `last_synced_at` used to be set inside `retainRaw()`, which is a method about keeping the
     * provider's raw bodies. Two consequences, both wrong and both quiet: a connector that is not an
     * `ApiAdvertisingConnector` synced successfully and the account still read «never synced»; and
     * the stamp was written BEFORE `finish()` decided the status, so a run that ended «the provider
     * returned no insight rows» had already claimed a sync.
     *
     * A checkpoint belongs to the outcome, so it is written where the outcome is decided.
     */
    private function checkpoint(ExternalAccount $account, string $status): void
    {
        $succeeded = in_array($status, ['success', 'partial'], true);

        $account->forceFill([
            // Every outcome is an attempt, including the refusals. «We tried and it failed» and «we
            // never tried» were the same absent timestamp until this line existed.
            'last_sync_attempt_at' => Carbon::now(),
            'last_synced_at' => $succeeded ? Carbon::now() : $account->last_synced_at,
            'last_sync_error_category' => $this->errorCategory($status),
            'next_sync_at' => Carbon::now()->addMinutes(self::SWEEP_INTERVAL_MINUTES),
        ])->save();
    }

    /**
     * WHY it did not work, as a category — because the category is what decides who acts.
     *
     * An operator adds credentials; a customer re-authorises; nobody at all acts on a provider having
     * a bad minute. A free-text message cannot be grouped, counted or routed, and every screen that
     * wanted to say «1 حساب يحتاج انتباه» had nothing to count.
     */
    private function errorCategory(string $status): ?string
    {
        return match ($status) {
            'awaiting_credentials' => 'awaiting_credentials',
            'awaiting_assignment' => 'awaiting_assignment',
            'failed' => 'provider_error',
            /*
             * `partial` deliberately carries NO category.
             *
             * It is the status for two different, ordinary things — a window the provider genuinely
             * had no rows for, and a window where some rows could not be mapped — and neither is a
             * fault anybody should be paged about. The run row already says which; marking the
             * account as needing attention for an account with no spend last Tuesday would fill the
             * attention count with noise and train people to ignore it.
             */
            default => null,
        };
    }
}
