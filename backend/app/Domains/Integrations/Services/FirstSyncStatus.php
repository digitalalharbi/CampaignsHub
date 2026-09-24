<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Metrics\Enums\SyncRunStatus;
use App\Domains\Metrics\Models\MetricSyncRun;
use Illuminate\Support\Carbon;

/**
 * INTEGRATION-FIRST-SYNC-VISIBILITY-001 — what a confirmed selection's first sync has actually done.
 *
 * ## Why this is a whole selection, not an account
 *
 * The wizard used to watch ONE account — the first of the selection — and say what that one run did.
 * For the single-account case it was right, and for every other case it was a sentence about a
 * stranger: «اكتملت — ٩٣٦ صفًا» while the other four accounts of the same confirmation were still
 * queued, or refused. A person who ticks five accounts and presses «تأكيد الربط» asked one question,
 * and the answer has to be about the five.
 *
 * Doing that from the browser would be five polls every two seconds against a dialog somebody is
 * about to close, growing with the plan's account limit. It is one question, so it is one request:
 * the server reads the runs, applies the rules once, and answers for the selection.
 *
 * ## The rules, and why each one is here
 *
 *  - Only runs that STARTED at or after the confirmation count. An account bound to a second project
 *    already has history, and yesterday's success is not this button's outcome.
 *  - A run still going is `running`, never «no result yet» — the difference is what the reader is
 *    waiting for.
 *  - No run at all is `queued`: the truth on a system whose worker has not picked the job up. Not a
 *    failure, and emphatically not a success.
 *  - `no_data` is its own outcome. A provider that answered honestly with nothing is not a failure,
 *    and calling it one teaches people to distrust a working connection.
 *  - `partial_mapping` and `awaiting_assignment` are NOT success. The browser's rules collapsed both
 *    into whichever of imported/no_data the row count implied, which is how an attention state
 *    reached a customer wearing a green tick.
 *
 * ## `settled` is the only thing the dialog may act on
 *
 * The caller refreshes the rest of the product when — and only when — the answer stops changing.
 * A selection with one account still running is not finished, however good the other four look, and
 * announcing success over a queue is the lie this unit exists to remove.
 */
final class FirstSyncStatus
{
    /** Per-account outcomes, in the order a summary should read them. */
    private const ATTENTION = ['failed', 'awaiting_assignment', 'partial'];

    /**
     * @param  list<ExternalAccount>  $accounts  the confirmed selection, in the order it was chosen
     * @return array<string,mixed>
     */
    public function for(array $accounts, Carbon $since, string $tenantId): array
    {
        $ids = array_map(static fn (ExternalAccount $a): string => (string) $a->getKey(), $accounts);

        /*
         * One query for the whole selection, newest first.
         *
         * A retry's answer supersedes the attempt before it, so only the newest run per account is
         * read — and it is read here rather than with a `latest of many` join because the selection
         * is bounded by the plan's account limit and the window by the confirmation.
         */
        $runs = $ids === [] ? collect() : MetricSyncRun::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('external_account_id', $ids)
            ->where('started_at', '>=', $since)
            ->orderByDesc('started_at')
            ->get()
            ->groupBy('external_account_id');

        $rows = [];
        foreach ($accounts as $account) {
            $latest = $runs->get((string) $account->getKey())?->first();
            $rows[] = [
                'id' => (string) $account->getKey(),
                'external_id' => (string) $account->external_id,
                'name' => (string) $account->name,
                'state' => $this->state($latest),
                // Rows are what THIS run imported, never the account's lifetime total.
                'rows' => $latest === null ? 0 : (int) $latest->metrics_upserted,
                'error' => $latest?->error,
                'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            ];
        }

        return ['since' => $since->toIso8601String(), 'accounts' => $rows, 'summary' => $this->summarise($rows)];
    }

    /** One account's outcome, from its newest run since the confirmation. */
    private function state(?MetricSyncRun $run): string
    {
        if ($run === null) {
            return 'queued';
        }

        return match ($run->status) {
            SyncRunStatus::Running->value, 'pending' => 'running',
            SyncRunStatus::Failed->value => 'failed',
            SyncRunStatus::AwaitingAssignment->value => 'awaiting_assignment',
            SyncRunStatus::PartialMapping->value => 'partial',
            SyncRunStatus::NoData->value => 'no_data',
            /*
             * A success that imported nothing is reported as `no_data`, not as «0 imported».
             *
             * «Imported 0 rows» reads as a broken pipeline; «the platform reported nothing for this
             * window» reads as what it is. Same run, different sentence, and the second is the one a
             * person can act on.
             */
            default => (int) $run->metrics_upserted > 0 ? 'imported' : 'no_data',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function summarise(array $rows): array
    {
        $count = static fn (string $state): int => count(array_filter(
            $rows,
            static fn (array $r): bool => $r['state'] === $state,
        ));

        $counts = [];
        foreach (['queued', 'running', 'imported', 'no_data', 'partial', 'failed', 'awaiting_assignment'] as $state) {
            $counts[$state] = $count($state);
        }

        $settled = $counts['queued'] === 0 && $counts['running'] === 0;
        $attention = array_sum(array_map(static fn (string $s): int => $counts[$s], self::ATTENTION));

        return [
            'total' => count($rows),
            ...$counts,
            'rows' => array_sum(array_map(static fn (array $r): int => (int) $r['rows'], $rows)),
            'settled' => $settled,
            'state' => $this->headline($rows, $counts, $settled, $attention),
            /*
             * How many of the selection a reader may treat as done. Stated separately from `total`
             * because «٣ من ٥ اكتملت» is the sentence a partial outcome deserves, and deriving it in
             * the browser from seven counters is how two surfaces end up disagreeing.
             */
            'succeeded' => $counts['imported'] + $counts['no_data'],
            'needs_attention' => $attention,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,int>  $counts
     */
    private function headline(array $rows, array $counts, bool $settled, int $attention): string
    {
        if ($rows === []) {
            return 'queued';
        }
        if (! $settled) {
            return $counts['running'] > 0 ? 'running' : 'queued';
        }
        // Every one of them refused is a different sentence from «two of five refused».
        if ($attention === count($rows)) {
            return $counts['failed'] === count($rows) ? 'failed' : 'partial';
        }
        if ($attention > 0) {
            return 'partial';
        }

        return $counts['imported'] > 0 ? 'imported' : 'no_data';
    }
}
