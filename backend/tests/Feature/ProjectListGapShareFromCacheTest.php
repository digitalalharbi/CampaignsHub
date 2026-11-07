<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Services\ProjectListSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

/**
 * GA4-GAP-SHARE-CACHE-001 — the project list answered 500 on Production-like stacks: the gap share came back from the
 * Redis cache store as the string «0.7500», and `gapShare(): ?float` refuses a string. The array store used by the
 * suite keeps the float, so only a stored string reproduces it.
 */
final class ProjectListGapShareFromCacheTest extends TestCase
{
    public function test_a_gap_share_the_cache_returns_as_text_is_read_as_a_number(): void
    {
        $key = 'ga4-gap-share|tenant-1|project-1|'.Carbon::today()->toDateString();
        Cache::put($key, '0.7500', 900);

        $read = (new ReflectionMethod(ProjectListSummary::class, 'gapShare'))
            ->invoke(app(ProjectListSummary::class), 'tenant-1', 'project-1');

        $this->assertSame(0.75, $read);
    }

    public function test_a_cached_absence_stays_absent(): void
    {
        $key = 'ga4-gap-share|tenant-1|project-2|'.Carbon::today()->toDateString();
        Cache::put($key, '', 900);

        $read = (new ReflectionMethod(ProjectListSummary::class, 'gapShare'))
            ->invoke(app(ProjectListSummary::class), 'tenant-1', 'project-2');

        $this->assertNull($read);
    }
}
