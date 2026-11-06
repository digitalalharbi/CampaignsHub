<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Support\ProviderRateBudget;
use App\Domains\Metrics\Exceptions\TransientSyncFailure;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Metrics\Models\MetricSyncRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsSyncEngineWorld;
use Tests\TestCase;

/**
 * INTEG-SYNC-ENGINE-001 item 4 — a job that exhausts its retries ends on the run ledger as a dead letter, with a category and no secret;
 * a platform admin can list them and re-drive one, and the re-drive re-proves the binding and cannot run twice.
 */
final class SyncDeadLetterTest extends TestCase
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

    private function admin(): User
    {
        $user = User::factory()->create(['is_platform_admin' => true]);
        $user->forceFill(['is_platform_admin' => true])->save();

        return $user;
    }

    private function work(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'sync', '--once' => true, '--sleep' => 0]);
    }

    private function deadLetter(array $meta = []): MetricSyncRun
    {
        $job = new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02', $meta);
        $job->failed(new TransientSyncFailure('provider_unavailable'));

        return MetricSyncRun::withoutGlobalScopes()->whereNotNull('dead_lettered_at')->latest('dead_lettered_at')->firstOrFail();
    }

    public function test_through_a_real_worker_three_5xx_attempts_end_in_one_dead_letter_with_backoff_between_them(): void
    {
        $this->script = ['mode' => 'first_503'];
        SyncAccountMetricsJob::dispatch((string) $this->account->id, '2026-07-30', '2026-08-02')->onConnection('database');

        $this->work();
        $this->assertSame(1, DB::table('metric_sync_runs')->count());
        $this->assertSame(1, DB::table('jobs')->count(), 'the job is waiting for its next attempt');
        $this->assertSame(0, DB::table('metric_sync_runs')->whereNotNull('dead_lettered_at')->count());
        $this->assertSame(Carbon::now()->addSeconds(60)->getTimestamp(), (int) DB::table('jobs')->value('available_at'));

        Carbon::setTestNow(Carbon::now()->addSeconds(61));
        $this->work();
        $this->assertSame(2, DB::table('metric_sync_runs')->count());
        $this->assertSame(Carbon::now()->addSeconds(300)->getTimestamp(), (int) DB::table('jobs')->value('available_at'));

        Carbon::setTestNow(Carbon::now()->addSeconds(301));
        $this->work();
        $this->assertSame(3, DB::table('metric_sync_runs')->count());
        $this->assertSame(0, DB::table('jobs')->count(), 'three exceptions and the job is done with');

        $dead = MetricSyncRun::withoutGlobalScopes()->whereNotNull('dead_lettered_at')->get();
        $this->assertCount(1, $dead);
        $this->assertSame('provider_unavailable', $dead[0]->dead_letter_category);
        $this->assertSame('failed', $dead[0]->status);
        $this->assertSame(3, (int) $dead[0]->attempts);
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_a_refusal_nobody_can_retry_is_recorded_as_it_always_was_and_is_not_a_dead_letter(): void
    {
        $this->script = ['mode' => 'first_401'];
        SyncAccountMetricsJob::dispatch((string) $this->account->id, '2026-07-30', '2026-08-02')->onConnection('database');

        $this->work();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('failed', $this->lastRun()->status);
        $this->assertSame(0, DB::table('metric_sync_runs')->whereNotNull('dead_lettered_at')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_budget_deferrals_do_not_use_up_the_attempts_a_failure_gets(): void
    {
        /*
         * `release()` counts as an attempt, so `tries` alone would dead-letter a job for being patient. `maxExceptions` is what counts
         * real failures: five deferrals, then a success.
         */
        config()->set('integrations.rate_budget.connection_requests', 1);
        app(ProviderRateBudget::class)->beforeRequest('meta', (string) $this->connection->id);
        SyncAccountMetricsJob::dispatch((string) $this->account->id, '2026-07-30', '2026-08-02')->onConnection('database');

        for ($i = 0; $i < 5; $i++) {
            $this->work();
            Carbon::setTestNow(Carbon::now()->addSeconds(10));
            app(ProviderRateBudget::class);
            DB::table('jobs')->update(['available_at' => Carbon::now()->getTimestamp()]);
        }

        $this->assertSame(0, DB::table('metric_sync_runs')->count());
        $this->assertSame(1, DB::table('jobs')->count());

        Carbon::setTestNow(Carbon::now()->addHours(2));
        DB::table('jobs')->update(['available_at' => Carbon::now()->getTimestamp()]);
        config()->set('integrations.rate_budget.connection_requests', 15000);
        $this->work();

        $this->assertSame('success', $this->lastRun()->status);
        $this->assertSame(0, DB::table('metric_sync_runs')->whereNotNull('dead_lettered_at')->count());
    }

    public function test_a_worker_that_timed_out_closes_the_run_it_left_open(): void
    {
        $open = new MetricSyncRun;
        $open->forceFill([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'connection_id' => $this->connection->id,
            'external_account_id' => $this->account->id, 'provider' => 'meta', 'status' => 'running',
            'window_start' => '2026-07-30', 'window_end' => '2026-08-02', 'attempts' => 1, 'started_at' => Carbon::now(),
        ])->save();

        (new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02'))
            ->failed(new TimeoutExceededException('App\\Job has timed out.'));

        $open->refresh();
        $this->assertSame('failed', $open->status);
        $this->assertNotNull($open->finished_at);
        $this->assertSame('worker_timeout', $open->dead_letter_category);
        $this->assertSame(1, DB::table('metric_sync_runs')->count());
    }

    public function test_a_job_that_died_before_any_run_still_leaves_one(): void
    {
        (new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02'))
            ->failed(new MaxAttemptsExceededException('too many'));

        $run = $this->lastRun();
        $this->assertSame('failed', $run->status);
        $this->assertSame('retries_exhausted', $run->dead_letter_category);
        $this->assertSame('2026-07-30', $run->window_start->toDateString());
        $this->assertSame((string) $this->project->id, (string) $run->project_id);
    }

    public function test_a_dead_letter_never_stores_a_secret(): void
    {
        (new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02'))
            ->failed(new \RuntimeException('cURL error 28 for https://graph.facebook.com/x?access_token=SEKRET-TOKEN&after=2'));

        $run = $this->lastRun();
        $this->assertSame('job_exception', $run->dead_letter_category);
        $this->assertStringNotContainsString('SEKRET-TOKEN', json_encode($run->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_an_account_unlinked_meanwhile_leaves_no_dead_letter(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);

        (new SyncAccountMetricsJob((string) $this->account->id, '2026-07-30', '2026-08-02'))->failed(new \RuntimeException('x'));

        $this->assertSame(0, DB::table('metric_sync_runs')->count());
    }

    // ── The API ───────────────────────────────────────────────────────────────────────────────

    public function test_only_a_platform_admin_reaches_the_api(): void
    {
        $run = $this->deadLetter();

        $this->getJson('/api/v1/admin/sync-dead-letters')->assertUnauthorized();
        $this->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertUnauthorized();

        $operator = $this->bwOperator($this->tenant);
        $this->actingAs($operator)->getJson('/api/v1/admin/sync-dead-letters')->assertForbidden();
        $this->actingAs($operator)->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertForbidden();

        $this->assertNull($run->fresh()->redriven_at);
    }

    public function test_the_list_carries_the_facts_and_never_the_payload(): void
    {
        $run = $this->deadLetter();
        $run->forceFill(['meta' => ['secret_in_meta' => 'SEKRET-TOKEN']])->save();

        $response = $this->actingAs($this->admin())->getJson('/api/v1/admin/sync-dead-letters')->assertOk();

        $row = collect($response->json('data.dead_letters'))->firstWhere('id', $run->id);
        $this->assertSame('provider_unavailable', $row['category']);
        $this->assertSame('meta', $row['provider']);
        $this->assertSame('2026-07-30', $row['window_start']);
        $this->assertArrayNotHasKey('meta', $row);
        $this->assertStringNotContainsString('SEKRET-TOKEN', (string) $response->getContent());
    }

    public function test_a_redrive_requeues_the_same_window_once_and_is_audited(): void
    {
        Bus::fake([SyncAccountMetricsJob::class]);
        $run = $this->deadLetter();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertStatus(202)->assertJsonPath('data.outcome', 'queued');

        Bus::assertDispatched(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $job): bool => $job->accountId === (string) $this->account->id && $job->from === '2026-07-30' && $job->to === '2026-08-02');
        $this->assertNotNull($run->fresh()->redriven_at);

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'integrations.sync.dead_letter_redriven')->firstOrFail();
        $this->assertSame((string) $run->id, (string) $audit->entity_id);
        $this->assertSame($admin->id, $audit->user_id);
        $this->assertSame((string) $this->tenant->id, (string) $audit->tenant_id);

        // Again: the answer says so, and nothing is queued twice.
        $this->actingAs($admin)->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertOk()->assertJsonPath('data.outcome', 'already_redriven');
        Bus::assertDispatchedTimes(SyncAccountMetricsJob::class, 1);
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'integrations.sync.dead_letter_redriven')->count());
    }

    public function test_a_redrive_re_proves_the_binding(): void
    {
        Bus::fake([SyncAccountMetricsJob::class]);
        $run = $this->deadLetter();
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);

        $this->actingAs($this->admin())->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertStatus(409)->assertJsonPath('data.outcome', 'binding_gone');

        Bus::assertNotDispatched(SyncAccountMetricsJob::class);
        $this->assertNull($run->fresh()->redriven_at);
    }

    public function test_a_redrive_refuses_an_account_now_bound_to_another_project(): void
    {
        Bus::fake([SyncAccountMetricsJob::class]);
        $run = $this->deadLetter();
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => false]);
        $this->bwBind($this->account, $this->bwProject($this->tenant, $this->bwWorkspace($this->tenant, 'W2'), 'P2'));

        $this->actingAs($this->admin())->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertStatus(409);

        Bus::assertNotDispatched(SyncAccountMetricsJob::class);
    }

    public function test_an_ordinary_failed_run_cannot_be_redriven_through_this_door(): void
    {
        $this->script = ['mode' => 'first_401'];
        SyncAccountMetricsJob::dispatchSync((string) $this->account->id, '2026-07-30', '2026-08-02');
        $run = MetricSyncRun::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertNull($run->dead_lettered_at);

        Bus::fake([SyncAccountMetricsJob::class]);
        $this->actingAs($this->admin())->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertNotFound();

        Bus::assertNotDispatched(SyncAccountMetricsJob::class);
        $this->assertNull($run->fresh()->redriven_at);
    }

    public function test_the_redrive_is_throttled(): void
    {
        Bus::fake([SyncAccountMetricsJob::class]);
        $run = $this->deadLetter();
        $admin = $this->admin();

        $last = null;
        for ($i = 0; $i < 31; $i++) {
            $last = $this->actingAs($admin)->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive");
        }

        $last->assertStatus(429);
    }

    public function test_a_redriven_job_that_runs_lands_the_window_and_writes_the_same_rows(): void
    {
        $run = $this->deadLetter();
        Carbon::setTestNow(Carbon::now()->addSecond());

        $this->actingAs($this->admin())->postJson("/api/v1/admin/sync-dead-letters/{$run->id}/redrive")->assertStatus(202);

        $this->assertSame('success', $this->lastRun()->status, (string) $this->lastRun()->error);
        $this->assertSame('2026-08-02', $this->checkpointFacts()['to'] ?? null);
        $this->assertSame(1, DB::table('daily_metrics')->where('metric_key', 'spend')->count());
    }
}
