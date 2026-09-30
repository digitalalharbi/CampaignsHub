<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Support;

use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * INTEGRATION-DATASOURCE-WIZARD-001 §16 — «are we allowed in» and «did the data arrive» are two
 * questions, and this product kept answering them with one word.
 *
 * ## What one word costs
 *
 * A card that says `syncing` is making a claim about the PIPELINE. A card that says
 * `REAUTH_REQUIRED` is making a claim about the AUTHORISATION. They are independent facts — a
 * connection whose grant was withdrawn can still have a run open, and a perfectly authorised
 * connection can have failed its last four — so the moment they share a field one of them has to
 * lose. On Production one did: a stale `running` row outranked a refused Meta grant, and the card
 * rendered «المزامنة جارية الآن» above an action area with no Reconnect button. The customer was
 * told to act and denied the control (#578).
 *
 * Ranking them differently would only move the defect. So they stop sharing a field:
 *
 *  - {@see self::CONNECTION_STATES} answers «what is the state of the authorisation».
 *  - {@see self::SYNC_STATES} answers «what is the state of the data».
 *
 * Both are always reported, neither can hide the other, and a surface that needs one sentence
 * chooses which to lead with rather than having the choice made for it by a `match` arm.
 *
 * ## Why the sync vocabulary is five words and not the pipeline's own seven
 *
 * {@see SyncRunStatus} is the PIPELINE's vocabulary and it is deliberately finer — it separates «the
 * provider had nothing» from «we could not place some rows» because an operator debugging a quiet
 * account needs that difference. This is the CARD's vocabulary, and a card is read in a second.
 * `no_data`, `partial_mapping` and `awaiting_assignment` all mean «the request completed», so they
 * report as {@see self::SUCCEEDED} here; the distinction is not lost, it is carried by the health
 * summary that sits beside it and by the run log behind it.
 */
final class IntegrationTruth
{
    /** A — the authorisation. Nothing about data. */
    public const NOT_CONNECTED = 'NOT_CONNECTED';

    /** The platform's own app credentials are not configured here, so nobody can connect yet. */
    public const AWAITING_CREDENTIALS = 'AWAITING_CREDENTIALS';

    public const CONNECTED = 'CONNECTED';

    /** The authorisation exists and cannot do the job: withdrawn, lapsed, or refused on its grant. */
    public const REAUTH_REQUIRED = 'REAUTH_REQUIRED';

    /** It was connected and is not any more — a different sentence from «never connected». */
    public const REVOKED = 'REVOKED';

    /** B — the data. Nothing about authorisation. */
    public const NEVER_SYNCED = 'NEVER_SYNCED';

    /** Selected and owed a run that has not started. */
    public const QUEUED = 'QUEUED';

    public const SYNCING = 'SYNCING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const CONNECTION_STATES = [
        self::NOT_CONNECTED, self::AWAITING_CREDENTIALS, self::CONNECTED, self::REAUTH_REQUIRED, self::REVOKED,
    ];

    public const SYNC_STATES = [
        self::NEVER_SYNCED, self::QUEUED, self::SYNCING, self::SUCCEEDED, self::FAILED,
    ];

    /**
     * How long a `running` row may stay open before a READER stops believing it.
     *
     * The same sixty minutes `integrations:close-abandoned-runs` uses, and shared from here so the
     * sweeper and the card cannot drift apart. The sweeper runs hourly and closes these rows; between
     * the crash and the sweep the card would otherwise say «مزامنة جارية» about a worker that died —
     * the permanent-syncing state this redesign is required to make impossible.
     */
    public const ABANDONED_AFTER_MINUTES = 60;

    /**
     * The data truth for a set of SELECTED accounts — never for everything a connection discovered.
     *
     * Empty input is {@see self::NEVER_SYNCED} and not an error: a connection with nothing selected
     * has no data state to report, and saying «never synced» is exactly true of it.
     *
     * @param  list<string>  $accountIds  the accounts with an ACTIVE binding (ACCOUNT-SCOPE-ISOLATION-001)
     */
    public static function syncStateFor(array $accountIds, string $tenantId, ?Carbon $now = null): string
    {
        if ($accountIds === []) {
            return self::NEVER_SYNCED;
        }

        $runs = MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $accountIds)
            ->orderByDesc('started_at')
            ->get(['external_account_id', 'status', 'started_at']);

        return self::syncStateFromRuns($accountIds, $runs, $now);
    }

    /**
     * The same rule over runs somebody has already loaded.
     *
     * The estate view answers for every project and provider a tenant has at once. Asking the
     * database once per group is how a page that must stay usable with hundreds of accounts becomes
     * a page that issues hundreds of queries, so the caller fetches the runs and the rule is applied
     * here — one rule, two entry points, no second implementation to drift.
     *
     * @param  list<string>  $accountIds
     * @param  Collection<int, MetricSyncRun>  $runs  newest first; may cover more accounts than asked for
     */
    public static function syncStateFromRuns(array $accountIds, Collection $runs, ?Carbon $now = null): string
    {
        if ($accountIds === []) {
            return self::NEVER_SYNCED;
        }

        $wanted = array_flip($accountIds);
        $runs = $runs->filter(
            static fn (MetricSyncRun $r): bool => isset($wanted[(string) $r->external_account_id]),
        );

        $now ??= Carbon::now();
        $alive = $now->copy()->subMinutes(self::ABANDONED_AFTER_MINUTES);

        $running = $runs->first(
            fn (MetricSyncRun $r): bool => $r->status === SyncRunStatus::Running->value
                && $r->started_at !== null
                && $r->started_at->greaterThanOrEqualTo($alive),
        );

        if ($running !== null) {
            return self::SYNCING;
        }

        $terminal = $runs
            ->filter(fn (MetricSyncRun $r): bool => $r->status !== SyncRunStatus::Running->value)
            ->groupBy('external_account_id');

        // An account that has never finished a run is work this connection still owes.
        foreach ($accountIds as $id) {
            if (! $terminal->has($id)) {
                return self::QUEUED;
            }
        }

        $newest = $terminal->flatten()->sortByDesc('started_at')->first();

        return $newest !== null && $newest->status === SyncRunStatus::Failed->value
            ? self::FAILED
            : self::SUCCEEDED;
    }
}
