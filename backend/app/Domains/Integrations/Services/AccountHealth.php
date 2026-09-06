<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;

/**
 * RUNTIME-100 §31 — how an account is doing, said as one word that names an action.
 *
 * ## Why «متصل» was not enough, and why a green tick is worse
 *
 * The integrations page had one badge per PROVIDER. Ten accounts behind one Snapchat authorisation,
 * nine syncing and one whose access was withdrawn, rendered as a single green «متصل» — and the one
 * account that had stopped is the only fact on that card anybody needed. A summary that averages
 * away its own exceptions is not a summary, it is a way of not mentioning them.
 *
 * ## The states, and the order they are asked in
 *
 * Precedence is «most actionable first», and each rank is a different PERSON acting:
 *
 *  1. `NOT_CONNECTED` — discovered inventory nobody assigned. Not a fault; not in the pipeline.
 *  2. `REVOKED` — the connection is gone. Nothing about this account can be fixed at account level.
 *  3. `ACCESS_LOST` — the connection is fine and this one account stopped coming back. The customer
 *     changed a permission at the provider; retrying for ever will not fix it.
 *  4. `FAILED` — the last attempt did not work, and we know why.
 *  5. `PENDING_FIRST_SYNC` — assigned, nothing has arrived yet. A state, not a fault.
 *  6. `DELAYED` — the last success is older than the schedule should allow, and NOTHING errored.
 *     Deliberately distinct from `FAILED`: reporting a late sweep as an error sends somebody to
 *     reconnect an authorisation that is working.
 *  7. `HEALTHY`.
 *
 * ## Derived, never stored
 *
 * Same reason `ConnectionWizardState` is: a stored health column is a second opinion that drifts
 * from the timestamps it was computed from, and the drift shows up as a green tick over an account
 * that has not synced since Tuesday. Every input here is a fact the sync path already writes.
 */
final class AccountHealth
{
    public const NOT_CONNECTED = 'not_connected';

    public const REVOKED = 'revoked';

    public const ACCESS_LOST = 'access_lost';

    public const FAILED = 'failed';

    public const PENDING_FIRST_SYNC = 'pending_first_sync';

    public const DELAYED = 'delayed';

    /**
     * CONNECTION HEALTH ≠ DATA HEALTH — the sweep ran, the provider answered, and there was nothing.
     *
     * Meta on `/app/integrations` read «يعمل»: authorised, one linked account, a recent sync, no
     * error — and it contributed nothing to the Dashboard. Production said why (metrics `no_data`,
     * raw 0 → parsed 0 → mapped 0 → stored 0) and the card could not, because this method returned
     * HEALTHY as soon as the account was bound, the connection was fine and `last_synced_at` was
     * recent. Whether a single ROW had been stored was never asked, so a provider feeding 3,420 rows
     * and one feeding zero were the same word.
     *
     * Deliberately NOT an error, and it sits outside `NEEDS_ATTENTION`. Nothing is broken: an ad
     * account that genuinely has no campaigns is in exactly this state and there is nothing to fix.
     * Reporting it as FAILED would be as wrong as reporting it as HEALTHY, in the other direction —
     * it would send an operator to reconnect an authorisation that is working perfectly.
     */
    public const NO_DATA = 'no_data';

    public const HEALTHY = 'healthy';

    /** The states that mean somebody has to do something. */
    public const NEEDS_ATTENTION = [self::REVOKED, self::ACCESS_LOST, self::FAILED, self::DELAYED];

    public function __construct(private readonly AccountAssignment $assignment) {}

