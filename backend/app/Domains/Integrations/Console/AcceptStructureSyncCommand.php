<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Jobs\SyncAccountStructureJob;
use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Integrations\Services\StructureAcceptance;
use App\Domains\Integrations\Services\StructureSweepTargets;
use App\Domains\Metrics\Enums\SyncRunStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * SNAP-STRUCTURE-RETRY-001 — queue ONE structure sweep and watch it to a terminal state.
 *
 * ## Why a fixed sleep is not acceptance
 *
 * The obvious version of this waits five minutes and prints whatever it finds. But the job's
 * legitimate ceiling is nine hundred seconds, so a five-minute wait reports «still running» on a
 * healthy sweep and «finished» on a sweep that was re-delivered and started again — the two things
 * this check exists to tell apart. Worse, it would have printed something reassuring for the exact
 * defect it is here to catch: a job re-queued every ninety seconds always has a recent-looking run.
 *
 * So this polls the run rows created BY THIS INVOCATION, bounded by an observation window that
 * comfortably outlasts the job ceiling, and it fails loudly rather than quietly for every way the
 * sweep can go wrong:
 *
 * - a second run row appears for a connection that was asked for one          → re-delivery
 * - any run reports `MaxAttemptsExceeded`                                     → re-delivery
 * - a run is still `running` when the window closes                           → killed, or stuck
 * - a run finishes `success` with `records = 0`                               → impossible; a lie
 * - a run finishes `no_data` while its retained payload carries rows          → a mapping defect
 * - a run row was already `running` before this started                       → stale, unclosed
 *
 * The measured `started_at → finished_at` of the successful run is printed, because the safety
 * margin in `config/queue.php` was derived from a request budget and should be replaced by a
 * measurement as soon as one exists.
 *
 * It queues the same job the six-hourly scheduler queues. Nothing new runs; it is only asked for
 * now, and then actually watched.
 */
final class AcceptStructureSyncCommand extends Command
{
    protected $signature = 'integrations:accept-structure
        {--provider= : Limit the sweep to one platform}
        {--observe=1500 : Seconds to watch before giving up. Must exceed the job timeout.}
        {--interval=15 : Seconds between polls}';

    protected $description = 'Queue one structure sweep, watch it to a terminal state, and fail if it was re-delivered, stalled or empty.';

