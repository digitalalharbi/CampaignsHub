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
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
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

    private function campaign(float $budget = 0): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'Camp '.uniqid(), 'objective' => 'conversions', 'status' => 'active',
            'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
    }

    private function metric(UnifiedCampaign $c, string $key, float $value, int $daysAgo = 1): void
    {
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
            'unified_campaign_id' => $c->id, 'provider' => 'sandbox', 'metric_key' => $key,
            'metric_date' => Carbon::now()->subDays($daysAgo)->toDateString(), 'value' => $value,
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

    /** EMAIL-ALERT-RECOVERY-001 — a later successful read closes the thread and says so, once. */
    public function test_a_sync_failure_recovers_when_a_later_read_succeeds_and_tells_the_reader_once(): void
    {
        $conn = $this->failedSync();
        $rule = $this->rule('sync_failure');
        $evaluator = app(AlertEvaluator::class);
        $evaluator->evaluateRule($rule);
        $event = AlertEvent::where('rule_id', $rule->id)->sole();
        $this->assertStringContainsString('No read has succeeded yet.', (string) $event->context['message']);
        $this->assertStringContainsString('does not repeat for 12 h', (string) $event->context['message'], 'the quiet period is stated');

        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'connection_id' => $conn, 'provider' => 'meta', 'status' => 'success',
            'window_start' => Carbon::now()->subDay()->toDateString(), 'window_end' => Carbon::now()->toDateString(),
            'started_at' => Carbon::now(), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $evaluator->evaluateRule($rule);

        $event->refresh();
        $this->assertSame('resolved', $event->status);
        $this->assertSame('recovered', $event->context['resolution']);
        $this->assertSame(1, AppNotification::where('title', 'like', 'Recovered:%')->count());

        $evaluator->evaluateRule($rule);
        $this->assertSame(1, AppNotification::where('title', 'like', 'Recovered:%')->count(), 'a recovery is told once');
    }

    /** A performance alert is never «recovered» by silence: no data is not a measured recovery. */
    public function test_a_performance_alert_stays_open_when_its_data_goes_quiet(): void
    {
        $c = $this->campaign();
        $this->metric($c, 'spend', 500);
        $this->metric($c, 'conversions', 0);
        $rule = $this->rule('no_results', ['threshold' => ['days' => 3]]);
        app(AlertEvaluator::class)->evaluateRule($rule);
        DB::table('daily_metrics')->delete();

        app(AlertEvaluator::class)->evaluateRule($rule);

        $this->assertSame('open', AlertEvent::where('rule_id', $rule->id)->sole()->status);
        $this->assertSame(0, AppNotification::where('title', 'like', 'Recovered:%')->count());
    }

    /** EMAIL-ALERT-MEASUREMENT-OUTAGE-001 — a GA4 property or a store that stopped syncing, with its last good read. */
    public function test_a_measurement_or_store_outage_is_raised_with_its_last_successful_read(): void
    {
        $ga4 = (string) Str::uuid();
        $store = (string) Str::uuid();
        $unselected = (string) Str::uuid();
        $run = fn (string $conn, string $type, string $status, int $minutesAgo) => DB::table('integration_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'provider_connection_id' => $conn, 'type' => $type, 'status' => $status, 'error' => $status === 'failed' ? 'quota exceeded' : null,
            'started_at' => Carbon::now()->subMinutes($minutesAgo), 'created_at' => Carbon::now(), 'updated_at' => Carbon::now(),
        ]);
        $run($ga4, 'measurement', 'success', 600);
        $run($ga4, 'measurement', 'failed', 5);
        $run($store, 'commerce', 'failed', 5);
        $run($unselected, 'measurement', 'awaiting_assignment', 5);
        $rule = $this->rule('sync_failure');

        $this->assertSame(2, app(AlertEvaluator::class)->evaluateRule($rule));

        $titles = AlertEvent::where('rule_id', $rule->id)->get()->map(fn (AlertEvent $e) => $e->context['title'])->sort()->values()->all();
        $this->assertSame(['Site measurement (GA4) sync failed', 'Store sync failed'], $titles);
        $ga4Event = AlertEvent::where('rule_id', $rule->id)->where('entity_id', $ga4)->sole();
        $this->assertStringContainsString('Last successful read:', (string) $ga4Event->context['message']);
        $this->assertNotNull($ga4Event->context['last_success_at']);
        $this->assertSame(0, AlertEvent::where('entity_id', $unselected)->count(), 'an unselected source is not an outage');
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
