<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Reports\Jobs\RefreshLiveReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportShareAccessLog;
use App\Domains\Reports\Services\LiveReportCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsLiveReportWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — a public live link answers from its last confirmed build, and only when it may.
 *
 * Three families of proof:
 *
 *   - GATES. Every access check still runs before a stored answer: revoked, paused, expired, password,
 *     the per-IP throttle, live mode, and the access log is written on a served read too.
 *   - KEY. Each input the build reads that an operator can change is in the key: after a change the
 *     next read is a fresh build that equals what the store-off endpoint answers for the new state, so
 *     the old answer is never served. Two links with identical settings never share an answer.
 *   - HONESTY. A served copy keeps its build's `generated_at`; the per-platform ages are re-read at serve
 *     time; nothing older than the bound is served; a failed refresh keeps the last answer and says why;
 *     an error, an empty payload or an oversize one is never stored; refreshes go on `live-refresh`, one
 *     per key.
 */
final class LiveReportLastConfirmedTest extends TestCase
{
    use BuildsLiveReportWorld;
    use RefreshDatabase;

    private ReportShare $share;

    private string $raw;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $this->freezeWorld();
        $this->buildWorld();
        [$this->share, $this->raw] = $this->liveLink();
        Cache::flush();
        config(['reports.live_cache.enabled' => true]);
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, string> $headers */
    private function read(string $raw, string $query = '', array $headers = []): TestResponse
    {
        return $this->getJson("/api/v1/reports/shared/{$raw}/live".($query === '' ? '' : '?'.$query), $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function data(string $raw, string $query = '', array $headers = []): array
    {
        return (array) $this->read($raw, $query, $headers)->assertOk()->json('data');
    }

    /**
     * What the endpoint answers with the store OFF — the truth a served answer is held to.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function truth(string $raw, string $query = '', array $headers = []): array
    {
        config(['reports.live_cache.enabled' => false]);
        $data = $this->data($raw, $query, $headers);
        config(['reports.live_cache.enabled' => true]);
        $this->assertArrayNotHasKey('delivery', $data, 'with the store off the payload carries no delivery block');

        return $data;
    }

    /** Warm the key, and prove the next identical read is served from it (so a later miss is the change's doing). */
    private function warm(string $raw, string $query = '', array $headers = []): array
    {
        $first = $this->data($raw, $query, $headers);
        $this->assertSame('fresh', $first['delivery']['source']);
        $second = $this->data($raw, $query, $headers);
        $this->assertSame('last_confirmed', $second['delivery']['source'], 'the warm read must be served, or the change below proves nothing');

        return $second;
    }

    /**
     * The read after a change: a fresh build, byte-equal to the store-off answer for the NEW state.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function assertServesTheNewState(string $raw, string $query = '', array $headers = []): array
    {
        $truth = $this->truth($raw, $query, $headers);
        $served = $this->data($raw, $query, $headers);
        $this->assertSame('fresh', $served['delivery']['source'], 'a change must never be answered from the old build');
        unset($served['delivery']);
        $this->assertSame(json_encode($truth), json_encode($served));

        return $served;
    }

    /** @param array<string, mixed> $changes */
    private function changeShare(array $changes): void
    {
        $share = ReportShare::withoutGlobalScopes()->findOrFail($this->share->getKey());
        $share->forceFill($changes)->save();
    }

    /** @param array<string, mixed> $settings */
    private function changeSettings(array $settings): void
    {
        $share = ReportShare::withoutGlobalScopes()->findOrFail($this->share->getKey());
        $share->forceFill(['settings' => array_replace((array) $share->settings, $settings)])->save();
    }

    private static function strip(array $payload): array
    {
        unset($payload['delivery']);

        return $payload;
    }

    // ── off ──────────────────────────────────────────────────────────────────────────────────────

    public function test_with_the_store_off_nothing_is_kept_and_no_delivery_block_is_sent(): void
    {
        config(['reports.live_cache.enabled' => false]);
        $data = $this->data($this->raw);

        $this->assertArrayNotHasKey('delivery', $data);
        $this->assertSame([], Cache::get('live-report:index:'.$this->share->getKey(), []));
    }

    // ── gates ────────────────────────────────────────────────────────────────────────────────────

    public function test_a_revoked_link_answers_404_at_once_although_its_build_is_stored(): void
    {
        $this->warm($this->raw);
        $this->changeShare(['revoked_at' => Carbon::now()]);

        $this->read($this->raw)->assertNotFound();
    }

    public function test_a_paused_link_answers_404_at_once_although_its_build_is_stored(): void
    {
        $this->warm($this->raw);
        $this->changeShare(['paused_at' => Carbon::now()]);

        $this->read($this->raw)->assertNotFound();
    }

    public function test_an_expired_link_answers_404_although_its_build_is_stored(): void
    {
        $this->changeShare(['expires_at' => Carbon::now()->addSeconds(30)]);
        $this->warm($this->raw);
        Carbon::setTestNow(Carbon::now()->addSeconds(31));

        $this->read($this->raw)->assertNotFound();
    }

    public function test_the_password_is_asked_on_every_read_although_the_build_is_stored(): void
    {
        $this->changeShare(['password_hash' => Hash::make('s3cret')]);
        $this->warm($this->raw, '', ['X-Report-Password' => 's3cret']);

        $this->read($this->raw)->assertStatus(401);
        $this->read($this->raw, '', ['X-Report-Password' => 'wrong'])->assertStatus(401);
        $this->assertSame('last_confirmed', $this->data($this->raw, '', ['X-Report-Password' => 's3cret'])['delivery']['source']);
    }

    public function test_a_link_switched_to_snapshot_is_refused_as_live_although_its_live_build_is_stored(): void
    {
        $this->warm($this->raw);
        $this->changeShare(['mode' => 'snapshot']);

        $this->read($this->raw)->assertStatus(409);
    }

    public function test_the_per_ip_throttle_still_counts_served_reads(): void
    {
        $this->warm($this->raw);
        for ($i = 2; $i < 60; $i++) {
            $this->read($this->raw)->assertOk();
        }

        $this->read($this->raw)->assertStatus(429);
    }

    public function test_a_served_read_is_logged_like_any_other_with_the_readers_ip(): void
    {
        $this->warm($this->raw);
        $before = ReportShareAccessLog::query()->where('share_id', $this->share->getKey())->where('detail', 'live')->count();

        $this->data($this->raw);

        $logs = ReportShareAccessLog::query()->where('share_id', $this->share->getKey())->where('detail', 'live')->get();
        $this->assertSame($before + 1, $logs->count());
        $this->assertSame('127.0.0.1', (string) $logs->last()->ip);
    }

    // ── the key: each dimension ──────────────────────────────────────────────────────────────────

    public function test_dimension_link_settings(): void
    {
        $before = $this->warm($this->raw);
        $this->changeSettings(['kpi_labels' => ['spend' => 'Budget used']]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotEquals($before['kpi_labels'], $after['kpi_labels']);
    }

    public function test_dimension_scope_and_its_ceiling(): void
    {
        $before = $this->warm($this->raw);
        $scope = (array) $this->share->scope;
        $scope['earliest'] = Carbon::parse($scope['earliest'])->addDays(3)->toDateString();
        $this->changeShare(['scope' => $scope]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame($before['available']['earliest'], $after['available']['earliest']);
    }

    public function test_dimension_account_ceiling(): void
    {
        $before = $this->warm($this->raw);
        $scope = (array) $this->share->scope;
        $scope['account_ids'] = [(string) $this->boundAccounts[0]->id];
        $this->changeShare(['scope' => $scope]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before['totals']), json_encode($after['totals']));
    }

    public function test_dimension_section_settings(): void
    {
        $before = $this->warm($this->raw);
        $this->changeSettings(['sections' => ['budget' => false, 'objective_breakdown' => false]]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before['sections']), json_encode($after['sections']));
    }

    public function test_dimension_report_section_choices_on_the_report_row(): void
    {
        $this->warm($this->raw);
        $report = Report::withoutGlobalScopes()->findOrFail($this->share->report_id);
        $report->forceFill(['config' => array_replace((array) $report->config, ['sections' => ['funnel' => false]])])->save();

        $this->assertServesTheNewState($this->raw);
    }

    public function test_dimension_form(): void
    {
        $before = $this->warm($this->raw);
        $this->changeShare(['form' => 'executive_summary']);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before), json_encode($after));
    }

