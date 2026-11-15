<?php

declare(strict_types=1);

namespace App\Domains\Notifications\Engine\Console;

use App\Domains\Notifications\Engine\Migration\LegacyNotificationImporter;
use Illuminate\Console\Command;

/**
 * `notifications:v2-import-legacy [--dry-run]` — move existing external recipients into the shared model as «pending
 * confirmation». Deletes nothing and sends nothing. Run by hand after the deploy; safe to run again.
 */
final class ImportLegacyCommand extends Command
{
    protected $signature = 'notifications:v2-import-legacy {--dry-run : Count only, write nothing}';

    protected $description = 'Move existing external recipients into the notifications-v2 model as pending confirmation (no deletion, no email).';

    public function handle(LegacyNotificationImporter $importer): int
    {
        $r = $importer->run((bool) $this->option('dry-run'));
        foreach ($r as $k => $v) {
            $this->line(sprintf('%-28s %d', $k, $v));
        }
        $this->line($this->option('dry-run') ? 'dry run: nothing written' : 'done: nothing deleted, no email sent');

        return self::SUCCESS;
    }
}
