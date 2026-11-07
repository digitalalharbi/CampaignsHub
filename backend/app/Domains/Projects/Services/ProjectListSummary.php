<?php

declare(strict_types=1);

namespace App\Domains\Projects\Services;

use App\Domains\Integrations\Measurement\Ga4GapExplain;
use App\Domains\Projects\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * PROJECT-LIST-SURFACE-001 §10 — the facts a projects list has to carry to be worth opening.
 *
 * ## What the card said before
 *
 * Name, client, status, «الإعداد 40%». Everything an operator actually asks a list of clients — how
 * many ad accounts does this one have, which platforms, when did its data last arrive, does anything
 * need me — required opening the project and looking. A list that cannot answer those is a menu, and
 * the product already had a menu in the rail.
 *
 * ## Why one pass and not one query per card
 *
 * An agency's list is every client they run. Four counts per card across four tables is sixteen round
 * trips at four clients and four hundred at a hundred, and the page that suffers is the one that
 * opens first. Every figure below is therefore ONE grouped query over the whole page, keyed by
 * project, and attached afterwards.
 *
 * ## Freshness is the ACCOUNT's, and only a selected one's
 *
 * «Last synced» is read from the accounts this project has ACTIVELY bound, never from the tenant's
 * inventory: an account a neighbouring project syncs hourly would otherwise make a dormant client
 * look current. That is the account-scope invariant applied to a date, and it is the same rule the
 * figures obey.
 *
 * ## What «needs attention» is allowed to mean
 *
 * Three states, each of which an operator can act on, and nothing else:
 *
 *  - `no_accounts` — nothing is bound, so the project cannot have data. This is the state a project
 *    sits in between being created and being connected, and the one nobody notices for a fortnight.
 *  - `never_synced` — accounts are bound and none has ever reported. The connection exists and the
 *    data does not, which is a different problem with a different fix.
 *  - `stale` — it reported once and has not lately.
 *
 * Deliberately NOT a score, and deliberately not «performance». A list badge that means «something
 * about this client is worse than it was» is a badge people learn to ignore; these three each name a
 * thing to go and do.
 */
final class ProjectListSummary
{
    /** After this long without data, a bound project is stale rather than quiet. */
    private const STALE_AFTER_HOURS = 48;

