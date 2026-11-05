<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Metrics\Jobs\FetchPeriodReachJob;
use App\Support\Queue\QueueContract;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Tests\TestCase;

/**
 * PERF-SYNC-ISOLATION-001 — provider syncs never share a worker with a person waiting on a request.
 *
 * Measured on Production 2026-11-02: one CPU, load average 12, eight provider syncs at once, a live
 * report taking 66 s. The fix is structural (own queue, own serialised supervisor), so the tests
 * assert the structure — by discovery, so a sync job added next year is covered.
 */
final class SyncQueueIsolationTest extends TestCase
{
    private const SYNC_NAMESPACES = [
        'App\\Domains\\Integrations\\',
        'App\\Domains\\Metrics\\',
        'App\\Domains\\Commerce\\',
    ];

    public function test_every_queued_job_in_the_provider_domains_names_the_sync_queue(): void
    {
        $checked = [];

        foreach (array_keys(QueueContract::jobTimeouts()) as $class) {
            if (! array_filter(self::SYNC_NAMESPACES, fn (string $ns) => str_starts_with($class, $ns))) {
                continue;
            }

            $job = $this->instantiate($class);
            $this->assertSame('sync', $job->queue, "{$class} is a provider job and must run on the `sync` queue");
            $checked[] = $class;
        }

        // The five known today; discovery may find more, never fewer.
        $this->assertGreaterThanOrEqual(5, count($checked), implode(', ', $checked));
    }

    public function test_production_serialises_sync_on_its_own_niced_supervisor_beside_default_and_reports(): void
    {
        $sync = config('horizon.environments.production.supervisor-sync');

        $this->assertContains('sync', $sync['queue']);
        $this->assertSame(1, $sync['maxProcesses']);
        $this->assertFalse($sync['balance']);
        $this->assertSame(900, $sync['timeout']);
        $this->assertSame(10, $sync['nice']);

        $this->assertSame(2, config('horizon.environments.production.supervisor-1.maxProcesses'));
        $this->assertSame(['reports'], config('horizon.environments.production.supervisor-reports.queue'));
        $this->assertSame(1, config('horizon.environments.production.supervisor-reports.maxProcesses'));

        // `sync` is on no other production supervisor, or it would not be serialised.
        foreach (['supervisor-1', 'supervisor-reports'] as $name) {
            $merged = array_replace_recursive((array) config("horizon.defaults.{$name}", []), (array) config("horizon.environments.production.{$name}"));
            $this->assertNotContains('sync', $merged['queue']);
        }
    }

    public function test_local_horizon_still_works_one_supervisor_watching_every_queue(): void
    {
        $merged = array_replace_recursive(config('horizon.defaults.supervisor-1'), config('horizon.environments.local.supervisor-1'));

        $this->assertEqualsCanonicalizing(['reports', 'default', 'sync', 'live-refresh'], $merged['queue']);
    }

    public function test_the_queue_timeout_contract_still_holds_with_the_new_supervisors(): void
    {
        $this->assertSame([], QueueContract::violations());
    }

    // ── FetchPeriodReachJob ──────────────────────────────────────────────────────────────────────

    public function test_a_too_large_refusal_is_not_retried_and_does_not_fail(): void
    {
        Log::spy();
        $job = new FetchPeriodReachJob('a', '2026-10-01', '2026-10-30');
        $queued = \Mockery::mock(Job::class);
        $queued->shouldReceive('attempts')->andReturn(1);
        $queued->shouldNotReceive('release');
        $job->setJob($queued);

        $job->handleRefusal(new RuntimeException('Snapchat refused the stats request (E9001): too large'));

        $this->addToAssertionCount(1);
    }

    public function test_a_rate_limit_backs_off_exponentially_and_stops_after_three_attempts(): void
    {
        Log::spy();
        $delays = [];

        foreach ([1, 2, 3] as $attempt) {
            $job = new FetchPeriodReachJob('a', '2026-10-01', '2026-10-30');
            $queued = \Mockery::mock(Job::class);
            $queued->shouldReceive('attempts')->andReturn($attempt);
            $queued->shouldReceive('release')->andReturnUsing(function (int $d) use (&$delays) {
                $delays[] = $d;
            });
            $job->setJob($queued);

            $job->handleRefusal(new RuntimeException('HTTP 429: Too Many Requests'));
        }

        $this->assertSame([60, 240], $delays, 'attempt 3 is the last: no further release');
        $this->assertSame(3, (new FetchPeriodReachJob('a', 'f', 't'))->tries);
    }

    public function test_an_unknown_failure_still_fails_loudly(): void
    {
        $this->expectException(RuntimeException::class);

        (new FetchPeriodReachJob('a', '2026-10-01', '2026-10-30'))->handleRefusal(new RuntimeException('Undefined index: reach'));
    }

    /** @param class-string $class */
    private function instantiate(string $class): object
    {
        $args = [];

        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $p) {
            $type = $p->getType();
            $name = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
            $args[] = match ($name) {
                'int' => 1,
                'array' => [],
                default => 'x',
            };
        }

        return new $class(...$args);
    }
}
