<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSyncEngineWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 item 2 — a delivery of a job whose twin is in progress does no work; a retry writes the same rows.
 *
 * `ShouldBeUnique` (INTEG-SYNC-001) stops a second DISPATCH. It does nothing about a second DELIVERY of the job already on the queue
 * — a redelivery after `retry_after`, a second worker — and that is what the overlap guard is for.
 */
final class SyncJobIdempotencyTest extends TestCase
{
    use BuildsSyncEngineWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyncEngineWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_redelivered_job_with_its_twin_in_progress_opens_no_run_and_asks_no_provider(): void
    {
        $job = new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02');
        $lock = Cache::lock((new WithoutOverlapping($job->uniqueId()))->getLockKey($job), 900);
        $this->assertTrue($lock->get());

        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, '2026-07-30', '2026-08-02');

        $this->assertSame(0, DB::table('metric_sync_runs')->count());
        Http::assertNothingSent();

        $lock->release();
        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, '2026-07-30', '2026-08-02');
        $this->assertSame(1, DB::table('metric_sync_runs')->count());
    }

    public function test_a_twin_for_another_window_or_account_is_not_held_up(): void
    {
        $job = new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02');
        $lock = Cache::lock((new WithoutOverlapping($job->uniqueId()))->getLockKey($job), 900);
        $this->assertTrue($lock->get());

        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, '2026-07-01', '2026-07-02');

        $this->assertSame(1, DB::table('metric_sync_runs')->count());
        $lock->release();
    }

    public function test_the_guard_expires_so_a_killed_worker_does_not_lock_the_account_out(): void
    {
        $job = new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02');
        $guard = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $guard);
        $this->assertSame((int) config('integrations.locking.account_sync_seconds'), $guard->expiresAfter);
        $this->assertNull($guard->releaseAfter, 'a twin is dropped, not re-queued to spin');
    }

    public function test_a_retried_job_writes_the_same_rows(): void
    {
        $id = (string) $this->account->id;

        SyncAccountMetricsJob::dispatchSync($id, '2026-07-30', '2026-08-02');
        $first = $this->figures();
        $this->assertNotSame([], $first['daily_metrics']);
        $this->assertNotSame([], $first['entity_daily_metrics']);

        // A failed attempt in between, then the retry.
        $this->script = ['mode' => 'page2_500', 'spend' => '5'];
        SyncAccountMetricsJob::dispatchSync($id, '2026-07-30', '2026-08-02');
        $this->assertSame($first, $this->figures());

        $this->script = ['mode' => 'ok'];
        SyncAccountMetricsJob::dispatchSync($id, '2026-07-30', '2026-08-02');
        SyncAccountMetricsJob::dispatchSync($id, '2026-07-30', '2026-08-02');

        $this->assertSame($first, $this->figures(), 'a retry changed or duplicated stored rows');
        $this->assertSame(4, DB::table('metric_sync_runs')->count());
    }

    /** @return array<string, list<string>> */
    private function figures(): array
    {
        $out = [];
        foreach (['daily_metrics', 'entity_daily_metrics'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(function ($row): string {
                $row = (array) $row;
                unset($row['updated_at'], $row['sync_run_id']);

                return json_encode($row, JSON_THROW_ON_ERROR);
            })->all();
        }

        return $out;
    }
}
