<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\ChangeTimeline;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AUTOMATION-FIRST-OPERATIONS-001 — anomaly detection is SENT, not waited for on a page.
 *
 * «Deterministic operational work runs on schedulers/queues/events with full observability, not
 * manual buttons.» Every other clause on that row was closed. This one read «anomaly detection
 * remains unsurfaced», and the word was exact: the detector has existed for weeks.
 * {@see ChangeTimeline} finds the days that departed from their own
 * trailing median, with a MAD scale so one spike cannot hide the next — and the ONLY caller in the
 * product was `MetricsController`. A person had to open the diagnostics tab and look.
 *
 * That is the manual button the row forbids. Nothing swept it, so the finding existed and nobody
 * was told; on the day an account's spend quadrupled, the product knew and waited to be asked.
 *
 * ## Why this is a rule type and not a new sweep
 *
 * A second notification path is what this requirement explicitly refuses, and the alerts engine
 * already owns everything an anomaly alert needs and would otherwise be rebuilt badly: cooldown,
 * dedup, snooze, channels, quiet hours, the task hand-off and the audit trail. So anomaly detection
 * enters as a TYPE — `metric_anomaly` — evaluated on the fifteen-minute sweep with the other nine.
 *
 * ## The two things that make it honest
 *
 * **One detector.** The handler calls `ChangeTimeline`, it does not re-derive a median. An alert
 * firing on an anomaly the diagnostics page does not draw would leave the reader with two answers to
 * one question and no way to tell which is the product's.
 *
 * **Recency.** `ChangeTimeline` answers «which days in this window were unusual», which is the right
 * question for a chart and the wrong one for an alert: swept every fifteen minutes over a three-week
 * window, a spike on the 2nd would be news for nineteen days. An alert is about a day somebody can
 * still act on, so only the last {@see AlertEvaluator} recency window counts — and a campaign whose
 * only strange day is a fortnight old stays quiet.
 */
