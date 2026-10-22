<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Console;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Ops\Services\ScheduledRunRows;
use Illuminate\Console\Command;

/**
 * TIKTOK-MEDIA-002 — the nightly pass: every creative with a provider link and no cover of our own gets one.
 *
 * The structure sweep refreshes the providers' signed links six-hourly; this takes the cover from whichever link
 * is live so the library never depends on a link that will die before the next sweep.
 */
final class RefreshCreativeCoversCommand extends Command
{
    protected $signature = 'creatives:refresh-covers {--provider=} {--limit=2000}';

    protected $description = 'Queue cover captures for creatives whose cover is missing or older than their provider link.';

    public function handle(): int
    {
        $query = ExternalCreative::withoutGlobalScopes()
            ->where(function ($q): void {
                $q->whereNotNull('thumbnail_url')->orWhereNotNull('asset_url');
            })
            ->orderByDesc('last_active_at')
            ->limit((int) $this->option('limit'));
        if ($this->option('provider')) {
            $query->where('provider', (string) $this->option('provider'));
        }

        $queued = 0;
        foreach ($query->cursor() as $creative) {
            if (CoverCache::wanted($creative)) {
                CacheCreativeCoverJob::dispatch((string) $creative->getKey());
                $queued++;
            }
        }
        app(ScheduledRunRows::class)->report($queued);
        $this->info("{$queued} cover capture(s) queued.");

        return self::SUCCESS;
    }
}
