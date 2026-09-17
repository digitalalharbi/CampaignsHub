<?php

declare(strict_types=1);

namespace App\Domains\Integrations\Console;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — re-fetch a window for ONE bound account, through the ordinary sync.
 *
 * ## Why a re-sync is a separate, manual thing
 *
 * The sweep re-asks for the last seven days every half hour; anything older keeps whatever the
 * mapping of its day wrote. #460 changed what a Snapchat ad-grain `landing_page_views` MEANS, so
 * rows from 2026-08-16 → 2026-09-06 carry the old pixel count until their days are fetched again —
 * and a cleanup that removed foreign rows leaves a window to refill from the right account. Both are
 * writes to Production history and are an Owner decision, so they are not scheduled and not implicit.
 *
 * ## What it refuses
 *
 * An account that is not ACTIVELY selected for a project — the same question every sweep asks. A
 * re-sync of a deselected account would be the leak this unit exists to close, performed on purpose.
 *
 * ## Dry run is the default
 *
 * It prints the account's binding, its connection state, what is stored in the window per grain, and
 * the chunks it would queue. `--apply` queues exactly those chunks through `SyncAccountMetricsJob` —
 * the job the scheduler runs, which re-proves the assignment before it fetches and upserts each day in
 * place. No second pipeline, and nothing here fetches anything itself.
 */
final class ResyncWindowCommand extends Command
{
    protected $signature = 'integrations:resync-window
        {--account= : The bound account to re-fetch (required)}
        {--from= : Window start, YYYY-MM-DD (required)}
        {--to= : Window end, YYYY-MM-DD (required)}
        {--chunk-days=7 : Days per queued job}
        {--apply : Actually queue. Without this the command only prints the plan.}';

    protected $description = 'Queue a re-fetch of one window for one ACTIVELY bound account — dry run unless --apply.';

    public function handle(AccountAssignment $assignment): int
    {
        $accountId = $this->stringOption('account');
        $fromText = $this->stringOption('from');
        $toText = $this->stringOption('to');
        $apply = (bool) $this->option('apply');

        if ($accountId === null || $fromText === null || $toText === null) {
            $this->error('--account, --from and --to are all required: a re-sync is one account over one window.');

            return self::FAILURE;
        }

        $from = Carbon::parse($fromText)->startOfDay();
        $to = Carbon::parse($toText)->endOfDay();

        if ($to->lt($from)) {
            $this->error('--to is before --from.');

            return self::FAILURE;
        }

        $account = ExternalAccount::withoutGlobalScopes()->find($accountId);

        if ($account === null) {
            $this->error('No such account.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  RE-SYNC WINDOW — %s', $apply ? 'QUEUEING' : 'dry run, nothing will be queued'));
        $this->line(str_repeat('=', 78));
        $this->line(sprintf('  account    %s  [%s]', $account->getKey(), $account->provider));
        $this->line(sprintf('  window     %s → %s', $from->toDateString(), $to->toDateString()));

        $projectId = $assignment->projectIdFor($account);
        $connection = ProviderConnection::withoutGlobalScopes()->whereKey($account->provider_connection_id)->value('status');

        $this->line(sprintf('  project    %s', $projectId ?? '(none — not selected for any project)'));
        $this->line(sprintf('  connection %s', $connection ?? '(missing)'));

        if (! $assignment->isActivelyAssigned($account)) {
            $this->error('  REFUSED: the account is not actively selected for a project, so nothing may be fetched for it.');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('  stored in the window today');
        foreach (['daily_metrics' => 'metric_date', 'entity_daily_metrics' => 'metric_date'] as $table => $column) {
            $count = DB::table($table)
                ->where('external_account_id', $account->getKey())
                ->whereBetween($column, [$from->toDateString(), $to->toDateString()])
                ->count();
            $this->line(sprintf('    %-24s %8d row(s)', $table, $count));
        }

        $chunkDays = max(1, (int) $this->option('chunk-days'));
        $chunks = [];
        $cursor = $from->copy();

        while ($cursor->lte($to)) {
            $end = $cursor->copy()->addDays($chunkDays - 1)->endOfDay();
            if ($end->gt($to)) {
                $end = $to->copy();
            }
            $chunks[] = [$cursor->toDateString(), $end->toDateString()];
            $cursor = $end->copy()->addDay()->startOfDay();
        }

        $this->line('');
        $this->line(sprintf('  %d job(s) of up to %d day(s)', count($chunks), $chunkDays));
        foreach ($chunks as [$start, $end]) {
            $this->line(sprintf('    %s → %s', $start, $end));
        }

        if (! $apply) {
            $this->line('');
            $this->line('  Nothing was queued. Re-run with --apply to queue exactly these jobs.');

            return self::SUCCESS;
        }

        foreach ($chunks as [$start, $end]) {
            SyncAccountMetricsJob::dispatch((string) $account->getKey(), $start, $end, ['source' => 'resync', 'manual' => true]);
        }

        $this->line('');
        $this->line(sprintf('  Queued %d job(s). Each re-proves the binding before it fetches and upserts its days in place.', count($chunks)));

        return self::SUCCESS;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
