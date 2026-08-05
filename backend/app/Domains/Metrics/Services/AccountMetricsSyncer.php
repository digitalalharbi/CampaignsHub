<?php

declare(strict_types=1);

namespace App\Domains\Metrics\Services;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
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

        // "Partial" is a real outcome: the provider answered, but some rows could not be mapped.
        $status = $skipped > 0 ? 'partial' : 'success';
        $message = $skipped > 0
            ? "{$skipped} record(s) could not be mapped to a known campaign and were skipped."
            : null;

        return $this->finish($run, $upserted === 0 && $skipped === 0 ? 'partial' : $status, $upserted,
            $upserted === 0 && $skipped === 0 ? 'The provider returned no insight rows for this window.' : $message);
    }

    /**
     * Map provider insight rows onto normalized metrics.
     *
     * @param  array<int,array<string,mixed>>  $records
     * @return array{0:int,1:int} [upserted metric rows, skipped records]
     */
    private function ingest(ExternalAccount $account, array $records): array
    {
        $metrics = [];
        $skipped = 0;

        // Provider campaign id → the external campaign row we already know about.
        $known = ExternalCampaign::withoutGlobalScopes()
            ->where('external_account_id', $account->id)
            ->get(['id', 'external_id', 'project_id', 'unified_campaign_id'])
            ->keyBy('external_id');

        foreach ($records as $row) {
            $externalCampaignId = (string) ($row['campaign_id'] ?? '');
            $link = $known->get($externalCampaignId);

            if ($link === null) {
                // An insight for a campaign we have never discovered is not silently dropped — it is
                // counted so the run can report itself as partial.
                $skipped++;

                continue;
            }

            $date = Carbon::parse((string) ($row['date'] ?? Carbon::now()->toDateString()));

            foreach (['spend', 'impressions', 'clicks', 'conversions', 'revenue', 'reach', 'video_views'] as $key) {
                if (! array_key_exists($key, $row)) {
                    continue;
                }
                $metrics[] = new NormalizedMetric(
                    tenantId: $account->tenant_id,
                    projectId: $link->project_id,
                    externalAccountId: $account->id,
                    externalCampaignId: $link->id,
                    provider: $account->provider,
                    metricKey: $key,
                    metricDate: $date,
                    value: (float) $row[$key],
                    unifiedCampaignId: $link->unified_campaign_id,
                );
            }
        }

        if ($metrics !== []) {
            $this->upsert->handle($metrics);
        }

        return [count($metrics), $skipped];
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
