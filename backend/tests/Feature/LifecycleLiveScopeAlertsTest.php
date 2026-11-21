<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertEvaluator;
use App\Domains\CRM\Enums\LeadStage;
use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Status\AccountDayCoverage;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\LifecycleFixtures;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 (N4, finishing N1 + N3's deferrals) — the alerts engine and the digest's link readings read N1's ONE
 * live-projects scope, and the digest judges a link by N3's coverage rule (the account's own time zone), not the Riyadh clock.
 */
final class LifecycleLiveScopeAlertsTest extends TestCase
{
    use LifecycleFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lead(Project $project): void
    {
        $lead = new Lead;
        $lead->forceFill([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'name' => 'Buyer', 'phone' => '+966500000001', 'source' => 'provider', 'status' => LeadStage::New->value,
            'received_at' => Carbon::now()->subHours(4),
        ])->save();
    }

    public function test_a_tenant_wide_rule_never_evaluates_an_archived_project_and_closes_its_open_alert_quietly(): void
    {
        $w = $this->lifecycleWorkspace('agency');
        $live = $this->lifecycleProject($w['tenant'], $w['client'], 'Live');
        $archived = $this->lifecycleProject($w['tenant'], $w['client'], 'Archived test', 'archived');
        app(TenantContext::class)->setTenantId((string) $w['tenant']->id);
        $this->lead($live);
        $this->lead($archived);

        $rule = AlertRule::create([
            'tenant_id' => $w['tenant']->id, 'project_id' => null, 'type' => 'lead_unassigned', 'name' => 'tenant-wide',
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true, 'threshold' => ['minutes' => 60],
        ]);
        // An alert raised about the archived project before it was archived.
        $stale = AlertEvent::query()->create([
            'tenant_id' => $w['tenant']->id, 'rule_id' => $rule->id, 'project_id' => $archived->id, 'type' => 'lead_unassigned',
            'entity_type' => Project::class, 'entity_id' => $archived->id, 'dedup_key' => 'old-'.$archived->id,
            'status' => 'open', 'severity' => 'warning', 'context' => ['count' => 1], 'first_triggered_at' => now(), 'last_triggered_at' => now(),
        ]);

        app(AlertEvaluator::class)->evaluateRule($rule);

        $open = AlertEvent::query()->where('tenant_id', $w['tenant']->id)->where('status', 'open')->get();
        $this->assertSame([(string) $live->id], $open->pluck('project_id')->map(static fn ($id): string => (string) $id)->all(), 'only the live project is alerted on');
        $stale->refresh();
        $this->assertSame('resolved', $stale->status);
        $this->assertSame('project_not_live', $stale->context['closed_reason'], 'closed silently with its reason, never «recovered»');

        // A rule scoped to the archived project finds nothing at all.
        $scoped = AlertRule::create([
            'tenant_id' => $w['tenant']->id, 'project_id' => $archived->id, 'type' => 'lead_unassigned', 'name' => 'scoped',
            'cooldown_minutes' => 720, 'channels' => ['in_app'], 'severity' => 'warning', 'active' => true, 'threshold' => ['minutes' => 60],
        ]);
        $this->assertSame(0, app(AlertEvaluator::class)->evaluateRule($scoped));
    }

    public function test_the_digest_link_reading_uses_the_account_time_zone_coverage_rule_not_the_riyadh_clock(): void
    {
        $w = $this->lifecycleWorkspace('agency');
        $project = $this->lifecycleProject($w['tenant'], $w['client'], 'Live');
        $archived = $this->lifecycleProject($w['tenant'], $w['client'], 'Archived', 'archived');
        $account = $this->lifecycleAccount($w['tenant'], 'google', 'EUR');
        DB::table('external_accounts')->where('id', $account->id)->update(['timezone' => 'America/Los_Angeles']);
        $this->lifecycleBind($account, $project);
        $this->lifecycleBind($account, $archived);

        // 10:00 Riyadh on the 15th = 23:00 on the 14th in Los Angeles: the 13th is the account's due day, and it is covered.
        Carbon::setTestNow(Carbon::parse('2026-11-15 07:00:00', 'UTC'));
        DB::table('metric_sync_runs')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $w['tenant']->id, 'project_id' => $project->id,
            'connection_id' => $account->provider_connection_id, 'external_account_id' => $account->id, 'provider' => 'google', 'status' => 'success',
            'window_start' => '2026-11-12', 'window_end' => '2026-11-13', 'metrics_upserted' => 0,
            'started_at' => '2026-11-14 14:59:00', 'finished_at' => '2026-11-14 15:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $coverage = app(AccountDayCoverage::class);
        $readings = $coverage->projectReadings((string) $w['tenant']->id, (string) $project->id, Carbon::now());
        $this->assertTrue($readings['google']['arrived'], 'the Riyadh rule wanted the 14th, a day that had not ended for the account');
        $this->assertSame('2026-11-13', $readings['google']['required_day']);
        $this->assertNotSame('not_synced', $coverage->noDataState((string) $w['tenant']->id, (string) $project->id, Carbon::parse('2026-11-14'), Carbon::now()));

        // The archived project is not live: no reading, no state.
        $this->assertSame([], $coverage->projectReadings((string) $w['tenant']->id, (string) $archived->id, Carbon::now()));
        $this->assertNull($coverage->noDataState((string) $w['tenant']->id, (string) $archived->id, Carbon::parse('2026-11-14'), Carbon::now()));
    }
}
