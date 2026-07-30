<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Identity\Actions\BackfillClientPortalIdentities;
use Illuminate\Console\Command;

/**
 * Step 1 of PORTAL-AUTH-001, run deliberately rather than on deploy (see the action for why).
 *
 * `--dry-run` reports what WOULD happen and writes nothing, because the first thing anyone should do
 * with a migration that mints identities is look at what it plans to touch.
 */
final class BackfillClientPortalCommand extends Command
{
    protected $signature = 'portal:backfill-identities {--dry-run : Report only; write nothing}';

    protected $description = 'Give every existing client-portal contact a user + ClientPortal membership (idempotent).';

    public function handle(BackfillClientPortalIdentities $backfill): int
    {
        $dry = (bool) $this->option('dry-run');

        $result = $backfill->execute($dry);

        $this->info(($dry ? '[dry run] ' : '')
            ."Granted: {$result['granted']}, updated: {$result['updated']}, "
            ."already correct: {$result['unchanged']}, needs attention: ".count($result['skipped']));

        if ($result['skipped'] !== []) {
            // Skipped is not failure — it is the fail-closed path, and each one needs a human. Shown
            // in full rather than counted, because "3 skipped" tells nobody which contacts to look at.
            $this->newLine();
            $this->warn('Needs attention — nothing was granted for these:');
            $this->table(['Contact', 'Reason'], array_map(
                fn (array $s) => [$s['contact'], $s['reason']],
                $result['skipped'],
            ));
        }

        return self::SUCCESS;
    }
}
