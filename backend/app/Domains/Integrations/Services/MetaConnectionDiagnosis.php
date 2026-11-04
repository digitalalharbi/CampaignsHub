<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\MetaCredentialProfile;
use App\Domains\Integrations\Support\MetaGraphRefusal;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * META-DIAGNOSIS-001 — every Meta connection, its state and the one action it needs, from what the sync recorded.
 *
 * Reads and nothing else: no Graph call, no write, no token. `integrations:meta-probe` is the separate read that
 * asks Meta, and this says what the product already knows, so it can be opened at any time. `state` never says a
 * connection works unless a real sync landed recently: an OAuth grant alone is `never_synced`.
 */
final class MetaConnectionDiagnosis
{
    private const STALE_AFTER_HOURS = 24;

    private const EXPIRING_WITHIN_DAYS = 7;

    /** What this product reads with, and what it reads partner accounts with. A write scope is not asked of anyone here. */
    private const SCOPES_NEEDED = ['ads_read', 'business_management'];

    public function __construct(private readonly AccountHealth $health) {}

    /** @return list<array<string,mixed>> */
    public function connections(): array
    {
        $connections = ProviderConnection::withoutGlobalScopes()->where('provider', 'meta')->orderBy('created_at')->get();

        if ($connections->isEmpty()) {
            return [];
        }

        $tenants = DB::table('tenants')->whereIn('id', $connections->pluck('tenant_id')->unique()->all())->pluck('name', 'id');

        return $connections->map(fn (ProviderConnection $c): array => $this->row($c, (string) ($tenants[$c->tenant_id] ?? '')))->values()->all();
    }

