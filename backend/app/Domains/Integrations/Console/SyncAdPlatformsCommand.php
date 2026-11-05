<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * INTEG-SYNC-001 — the sweep that makes synced data arrive without anybody pressing anything.
 *
 * Before this, every figure in the product depended on somebody opening the integrations page and
 * pressing sync. The pipeline was real; nothing drove it. A dashboard that is only current when
 * somebody remembers to refresh it is not a dashboard, it is a report with extra steps.
 *
 * ## What it sweeps, and what it deliberately leaves alone
 *
 * Only accounts whose CONNECTION is `connected`. An account behind a revoked, errored or
 * awaiting-credentials connection is skipped rather than attempted: attempting it would write a
 * failure row every half hour for ever, burying the one failure that means something under thousands
 * that mean "we already knew".
 *
 * ## The window is deliberately not just today
 *
 * Every platform restates recent days — conversions attribute late, spend is corrected, fraud is
 * refunded. A sweep that only ever asked for today would freeze each day's numbers at their most
 * wrong. Re-upserted idempotently, a window lets a figure settle.
 *
 * PERF-SYNC-ISOLATION-001 — the half-hourly sweep asks THREE days back, and a daily pass at 03:50
 * (`--days=14`, see routes/console.php) re-asks the fortnight in which providers restate. Seven days
 * every half hour was 48 sweeps a day re-reading days that had already settled, on a one-CPU host
 * where eight concurrent syncs starved the web requests. Accuracy is kept; the repeated reads are not.
 *
 * ## Spread (PERF-RECOVERY-001), off unless asked for
 *
 * Queued together, the accounts keep the one sync worker busy without a pause from :00 and :30 until
 * the last of them is done. With `--spread=N` (or `SYNC_SWEEP_SPREAD_MINUTES`) each account's job is
 * delayed by its own fixed offset inside the first N minutes: the same account lands on the same
 * second of every half hour, so its cadence is still thirty minutes and its window is the same, and
 * the worker rests between accounts. The offset comes from the account's id, not from its place in
 * the list, so adding or removing an account moves nobody else.
 *
 * N is capped at 20. A job is unique per (account, window) until it finishes, so one that began at
 * :20 and ran past :30 would make that account miss a beat; twenty minutes leaves ten for the job.
 * Nothing here touches «sync now»: those buttons dispatch their own job, with no delay.
 */
final class SyncAdPlatformsCommand extends Command
{
    protected $signature = 'integrations:sync
        {--days=3 : How many days back to re-ask for, so late attribution can settle}
        {--provider= : Limit the sweep to one platform}
        {--spread= : Minutes over which to spread the accounts (0 = all at once; omitted = SYNC_SWEEP_SPREAD_MINUTES)}';

    /** The longest spread: a job is unique until it finishes, and the next sweep is thirty minutes away. */
    public const MAX_SPREAD_MINUTES = 20;

    protected $description = 'Queue a metrics sync for every ad account behind a connected provider.';

    public function handle(): int
    {
        $from = Carbon::now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $to = Carbon::now()->endOfDay();

        $connections = ProviderConnection::withoutGlobalScopes()
            ->where('status', 'connected')
            ->when($this->option('provider'), fn ($q, $provider) => $q->where('provider', $provider))
            ->pluck('id');

        if ($connections->isEmpty()) {
            $this->info('No connected provider connections — nothing to sync.');

            return self::SUCCESS;
        }

        $queued = 0;
        $spread = $this->spreadSeconds();

        ExternalAccount::withoutGlobalScopes()
            ->whereIn('provider_connection_id', $connections)
            ->where('account_type', 'ad_account')
            ->where('status', 'active')
            // ACCOUNT-SCOPE-ISOLATION-002 — the credential no longer answers for it; see AccountAssignment.
            ->whereNull('access_lost_at')
            ->orderBy('id')
            // PROJECT-INTEGRATION-ASSIGNMENT-001 — assigned accounts only.
            //
            // Discovery alone puts a row here, and the first live Snapchat consent catalogued 309 of
            // them. A sweep that does not ask which of those somebody actually attached to a project
            // pulls all 309 every run: data nobody requested, against the provider's rate limit,
            // filed into a project nobody chose. Consent to SEE an account is not instruction to
            // sync it.
            ->tap(fn ($q) => app(AccountAssignment::class)->scopeToAssigned($q))
            // Chunked because a platform-wide sweep on a busy install is thousands of accounts, and
            // loading them all to dispatch a job each is a memory profile that only fails in
            // production.
            ->chunkById(200, function ($accounts) use ($from, $to, $spread, &$queued): void {
                foreach ($accounts as $account) {
                    $offset = self::offsetFor((string) $account->id, $spread);

                    // The job is unique per (account, window), so a sweep that overlaps a manual sync
                    // or a webhook-driven one adds nothing rather than duplicating the work.
                    $pending = SyncAccountMetricsJob::dispatch(
                        (string) $account->id,
                        $from->toDateString(),
                        $to->toDateString(),
                        $offset === 0
                            ? ['source' => 'scheduler']
                            // The run's wait is counted from the moment the job was DUE, not from the moment the sweep queued it.
                            : ['source' => 'scheduler', 'queued_at' => Carbon::now()->addSeconds($offset)->toIso8601String(), 'spread_seconds' => $offset],
                    );

                    if ($offset > 0) {
                        $pending->delay($offset);
                    }
                    $queued++;
                }
            });

        /*
         * AUTOMATION-FIRST-OPERATIONS-001 — the count the ledger is supposed to hold.
         *
         * Account syncs QUEUED. The metric rows they go on to write belong to those jobs.
         *
         * Already computed, already printed to a terminal nobody watches at 04:00, while
         * `scheduled_runs.rows_affected` stayed null — so the ops page could say SUCCEEDED without
         * being able to say whether the run did anything.
         */
        app(ScheduledRunRows::class)->report($queued);

        $this->info("Queued {$queued} account sync(s) for {$from->toDateString()} → {$to->toDateString()}.".($spread > 0 ? ' Spread over '.intdiv($spread, 60).' minute(s).' : ''));

        return self::SUCCESS;
    }

    /** The spread in seconds: the option when given, the configured default otherwise, never past the cap. */
    private function spreadSeconds(): int
    {
        $option = $this->option('spread');
        $minutes = $option === null || $option === '' ? (int) config('integrations.sync.sweep_spread_minutes', 0) : (int) $option;

        return max(0, min(self::MAX_SPREAD_MINUTES, $minutes)) * 60;
    }

    /** An account's own second inside the spread: the same for that account on every sweep, whoever else is swept. */
    public static function offsetFor(string $accountId, int $spreadSeconds): int
    {
        return $spreadSeconds <= 0 ? 0 : crc32($accountId) % $spreadSeconds;
    }
}
