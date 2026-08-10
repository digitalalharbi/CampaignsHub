<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Connectors;

use App\Domains\Integrations\Connectors\Contracts\Connector;
use App\Domains\Integrations\Connectors\Enums\ConnectionState;
use App\Domains\Integrations\Connectors\ValueObjects\SyncWindow;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\ValueObjects\SyncResult;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Metrics\Services\ReportingCurrency;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The Connection Center: reports the HONEST {@see ConnectionState} of every connector and triggers
 * syncs by delegating to the connector. It NEVER reports `connected`/`production_verified` without a
 * real successful sync, and real providers without credentials always resolve to
 * {@see ConnectionState::AwaitingCredentials} with a no-op sync.
 *
 * Additive: it reuses the existing ProviderConnection lifecycle fields and records history through the
 * existing {@see MetricSyncRun} + {@see UpsertDailyMetrics} — it rewrites none of them.
 */
final class ConnectionCenterService
{
    public function __construct(
        private readonly ConnectorCapabilityRegistry $registry,
        private readonly UpsertDailyMetrics $upsert,
        private readonly ProjectContext $project,
        private readonly TenantContext $tenant,
        private readonly ReportingCurrency $currency,
    ) {}

    /** @return array<string, Connector> */
    public function connectors(): array
    {
        return $this->registry->all();
    }

    public function connector(string $provider): ?Connector
    {
        return $this->registry->get($provider);
    }

    /** The most recent tenant-scoped connection for a provider, if one exists. */
    public function latestConnection(string $provider): ?ProviderConnection
    {
        return ProviderConnection::where('provider', $provider)->latest()->first();
    }

    /**
     * Derive the honest state from the connector's credential reality plus the connection's
     * status / token_expires_at / last_error. Precedence is "most actionable first".
     */
    public function stateFor(Connector $connector, ?ProviderConnection $connection): ConnectionState
    {
        if ($connection === null) {
            return $connector->hasCredentials()
                ? ConnectionState::Available
                : ConnectionState::AwaitingCredentials;
        }

        if ($connection->token_expires_at !== null && $connection->token_expires_at->isPast()) {
            return ConnectionState::TokenExpired;
        }

        if (filled($connection->last_error)) {
            return Str::contains(Str::lower((string) $connection->last_error), 'permission')
                ? ConnectionState::PermissionMissing
                : ConnectionState::SyncFailed;
        }

        return match ($connection->status) {
            'awaiting_credentials' => ConnectionState::AwaitingCredentials,
            'permission_missing' => ConnectionState::PermissionMissing,
            'error' => ConnectionState::SyncFailed,
            'revoked', 'disconnected' => $connector->hasCredentials()
                ? ConnectionState::Available
                : ConnectionState::AwaitingCredentials,
            'connected' => $this->connectedState($connector, $connection),
            default => $connector->hasCredentials()
                ? ConnectionState::Available
                : ConnectionState::AwaitingCredentials,
        };
    }

    /** A "connected" row is only ever Sandbox- or Production-verified — and Production needs a real sync. */
    private function connectedState(Connector $connector, ProviderConnection $connection): ConnectionState
    {
        if ($connector->isSandbox()) {
            return ConnectionState::SandboxVerified;
        }

        if ($connector->hasCredentials() && $connection->last_successful_sync_at !== null) {
            return ConnectionState::ProductionVerified;
        }

        // Real connector without real credentials must never masquerade as production.
        return $connector->hasCredentials()
            ? ConnectionState::Available
            : ConnectionState::AwaitingCredentials;
    }

    /** @return array<string, mixed> The connector + its honest state, for the read API. */
    public function describe(Connector $connector, ?ProviderConnection $connection): array
    {
        $state = $this->stateFor($connector, $connection);

        return [
            'provider' => $connector->provider(),
            'label' => $connector->label(),
            'capabilities' => $connector->capabilities(),
            'is_sandbox' => $connector->isSandbox(),
            'has_credentials' => $connector->hasCredentials(),
            'awaiting_external_dependency' => ! $connector->isSandbox() && ! $connector->hasCredentials(),
            'state' => $state->value,
            'state_label' => $state->label(),
            'is_healthy' => $state->isHealthy(),
            'connection' => $connection === null ? null : [
                'id' => $connection->id,
                'status' => $connection->status,
                'token_expires_at' => optional($connection->token_expires_at)->toIso8601String(),
                'last_successful_sync_at' => optional($connection->last_successful_sync_at)->toIso8601String(),
                'last_error' => $connection->last_error,
            ],
        ];
    }

