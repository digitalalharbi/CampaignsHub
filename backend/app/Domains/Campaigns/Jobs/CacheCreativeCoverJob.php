<?php

declare(strict_types=1);

namespace App\Domains\Campaigns\Jobs;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** TIKTOK-MEDIA-002 — one creative's cover, fetched off the request path. */
final class CacheCreativeCoverJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly string $creativeId) {}

    public function handle(CoverCache $covers): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->find($this->creativeId);
        if ($creative !== null && CoverCache::wanted($creative)) {
            $covers->capture($creative);
        }
    }
}