    /**
     * @param  Collection<int,Project>  $projects
     * @return array<string,array<string,mixed>> keyed by project id
     */
    public function for(Collection $projects): array
    {
        $ids = $projects->map(static fn (Project $p): string => (string) $p->getKey())->all();

        if ($ids === []) {
            return [];
        }

        $bindings = $this->bindings($ids);
        $teams = $this->teams($ids);
        $campaigns = $this->campaigns($ids);
        $ga4 = $this->ga4($projects);

        $out = [];
        foreach ($ids as $id) {
            $b = $bindings[$id] ?? ['accounts' => 0, 'providers' => [], 'last_synced_at' => null];

            $out[$id] = [
                'accounts' => $b['accounts'],
                // The platforms this project actually reads, in a stable order so two loads agree.
                'providers' => $b['providers'],
                'data_last_synced_at' => $b['last_synced_at'],
                'team_members' => $teams[$id] ?? 0,
                /*
                 * PRODUCT-VISUAL-001 §10 — how much work this client actually has.
                 *
                 * Accounts say what it is CONNECTED to; campaigns say what is running in it, and a
                 * project directory that shows the first without the second cannot tell a client
                 * being managed from one that was set up and forgotten. Canonical rows, not a guess
                 * from metrics, so «connected, and nothing running» stays a state the page can show.
                 */
                'campaigns' => $campaigns[$id] ?? 0,
                'attention' => $this->attention($b),
                // GA4-HEALTH-001 — the store measurement's health beside the ad accounts': connected, last read, the gap.
                'ga4' => $ga4[$id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * GA4-HEALTH-001 — per project that selected a GA4 property: `connected` (read lately), `stale` (not read for
     * two days), or `failed` (its latest measurement run failed); when it was last read; and the platforms' claimed
     * conversions against GA4's for the last thirty days as a share (cached a quarter of an hour: it is a match, not a
     * column). The share is withheld (null) rather than guessed, and any failure leaves the chip without it.
     *
     * @param  Collection<int,Project>  $projects
     * @return array<string,array{state:string,last_read_at:?string,gap_share:?float}>
     */
    private function ga4(Collection $projects): array
    {
        $ids = $projects->map(static fn (Project $p): string => (string) $p->getKey())->all();
        $rows = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->whereIn('b.project_id', $ids)->where('b.provider', 'ga4')->where('b.is_active', true)
            ->get(['b.project_id', 'b.tenant_id', 'a.last_synced_at', 'a.provider_connection_id']);
        $out = [];
        foreach ($rows as $r) {
            $failed = DB::table('integration_sync_runs')->where('type', 'measurement')->where('provider_connection_id', $r->provider_connection_id)
                ->orderByDesc('started_at')->value('status') === 'failed';
            $last = $r->last_synced_at === null ? null : Carbon::parse((string) $r->last_synced_at);
            $state = $failed ? 'failed' : ($last === null || $last->lt(Carbon::now()->subHours(self::STALE_AFTER_HOURS)) ? 'stale' : 'connected');
            $out[(string) $r->project_id] = [
                'state' => $state,
                'last_read_at' => $last?->toIso8601String(),
                'gap_share' => $this->gapShare((string) $r->tenant_id, (string) $r->project_id),
            ];
        }

        return $out;
    }

    private function gapShare(string $tenantId, string $projectId): ?float
    {
        $cached = Cache::remember('ga4-gap-share|'.$tenantId.'|'.$projectId.'|'.Carbon::today()->toDateString(), 900, static function () use ($tenantId, $projectId): ?float {
            try {
                $g = app(Ga4GapExplain::class)->build($tenantId, $projectId, Carbon::today()->subDays(30), Carbon::today()->subDay(), internal: false);

                return ($g['state'] ?? null) === 'ready' ? ($g['headline']['gap_share'] ?? null) : null;
            } catch (\Throwable) {
                return null;
            }
        });

        // The Redis cache store keeps a number as its bare text and hands it back as a string on the next read
        // (Laravel leaves numeric values unserialised), so the declared type is restored here, not trusted from the store.
        return is_numeric($cached) ? (float) $cached : null;
    }

    /**
     * Active bindings per project, with the platforms and the newest sync among their accounts.
     *
     * Joined to `external_accounts` rather than read from the binding, because `last_synced_at` is a
     * fact about the ACCOUNT — the binding records a decision, not an arrival.
     *
     * @param  list<string>  $ids
     * @return array<string,array{accounts:int,providers:list<string>,last_synced_at:?string}>
     */
    private function bindings(array $ids): array
    {
        $rows = DB::table('project_integration_bindings as b')
            ->join('external_accounts as a', 'a.id', '=', 'b.external_account_id')
            ->whereIn('b.project_id', $ids)
            ->where('b.is_active', true)
            ->select('b.project_id', 'b.provider', 'a.last_synced_at')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row->project_id;
            $out[$id] ??= ['accounts' => 0, 'providers' => [], 'last_synced_at' => null];
            $out[$id]['accounts']++;

            $provider = (string) $row->provider;
            if (! in_array($provider, $out[$id]['providers'], true)) {
                $out[$id]['providers'][] = $provider;
            }

            if ($row->last_synced_at !== null) {
                $at = Carbon::parse($row->last_synced_at);
                $seen = $out[$id]['last_synced_at'];
                if ($seen === null || $at->greaterThan(Carbon::parse($seen))) {
                    $out[$id]['last_synced_at'] = $at->toIso8601String();
                }
            }
        }

        foreach ($out as $id => $row) {
            sort($out[$id]['providers']);
        }

        return $out;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string,int>
     */
    private function teams(array $ids): array
    {
        return DB::table('project_memberships')
            ->whereIn('project_id', $ids)
            ->where('status', 'active')
            ->groupBy('project_id')
            ->selectRaw('project_id, count(*) as total')
            ->pluck('total', 'project_id')
            ->map(static fn ($n): int => (int) $n)
            ->all();
    }

    /** @param array{accounts:int,providers:list<string>,last_synced_at:?string} $b */
    private function attention(array $b): ?string
    {
        if ($b['accounts'] === 0) {
            return 'no_accounts';
        }
        if ($b['last_synced_at'] === null) {
            return 'never_synced';
        }

        return Carbon::parse($b['last_synced_at'])->lt(now()->subHours(self::STALE_AFTER_HOURS))
            ? 'stale'
            : null;
    }

    /**
     * Campaigns per project, from the canonical rows.
     *
     * Unlinked rows are excluded: a campaign that was moved off this project is not this project's
     * work any more, and counting it would keep a finished engagement looking busy for ever.
     *
     * @param  list<string>  $ids
     * @return array<string,int>
     */
    private function campaigns(array $ids): array
    {
        $rows = DB::table('external_campaigns')
            ->whereIn('project_id', $ids)
            ->whereNull('unlinked_at')
            ->groupBy('project_id')
            ->get(['project_id', DB::raw('count(*) as total')]);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->project_id] = (int) $row->total;
        }

        return $out;
    }
}
