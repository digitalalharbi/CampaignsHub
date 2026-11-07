<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use Illuminate\Console\Command;

/**
 * TIKTOK-MEDIA-002 — the original name of the cover pass, kept so a runbook that types it still works.
 *
 * CREATIVE-PREVIEWS-001 — it now runs `creatives:backfill-covers --execute`, the one cover entry point. The
 * old pass took its `--limit` BEFORE asking which rows needed a cover, so on an estate of 7,000 creatives the
 * newest 2,000 (mostly ones already cached) used the whole allowance and the rest were never reached.
 */
final class RefreshCreativeCoversCommand extends Command
{
    protected $signature = 'creatives:refresh-covers {--provider=} {--limit=2000}';

    protected $description = 'Queue cover captures for creatives whose cover is missing or older than their provider link (alias of creatives:backfill-covers --execute).';

    public function handle(): int
    {
        return $this->call('creatives:backfill-covers', array_filter([
            '--provider' => $this->option('provider'),
            '--limit' => $this->option('limit'),
            '--execute' => true,
        ], static fn ($v) => $v !== null && $v !== ''));
    }
}
