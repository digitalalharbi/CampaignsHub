<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Console\SyncAdPlatformsCommand;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — the sweep can give each account its own second of the half hour, and does not unless asked.
 */
final class SyncSweepSpreadTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
        $this->buildWorld(1);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /** @return array<string, array{delay: int, meta: array<string, mixed>, from: string, to: string}> account id => what was queued */
    private function sweep(array $options = []): array
    {
        $this->artisan('integrations:sync', $options)->assertSuccessful();

        $out = [];
        foreach (Queue::pushed(SyncAccountMetricsJob::class) as $job) {
            $meta = (new \ReflectionProperty($job, 'meta'))->getValue($job);
            $out[$job->accountId] = ['delay' => (int) ($job->delay ?? 0), 'meta' => $meta, 'from' => $job->from, 'to' => $job->to, 'queue' => $job->queue];
        }
        ksort($out);

        return $out;
    }

    public function test_by_default_every_account_is_queued_at_once_as_before(): void
    {
        $queued = $this->sweep();

        // Six bound accounts and the one bound to the other project; never the deselected one.
        $this->assertCount(7, $queued);
        $this->assertArrayNotHasKey((string) $this->deselectedAccount->id, $queued);
        foreach ($queued as $job) {
            $this->assertSame(0, $job['delay']);
            $this->assertSame('scheduler', $job['meta']['source']);
            $this->assertArrayNotHasKey('spread_seconds', $job['meta']);
            $this->assertSame(Carbon::now()->toIso8601String(), $job['meta']['queued_at']);
        }
    }

    public function test_a_spread_gives_each_account_its_own_fixed_second_and_changes_nothing_else(): void
    {
        $plain = $this->sweep();
        Queue::fake();
        $spread = $this->sweep(['--spread' => 20]);

        $this->assertSame(array_keys($plain), array_keys($spread), 'a spread changed WHICH accounts are swept');

        foreach ($spread as $account => $job) {
            $this->assertSame(SyncAdPlatformsCommand::offsetFor($account, 1200), $job['delay']);
            $this->assertGreaterThanOrEqual(0, $job['delay']);
            $this->assertLessThan(1200, $job['delay']);
            // Same window, same queue: only the moment moved.
            $this->assertSame([$plain[$account]['from'], $plain[$account]['to'], 'sync'], [$job['from'], $job['to'], $job['queue']]);
            if ($job['delay'] > 0) {
                $this->assertSame($job['delay'], $job['meta']['spread_seconds']);
                $this->assertSame(Carbon::now()->addSeconds($job['delay'])->toIso8601String(), $job['meta']['queued_at'], 'the wait must be counted from when the job was due');
            }
        }
        $this->assertGreaterThan(1, count(array_unique(array_column($spread, 'delay'))), 'every account landed on the same second');

        // Thirty minutes later the same account lands on the same second: its cadence is still thirty minutes.
        Carbon::setTestNow(Carbon::now()->addMinutes(30));
        Queue::fake();
        $this->assertSame(array_column($spread, 'delay'), array_column($this->sweep(['--spread' => 20]), 'delay'));
    }

    public function test_the_configured_spread_is_used_when_no_option_is_given_and_is_capped(): void
    {
        config(['integrations.sync.sweep_spread_minutes' => 500]);
        $configured = $this->sweep();
        $cap = SyncAdPlatformsCommand::MAX_SPREAD_MINUTES * 60;

        foreach ($configured as $account => $job) {
            $this->assertSame(SyncAdPlatformsCommand::offsetFor($account, $cap), $job['delay']);
        }

        // An operator who wants it now says so, whatever is configured.
        Queue::fake();
        foreach ($this->sweep(['--spread' => 0]) as $job) {
            $this->assertSame(0, $job['delay']);
        }
    }
}
