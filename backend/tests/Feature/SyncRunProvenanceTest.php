<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Models\MetricSyncRun;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * INTEG-RUNTIME §9 — a run log that cannot say what ASKED for a run is a wall of identical lines.
 *
 * The next move differs for every value. A failed `first_sync` means a setup that never worked; a
 * failed `scheduled` means something that used to work has stopped; a failed `reconnect` means the
 * authorisation somebody just renewed is no better than the one it replaced. One word, three
 * different people to call.
 */
final class SyncRunProvenanceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * The first sync after re-authorising is the RECONNECT's, not an ordinary first sync.
     *
     * `AdPlatformOAuthController` dispatches it with both flags, and reading the wrong one loses the
     * only fact that explains the run: somebody had just been sent through a consent screen.
     */
    public function test_a_reconnects_first_sync_is_attributed_to_the_reconnect(): void
    {
        $this->assertSame('reconnect', $this->aRun(['source' => 'reconnect', 'first_sync' => true])->source());
    }

    public function test_each_cause_is_named_by_the_meta_its_dispatcher_already_writes(): void
    {
        $this->assertSame('first_sync', $this->aRun(['source' => 'assignment', 'first_sync' => true])->source());
        $this->assertSame('scheduled', $this->aRun(['source' => 'scheduler'])->source());
        $this->assertSame('backfill', $this->aRun(['source' => 'backfill', 'backfill' => true])->source());
        $this->assertSame('manual', $this->aRun(['triggered_by' => 'u-1', 'manual' => true])->source());
        $this->assertSame('manual', $this->aRun(['source' => 'resync', 'manual' => true])->source());
    }

    /** A run older than these flags was the scheduler, and says so rather than «unknown». */
    public function test_a_run_recorded_before_the_flags_existed_reads_as_scheduled(): void
    {
        $this->assertSame('scheduled', $this->aRun([])->source());
    }

    /**
     * The queue wait is a real measurement, and its absence is not zero.
     *
     * Zero would claim a run began the instant it was asked for, which is the one thing a queue
     * never does — and it is what a `0` default would have said about every run recorded before the
     * column existed.
     */
    public function test_the_queue_wait_is_measured_and_is_null_when_nobody_recorded_it(): void
    {
        $queued = $this->aRun(['source' => 'scheduler']);
        $queued->forceFill([
            'queued_at' => Carbon::parse('2026-09-30T10:00:00Z'),
            'started_at' => Carbon::parse('2026-09-30T10:02:30Z'),
        ])->save();

        $this->assertSame(150, $queued->fresh()->waitedSeconds());
        $this->assertNull($this->aRun([])->waitedSeconds());
    }

    /** The log row carries both, so a reader never has to open the raw meta to know either. */
    public function test_the_log_row_states_the_cause_and_the_wait(): void
    {
        $run = $this->aRun(['source' => 'reconnect', 'first_sync' => true]);
        $run->forceFill([
            'queued_at' => Carbon::parse('2026-09-30T10:00:00Z'),
            'started_at' => Carbon::parse('2026-09-30T10:00:20Z'),
        ])->save();

        $row = $run->fresh()->logRow();

        $this->assertSame('reconnect', $row['source']);
        $this->assertSame(20, $row['waited_seconds']);
        $this->assertNotNull($row['queued_at']);
    }

    /** @param array<string,mixed> $meta */
    private function aRun(array $meta): MetricSyncRun
    {
        return MetricSyncRun::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => 'meta',
            'status' => 'success',
            'window_start' => '2026-09-23',
            'window_end' => '2026-09-30',
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
            'meta' => $meta,
        ]);
    }
}