    public function test_dimension_generic_name_switches(): void
    {
        $before = $this->warm($this->raw);
        $this->changeSettings(['generic_names' => ['campaign', 'content']]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before['campaign_rows']), json_encode($after['campaign_rows']));
    }

    public function test_dimension_hide_flags(): void
    {
        $before = $this->warm($this->raw);
        $this->changeShare(['hide_spend' => ! $this->share->hide_spend]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before), json_encode($after));
    }

    public function test_dimension_period(): void
    {
        $this->warm($this->raw, 'from=2026-10-05&to=2026-10-12');

        $other = $this->assertServesTheNewState($this->raw, 'from=2026-10-06&to=2026-10-12');
        $this->assertSame('2026-10-06', $other['period']['from']);
    }

    public function test_dimension_filters_in_the_query(): void
    {
        $this->warm($this->raw);

        $this->assertServesTheNewState($this->raw, 'providers[]=meta');
    }

    public function test_a_query_the_page_never_sends_is_answered_fresh_and_never_kept(): void
    {
        $this->assertSame('fresh', $this->data($this->raw, 'utm=1')['delivery']['source']);
        $this->assertSame('fresh', $this->data($this->raw, 'utm=1')['delivery']['source']);
    }

    public function test_dimension_language(): void
    {
        $this->warm($this->raw, '', ['Accept-Language' => 'ar']);

        $this->assertServesTheNewState($this->raw, '', ['Accept-Language' => 'en']);
    }

    public function test_dimension_report_language(): void
    {
        $this->warm($this->raw);
        $report = Report::withoutGlobalScopes()->findOrFail($this->share->report_id);
        $report->forceFill(['config' => array_replace((array) $report->config, ['locale' => 'en'])])->save();

        $this->assertServesTheNewState($this->raw);
    }

    public function test_dimension_bound_account_visibility_a_deselected_account_stops_showing(): void
    {
        $before = $this->warm($this->raw);
        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $this->project->id)
            ->where('external_account_id', $this->boundAccounts[0]->id)
            ->update(['is_active' => false]);

        $after = $this->assertServesTheNewState($this->raw);
        $this->assertNotSame(json_encode($before['totals']), json_encode($after['totals']));
    }

    public function test_dimension_bound_account_visibility_a_new_binding_takes_effect(): void
    {
        $this->warm($this->raw);
        ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('external_account_id', $this->deselectedAccount->id)
            ->where('project_id', $this->project->id)
            ->update(['is_active' => true]);

        $this->assertServesTheNewState($this->raw);
    }

    public function test_dimension_the_day_a_rolling_window_is_anchored_on(): void
    {
        config(['reports.live_cache.max_age_seconds' => 86400]);
        $this->warm($this->raw);
        // 09:00 UTC → 21:30 UTC is 00:30 the next day in Riyadh: the same build would be «today» for the wrong day.
        Carbon::setTestNow(Carbon::now()->addHours(12)->addMinutes(30));

        $this->assertSame('fresh', $this->data($this->raw)['delivery']['source']);
    }

    public function test_two_links_with_identical_settings_never_share_an_answer(): void
    {
        $first = $this->warm($this->raw);
        [$twin, $twinRaw] = $this->liveLink();

        $second = $this->data($twinRaw);

        $this->assertSame('fresh', $second['delivery']['source'], 'the twin link was answered from the other link\'s build');
        $key = static fn (array $p): array => array_values(array_filter(array_map(static fn (array $r): mixed => $r['content_key'] ?? null, (array) ($p['ads_roster'] ?? []))));
        $this->assertNotSame([], $key($first));
        $this->assertSame([], array_values(array_intersect($key($first), $key($second))), 'content keys are per link; a shared answer would hand one link the other\'s keys');
        $this->assertNotSame($this->share->getKey(), $twin->getKey());
    }

    // ── honesty ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_first_read_builds_and_returns_the_real_answer(): void
    {
        $truth = $this->truth($this->raw);
        $first = $this->data($this->raw);

        $this->assertSame('fresh', $first['delivery']['source']);
        $this->assertSame(json_encode($truth), json_encode(self::strip($first)));
    }

    public function test_a_served_copy_keeps_its_build_time_and_says_it_is_the_last_confirmed_one(): void
    {
        $built = $this->data($this->raw);
        $generated = $built['freshness_summary']['generated_at'];
        Carbon::setTestNow(Carbon::now()->addSeconds(60));

        $served = $this->data($this->raw);

        $this->assertSame('last_confirmed', $served['delivery']['source']);
        $this->assertFalse($served['delivery']['refreshing'], 'inside the fresh window nothing is refreshed');
        $this->assertSame($generated, $served['freshness_summary']['generated_at'], 'the footer prints the build\'s time, never the request\'s');
        $this->assertSame($generated, $served['delivery']['built_at']);
        $this->assertNotSame(Carbon::now()->utc()->toIso8601ZuluString(), $served['freshness_summary']['generated_at']);
    }

    public function test_a_stale_copy_is_served_once_refreshing_and_one_refresh_is_queued_on_the_low_priority_queue(): void
    {
        Queue::fake();
        $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        $first = $this->data($this->raw);
        $second = $this->data($this->raw);

        $this->assertSame('last_confirmed', $first['delivery']['source']);
        $this->assertTrue($first['delivery']['refreshing']);
        $this->assertTrue($second['delivery']['refreshing']);
        Queue::assertPushedOn('live-refresh', RefreshLiveReportJob::class);
        Queue::assertPushed(RefreshLiveReportJob::class, 1);
    }

    public function test_past_the_bound_the_read_builds_synchronously_rather_than_serve_older(): void
    {
        $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addSeconds(901));

        $read = $this->data($this->raw);

        $this->assertSame('fresh', $read['delivery']['source']);
        $this->assertSame(Carbon::now()->utc()->toIso8601ZuluString(), $read['freshness_summary']['generated_at']);
    }

    public function test_the_refresh_job_replaces_the_build_and_the_next_read_carries_the_new_build_time(): void
    {
        Queue::fake();
        $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->data($this->raw);

        $job = Queue::pushed(RefreshLiveReportJob::class)->first();
        $this->assertSame('stored', app(LiveReportCache::class)->refreshNow($job->shareId, $job->query, $job->currency, $job->key, $job->locale));

        $read = $this->data($this->raw);
        $this->assertSame('last_confirmed', $read['delivery']['source']);
        $this->assertSame(Carbon::now()->utc()->toIso8601ZuluString(), $read['freshness_summary']['generated_at']);
        $this->assertFalse($read['delivery']['refreshing']);
    }

    public function test_a_failed_refresh_keeps_the_last_answer_and_records_why(): void
    {
        Queue::fake();
        $built = $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->data($this->raw);
        $job = Queue::pushed(RefreshLiveReportJob::class)->first();

        $boom = true;
        DB::listen(function ($q) use (&$boom): void {
            if ($boom && str_contains($q->sql, 'daily_metrics')) {
                throw new RuntimeException('database went away');
            }
        });
        $outcome = app(LiveReportCache::class)->refreshNow($job->shareId, $job->query, $job->currency, $job->key, $job->locale);
        $boom = false;

        $this->assertSame('failed', $outcome);
        $this->assertStringContainsString('database went away', (string) app(LiveReportCache::class)->lastFailure($job->key)['reason']);
        $served = $this->data($this->raw);
        $this->assertSame('last_confirmed', $served['delivery']['source']);
        $this->assertSame($built['freshness_summary']['generated_at'], $served['freshness_summary']['generated_at']);
    }

    public function test_a_read_whose_build_fails_stores_nothing(): void
    {
        $boom = true;
        DB::listen(function ($q) use (&$boom): void {
            if ($boom && str_contains($q->sql, 'daily_metrics')) {
                throw new RuntimeException('database went away');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->read($this->raw);
            $this->fail('the build was meant to fail');
        } catch (RuntimeException) {
        }
        $boom = false;
        $this->withExceptionHandling();

        $this->assertSame('fresh', $this->data($this->raw)['delivery']['source']);
    }

    public function test_an_empty_or_shapeless_payload_is_never_stored(): void
    {
        $cache = app(LiveReportCache::class);
        $store = fn (mixed $payload): bool => (fn () => $this->store('live-report:test', 'share', Carbon::now(), $payload))->call($cache);

        $this->assertFalse($store([]));
        $this->assertFalse($store(['message' => 'error']));
        $this->assertFalse($store(['period' => ['from' => '2026-10-01'], 'totals' => [], 'when' => Carbon::now()]), 'an object other than stdClass would not come back as itself');
        $this->assertTrue($store(['period' => ['from' => '2026-10-01'], 'totals' => [], 'labels' => (object) []]));
    }

    public function test_a_build_larger_than_the_memory_bound_is_answered_but_not_kept(): void
    {
        config(['reports.live_cache.max_bytes' => 1000]);

        $this->assertSame('fresh', $this->data($this->raw)['delivery']['source']);
        $this->assertSame('fresh', $this->data($this->raw)['delivery']['source']);
    }

    public function test_at_most_max_variants_answers_are_kept_per_link(): void
    {
        config(['reports.live_cache.max_variants' => 2]);
        foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $from) {
            $this->data($this->raw, "from={$from}&to=2026-10-12");
        }

        $this->assertSame('last_confirmed', $this->data($this->raw, 'from=2026-10-05&to=2026-10-12')['delivery']['source']);
        $this->assertSame('last_confirmed', $this->data($this->raw, 'from=2026-10-06&to=2026-10-12')['delivery']['source']);
        $this->assertSame('fresh', $this->data($this->raw, 'from=2026-10-07&to=2026-10-12')['delivery']['source'], 'a third variant was kept past the bound');
    }

    public function test_a_refresh_for_a_link_revoked_meanwhile_is_dropped_and_forgets_the_build(): void
    {
        Queue::fake();
        $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->data($this->raw);
        $job = Queue::pushed(RefreshLiveReportJob::class)->first();
        $this->changeShare(['revoked_at' => Carbon::now()]);

        $this->assertSame('dropped', app(LiveReportCache::class)->refreshNow($job->shareId, $job->query, $job->currency, $job->key, $job->locale));
        $this->assertNull(Cache::get($job->key));
    }

    public function test_a_refresh_for_a_link_edited_meanwhile_does_not_store_the_old_state(): void
    {
        Queue::fake();
        $this->data($this->raw);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        $this->data($this->raw);
        $job = Queue::pushed(RefreshLiveReportJob::class)->first();
        $this->changeSettings(['kpi_labels' => ['spend' => 'Budget used']]);

        $this->assertSame('superseded', app(LiveReportCache::class)->refreshNow($job->shareId, $job->query, $job->currency, $job->key, $job->locale));
    }

    public function test_served_ages_are_read_again_at_serve_time_and_can_only_grow(): void
    {
        $scope = (array) $this->share->scope;
        $scope['providers'] = ['meta'];
        $this->changeShare(['scope' => $scope]);
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) collect($this->boundAccounts)->firstWhere('provider', 'meta')->id, 'provider' => 'meta',
            'status' => 'success', 'window_start' => '2026-10-18', 'window_end' => '2026-10-21',
            'started_at' => Carbon::now()->subMinutes(86), 'finished_at' => Carbon::now()->subMinutes(85),
            'created_at' => Carbon::now()->subMinutes(86), 'updated_at' => Carbon::now()->subMinutes(85),
        ]);

        $built = $this->data($this->raw);
        $meta = collect($built['freshness'])->firstWhere('provider', 'meta');
        $this->assertSame(85, $meta['age_minutes']);
        $this->assertSame('fresh', $meta['data_status']);

        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        Queue::fake();
        $served = $this->data($this->raw);
        $metaNow = collect($served['freshness'])->firstWhere('provider', 'meta');

        $this->assertSame('last_confirmed', $served['delivery']['source']);
        $this->assertSame(95, $metaNow['age_minutes'], 'a served copy must not claim the platform is as recent as it was at build time');
        $this->assertSame('delayed', $metaNow['data_status']);
        $this->assertSame($meta['last_success_at'], $metaNow['last_success_at']);
        $this->assertSame($built['freshness_summary']['generated_at'], $served['freshness_summary']['generated_at']);
        $this->assertSame('delayed', $served['freshness_summary']['worst_status']);
    }

    // ── cost ─────────────────────────────────────────────────────────────────────────────────────

    public function test_a_served_read_asks_the_database_a_handful_of_questions_and_none_of_the_figures(): void
    {
        $this->warm($this->raw);
        $statements = [];
        DB::listen(function ($q) use (&$statements): void {
            $statements[] = $q->sql;
        });

        $this->data($this->raw);

        $this->assertLessThanOrEqual(12, count($statements), implode("\n", $statements));
        $this->assertSame([], array_values(array_filter($statements, static fn (string $s): bool => str_contains($s, 'daily_metrics'))));
    }

    // ── the queue is worked ──────────────────────────────────────────────────────────────────────

    public function test_the_refresh_queue_is_worked_by_a_niced_production_supervisor_and_by_local_horizon(): void
    {
        $queue = (string) config('reports.live_cache.queue');
        $this->assertSame($queue, (new RefreshLiveReportJob('s', [], 'SAR', 'k', 'ar'))->queue);

        $workers = [];
        foreach ((array) config('horizon.environments.production') as $name => $settings) {
            $merged = array_replace_recursive((array) config("horizon.defaults.{$name}", []), (array) $settings);
            if (in_array($queue, (array) $merged['queue'], true)) {
                $workers[$name] = $merged;
            }
        }
        $this->assertSame(['supervisor-sync'], array_keys($workers), 'exactly one production supervisor works the refresh queue');
        $this->assertSame(10, $workers['supervisor-sync']['nice']);
        $this->assertSame(1, $workers['supervisor-sync']['maxProcesses']);
        $this->assertGreaterThan((new RefreshLiveReportJob('s', [], 'SAR', 'k', 'ar'))->timeout, $workers['supervisor-sync']['timeout']);

        $local = array_replace_recursive((array) config('horizon.defaults.supervisor-1'), (array) config('horizon.environments.local.supervisor-1'));
        $this->assertContains($queue, $local['queue']);
    }
}
