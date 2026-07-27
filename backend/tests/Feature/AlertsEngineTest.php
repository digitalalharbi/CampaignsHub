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