    /**
     * Trigger a sync by delegating to the connector, recording a {@see MetricSyncRun} for history and
     * (for the Sandbox) upserting the returned demo metrics through the existing pipeline. Real
     * providers without credentials produce a failed no-op run and stay in their awaiting state.
     *
     * @return array{run: MetricSyncRun, result: SyncResult, state: ConnectionState}
     */
    public function sync(Connector $connector, ?ProviderConnection $connection, SyncWindow $window): array
    {
        $run = MetricSyncRun::create([
            'connection_id' => $connection?->id,
            'provider' => $connector->provider(),
            'status' => 'running',
            'window_start' => $window->from->toDateString(),
            'window_end' => $window->to->toDateString(),
            'attempts' => 1,
            'started_at' => now(),
        ]);

        $result = $connector->syncMetrics($connection, $window);

        $upserted = 0;
        if ($result->success && $connector->isSandbox()) {
            $upserted = $this->persistSandboxMetrics($connector, $connection, (string) $run->id, $result->records, $window);
        }

        $run->update([
            'status' => $result->success ? 'success' : 'failed',
            'metrics_upserted' => $upserted,
            'finished_at' => now(),
            'error' => $result->success ? null : $result->message,
            'meta' => ['records' => $result->count, 'sandbox' => $connector->isSandbox()],
        ]);

        if ($result->success && $connection !== null) {
            $connection->update(['last_successful_sync_at' => now(), 'last_error' => null]);
        }

        return [
            'run' => $run,
            'result' => $result,
            'state' => $this->stateFor($connector, $connection?->fresh()),
        ];
    }

    /**
     * Turn the Sandbox's demo insight records into normalized daily metrics and upsert them through the
     * existing idempotent action — a genuine (offline, clearly-labelled) sync into daily_metrics.
     *
     * @param  array<int,array<string,mixed>>  $records
     */
    private function persistSandboxMetrics(Connector $connector, ?ProviderConnection $connection, string $runId, array $records, SyncWindow $window): int
    {
        $connectionId = $connection !== null ? (string) $connection->id : null;
        $tenantId = $connectionId !== null ? (string) $connection->tenant_id : $this->tenant->tenantId();
        $projectId = $this->project->projectId();

        // Without a tenant + active project we cannot write project-scoped rows; skip rather than guess.
        if ($tenantId === null || $projectId === null) {
            return 0;
        }

        $accountId = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'cc-acct:'.$connector->provider().':'.($connectionId ?? 'sandbox'));
        $now = Carbon::now();
        $metrics = [];

        foreach ($records as $record) {
            $date = Carbon::parse((string) ($record['date'] ?? $window->from->toDateString()));
            $campaignId = (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, 'cc-camp:'.(string) ($record['campaign_id'] ?? 'sbx'));

            foreach (['spend', 'impressions', 'clicks'] as $key) {
                if (! array_key_exists($key, $record)) {
                    continue;
                }

                /*
                 * FX-001 — a sandbox row is labelled with its currency like any other.
                 *
                 * These figures are already in the project's reporting currency, so the conversion is
                 * an identity. Writing it anyway is the point: a monetary row with NO currency columns
                 * is indistinguishable from one whose conversion was WITHHELD, and the coverage count
                 * that warns an operator about withheld figures would report demo data as a problem.
                 */
                $reporting = $this->currency->forProject($projectId);
                $money = $this->currency->isMonetary($key)
                    ? $this->currency->normalise((float) $record[$key], $reporting, $reporting, $date)
                    : null;

                $metrics[] = new NormalizedMetric(
                    tenantId: $tenantId,
                    projectId: $projectId,
                    externalAccountId: $accountId,
                    externalCampaignId: $campaignId,
                    provider: $connector->provider(),
                    metricKey: $key,
                    metricDate: $date,
                    value: $money === null ? (float) $record[$key] : $money['value'],
                    connectionId: $connectionId,
                    originalCurrency: $money['original_currency'] ?? null,
                    projectCurrency: $money['project_currency'] ?? null,
                    originalAmount: $money['original_amount'] ?? null,
                    convertedAmount: $money['converted_amount'] ?? null,
                    exchangeRate: $money['exchange_rate'] ?? null,
                    rateDate: $money['rate_date'] ?? null,
                    rateSource: $money['rate_source'] ?? null,
                    sourceType: 'api',
                    dataFreshnessAt: $now,
                    syncRunId: $runId,
                    raw: $record,
                    isDemo: true,
                );
            }
        }

        return $this->upsert->handle($metrics);
    }
}
