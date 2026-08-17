<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Services;

use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Illuminate\Support\Carbon;

/**
 * RUNTIME-100 §13 §14 — what happens the moment an account is actually connected to a project.
 *
 * ## The step the customer should not have to find
 *
 * Confirming a selection is the customer saying «this account feeds this project». Everything after
 * that is our job. The product used to leave a sync button on the page and wait, which meant the
 * first thing a new integration showed was an empty project and no explanation — the data existed,
 * the authorisation existed, the binding existed, and nothing had asked the provider for anything.
 *
 * ## Structure first, then metrics, and why the order is not cosmetic
 *
 * Metrics arrive keyed by the provider's campaign, ad set and ad ids. Importing them before the
 * structure that owns those ids leaves rows that reference campaigns the product has never heard of,
 * which either fail to attach or attach later and out of order. Structure is the smaller, faster call
 * and it is the one that makes the numbers mean something, so it goes first.
 *
 * ## The backfill window is configured, not invented
 *
 * A first sync that asks only for today shows a customer an empty dashboard on the day they connect,
 * which is the worst possible first impression of a product whose whole claim is «your data, in one
 * place». A first sync that asks for two years hammers the provider's rate limit for data nobody
 * scrolled back to.
 *
 * `integrations.first_sync.backfill_days` is the answer, and it is a CONFIGURED number rather than a
 * constant hidden in a service, because it is exactly the kind of decision that gets revised once a
 * real account's history has been measured. It is clamped by the provider's own limit where the
 * provider states one, because asking beyond it is not a bigger request — it is an error.
 */
final class FirstSync
{
    public function __construct(private readonly AccountAssignment $assignment) {}

    /**
     * Queue the first sync for accounts that were just connected.
     *
     * Called from `DB::afterCommit()`, never from inside the transaction: a job that reaches a worker
     * before its binding is visible finds no assignment, correctly refuses, and reads to everybody as
     * «I confirmed and nothing happened».
     *
     * @param  list<string>  $accountIds
     */
    public function start(array $accountIds, string $source = 'assignment'): void
    {
        if ($accountIds === []) {
            return;
        }

        $from = Carbon::now()->subDays($this->backfillDays())->startOfDay();
        $to = Carbon::now()->endOfDay();

        foreach ($accountIds as $accountId) {
            SyncAccountStructureJob::dispatch($accountId, ['source' => $source, 'first_sync' => true]);

            SyncAccountMetricsJob::dispatch(
                $accountId,
                $from->toDateString(),
                $to->toDateString(),
                ['source' => $source, 'first_sync' => true],
            )
                /*
                 * Behind the structure job in the same queue rather than chained.
                 *
                 * A chain would abandon the metrics sync whenever the structure sync failed, and a
                 * structure failure is usually one provider call being slow — not a reason to leave
                 * the customer with no numbers at all. Ordering is what is needed here, not coupling.
                 */
                ->delay(Carbon::now()->addSeconds(5));
        }
    }

    /** Everything currently connected to a project, for a re-sync that must not touch anything else. */
    public function startForProject(string $projectId, string $source = 'manual'): void
    {
        $this->start(
            $this->assignment->activeAccountIdsForProject($projectId),
            $source,
        );
    }

    private function backfillDays(): int
    {
        $days = (int) config('integrations.first_sync.backfill_days', 30);

        return max(1, min($days, 365));
    }
}