    public function handle(StructureSweepTargets $targets): int
    {
        $observe = max(1, (int) $this->option('observe'));
        $interval = max(1, (int) $this->option('interval'));
        $jobTimeout = (new SyncAccountStructureJob('any'))->timeout;

        if ($observe <= $jobTimeout) {
            $this->error("--observe={$observe}s does not outlast the job's own timeout of {$jobTimeout}s. "
                .'A window shorter than the work cannot tell a slow sweep from a stalled one.');

            return self::FAILURE;
        }

        $accounts = $targets->accounts($this->option('provider'));

        if ($accounts->isEmpty()) {
            $this->error('No connected, assigned ad accounts — there is nothing to accept.');

            return self::FAILURE;
        }

        $connections = $accounts->pluck('provider_connection_id')->unique()->values();
        $expected = $accounts->groupBy('provider_connection_id')->map->count();

        $this->line('Accounts in this sweep:');
        foreach ($accounts as $account) {
            $this->line("  {$account->id}  provider={$account->provider}  external={$account->external_id}");
        }

        // ── Anything already open is a stale row, and it is a finding, not a starting condition ──
        $stale = IntegrationSyncRun::withoutGlobalScopes()
            ->where('type', 'structure')
            ->where('status', SyncRunStatus::Running->value)
            ->whereIn('provider_connection_id', $connections)
            ->get();

        if ($stale->isNotEmpty()) {
            $this->newLine();
            $this->error("{$stale->count()} structure run(s) were already «running» before this started:");
            foreach ($stale as $run) {
                $this->error("  {$run->id}  started {$run->started_at}  connection {$run->provider_connection_id}");
            }
            $this->error('A run left open is a job that died without saying so. Close the cause before accepting.');

            return self::FAILURE;
        }

        // The watermark. Runs are attributed to this invocation by having been created after it, on a
        // connection we asked for — the run row carries the connection, not the account.
        $watermark = Carbon::now();
        $seen = IntegrationSyncRun::withoutGlobalScopes()
            ->where('type', 'structure')
            ->whereIn('provider_connection_id', $connections)
            ->pluck('id');

        foreach ($accounts as $account) {
            SyncAccountStructureJob::dispatch((string) $account->id, ['source' => 'acceptance']);
        }

        $this->newLine();
        $this->info("Queued {$accounts->count()} structure sync(s) at {$watermark->toDateTimeString()}. "
            ."Watching for up to {$observe}s, every {$interval}s.");
        $this->newLine();

        $deadline = $watermark->copy()->addSeconds($observe);
        $runs = collect();

        while (Carbon::now()->lessThan($deadline)) {
            $runs = $this->runsSince($connections, $seen);

            $byConnection = $runs->groupBy('provider_connection_id');

            foreach ($expected as $connection => $count) {
                $actual = $byConnection->get($connection, collect())->count();

                if ($actual > $count) {
                    $this->reportRuns($runs);
                    $this->error("Connection {$connection} produced {$actual} structure runs for {$count} account(s). "
                        .'A second run for work already in flight is the broker re-delivering a job that never stopped — '
                        .'SNAP-STRUCTURE-RETRY-001 exactly.');

                    return self::FAILURE;
                }
            }

            $tooManyAttempts = $runs->first(fn ($r) => $r->error !== null
                && str_contains(strtolower((string) $r->error), 'attempted too many times'));

            if ($tooManyAttempts !== null) {
                $this->reportRuns($runs);
                $this->error("Run {$tooManyAttempts->id} reports MaxAttemptsExceeded: {$tooManyAttempts->error}");
                $this->error('The job exhausted its attempts, which means it was re-queued while still running.');

                return self::FAILURE;
            }

            $open = $runs->where('status', SyncRunStatus::Running->value);
            $started = $runs->count();

            if ($started === $accounts->count() && $open->isEmpty()) {
                break;
            }

            $elapsed = (int) $watermark->diffInSeconds(Carbon::now());
            $this->line("  {$elapsed}s — {$started}/{$accounts->count()} run(s) started, {$open->count()} still running");

            sleep($interval);
        }

        $runs = $this->runsSince($connections, $seen);
        $this->newLine();
        $this->reportRuns($runs);

        return $this->verdict($runs, $accounts->count(), $observe);
    }

    /**
     * @param  Collection<int, string>  $connections
     * @param  Collection<int, string>  $seen
     * @return Collection<int, IntegrationSyncRun>
     */
    private function runsSince(Collection $connections, Collection $seen): Collection
    {
        return IntegrationSyncRun::withoutGlobalScopes()
            ->where('type', 'structure')
            ->whereIn('provider_connection_id', $connections)
            ->whereNotIn('id', $seen->all())
            ->orderBy('started_at')
            ->get();
    }

    /** @param Collection<int, IntegrationSyncRun> $runs */
    private function reportRuns(Collection $runs): void
    {
        if ($runs->isEmpty()) {
            $this->warn('No structure run was created by this invocation.');

            return;
        }

        $this->line('Runs created by this invocation:');
        foreach ($runs as $run) {
            $duration = $run->finished_at === null
                ? 'still running'
                : (int) $run->started_at->diffInSeconds($run->finished_at).'s';

            $this->line(sprintf(
                '  %s  %-16s records=%-6s %s → %s  (%s)',
                $run->id,
                $run->status,
                $run->records ?? 0,
                $run->started_at,
                $run->finished_at ?? '—',
                $duration,
            ));

            if ($run->error !== null) {
                $this->line("      {$run->error}");
            }
        }
    }

    /**
     * @param  Collection<int, IntegrationSyncRun>  $runs
     */
    private function verdict(Collection $runs, int $accounts, int $observe): int
    {
        $problems = app(StructureAcceptance::class)->problems($runs, $accounts, $observe);

        $this->newLine();

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error('✗ '.$problem);
            }

            return self::FAILURE;
        }

        $run = $runs->first();
        $seconds = (int) $run->started_at->diffInSeconds($run->finished_at);

        $this->info('✓ One run started, was not re-delivered, finished success, and stored '.$run->records.' record(s).');
        $this->info("✓ Measured structure sweep runtime: {$seconds}s. "
            .'This is the number the retry_after margin in config/queue.php should be read against.');

        return self::SUCCESS;
    }
}
