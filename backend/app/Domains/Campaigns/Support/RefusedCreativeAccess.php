<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Support;

use App\Domains\Integrations\Support\ProviderRefusal;
use Illuminate\Support\Facades\DB;

/**
 * The external campaigns whose ad account the platform currently refuses — read once per request.
 *
 * A connection counts as refused when its most recent structure or media-refresh run FAILED with a
 * permission refusal and it has not been re-authorised or rediscovered since (both stamp
 * `last_health_check_at`). The refused set is small — a handful of connections — so the campaigns
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
            ->selectRaw('DISTINCT ON (r.provider_connection_id) r.provider_connection_id, r.status, r.error, r.finished_at, c.last_health_check_at')
            ->orderBy('r.provider_connection_id')
            ->orderByDesc('r.started_at')
            ->get();

        $refused = $latest
            ->filter(static fn (object $run): bool => $run->status === 'failed'
                && ProviderRefusal::isPermission(is_string($run->error) ? $run->error : null)
                && ($run->last_health_check_at === null || $run->finished_at === null || $run->last_health_check_at <= $run->finished_at))
            ->pluck('provider_connection_id')
            ->all();

        $this->campaigns = $refused === [] ? [] : array_fill_keys(
            DB::table('external_campaigns AS e')
                ->join('external_accounts AS a', 'a.id', '=', 'e.external_account_id')
                ->whereIn('a.provider_connection_id', $refused)
                ->pluck('e.id')
                ->map(static fn (mixed $id): string => (string) $id)
                ->all(),
            true,
        );

        return $this->campaigns;
    }
}