    /** @return array<string,mixed> */
    private function row(ProviderConnection $connection, string $tenantName): array
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connection->getKey())
            ->where('account_type', 'ad_account')
            ->orderBy('external_id')
            ->get();

        $creatives = $this->creativeCounts($accounts);
        $selected = DB::table('project_integration_bindings')
            ->whereIn('external_account_id', $accounts->modelKeys())
            ->where('is_active', true)
            ->distinct()
            ->pluck('external_account_id')
            ->map(strval(...))
            ->flip();

        $rows = $accounts->map(fn (ExternalAccount $a): array => $this->accountRow($a, $selected->has((string) $a->getKey()), $creatives))->values()->all();
        $lastSync = $this->latest([$connection->last_successful_sync_at, ...$accounts->pluck('last_synced_at')->all()]);
        $token = $this->token($connection);

        return [
            'id' => (string) $connection->getKey(),
            'tenant' => $tenantName,
            'name' => (string) $connection->connection_name,
            'app' => MetaCredentialProfile::ofConnection($connection->app_profile ?? null)->value,
            'status' => (string) $connection->status,
            'state' => $this->state($connection, $token['state'], $lastSync),
            'token' => $token,
            'scopes_granted' => is_array($connection->scopes) ? array_values($connection->scopes) : null,
            'scopes_missing' => is_array($connection->scopes) && $connection->scopes !== []
                ? array_values(array_diff(self::SCOPES_NEEDED, $connection->scopes))
                : null,
            'refusal' => $this->refusal($connection->last_error),
            'last_error' => ProviderErrorText::forDisplay($connection->last_error),
            'insights_denied_at' => $connection->insights_denied_at?->toIso8601String(),
            'discovery_blocked_reason' => $connection->discovery_blocked_reason,
            'last_discovery_succeeded_at' => $connection->last_discovery_succeeded_at?->toIso8601String(),
            'last_sync_at' => $lastSync?->toIso8601String(),
            'accounts_total' => $accounts->count(),
            'accounts_selected' => $selected->count(),
            'accounts_synced' => $accounts->filter(static fn (ExternalAccount $a): bool => $a->last_synced_at !== null)->count(),
            'expired_media' => array_sum(array_column($rows, 'expired_media')),
            'refusals_24h' => $this->refusalsLastDay($connection),
            'accounts' => $rows,
        ];
    }

    /**
     * @param  array<string, array{total:int,expired:int}>  $creatives
     * @return array<string,mixed>
     */
    private function accountRow(ExternalAccount $account, bool $selected, array $creatives): array
    {
        $structure = DB::table('integration_sync_runs')
            ->where('provider_connection_id', $account->provider_connection_id)
            ->where('type', 'structure')
            ->whereRaw("meta->>'external_account_id' = ?", [(string) $account->external_id])
            ->orderByDesc('started_at')
            ->first(['status', 'error', 'meta', 'started_at']);

        $metrics = MetricSyncRun::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->orderByDesc('started_at')
            ->first(['status', 'error', 'started_at']);

        $meta = $structure === null ? [] : (array) json_decode((string) $structure->meta, true);

        return [
            'external_id' => (string) $account->external_id,
            'name' => (string) $account->name,
            'status' => (string) $account->status,
            'health' => $this->health->for($account),
            'selected' => $selected,
            'access_lost_at' => $account->access_lost_at?->toIso8601String(),
            'last_metrics_at' => $account->last_synced_at?->toIso8601String(),
            'error_category' => $account->last_sync_error_category,
            'creatives' => $creatives[(string) $account->getKey()]['total'] ?? 0,
            'expired_media' => $creatives[(string) $account->getKey()]['expired'] ?? 0,
            'structure' => [
                'status' => $structure?->status,
                'at' => $structure?->started_at,
                'refusal' => $this->refusal($structure?->error),
                'media' => $meta['media'] ?? null,
                'media_renewal' => $meta['media_renewal'] ?? null,
                'usage' => $meta['usage'] ?? null,
            ],
            'metrics' => [
                'status' => $metrics === null ? null : $metrics->getRawOriginal('status'),
                'at' => $metrics?->started_at?->toIso8601String(),
                'refusal' => $this->refusal($metrics?->error),
            ],
        ];
    }

    /**
     * @param  Collection<int, ExternalAccount>  $accounts
     * @return array<string, array{total:int,expired:int}>
     */
    private function creativeCounts(Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $rows = DB::table('external_creatives AS k')
            ->join('external_campaigns AS e', 'e.id', '=', 'k.external_campaign_id')
            ->whereIn('e.external_account_id', $accounts->modelKeys())
            ->where('k.is_demo', false)
            ->selectRaw('e.external_account_id AS account, count(*) AS total, count(*) FILTER (WHERE k.asset_expires_at <= ?) AS expired', [Carbon::now()])
            ->groupBy('e.external_account_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->account] = ['total' => (int) $row->total, 'expired' => (int) $row->expired];
        }

        return $out;
    }

    /** @return array{state:string,expires_at:?string} */
    private function token(ProviderConnection $connection): array
    {
        $expires = $connection->token_expires_at;

        return [
            'state' => match (true) {
                $expires === null => 'not_stated',
                $expires->isPast() => 'expired',
                $expires->lte(Carbon::now()->addDays(self::EXPIRING_WITHIN_DAYS)) => 'expiring',
                default => 'valid',
            },
            'expires_at' => $expires?->toIso8601String(),
        ];
    }

    private function state(ProviderConnection $connection, string $tokenState, ?Carbon $lastSync): string
    {
        return match (true) {
            $connection->status === 'error' => 'needs_reconnect',
            $connection->status !== 'connected' => 'disconnected',
            $tokenState === 'expired' => 'token_expired',
            $connection->insights_denied_at !== null => 'permission_missing',
            $lastSync === null => 'never_synced',
            $lastSync->lt(Carbon::now()->subHours(self::STALE_AFTER_HOURS)) => 'stale',
            default => 'syncing',
        };
    }

    /** @return array<string,mixed>|null */
    private function refusal(?string $message): ?array
    {
        return MetaGraphRefusal::read($message)?->toArray();
    }

    /** @return array<string,int> Refusals by kind across structure and metrics runs, alphabetically. */
    private function refusalsLastDay(ProviderConnection $connection): array
    {
        $since = Carbon::now()->subDay();
        $errors = DB::table('integration_sync_runs')
            ->where('provider_connection_id', $connection->getKey())
            ->where('started_at', '>=', $since)
            ->whereNotNull('error')
            ->pluck('error')
            ->merge(DB::table('metric_sync_runs')
                ->where('connection_id', $connection->getKey())
                ->where('started_at', '>=', $since)
                ->whereNotNull('error')
                ->pluck('error'));

        $kinds = [];
        foreach ($errors as $error) {
            $kind = MetaGraphRefusal::read((string) $error)?->kind;
            if ($kind !== null) {
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
        }
        ksort($kinds);

        return $kinds;
    }

    /** @param list<mixed> $moments */
    private function latest(array $moments): ?Carbon
    {
        $known = array_filter($moments, static fn (mixed $m): bool => $m instanceof Carbon);

        return $known === [] ? null : collect($known)->max();
    }
}
