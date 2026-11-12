<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Jobs\FetchPeriodReachJob;
use App\Domains\Metrics\Services\ChangeTimeline;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\BuildsAlertSweepWorld;
use Tests\TestCase;

/**
 * PERF-RECOVERY-001 — the sweep's one-statement reads answer what the one-campaign reads answered, inside the same bounds, and their cost does not grow with the campaigns.
 */
final class AlertSweepBatchedReadsTest extends TestCase
{
    use BuildsAlertSweepWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeWorld();
        $this->buildAlertWorld();
        // No read here may reach a provider; the fetch a one-campaign read queues stays a recorded dispatch.
        Bus::fake([FetchPeriodReachJob::class]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->thawWorld();
        parent::tearDown();
    }

    /**
     * `pacedThrough()` ended «today or the window's end, whichever is earlier» with `startOfDay()` on the caller's own instant, so once a
     * provider stopped short the budget rule moved the sweep's clock to midnight. Everything after it in that sweep — the triggers of later
     * rules, the mail ledger's stamps, and the 24-hour window the repeat ceiling counts in — then ran up to a day early: four critical
     * alerts were held as «repeated too often» with one send inside the real 24 hours.
     */
    public function test_a_provider_that_stopped_short_does_not_move_the_sweep_clock_to_midnight(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'connection_id' => (string) Str::uuid(),
            'external_account_id' => $this->boundAccounts[2]->id, 'provider' => 'snapchat', 'status' => 'success', 'window_start' => '2026-10-01',
            'window_end' => '2026-10-10', 'started_at' => Carbon::now(), 'finished_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        app(TenantContext::class)->forget();

        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'UTC'));
        $now = Carbon::now();
        app(AlertEvaluator::class)->evaluateAll($now);

        $this->assertSame('2026-10-22 10:00:00', $now->toDateTimeString(), 'a rule must not move the instant the sweep hands it');
        $this->assertSame(
            ['2026-10-22 10:00:00+00'],
            DB::table('alert_events')->where('tenant_id', $this->tenant->id)->distinct()->orderBy('last_triggered_at')->pluck('last_triggered_at')->all(),
            'every alert of one sweep is triggered at the sweep\'s own instant',
        );
    }

    private function metrics(): MetricsAggregator
    {
        return app(MetricsAggregator::class)->acrossProjects();
    }

    /** @return list<string> */
    private function campaignIds(string $tenantId): array
    {
        return DB::table('unified_campaigns')->where('tenant_id', $tenantId)->orderBy('id')->pluck('id')->map(static fn ($id): string => (string) $id)->all();
    }

    /** @return list<array{0: Carbon, 1: Carbon}> the windows a rule reads, one holding no rows */
    private function windows(): array
    {
        $now = Carbon::now();

        return [[$now->copy()->subDays(3), $now], [$now->copy()->subDays(7), $now], [$now->copy()->subDays(14), $now->copy()->subDays(8)], [$now->copy()->subDays(21), $now], [$now->copy()->subDays(400), $now->copy()->subDays(390)]];
    }

    public function test_every_campaign_reads_exactly_what_its_own_totals_read_in_both_tenants(): void
    {
        foreach ([$this->tenant->id, $this->otherTenant->id] as $tenantId) {
            app(TenantContext::class)->setTenantId($tenantId);
            $ids = $this->campaignIds($tenantId);
            $set = $this->metrics()->forCampaigns($ids);

            foreach ($this->windows() as [$from, $to]) {
                $figures = $set->figuresByCampaign($from, $to);
                $this->assertSame($ids, array_keys($figures), 'every campaign of the set answers, rows or none');

                foreach ($ids as $id) {
                    $one = $this->metrics()->forCampaign($id)->totals($from, $to);
                    $this->assertSame($one['coverage'], $set->coverageOfWindow($from, $to));
                    // The two annotations a batched read leaves out by design, and nothing else.
                    $expected = array_diff_key($one, array_flip(['coverage', 'spend_coverage', 'revenue_coverage', 'reach', 'frequency']));
                    // OBJECTIVE-RESULT-001 — the batched read also names each campaign's objective result; the figures beside it are unchanged.
                    $result = ['result_family', 'result_key', 'result', 'result_label', 'cost_per_result', 'cost_per_result_label', 'result_missing_reason', 'result_missing_label', 'result_note'];
                    $this->assertSame($expected, array_diff_key($figures[$id], array_flip(['reach', 'frequency', ...$result])), "campaign {$id}");
                }
            }
        }
    }

    public function test_every_campaign_reads_the_series_its_own_timeline_judged(): void
    {
        foreach ([$this->tenant->id, $this->otherTenant->id] as $tenantId) {
            app(TenantContext::class)->setTenantId($tenantId);
            $ids = $this->campaignIds($tenantId);
            $set = $this->metrics()->forCampaigns($ids);
            $now = Carbon::now();
            $from = $now->copy()->subDays(21);
            $series = $set->timeseriesByCampaign($from, $now);

            foreach ($ids as $id) {
                $own = $this->metrics()->forCampaign($id);
                $strip = static fn (array $rows): array => array_map(static fn (array $r): array => array_diff_key($r, array_flip(['reach', 'frequency', 'expected_contributors', 'coverage_state', 'excluded_contributors'])), $rows);

                $this->assertSame($strip($own->timeseries($from, $now)), $strip($series[$id] ?? []), "campaign {$id}");
                $this->assertSame((new ChangeTimeline($own))->build($from, $now), (new ChangeTimeline($set))->fromSeries($series[$id] ?? []), "campaign {$id}");
            }
        }
    }

    public function test_a_set_holding_another_tenants_campaigns_reads_none_of_their_rows(): void
    {
        $theirs = $this->named['second-no-results'];
        $ours = $this->named['cost-rise'];
        $now = Carbon::now();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $read = $this->metrics()->forCampaigns([$ours, $theirs]);
        $this->assertSame(0.0, $read->figuresByCampaign($now->copy()->subDays(7), $now)[$theirs]['spend']);
        $this->assertGreaterThan(0.0, $read->figuresByCampaign($now->copy()->subDays(7), $now)[$ours]['spend']);
        $this->assertArrayNotHasKey($theirs, $read->timeseriesByCampaign($now->copy()->subDays(7), $now));

        app(TenantContext::class)->setTenantId($this->otherTenant->id);
        $read = $this->metrics()->forCampaigns([$ours, $theirs]);
        $this->assertSame(0.0, $read->figuresByCampaign($now->copy()->subDays(7), $now)[$ours]['spend']);
        $this->assertGreaterThan(0.0, $read->figuresByCampaign($now->copy()->subDays(7), $now)[$theirs]['spend']);
        $this->assertArrayNotHasKey($ours, $read->timeseriesByCampaign($now->copy()->subDays(7), $now));

        // The sweep itself: each tenant's events name only that tenant's campaigns.
        app(TenantContext::class)->forget();
        app(AlertEvaluator::class)->evaluateAll();
        foreach (DB::table('alert_events')->where('entity_type', UnifiedCampaign::class)->get() as $event) {
            $this->assertSame((string) $event->tenant_id, (string) DB::table('unified_campaigns')->where('id', $event->entity_id)->value('tenant_id'));
        }
    }

    public function test_visibility_stays_deny_by_default_per_row_in_a_set(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $unbound = $this->seedUnboundAccount();
        app(ProjectContext::class)->forget();
        // The test harness selects an account that receives rows, so a fixture does not have to; this one is the case it exists to rule out.
        DB::table('project_integration_bindings')->where('external_account_id', $unbound->id)->delete();
        $unboundCampaign = (string) DB::table('daily_metrics')->where('external_account_id', $unbound->id)->value('unified_campaign_id');

        $now = Carbon::now();
        $from = $now->copy()->subDays(21);
        $read = $this->metrics()->forCampaigns([$this->named['only-deselected'], $this->named['half-deselected'], $this->named['only-account-elsewhere'], $this->named['rows-filed-elsewhere'], $unboundCampaign]);
        $figures = $read->figuresByCampaign($from, $now);
        $series = $read->timeseriesByCampaign($from, $now);

        // A deselected account, an account bound to another project and an account bound nowhere show nothing.
        foreach (['only-deselected', 'only-account-elsewhere'] as $label) {
            $this->assertSame(0.0, $figures[$this->named[$label]]['spend'], $label);
            $this->assertArrayNotHasKey($this->named[$label], $series, $label);
        }
        $this->assertSame(0.0, $figures[$unboundCampaign]['spend']);
        // Beside a visible account, only the visible account's money: fourteen days of 10, none of the deselected 900.
        $this->assertSame(140.0, $figures[$this->named['half-deselected']]['spend']);
        // A row is judged by its OWN project: the account is bound to the project the row is filed under.
        $this->assertSame(980.0, $figures[$this->named['rows-filed-elsewhere']]['spend']);
    }

    public function test_a_project_bound_rule_reads_no_other_projects_campaigns(): void
    {
        app(AlertEvaluator::class)->evaluateAll();
        $otherProjectRule = $this->rules['other-project-no_results'];
        $entities = DB::table('alert_events')->where('rule_id', $otherProjectRule->id)->pluck('entity_id')->map(static fn ($id): string => (string) $id)->all();

        $this->assertContains($this->named['other-project-no-results'], $entities);
        foreach ($entities as $id) {
            $this->assertSame((string) $this->otherProject->id, (string) DB::table('unified_campaigns')->where('id', $id)->value('project_id'));
        }
    }

    public function test_a_read_with_no_set_refuses_rather_than_meaning_every_campaign(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->expectException(LogicException::class);
        $this->metrics()->figuresByCampaign(Carbon::now()->subDays(3), Carbon::now());
    }

    public function test_an_empty_set_reads_nothing_and_cannot_widen(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $read = $this->metrics()->forCampaigns([]);
        $this->assertSame([], $read->figuresByCampaign(Carbon::now()->subDays(3), Carbon::now()));
        $this->assertSame([], $read->timeseriesByCampaign(Carbon::now()->subDays(3), Carbon::now()));
    }

    public function test_a_sweep_asks_no_provider_for_a_reach_no_alert_reads(): void
    {
        app(AlertEvaluator::class)->evaluateAll();

        Bus::assertNotDispatched(FetchPeriodReachJob::class);
    }

    public function test_the_statements_of_a_sweep_do_not_grow_with_the_campaigns(): void
    {
        $count = function (): int {
            $n = 0;
            DB::listen(static function () use (&$n): void {
                $n++;
            });
            // Inside the cooldown the writes are out of the way; what is left is what the sweep reads.
            Carbon::setTestNow(Carbon::now()->addMinutes(15));
            app(AlertEvaluator::class)->evaluateAll();

            return $n;
        };

        app(AlertEvaluator::class)->evaluateAll();
        $before = $count();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(ProjectContext::class)->setProjectId($this->project->id);
        $this->growWorld(3, 100);
        app(ProjectContext::class)->forget();
        app(TenantContext::class)->forget();

        app(AlertEvaluator::class)->evaluateAll();
        $after = $count();

        // Eighteen more campaigns. New breaches each cost their own event lookups, so the bound is well under one statement per campaign per read.
        $this->assertLessThan(500, $before, 'a sweep of ~50 campaigns is hundreds of statements, not the thousands it was');
        $this->assertLessThan($before + 18 * 2, $after, 'cost per campaign is back');
    }

    public function test_the_schedule_will_not_stack_a_sweep_and_a_killed_run_costs_one_tick_at_most(): void
    {
        $event = collect(app(Schedule::class)->events())->first(static fn ($e): bool => str_contains((string) $e->command, 'alerts:evaluate'));

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertLessThan(15, $event->expiresAt, 'a lock outliving the next tick means a killed run silences alerts');
    }
}
