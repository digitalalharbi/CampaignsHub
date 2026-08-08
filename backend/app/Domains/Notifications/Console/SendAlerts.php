<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Console;

use App\Domains\Notifications\Services\AlertDispatcher;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The alert sweep — MAIL-006.
 *
 * Runs several times a day rather than hourly: an alert is «this needs a decision», and the
 * decisions this product surfaces — a budget running ahead of plan, a cost per result climbing, a
 * source that stopped syncing — do not change between nine and ten in the morning. Four sweeps a day
 * is soon enough to act on and rare enough not to be noise.
 *
 * Opt-in, like the digests: a preference row with `alerts` off is never swept. An opt-in that
 * defaults to on is not an opt-in, and the first scheduled run after a deploy is the worst possible
 * moment to discover that.
 */
final class SendAlerts extends Command
{
    protected $signature = 'notifications:send-alerts
        {--user= : Sweep one user id only — for verifying a change without mailing an account}
        {--date= : The day to examine, YYYY-MM-DD. Defaults to today}';

    protected $description = 'Send immediate alerts for findings that should not wait for tomorrow’s digest.';

    public function handle(AlertDispatcher $alerts): int
    {
        $day = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'))
            : Carbon::today();

        $rows = DB::table('notification_preferences')
            ->whereNull('client_workspace_id')
            ->when($this->option('user') !== null, fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            if (! $this->wantsAlerts($row)) {
                continue;
            }

            $user = User::query()->find($row->user_id);
            if ($user === null || $user->email === null) {
                continue;
            }

            $counts = $alerts->sweep($user, (string) $row->tenant_id, $day, (string) ($row->locale ?? 'ar'));
            foreach ($counts as $state => $n) {
                $totals[$state] = ($totals[$state] ?? 0) + $n;
            }
        }

        $this->info('alerts '.json_encode($totals));

        return self::SUCCESS;
    }

    /**
     * Absent means NO.
     *
     * The column holds the same `digests`-style map, and a row written before alerts existed has no
     * `alerts` key at all — which must read as «never asked», not as «why not».
     */
    private function wantsAlerts(object $row): bool
    {
        $chosen = $row->digests === null ? [] : (array) json_decode((string) $row->digests, true);

        return ($chosen['alerts'] ?? false) === true;
    }
}
