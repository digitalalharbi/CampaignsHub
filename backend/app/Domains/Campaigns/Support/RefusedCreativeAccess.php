<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Integrations\Support\ProviderRefusal;
use Illuminate\Support\Facades\DB;

/**
 * The external campaigns whose ad account the platform currently refuses — read once per request.
 *
 * An ad account counts as refused when its most recent structure run FAILED with a permission refusal and its
 * connection has not been re-authorised or rediscovered since (both stamp `last_health_check_at`). One
 * authorisation holds many accounts with different answers, so the verdict is per account, read from
 * `meta.external_account_id`; a run older than that key speaks for its connection until a newer run names an
 * account. A connection in `error` refuses every account under it. The refused set is small, so the campaigns
 * under them are loaded once and every creative on a page is answered from memory, never per card.
 */
final class RefusedCreativeAccess
{
    /** @var array<string, true>|null */
    private ?array $campaigns = null;

    public function refuses(?string $externalCampaignId): bool
    {
        if ($externalCampaignId === null) {
            return false;
        }

        return isset($this->load()[$externalCampaignId]);
    }

    /** @return array<string, true> */
    private function load(): array
    {
        if ($this->campaigns !== null) {
            return $this->campaigns;
        }

        $latest = DB::table('integration_sync_runs AS r')
            ->join('provider_connections AS c', 'c.id', '=', 'r.provider_connection_id')
            ->whereIn('r.type', ['structure', 'media_refresh'])
            ->selectRaw("DISTINCT ON (r.provider_connection_id, (r.meta->>'external_account_id')) r.provider_connection_id, r.meta->>'external_account_id' AS account, r.status, r.error, r.started_at, r.finished_at, c.last_health_check_at")
            ->orderBy('r.provider_connection_id')
            ->orderByRaw("(r.meta->>'external_account_id')")
            ->orderByDesc('r.started_at')
            ->get();

        // Connections Meta has stopped honouring: the sweep no longer asks, so no later run can say otherwise.
        $wholeConnection = DB::table('provider_connections')->where('status', 'error')->pluck('id')->map(strval(...))->all();
        $byAccount = [];

        foreach ($latest as $run) {
            $refused = $run->status === 'failed'
                && ProviderRefusal::isPermission(is_string($run->error) ? $run->error : null)
                && ($run->last_health_check_at === null || $run->finished_at === null || $run->last_health_check_at <= $run->finished_at);

            if (! $refused) {
                continue;
            }

            if ($run->account !== null) {
                $byAccount[(string) $run->provider_connection_id][] = (string) $run->account;

                continue;
            }

            // A run older than the column names no account. A later run that does name one replaces its verdict.
            $superseded = $latest->contains(static fn (object $other): bool => $other->provider_connection_id === $run->provider_connection_id
                && $other->account !== null && $other->started_at > $run->started_at);

            if (! $superseded) {
                $wholeConnection[] = (string) $run->provider_connection_id;
            }
        }

        if ($wholeConnection === [] && $byAccount === []) {
            return $this->campaigns = [];
        }

        $this->campaigns = array_fill_keys(
            DB::table('external_campaigns AS e')
                ->join('external_accounts AS a', 'a.id', '=', 'e.external_account_id')
                ->where(static function ($q) use ($wholeConnection, $byAccount): void {
                    $q->whereIn('a.provider_connection_id', $wholeConnection);

                    foreach ($byAccount as $connection => $accounts) {
                        $q->orWhere(static fn ($one) => $one->where('a.provider_connection_id', $connection)->whereIn('a.external_id', $accounts));
                    }
                })
                ->pluck('e.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
            true,
        );

        return $this->campaigns;
    }
}
