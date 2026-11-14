<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The alerting engine: rules → signals → alerts, with cooldown / dedup / snooze / resolve / create-task and
 * honest (never-fake) notification delivery.
 */
final class AlertsEngineTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * A daytime clock. These tests seed «yesterday» as the UTC date, while an alert measures yesterday in Riyadh
         * (DIGESTS-YESTERDAY-BY-OBJECTIVE-001): from 21:00 to 24:00 UTC the two days differ and the fixture is not yesterday.
         */
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00:00', 'UTC'));
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(string $type, array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'type' => $type,
            'name' => ucfirst($type),
            'cooldown_minutes' => 720,
            'channels' => ['in_app', 'email'],
            'severity' => 'warning',
            'active' => true,
        ], $over));
    }

    private function campaign(float $budget = 0, string $objective = 'conversions'): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Camp '.uniqid(), 'objective' => $objective, 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
    }

    private function metric(UnifiedCampaign $c, string $key, float $value, int $daysAgo = 1, string $provider = 'sandbox'): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => $provider, 'metric_key' => $key,
            'metric_date' => Carbon::now('Asia/Riyadh')->subDays($daysAgo)->toDateString(), 'value' => $value, // the client's calendar: «yesterday» is Riyadh's
            /*
             * Every money row a sync writes carries the unit it was converted INTO, and the budget
             * alert now reads the money contract's verdict rather than dividing two bare numbers —
             * so a fixture without a currency is a campaign whose spend cannot be compared to its
             * budget at all, which is a different scenario from the one these tests describe.
             */
            'project_currency' => 'SAR',
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
    }

    private function failedSync(): string
    {
        $connId = (string) Str::uuid();
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'connection_id' => $connId, 'provider' => 'meta', 'status' => 'failed',
            'window_start' => Carbon::now()->subDay()->toDateString(), 'window_end' => Carbon::now()->toDateString(),
            'error' => 'token expired', 'started_at' => Carbon::now()->subMinutes(5),
            'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);

        return $connId;
    }

    public function test_sync_failure_raises_once_then_cooldown_suppresses_and_delivery_is_honest(): void
    {
        $this->failedSync();
        $rule = $this->rule('sync_failure');
        $evaluator = app(AlertEvaluator::class);

        $this->assertSame(1, $evaluator->evaluateRule($rule));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->where('status', 'open')->count());

        // A notification hit the inbox; its email delivery is awaiting_credentials — never "sent".
        $notification = AppNotification::where('type', 'sync_failed')->first();
        $this->assertNotNull($notification);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notification->id, 'channel' => 'email', 'status' => 'awaiting_credentials']);
        $this->assertDatabaseMissing('notification_deliveries', ['channel' => 'email', 'status' => 'sent']);

        // Same problem, evaluated again immediately → suppressed by cooldown (no storm, no new event).
        $this->assertSame(0, $evaluator->evaluateRule($rule));
        $this->assertSame(1, AlertEvent::where('rule_id', $rule->id)->count());
    }

    public function test_no_results_raises_and_opens_a_task_when_configured(): void
    {
        $c = $this->campaign();
        $this->metric($c, 'spend', 500);
        $this->metric($c, 'conversions', 0);
        $rule = $this->rule('no_results', ['create_task' => true, 'threshold' => ['days' => 3]]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertNotNull($event->task_id);
        $this->assertDatabaseHas('tasks', ['id' => $event->task_id, 'status' => 'todo']);
        $this->assertSame(1, Task::where('tenant_id', $this->tenant->id)->count());
    }

    /**
     * OBJECTIVE-RESULT-001 — «صرف بدون نتائج» reads the campaign's OWN result. 1KCOFFEE (traffic,
     * thousands of visits, no conversions) was told it had spent with no results.
     */
    public function test_no_results_ignores_a_traffic_campaign_that_brought_visits(): void
    {
        $c = $this->campaign(objective: 'traffic');
        $this->metric($c, 'spend', 1440);
        $this->metric($c, 'conversions', 0);
        $this->metric($c, 'clicks', 18464);
        $this->metric($c, 'landing_page_views', 2881);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('no_results', ['threshold' => ['days' => 3]])));
    }

    /**
     * «حلويات برديسي» on Production: a traffic campaign on Snapchat. Snapchat's swipes are stored as `clicks`, and its
     * `landing_page_views` arrive as 0 when no pixel measures the page — «not tracked», not «nobody came». Its result is the
     * swipes, so it is not «بدون نتائج»; and an alert the old rule left open on it closes quietly, never «تعافى».
     */
    public function test_no_results_reads_a_snapchat_traffic_campaigns_swipes_and_closes_its_old_alert_quietly(): void
    {
        $c = $this->campaign(objective: 'traffic');
        $this->metric($c, 'spend', 620, provider: 'snapchat');
        $this->metric($c, 'impressions', 85000, provider: 'snapchat');
        $this->metric($c, 'clicks', 940, provider: 'snapchat');
        $this->metric($c, 'landing_page_views', 0, provider: 'snapchat');
        $this->metric($c, 'conversions', 0, provider: 'snapchat');
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);
        $old = AlertEvent::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'no_results',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id,
            'dedup_key' => hash('sha256', implode('|', [$rule->id, UnifiedCampaign::class, $c->id])),
            'status' => 'open', 'severity' => 'warning',
            'context' => ['spend' => 620.0, 'conversions' => 0.0, 'days' => 3, 'title' => 'Spending with no results', 'message' => 'x'],
            'last_triggered_at' => Carbon::now()->subDay(),
        ]);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($rule));
        $this->assertSame('resolved', $old->fresh()->status);
        $this->assertSame('result_redefined', $old->fresh()->context['closed_reason']);
        $this->assertSame(0, AppNotification::where('tenant_id', $this->tenant->id)->count());
    }

    /** An objective nobody set has no result to be zero — no alert. */
    public function test_no_results_skips_a_campaign_whose_objective_is_not_set(): void
    {
        $c = $this->campaign(objective: 'other');
        $this->metric($c, 'spend', 500);
        $this->metric($c, 'conversions', 0);

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('no_results', ['threshold' => ['days' => 3]])));
    }

    /**
     * Only a RUNNING campaign is a performance problem. A paused or completed one, or one whose end
     * date has passed (Ettifaq vs Ajman, 29 Oct), is not alerted as «صرف بدون نتائج».
     */
    public function test_no_results_skips_paused_completed_and_ended_campaigns(): void
    {
        foreach ([['status' => 'paused'], ['status' => 'completed'], ['status' => 'active', 'ends_on' => Carbon::now()->subDays(2)->toDateString()]] as $state) {
            $c = $this->campaign();
            $c->forceFill($state)->save();
            $this->metric($c, 'spend', 500);
            $this->metric($c, 'conversions', 0);
        }

        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('no_results', ['threshold' => ['days' => 3]])));
    }

    /** A traffic campaign whose platform measured 0 visits IS a zero result, and the alert names it. */
    public function test_no_results_names_the_objective_result_that_is_zero(): void
    {
        $c = $this->campaign(objective: 'traffic');
        $this->metric($c, 'spend', 500);
        $this->metric($c, 'clicks', 300);
        $this->metric($c, 'landing_page_views', 0);
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $context = AlertEvent::where('rule_id', $rule->id)->firstOrFail()->context;
        $this->assertSame('landing_page_views', $context['result_key']);
        $this->assertSame('زيارات الصفحة', $context['result_label']['ar']);
    }

    /**
     * ALERT-OBJECTIVE-RESULT-001 review (1) — a figure the platform never SENDS is not a zero. Snapchat and
     * TikTok write no `engagements` row, TikTok no `installs`; a leads campaign may carry none of leads,
     * form leads or conversions. Each spent with clicks and was told «بدون نتائج». A reported 0 still alerts.
     */
    public function test_no_results_skips_a_result_the_platform_never_sends(): void
    {
        $silent = [];
        foreach ([['engagement', 'snapchat', true], ['engagement', 'tiktok', true], ['app_installs', 'tiktok', true], ['app_installs', 'tiktok', false], ['leads', 'meta', true]] as [$objective, $provider, $clicks]) {
            $c = $this->campaign(objective: $objective);
            $this->metric($c, 'spend', 500, provider: $provider);
            $this->metric($c, 'impressions', 40000, provider: $provider);
            if ($clicks) {
                $this->metric($c, 'clicks', 300, provider: $provider);
            }
            $silent[] = (string) $c->id;
        }
        // The control: Meta REPORTED engagements, and they were 0 — that is a real «بدون تفاعل».
        $reported = $this->campaign(objective: 'engagement');
        $this->metric($reported, 'spend', 500, provider: 'meta');
        $this->metric($reported, 'clicks', 300, provider: 'meta');
        $this->metric($reported, 'engagements', 0, provider: 'meta');
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $alerted = AlertEvent::where('rule_id', $rule->id)->pluck('entity_id')->map(static fn ($id): string => (string) $id)->all();
        $this->assertSame([(string) $reported->id], $alerted);
        $this->assertSame([], array_intersect($silent, $alerted));
        $this->assertSame('engagements', AlertEvent::where('rule_id', $rule->id)->firstOrFail()->context['result_key']);
    }

    /**
     * The other silence: Meta drops a figure that is zero, so a sales campaign with no purchase has no `conversions` row. Meta
     * DOES send conversions (its sibling campaign proves it), so that absence is a zero and the alert must still fire.
     */
    public function test_no_results_still_fires_when_a_platform_that_sends_the_figure_omitted_its_zero(): void
    {
        $converting = $this->campaign();
        $this->metric($converting, 'spend', 300, provider: 'meta');
        $this->metric($converting, 'conversions', 4, provider: 'meta');
        $silent = $this->campaign();
        $this->metric($silent, 'spend', 500, provider: 'meta');
        $this->metric($silent, 'clicks', 120, provider: 'meta');
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);

        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();
        $this->assertSame((string) $silent->id, (string) $event->entity_id);
        $this->assertSame('conversions', $event->context['result_key']);
    }

    /**
     * Review (2) — the deploy-time transition. Alerts the old rule opened on a traffic campaign that brought
     * visits, a paused campaign and one with no objective are not «تعافى»: they close quietly with their
     * reason, with no notification and nothing for the mail. A real recovery still says so.
     */
    public function test_alerts_that_no_longer_apply_close_quietly_and_a_real_recovery_still_notifies(): void
    {
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);
        $open = function (UnifiedCampaign $c, array $context = []) use ($rule): AlertEvent {
            return AlertEvent::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'rule_id' => $rule->id, 'type' => 'no_results',
                'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id,
                'dedup_key' => hash('sha256', implode('|', [$rule->id, UnifiedCampaign::class, $c->id])),
                'status' => 'open', 'severity' => 'warning',
                'context' => $context + ['spend' => 500.0, 'conversions' => 0.0, 'days' => 3, 'title' => 'Spending with no results', 'message' => 'x'],
                'last_triggered_at' => Carbon::now()->subDays(2),
            ]);
        };

        $traffic = $this->campaign(objective: 'traffic');
        $this->metric($traffic, 'spend', 1440);
        $this->metric($traffic, 'clicks', 18464);
        $this->metric($traffic, 'landing_page_views', 2881);
        $paused = $this->campaign();
        $paused->forceFill(['status' => 'paused'])->save();
        $this->metric($paused, 'spend', 500);
        $this->metric($paused, 'conversions', 0);
        $unknown = $this->campaign(objective: 'other');
        $this->metric($unknown, 'spend', 500);
        $recovered = $this->campaign();
        $this->metric($recovered, 'spend', 500);
        $this->metric($recovered, 'conversions', 4);

        $quiet = ['result_redefined' => $open($traffic), 'campaign_inactive' => $open($paused), 'objective_unknown' => $open($unknown)];
        $real = $open($recovered, ['result_key' => 'conversions']);

        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateRule($rule);

        foreach ($quiet as $reason => $event) {
            $fresh = $event->fresh();
            $this->assertSame('resolved', $fresh->status, $reason);
            $this->assertSame($reason, $fresh->context['closed_reason'] ?? null, $reason);
            $this->assertArrayNotHasKey('recovered_at', $fresh->context, $reason);
        }
        $this->assertSame('resolved', $real->fresh()->status);
        $this->assertArrayHasKey('recovered_at', $real->fresh()->context);

        // Exactly one notification and one mail item: the real recovery's.
        $this->assertSame(1, AppNotification::where('tenant_id', $this->tenant->id)->count());
        $this->assertStringStartsWith('Recovered: ', (string) AppNotification::where('tenant_id', $this->tenant->id)->value('title'));
        $outbox = (new \ReflectionProperty($evaluator, 'outbox'))->getValue($evaluator);
        $this->assertCount(1, $outbox);
        $this->assertSame((string) $real->id, $outbox[0]['event_id']);
    }

    /**
     * Review (4) — «ended» is the client's day, not the server's. At 22:30 UTC on 12 Nov it is already
     * 13 Nov in Riyadh: a campaign that ended on the 12th has ended; one ending on the 13th runs today.
     */
    public function test_no_results_reads_the_end_date_on_the_clients_clock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 22:30:00', 'UTC'));
        try {
            $endedYesterday = $this->campaign();
            $endedYesterday->forceFill(['ends_on' => '2026-11-12'])->save();
            $endsToday = $this->campaign();
            $endsToday->forceFill(['ends_on' => '2026-11-13'])->save();
            foreach ([$endedYesterday, $endsToday] as $c) {
                $this->metric($c, 'spend', 500);
                $this->metric($c, 'conversions', 0);
            }
            $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);

            $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($rule));
            $this->assertSame((string) $endsToday->id, (string) AlertEvent::where('rule_id', $rule->id)->value('entity_id'));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** The end date is read on the PROJECT's clock: a client on UTC whose campaign ends on the 12th is still running at 22:30 UTC. */
    public function test_a_campaign_end_date_is_read_in_the_project_timezone_not_a_fixed_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 22:30:00', 'UTC'));
        try {
            ClientWorkspace::query()->withoutGlobalScopes()->whereKey($this->project->client_workspace_id)->update(['timezone' => 'UTC']);
            $endsTodayInUtc = $this->campaign();
            $endsTodayInUtc->forceFill(['ends_on' => '2026-11-12'])->save();

            $this->assertTrue(AlertEvaluator::running($endsTodayInUtc->refresh(), Carbon::now()), 'still the 12th on the client\'s clock');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_budget_risk_respects_the_threshold(): void
    {
        $c = $this->campaign(budget: 1000);
        $this->metric($c, 'spend', 500); // 50% spent

        // Threshold 90% → not breached.
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($this->rule('budget_risk', ['threshold' => ['ratio' => 0.9]])));
        // Threshold 40% → breached.
        $this->assertSame(1, app(AlertEvaluator::class)->evaluateRule($this->rule('budget_risk', ['threshold' => ['ratio' => 0.4]])));
    }

    public function test_snooze_suppresses_and_resolve_closes(): void
    {
        $this->failedSync();
        $rule = $this->rule('sync_failure', ['cooldown_minutes' => 5]);
        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateRule($rule);
        $event = AlertEvent::where('rule_id', $rule->id)->firstOrFail();

        // Snoozed into the future → next evaluation (even past cooldown) stays suppressed.
        $evaluator->snooze($event, Carbon::now()->addHours(2));
        $this->assertSame(0, $evaluator->evaluateRule($rule, Carbon::now()->addMinutes(10)));

        // Resolving closes the event.
        $evaluator->resolve($event->refresh());
        $this->assertSame('resolved', $event->refresh()->status);
        $this->assertNotNull($event->resolved_at);
    }

    public function test_command_runs(): void
    {
        $this->failedSync();
        $this->rule('sync_failure');
        $this->artisan('alerts:evaluate')->assertSuccessful();
        $this->assertGreaterThanOrEqual(1, AlertEvent::count());
    }
}
