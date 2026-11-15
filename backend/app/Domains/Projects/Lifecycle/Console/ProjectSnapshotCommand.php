<?php

declare(strict_types=1);

namespace App\Domains\Projects\Lifecycle\Console;

use App\Domains\Projects\Lifecycle\ProjectSnapshot;
use Illuminate\Console\Command;

/**
 * PROJECT-LIFECYCLE-001 §PROTECTION — `lifecycle:snapshot --project=<id>`: READ-ONLY counts of one project as JSON
 * (accounts, campaigns, last successful sync per platform, 30-day metric totals, scheduled reports, client-portal users,
 * budgets/limits/rules/subscriptions). Run before and after any operation; compare the two outputs. Writes nothing.
 */
final class ProjectSnapshotCommand extends Command
{
    protected $signature = 'lifecycle:snapshot {--project= : The project id (required)} {--as-of= : The day the 30-day window ends before, YYYY-MM-DD (default today)}';

    protected $description = 'Read-only JSON snapshot (counts only) of one project, for before/after comparison.';

    public function handle(ProjectSnapshot $snapshots): int
    {
        $id = trim((string) $this->option('project'));
        if ($id === '') {
            $this->error('--project=<id> is required.');

            return self::INVALID;
        }

        $asOf = $this->option('as-of') ? \Illuminate\Support\Carbon::parse((string) $this->option('as-of')) : null;
        $snapshot = $snapshots->for($id, $asOf);
        if ($snapshot === null) {
            $this->error('No project with that id.');

            return self::FAILURE;
        }

        $this->line((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