    public function for(ExternalAccount $account): string
    {
        if ($this->assignment->projectIdFor($account) === null) {
            return self::NOT_CONNECTED;
        }

        $connectionStatus = ProviderConnection::withoutGlobalScopes()
            ->whereKey($account->provider_connection_id)
            ->value('status');

        if (in_array($connectionStatus, ['revoked', 'disconnected', 'error'], true)) {
            return self::REVOKED;
        }

        if ($account->access_lost_at !== null) {
            return self::ACCESS_LOST;
        }

        if ($account->last_sync_error_category !== null) {
            return self::FAILED;
        }

        if ($account->last_synced_at === null) {
            return self::PENDING_FIRST_SYNC;
        }

        if ($account->last_synced_at->lt(Carbon::now()->subHours($this->staleAfterHours()))) {
            return self::DELAYED;
        }

        /*
         * The last thing asked, because it is the only one about DATA rather than about the
         * connection: did the most recent run put anything in?
         *
         * The LATEST run, not a window of them: «is data flowing now» is a question about now, and
         * an account that answered yesterday and is empty today is empty today. `no_data` is the
         * status the metrics syncer already writes for exactly this, and `metrics_upserted = 0` is
         * checked beside it so a run that reported success while storing nothing cannot slip past
         * on its status alone.
         *
         * An account with no run at all is unreachable here — `last_synced_at === null` returned
         * PENDING_FIRST_SYNC above — which keeps «we have never asked» and «we asked and there was
         * nothing» as the two different sentences they are.
         */
        $latest = MetricSyncRun::withoutGlobalScopes()
            ->where('external_account_id', $account->getKey())
            ->orderByDesc('started_at')
            ->first(['status', 'metrics_upserted']);

        if ($latest !== null && ($latest->status === 'no_data' || (int) $latest->metrics_upserted === 0)) {
            return self::NO_DATA;
        }

        return self::HEALTHY;
    }

    /**
     * What one connection's accounts add up to.
     *
     * Returned as counts rather than a single worst-case state, because «10 connected · 9 healthy ·
     * 1 needs attention» is a sentence somebody can act on, and both a green tick and a red one over
     * the same ten accounts would be a lie in one direction or the other.
     *
     * `no_data` is counted separately and is NOT folded into either «healthy» or «needs attention».
     * It is neither: the authorisation works and there is nothing to repair, and it is the number the
     * operator asking «why is Meta connected but not in my KPIs?» is actually looking for.
     *
     * @return array{connected:int, healthy:int, no_data:int, needs_attention:int, pending_first_sync:int, states:array<string,int>}
     */
    public function summarise(string $connectionId): array
    {
        $accounts = ExternalAccount::withoutGlobalScopes()
            ->where('provider_connection_id', $connectionId)
            ->where('account_type', 'ad_account')
            ->get();

        $states = [];
        $connected = 0;

        foreach ($accounts as $account) {
            $state = $this->for($account);

            if ($state === self::NOT_CONNECTED) {
                // Discovered inventory is counted as inventory elsewhere; it is not part of this
                // connection's operating health, and folding it in would drown the real numbers.
                continue;
            }

            $connected++;
            $states[$state] = ($states[$state] ?? 0) + 1;
        }

        return [
            'connected' => $connected,
            'healthy' => $states[self::HEALTHY] ?? 0,
            'no_data' => $states[self::NO_DATA] ?? 0,
            'pending_first_sync' => $states[self::PENDING_FIRST_SYNC] ?? 0,
            'needs_attention' => array_sum(array_map(
                static fn (string $s): int => $states[$s] ?? 0,
                self::NEEDS_ATTENTION,
            )),
            'states' => $states,
        ];
    }

    /**
     * How old a success may be before it is «delayed».
     *
     * The metrics sweep runs every thirty minutes, so a success from yesterday means several dozen
     * sweeps produced nothing — but a worker restart, a deploy or a provider's quiet hour can each
     * eat a few of those legitimately. The default is generous on purpose: this flag has to mean
     * «something is wrong», and a threshold that fires on ordinary operational noise trains people
     * to ignore it.
     */
    private function staleAfterHours(): int
    {
        return max(1, (int) config('integrations.health.stale_after_hours', 48));
    }
}