final class MetricAnomalyAlertTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00', 'UTC'));

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id,
            'name' => 'P', 'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function campaign(string $name = 'Camp'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => 0, 'budget_currency' => 'SAR',
        ]);
    }

    private function rule(int $days = 21, int $cooldown = 720): AlertRule
    {
        return AlertRule::create([
            'tenant_id' => $this->tenant->id, 'type' => 'metric_anomaly', 'name' => 'anomaly',
            'cooldown_minutes' => $cooldown, 'channels' => ['in_app'], 'severity' => 'warning',
            'active' => true, 'threshold' => ['days' => $days],
        ]);
    }

    private function day(UnifiedCampaign $c, string $key, float $value, int $daysAgo): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => $key,
            'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(),
            'value' => $value, 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * A fortnight of ordinary days, ending `$endingDaysAgo` days back.
     *
     * Deliberately NOT a flat line: `ChangeTimeline` refuses a series whose MAD is zero, because a
     * figure that never varied has no scale to be unusual against. A fixture of identical days would
     * therefore prove the refusal rather than the detection.
     */
    private function ordinary(UnifiedCampaign $c, string $key, int $from = 14, int $endingDaysAgo = 1): void
    {
        $wobble = [100.0, 103.0, 98.0, 101.0, 99.0, 102.0, 97.0];

        for ($d = $from; $d >= $endingDaysAgo; $d--) {
            $this->day($c, $key, $wobble[$d % count($wobble)], $d);
        }
    }

    /** The day that departed is named, with the figure and the baseline it departed from. */
    public function test_a_day_that_departs_from_its_own_baseline_raises_one_alert(): void
    {
        $c = $this->campaign('Ramadan push');
        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->day($c, 'spend', 1200.0, 1);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));

        $event = AlertEvent::query()->firstOrFail();
        $this->assertSame((string) $c->id, $event->entity_id);
        /* The engine persists the sentence inside `context`, beside the figures it was built from. */
        $message = (string) ($event->context['message'] ?? '');
        $this->assertStringContainsString('Ramadan push', $message);
        $this->assertStringContainsString(Carbon::now()->subDay()->toDateString(), $message);

        $points = $event->context['points'] ?? [];
        $this->assertCount(1, $points, 'one metric departed; the alert should name exactly that one');
        $this->assertSame('spend', $points[0]['metric']);
        $this->assertSame(1200.0, (float) $points[0]['value']);
        $this->assertGreaterThan(0, (float) $points[0]['baseline'], 'the baseline it departed from is the evidence');
        $this->assertSame('up', $points[0]['direction']);
    }

    /**
     * Two metrics moving on one day is ONE incident, and the alert says both.
     *
     * The engine's dedup key is (rule, entity) — deliberately, because «this campaign is over
     * budget» is one fact however many ways you notice it. Raising per metric would either need a
     * second dedup vocabulary or would page somebody three times for one bad Tuesday.
     */
    public function test_several_metrics_moving_on_one_day_are_one_alert_naming_each(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->ordinary($c, 'clicks', endingDaysAgo: 2);
        $this->day($c, 'spend', 1200.0, 1);
        $this->day($c, 'clicks', 1500.0, 1);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));

        $metrics = array_column(AlertEvent::query()->firstOrFail()->context['points'], 'metric');
        sort($metrics);
        $this->assertSame(['clicks', 'spend'], $metrics);
    }

    /**
     * A week-old spike is history. An alert about it is noise with a timestamp.
     *
     * The spike sits eight days back for a reason the first draft of this case got wrong. The series
     * runs fourteen days and `ChangeTimeline` will not judge a day with fewer than five days of
     * history behind it, so a spike planted ten days back is at index four and is never a candidate
     * at all — the case passed with the recency filter deleted, which makes it a case that cannot
     * fail. Eight days back is index six: detected, and still outside the window an operator can
     * act on. Proved by deleting the filter and watching this go red.
     */
    public function test_an_anomaly_too_old_to_act_on_raises_nothing(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', endingDaysAgo: 1);
        // Overwrite one middle day with a spike, leaving every later day ordinary.
        DB::table('daily_metrics')
            ->where('unified_campaign_id', $c->id)
            ->where('metric_date', Carbon::now()->subDays(8)->toDateString())
            ->update(['value' => 1200.0]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
        $this->assertSame(0, AlertEvent::query()->count());
    }

    /**
     * Pausing a campaign is a decision, not an anomaly.
     *
     * When a provider keeps reporting zeros after the pause, the collapse is real and the alert is
     * still wrong: it pages the operator for their own action, which is how a whole alert type comes
     * to be dismissed. Same fixture as {@see test_a_collapse_is_an_anomaly_too()} — so if the status
     * filter goes, this is the case that notices.
     */
    public function test_a_paused_campaign_is_not_swept(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->day($c, 'spend', 0.0, 1);
        $c->update(['status' => 'paused']);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /** An account that behaved raises nothing — a detector that always finds something teaches its reader to ignore it. */
    public function test_an_ordinary_fortnight_raises_nothing(): void
    {
        $this->ordinary($this->campaign(), 'spend');

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /** Too short a window has no baseline, and says nothing rather than judging on three days. */
    public function test_a_window_too_short_for_a_baseline_raises_nothing(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', from: 3, endingDaysAgo: 2);
        $this->day($c, 'spend', 1200.0, 1);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule()));
    }

    /**
     * It is on the SHARED engine, which is the whole reason it is a type.
     *
     * The second sweep inside the cooldown must raise nothing. If this passes while the others do,
     * the handler has been given its own notification path and the requirement's «not a second
     * engine» has been broken quietly.
     */
    public function test_the_repeat_sweep_is_suppressed_by_the_rules_own_cooldown(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->day($c, 'spend', 1200.0, 1);

        $rule = $this->rule();
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $this->assertSame(0, $evaluator->evaluateRule($rule), 'the cooldown did not hold');
        $this->assertSame(1, AlertEvent::query()->count());
    }

    /** A drop is as much a departure as a spike, and the direction travels with it. */
    public function test_a_collapse_is_an_anomaly_too(): void
    {
        $c = $this->campaign();
        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->day($c, 'spend', 0.5, 1);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule()));
        $this->assertSame('down', AlertEvent::query()->firstOrFail()->context['points'][0]['direction']);
    }

    /** Another tenant's strange Tuesday is not this tenant's alert. */
    public function test_the_sweep_stays_inside_the_rules_project(): void
    {
        $other = Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->project->client_workspace_id,
            'name' => 'Other', 'status' => 'active',
        ]);

        $c = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $other->id,
            'name' => 'Theirs', 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => 0, 'budget_currency' => 'SAR',
        ]);

        $this->ordinary($c, 'spend', endingDaysAgo: 2);
        $this->day($c, 'spend', 1200.0, 1);

        $rule = $this->rule();
        $rule->update(['project_id' => $this->project->id]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
    }
}
