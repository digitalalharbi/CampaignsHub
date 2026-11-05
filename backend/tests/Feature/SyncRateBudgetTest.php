<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Support\ProviderRateBudget;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsSyncEngineWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 item 3 — a budget per provider and per connection. Exhausting one releases the job with a delay: it neither
 * fails nor spins, it does not call the provider, and it does not touch any other connection.
 */
final class SyncRateBudgetTest extends TestCase
{
    use BuildsSyncEngineWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    private ExternalAccount $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSyncEngineWorld('2026-08-02 12:00:00');

        $workspace = $this->bwWorkspace($this->tenant, 'W2');
        $otherConnection = $this->bwConnection($this->tenant, 'meta', $workspace);
        $this->other = $this->bwAccount($otherConnection, 'act_2');
        $project = $this->bwProject($this->tenant, $workspace, 'P2');
        $this->bwBind($this->other, $project);
        $this->bwStructure($this->other, $project);

        config()->set('integrations.rate_budget.window_seconds', 3600);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function job(?ExternalAccount $account = null, array $meta = []): SyncAccountMetricsJob
    {
        return (new SyncAccountMetricsJob((string) ($account ?? $this->account)->id, '2026-07-30', '2026-08-02', $meta))->withFakeQueueInteractions();
    }

    private function handled(SyncAccountMetricsJob $job): SyncAccountMetricsJob
    {
        app()->call([$job, 'handle']);

        return $job;
    }

    private function spend(string $provider, string $connectionId, int $times): void
    {
        $budget = app(ProviderRateBudget::class);
        for ($i = 0; $i < $times; $i++) {
            $budget->beforeRequest($provider, $connectionId);
        }
    }

