<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertRuleEvaluator;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Models\AppNotification;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Alert evaluation: triggers on real metrics, dedups, respects cooldown, auto-resolves, isolates projects. */
final class AlertEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    private function spend(string $projectId, float $value, string $date, string $key = 'spend'): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $projectId, 'provider' => 'meta',
            'external_account_id' => '11111111-1111-1111-1111-111111111111',
            'external_campaign_id' => '22222222-2222-2222-2222-222222222222', 'metric_key' => $key,
            'metric_date' => $date, 'value' => $value, 'project_currency' => 'SAR', 'source_type' => 'sandbox',
        ]);
    }

    private function rule(array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => 'إنفاق دون تحويلات', 'rule_type' => 'spend_no_conversions', 'threshold' => 100,
            'evaluation_window' => 7, 'severity' => 'critical', 'cooldown' => 86400, 'is_active' => true,
        ], $over));
    }

    public function test_triggers_dedups_and_auto_resolves(): void
    {
        $now = Carbon::parse('2026-07-10 12:00:00');
        $this->spend($this->project->id, 500, '2026-07-09'); // spend, no conversions
        $rule = $this->rule();
        $ev = app(AlertRuleEvaluator::class);

        // 1. First evaluation raises one notification.
        $this->assertSame(1, $ev->evaluateProject($this->tenant->id, $this->project->id, $now)['raised']);
        $this->assertSame(1, AppNotification::withoutGlobalScopes()->where('entity_id', $rule->id)->count());

        // 2. Re-evaluation dedups (still one open notification).
        $r2 = $ev->evaluateProject($this->tenant->id, $this->project->id, $now->copy()->addMinutes(5));
        $this->assertSame(0, $r2['raised']);
        $this->assertSame(1, $r2['skipped']);

        // 3. Condition clears (conversions appear) → auto-resolve.
        $this->spend($this->project->id, 10, '2026-07-09', 'conversions');
        $r3 = $ev->evaluateProject($this->tenant->id, $this->project->id, $now->copy()->addDays(2));
        $this->assertSame(1, $r3['resolved']);
        $this->assertNotNull(AppNotification::withoutGlobalScopes()->where('entity_id', $rule->id)->first()->resolved_at);
    }

    public function test_cooldown_suppresses_re_alert_after_resolve(): void
    {
        $now = Carbon::parse('2026-07-10 12:00:00');
        $this->spend($this->project->id, 500, '2026-07-09');
        $rule = $this->rule(['cooldown' => 86400]);
        $ev = app(AlertRuleEvaluator::class);

        $ev->evaluateProject($this->tenant->id, $this->project->id, $now);
        // Resolve the open alert, then re-evaluate within cooldown → suppressed.
        AppNotification::withoutGlobalScopes()->where('entity_id', $rule->id)->update(['resolved_at' => $now, 'status' => 'resolved']);
        $r = $ev->evaluateProject($this->tenant->id, $this->project->id, $now->copy()->addHour());
        $this->assertSame(0, $r['raised']);
        $this->assertSame(1, $r['skipped']);
    }

    public function test_demo_rule_raises_demo_notification_only(): void
    {
        $now = Carbon::parse('2026-07-10 12:00:00');
        $this->spend($this->project->id, 500, '2026-07-09');
        $rule = $this->rule(['is_demo' => true]);
        app(AlertRuleEvaluator::class)->evaluateProject($this->tenant->id, $this->project->id, $now);

        $this->assertTrue((bool) AppNotification::withoutGlobalScopes()->where('entity_id', $rule->id)->first()->is_demo);
    }

    public function test_project_isolation(): void
    {
        $now = Carbon::parse('2026-07-10 12:00:00');
        $ws = ClientWorkspace::create(['name' => 'C2', 'slug' => 'c2', 'mode' => 'managed']);
        $other = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P2', 'status' => 'active']);
        // Spend is on the OTHER project; this project's rule must not trigger.
        $this->spend($other->id, 500, '2026-07-09');
        $rule = $this->rule();

        $r = app(AlertRuleEvaluator::class)->evaluateProject($this->tenant->id, $this->project->id, $now);
        $this->assertSame(0, $r['raised']);
        $this->assertSame(0, AppNotification::withoutGlobalScopes()->where('entity_id', $rule->id)->count());
    }
}
