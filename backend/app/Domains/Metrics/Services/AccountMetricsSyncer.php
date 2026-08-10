<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationRawPayload;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
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
    public function __construct(
        private readonly AdvertisingConnectorRegistry $registry,
        private readonly UpsertDailyMetrics $upsert,
        private readonly InsightRowNormaliser $normaliser,
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

        if ($connector === null) {
            return $this->finish($run, 'failed', 0, "No connector is registered for provider '{$account->provider}'.");
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
                return $this->finish($run, 'failed', 0, 'The ad account has no provider connection to sync through.');
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
            );
        }

        try {
            $result = $connector->syncInsights($account->external_id, $from->toDateString(), $to->toDateString());
        } catch (Throwable $e) {
            return $this->finish($run, 'failed', 0, $e->getMessage());
        }

        if (! $result->success) {
            return $this->finish($run, 'failed', 0, $result->message ?? 'The provider reported a failed sync.');
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

        return $this->finish($run, $upserted === 0 && $skipped === 0 ? 'partial' : $status, $upserted,
            $upserted === 0 && $skipped === 0 ? 'The provider returned no insight rows for this window.' : $message);
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

        $account->forceFill(['last_synced_at' => Carbon::now()])->save();
    }

    private function finish(MetricSyncRun $run, string $status, int $upserted, ?string $error): MetricSyncRun
    {
        $run->forceFill([
            'status' => $status,
            'metrics_upserted' => $upserted,
            'finished_at' => Carbon::now(),
            'error' => $error,
        ])->save();

        return $run;
    }

    /**
     * An account can feed several projects; the run is stamped with the first project it actually feeds.
     * An account that feeds nothing yet still needs a project to file the run under (the column is NOT
     * NULL and a run with no home would be unreadable), so it falls back to its client workspace and
     * finally to the tenant's first project.
     */
    private function projectIdFor(ExternalAccount $account): ?string
    {
        $fromCampaigns = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)
            ->value('project_id');

        if ($fromCampaigns !== null) {
            return $fromCampaigns;
        }

        return Project::withoutGlobalScopes()
            ->where('tenant_id', $account->tenant_id)
            ->when($account->client_workspace_id, fn ($q, $ws) => $q->orderByRaw('CASE WHEN client_workspace_id = ? THEN 0 ELSE 1 END', [$ws]))
            ->orderBy('created_at')
            ->value('id');
    }
}