    public function test_an_exhausted_connection_releases_the_job_until_the_window_turns_and_asks_nothing(): void
    {
        config()->set('integrations.rate_budget.connection_requests', 3);
        $this->spend('meta', (string) $this->connection->id, 3);

        $job = $this->handled($this->job());

        // 12:00:00 → the hour's window ends in exactly 3600 seconds.
        $job->assertReleased(delay: 3600);
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('metric_sync_runs')->count(), 'a deferred job records no attempt, because it made none');
    }

    public function test_one_connections_exhausted_budget_does_not_touch_another_connection(): void
    {
        config()->set('integrations.rate_budget.connection_requests', 6);
        $this->spend('meta', (string) $this->connection->id, 6);

        $this->handled($this->job())->assertReleased();
        $other = $this->handled($this->job($this->other));

        $other->assertNotReleased();
        $this->assertSame('success', $this->lastRun()->status, (string) $this->lastRun()->error);
        $this->assertSame((string) $this->other->id, (string) $this->lastRun()->external_account_id);
    }

    public function test_no_single_connection_can_spend_more_than_its_share_of_the_provider_pool(): void
    {
        // A connection limit above the pool would let one customer drain it; the share caps it at half.
        config()->set('integrations.rate_budget.provider_requests', 10);
        config()->set('integrations.rate_budget.connection_requests', 1000);
        config()->set('integrations.rate_budget.connection_share', 0.5);

        $this->spend('meta', (string) $this->connection->id, 5);

        $this->handled($this->job())->assertReleased();
        $this->handled($this->job($this->other))->assertNotReleased();
    }

    public function test_the_provider_pool_holds_every_connection_of_that_provider_and_no_other_provider(): void
    {
        config()->set('integrations.rate_budget.provider_requests', 4);
        config()->set('integrations.rate_budget.connection_share', 1.0);
        $budget = app(ProviderRateBudget::class);
        $this->spend('meta', 'some-other-connection', 4);

        $this->assertNotNull($budget->delayFor('meta', (string) $this->connection->id));
        $this->assertNull($budget->delayFor('tiktok', (string) $this->connection->id));
    }

    public function test_a_per_provider_limit_overrides_the_default(): void
    {
        config()->set('integrations.rate_budget.provider_requests', 100000);
        config()->set('integrations.rate_budget.connection_requests', 100000);
        config()->set('integrations.rate_budget.connection_share', 1.0);
        config()->set('integrations.rate_budget.providers.meta', 2);
        $this->spend('meta', 'c', 2);

        $this->assertNotNull(app(ProviderRateBudget::class)->delayFor('meta', 'c'));
        $this->assertNull(app(ProviderRateBudget::class)->delayFor('google', 'c'));
    }

    public function test_a_budget_spent_mid_window_stops_the_read_keeps_the_checkpoint_and_releases(): void
    {
        $this->handled($this->job());
        $before = $this->checkpointFacts();
        $this->assertNotSame([], $before);

        Carbon::setTestNow(Carbon::parse('2026-08-02 13:00:00', 'UTC'));
        config()->set('integrations.rate_budget.connection_requests', 1);
        $this->script = ['mode' => 'page2_500'];
        $this->asked = [];

        $job = $this->handled($this->job());

        $this->assertCount(1, $this->asked, 'the request past the budget left the process');
        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('budget', (string) $run->error);
        $this->assertSame($before, $this->checkpointFacts());
        $job->assertReleased(delay: 3600);
    }

    public function test_retry_after_on_a_429_holds_that_connection_only_and_the_job_comes_back_after_it(): void
    {
        $this->script = ['mode' => 'page2_429', 'headers' => ['Retry-After' => '900']];

        $job = $this->handled($this->job());

        $this->assertSame('failed', $this->lastRun()->status);
        $job->assertReleased(delay: 900);

        $budget = app(ProviderRateBudget::class);
        $this->assertSame(900, $budget->delayFor('meta', (string) $this->connection->id));
        $this->assertNull($budget->delayFor('meta', (string) $this->other->provider_connection_id));

        Carbon::setTestNow(Carbon::now()->addSeconds(901));
        $this->assertNull($budget->delayFor('meta', (string) $this->connection->id));
    }

    public function test_a_hold_longer_than_the_ceiling_is_capped(): void
    {
        config()->set('integrations.rate_budget.max_hold_seconds', 1800);
        $this->script = ['mode' => 'page2_429', 'headers' => ['Retry-After' => '86400']];

        $this->handled($this->job())->assertReleased(delay: 1800);
    }

    public function test_a_successful_answer_clears_the_hold_before_it_would_have_expired(): void
    {
        $budget = app(ProviderRateBudget::class);
        $budget->afterResponse('meta', 'c1', new Response(429, ['Retry-After' => '900']));
        $budget->afterResponse('meta', 'c2', new Response(429, ['Retry-After' => '900']));
        $this->assertSame(900, $budget->delayFor('meta', 'c1'));

        // No time passes: the provider answered, so what it said before no longer holds. The other connection's hold stands.
        $budget->afterResponse('meta', 'c1', new Response(200));

        $this->assertNull($budget->delayFor('meta', 'c1'));
        $this->assertSame(900, $budget->delayFor('meta', 'c2'));
    }

    public function test_metas_documented_usage_header_holds_the_connection_when_meta_says_access_is_being_regained(): void
    {
        $usage = json_encode(['act_1' => [['type' => 'ads_insights', 'call_count' => 100, 'total_cputime' => 50, 'total_time' => 50, 'estimated_time_to_regain_access' => 5]]], JSON_THROW_ON_ERROR);
        $this->script = ['mode' => 'ok', 'headers' => ['X-Business-Use-Case-Usage' => $usage]];

        $this->handled($this->job());

        $budget = app(ProviderRateBudget::class);
        $this->assertSame(300, $budget->delayFor('meta', (string) $this->connection->id));
        $this->assertNull($budget->delayFor('meta', (string) $this->other->provider_connection_id));
        $this->handled($this->job())->assertReleased(delay: 300);
    }

    public function test_metas_usage_at_the_ceiling_holds_for_five_minutes_when_it_states_no_regain_time(): void
    {
        $usage = json_encode(['act_1' => [['type' => 'ads_insights', 'call_count' => 100, 'total_cputime' => 40, 'total_time' => 30, 'estimated_time_to_regain_access' => 0]]], JSON_THROW_ON_ERROR);
        $response = new Response(200, ['X-Business-Use-Case-Usage' => $usage]);

        app(ProviderRateBudget::class)->afterResponse('meta', 'c1', $response);

        $this->assertSame(300, app(ProviderRateBudget::class)->delayFor('meta', 'c1'));
    }

    public function test_usage_below_the_ceiling_changes_nothing(): void
    {
        $usage = json_encode(['act_1' => [['type' => 'ads_insights', 'call_count' => 80, 'total_cputime' => 40, 'total_time' => 30, 'estimated_time_to_regain_access' => 0]]], JSON_THROW_ON_ERROR);
        $this->script = ['mode' => 'ok', 'headers' => ['X-Business-Use-Case-Usage' => $usage]];

        $this->handled($this->job());

        $this->assertNull(app(ProviderRateBudget::class)->delayFor('meta', (string) $this->connection->id));
    }

    public function test_the_usage_header_is_read_from_meta_only(): void
    {
        $usage = json_encode(['x' => [['call_count' => 100, 'estimated_time_to_regain_access' => 5]]], JSON_THROW_ON_ERROR);
        $response = new Response(200, ['X-Business-Use-Case-Usage' => $usage]);

        $budget = app(ProviderRateBudget::class);
        $budget->afterResponse('tiktok', 'c1', $response);

        $this->assertNull($budget->delayFor('tiktok', 'c1'));
    }

    public function test_the_defaults_cannot_be_reached_by_one_sync_worker(): void
    {
        /*
         * The defaults are a brake on a runaway, not a fit to any provider's quota. `supervisor-sync` runs one process, so requests
         * are sequential: at 250 ms a round trip one worker makes at most 14,400 an hour. Both limits sit above that.
         */
        $this->assertSame(1, config('horizon.environments.production.supervisor-sync.maxProcesses'));
        $budget = app(ProviderRateBudget::class);
        $this->assertGreaterThan(14_400, $budget->connectionLimit('meta'));
        $this->assertGreaterThan(14_400, $budget->providerLimit('meta'));
        $this->assertTrue((bool) config('integrations.rate_budget.enabled'));
    }

    public function test_a_switched_off_budget_counts_and_refuses_nothing(): void
    {
        config()->set('integrations.rate_budget.enabled', false);
        config()->set('integrations.rate_budget.connection_requests', 1);
        $this->spend('meta', (string) $this->connection->id, 5);

        $this->handled($this->job())->assertNotReleased();
        $this->assertSame('success', $this->lastRun()->status);
    }

    public function test_a_broken_cache_does_not_stop_a_sync(): void
    {
        // A budget is a courtesy to the provider, not a permission: losing it must not lose the sync.
        config()->set('integrations.rate_budget.connection_requests', 1);
        config()->set('cache.stores.broken', ['driver' => 'redis', 'connection' => 'does-not-exist']);
        config()->set('integrations.rate_budget.cache_store', 'broken');

        $this->handled($this->job())->assertNotReleased();
        $this->assertSame('success', $this->lastRun()->status);
    }

    public function test_the_requests_a_run_made_are_written_on_it(): void
    {
        $this->handled($this->job());

        $this->assertGreaterThanOrEqual(3, count($this->asked));
        $this->assertSame(count($this->asked), $this->lastRun()->meta['provider_requests'] ?? null);
    }

    public function test_a_campaign_write_is_counted_but_never_refused_by_the_sync_budget(): void
    {
        config()->set('integrations.rate_budget.connection_requests', 1);
        $budget = app(ProviderRateBudget::class);

        // Outside a sync job the counter moves and nothing throws: an operator's write is not a sweep.
        $this->spend('meta', (string) $this->connection->id, 5);

        $this->assertNotNull($budget->delayFor('meta', (string) $this->connection->id));
    }
}
