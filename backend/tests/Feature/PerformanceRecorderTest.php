<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Ops\Console\PerfCheckCommand;
use App\Domains\Ops\Console\PerfSnapshotCommand;
use App\Domains\Ops\Performance\PerformanceRecorder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/** PERF-RECOVERY-001 — the measurements, and the two rules around them: harmless and free of customer data. */
final class PerformanceRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.perf_recording' => true]);
    }

    public function test_a_duration_lands_in_the_smallest_bucket_that_holds_it(): void
    {
        $this->assertSame(50, PerformanceRecorder::bucketFor(12));
        $this->assertSame(2500, PerformanceRecorder::bucketFor(2500));
        $this->assertSame(PerformanceRecorder::OVERFLOW, PerformanceRecorder::bucketFor(90000));
    }

    public function test_a_percentile_is_the_upper_bound_of_its_bucket(): void
    {
        $buckets = [100 => 90, 5000 => 10];

        $this->assertSame(100, PerformanceRecorder::percentile($buckets, 0.5));
        $this->assertSame(5000, PerformanceRecorder::percentile($buckets, 0.95));
        $this->assertNull(PerformanceRecorder::percentile([], 0.95));
    }

    public function test_a_redis_failure_never_reaches_the_request(): void
    {
        Redis::shouldReceive('hincrby')->andThrow(new RuntimeException('redis is down'));

        $recorder = new PerformanceRecorder;
        $recorder->noteRequest('GET api/v1/health', 12.0);
        $recorder->noteQuery('select * from t where id = ?', 500.0, 'http');

        $this->addToAssertionCount(1);
    }

    public function test_a_public_report_is_filed_under_its_pattern_and_never_its_token(): void
    {
        $seen = [];
        Redis::shouldReceive('hincrby')->andReturnUsing(function ($key, $field) use (&$seen) {
            $seen[] = $field;

            return 1;
        });
        Redis::shouldReceive('expire')->andReturn(true);

        $this->getJson('/api/v1/reports/shared/TOKENTOKENTOKENTOKEN99/live');

        $this->assertNotEmpty($seen);
        $this->assertStringNotContainsString('TOKENTOKENTOKENTOKEN99', implode(' ', $seen));
        $this->assertStringContainsString('{token}', implode(' ', $seen));
    }

    public function test_a_route_is_degraded_only_over_its_limit_with_enough_requests(): void
    {
        $this->assertSame(['GET slow' => 5000], PerfCheckCommand::degraded([
            'GET slow' => ['count' => 100, 'buckets' => [5000 => 100]],
            'GET rare' => ['count' => 3, 'buckets' => [30000 => 3]],
            'GET fast' => ['count' => 100, 'buckets' => [100 => 100]],
        ]));
    }

    public function test_a_running_statement_loses_its_values(): void
    {
        $this->assertSame(
            'select * from users where email = ? and id = ?',
            PerfSnapshotCommand::withoutLiterals("select * from users\n where email = 'a@b.c' and id = 42"),
        );
    }

    public function test_the_snapshot_runs_on_an_empty_install(): void
    {
        Redis::shouldReceive('hgetall')->andReturn([]);
        Redis::shouldReceive('info')->andReturn(['used_memory_human' => '1M']);

        $this->assertSame(0, Artisan::call('ops:perf-snapshot', ['--hours' => 1]));
        $this->assertStringContainsString('SLOWEST ROUTES', Artisan::output());
    }
}
