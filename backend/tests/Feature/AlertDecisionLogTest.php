<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Agents\Models\ActionProposal;
use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertEventDecision;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertAnatomy;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * OPERATIONAL-INTELLIGENCE-P1-001 — an alert is decision-ready: anatomy + priority, a decision log that is
 * append-only, owners who belong to the alert's own tenant and project, and an impact that is honest until
 * its window has completed.
 */
final class AlertDecisionLogTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string,mixed> $context */
    private function event(string $projectId, string $type = 'cpa_increase', array $context = [], string $severity = 'warning', ?UnifiedCampaign $campaign = null): AlertEvent
    {
        $rule = AlertRule::create(['tenant_id' => $this->agency->id, 'project_id' => $projectId, 'type' => $type, 'name' => 'R', 'active' => true]);

        return AlertEvent::create([
            'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'rule_id' => $rule->id, 'type' => $type,
            'entity_type' => $campaign ? UnifiedCampaign::class : null, 'entity_id' => $campaign?->id,
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => 'open', 'severity' => $severity,
            'context' => $context + ['cpa_current' => 50.0, 'cpa_previous' => 30.0, 'threshold_pct' => 25.0, 'window_days' => 7],
            'last_triggered_at' => now(),
        ]);
    }

    private function campaign(string $projectId, string $name = 'Camp', array $platforms = ['meta']): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'name' => $name, 'objective' => 'sales',
            'status' => 'active', 'platforms' => $platforms, 'budget_currency' => 'SAR',
        ]);
    }

    private function metric(UnifiedCampaign $c, string $date, string $key, float $value, string $provider = 'meta'): void
    {
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $c->project_id, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => $provider,
            'metric_key' => $key, 'metric_date' => $date, 'value' => $value, 'project_currency' => 'SAR',
        ]);
    }

    // ---- anatomy and the evidence rule -----------------------------------------------------------------------------------

    public function test_priority_is_deterministic_and_evidence_gated(): void
    {
        $ctx = ['cpa_current' => 50.0, 'cpa_previous' => 30.0, 'threshold_pct' => 25.0, 'window_days' => 7];
        // 67% rise on a 25% rule is >= 2x the threshold: P1 even at «warning».
        $this->assertSame('P1', AlertAnatomy::build('cpa_increase', $ctx, 'warning')['priority']);
        $this->assertSame('P1', AlertAnatomy::build('cpa_increase', ['cpa_current' => 33.0] + $ctx, 'critical')['priority']);
        $this->assertSame('P2', AlertAnatomy::build('cpa_increase', ['cpa_current' => 33.0] + $ctx, 'warning')['priority']);
        $this->assertSame('P3', AlertAnatomy::build('cpa_increase', ['cpa_current' => 33.0] + $ctx, 'info')['priority']);

        // Thin evidence: no «before» figure → P3, flagged, and NO recommended action or impact claim.
        $thin = AlertAnatomy::build('cpa_increase', ['cpa_current' => 50.0, 'window_days' => 7], 'critical');
        $this->assertSame('P3', $thin['priority']);
        $this->assertFalse($thin['evidence']['sufficient']);
        $this->assertContains('before', $thin['evidence']['missing']);
        $this->assertNull($thin['anatomy']['action']);
        $this->assertNull($thin['anatomy']['impact']);
        $this->assertStringContainsString('بيانات غير كافية', $thin['anatomy']['insufficient_note']['ar']);

        // A type with no defined evidence is never presented as a finding with a recommendation.
        $this->assertNull(AlertAnatomy::build('report_failed', [], 'critical')['anatomy']['action']);

        $ok = AlertAnatomy::build('cpa_increase', $ctx, 'warning');
        $this->assertSame(['metric' => 'cpa', 'window_days' => 7, 'before' => 30.0, 'now' => 50.0, 'threshold' => 25.0], array_intersect_key($ok['evidence'], array_flip(['metric', 'window_days', 'before', 'now', 'threshold'])));
        $this->assertNotNull($ok['anatomy']['action']);
    }

    public function test_the_ledger_derives_anatomy_for_rows_written_before_it_without_rewriting_them(): void
    {
        $e = $this->event((string) $this->projectA->id);
        $this->assertNull($e->fresh()->priority);

        $rows = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/events')->assertOk()->json('data');
        $this->assertSame('P1', $rows[0]['priority']);
        $this->assertSame(30, (int) $rows[0]['evidence']['before']);
        $this->assertNull($e->fresh()->priority, 'derived on read, never written back');
    }

    // ---- decisions, tenancy, audit -----------------------------------------------------------------------------------------

    public function test_status_changes_are_logged_append_only_with_the_previous_status(): void
    {
        $e = $this->event((string) $this->projectA->id);
        $op = $this->owner();

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'in_progress'])->assertOk();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'done', 'action_tag' => 'pause_ad', 'note' => 'paused the weakest ad'])->assertOk();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/note", ['note' => 'check in a week'])->assertCreated();

        $fresh = $e->fresh();
        $this->assertSame('done', $fresh->decision_status);
        $this->assertSame('resolved', $fresh->status, 'done closes the alert lifecycle too');

        $log = AlertEventDecision::withoutGlobalScopes()->where('alert_event_id', $e->id)->orderBy('seq')->get();
        $this->assertSame(['status', 'status', 'note'], $log->pluck('kind')->all());
        $this->assertSame(['new', 'in_progress'], $log->take(2)->pluck('from_status')->all());
        $this->assertSame('pause_ad', $log[1]->action_tag);
        $this->assertSame($op->id, $log[1]->user_id);

        try {
            $log[0]->update(['note' => 'rewritten']);
            $this->fail('an update must be refused');
        } catch (LogicException) {
        }
        try {
            $log[0]->delete();
            $this->fail('a delete must be refused');
        } catch (LogicException) {
        }
        $this->assertSame(3, AlertEventDecision::withoutGlobalScopes()->where('alert_event_id', $e->id)->count());

        $detail = $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->assertOk()->json('data');
        $this->assertCount(3, $detail['log']);
    }

    public function test_dismissing_needs_a_reason_and_view_only_readers_cannot_decide(): void
    {
        $e = $this->event((string) $this->projectA->id);
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'dismissed'])->assertStatus(422);
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'dismissed', 'reason' => 'seasonal dip, expected'])->assertOk();
        $this->assertSame('seasonal dip, expected', $e->fresh()->decision_reason);

        $viewer = $this->operatorIn($this->agency, ['alerts.view']);
        $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'in_progress'])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/assign", ['owner_id' => $viewer->id])->assertForbidden();
    }

    public function test_a_scoped_member_cannot_reach_or_decide_a_sibling_clients_alert(): void
    {
        $a = $this->event((string) $this->projectA->id);
        $op = $this->scopedTo($this->clientB, ['alerts.view', 'alerts.manage']);

        $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$a->id}")->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$a->id}/decision", ['status' => 'in_progress'])->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$a->id}/note", ['note' => 'x'])->assertNotFound();
        $this->assertSame(0, AlertEventDecision::withoutGlobalScopes()->count());
    }

    public function test_an_owner_must_be_a_member_of_the_same_tenant_and_project(): void
    {
        $e = $this->event((string) $this->projectA->id);
        $op = $this->owner();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency', 'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now()]);
        $stranger = $this->operatorIn($other);
        $siblingOnly = $this->scopedTo($this->clientB, ['alerts.view']);
        $noAlerts = $this->operatorIn($this->agency, ['clients.view']);
        $sameClient = $this->scopedTo($this->clientA, ['alerts.view']);

        foreach ([$stranger, $siblingOnly, $noAlerts] as $bad) {
            $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/assign", ['owner_id' => $bad->id])->assertStatus(422);
        }
        $this->assertNull($e->fresh()->owner_id);
        $this->assertSame(0, AlertEventDecision::withoutGlobalScopes()->where('kind', 'assign')->count());

        $detail = $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->assertOk()->json('data.assignees');
        $ids = array_column($detail, 'id');
        $this->assertContains($sameClient->id, $ids);
        $this->assertNotContains($stranger->id, $ids);
        $this->assertNotContains($siblingOnly->id, $ids);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/assign", ['owner_id' => $sameClient->id, 'note' => 'yours'])->assertOk();
        $this->assertSame($sameClient->id, (int) $e->fresh()->owner_id);
        $row = AlertEventDecision::withoutGlobalScopes()->where('kind', 'assign')->firstOrFail();
        $this->assertSame($sameClient->id, (int) $row->to_owner_id);
        $this->assertNull($row->from_owner_id);

        // Unassigning is a logged change too.
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/assign", ['owner_id' => null])->assertOk();
        $this->assertSame(2, AlertEventDecision::withoutGlobalScopes()->where('kind', 'assign')->count());
    }

    // ---- impact ----------------------------------------------------------------------------------------------------------

    public function test_impact_is_insufficient_until_the_window_completes_then_computed(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        $c = $this->campaign((string) $this->projectA->id);
        // The 7 complete days before 2026-11-01: spend 100/day, 1 conversion/day → CPA 100.
        for ($d = 1; $d <= 7; $d++) {
            $day = Carbon::parse('2026-11-01')->subDays($d)->toDateString();
            $this->metric($c, $day, 'spend', 100);
            $this->metric($c, $day, 'conversions', 1);
        }
        $e = $this->event((string) $this->projectA->id, 'cpa_increase', [], 'warning', $c);
        $op = $this->owner();

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'done', 'action_tag' => 'pause_ad'])->assertOk();
        $this->assertEquals(100.0, $e->fresh()->impact_baseline['value_at_done']);

        $now = $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->assertOk()->json('data.impact');
        $this->assertSame('insufficient_data', $now['state']);
        $this->assertSame('window_incomplete', $now['reason']);
        $this->assertEquals(100.0, $now['before']);
        $this->assertArrayNotHasKey('after', $now);

        // Part-way through the window: still not enough, and still no partial figure.
        Carbon::setTestNow('2026-11-05 12:00:00');
        for ($d = 1; $d <= 4; $d++) {
            $day = Carbon::parse('2026-11-01')->addDays($d)->toDateString();
            $this->metric($c, $day, 'spend', 70);
            $this->metric($c, $day, 'conversions', 2);
        }
        $this->assertSame('insufficient_data', $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->json('data.impact.state'));

        // The window completes (11-02 … 11-08) and the day after it is over.
        for ($d = 5; $d <= 7; $d++) {
            $day = Carbon::parse('2026-11-01')->addDays($d)->toDateString();
            $this->metric($c, $day, 'spend', 70);
            $this->metric($c, $day, 'conversions', 2);
        }
        Carbon::setTestNow('2026-11-09 08:00:00');
        $after = $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->assertOk()->json('data.impact');
        $this->assertSame('computed', $after['state'], json_encode($after));
        $this->assertEquals(35.0, $after['after']);
        $this->assertEquals(-65.0, $after['change_pct']);
        $this->assertSame('improved', $after['verdict']);
        $this->assertSame('pause_ad', $after['action_tag']);
    }

    public function test_an_alert_that_is_not_a_campaign_figure_says_so(): void
    {
        $e = $this->event((string) $this->projectA->id, 'token_expiry', ['provider' => 'meta', 'expires_at' => '2026-11-20T00:00:00Z']);
        $op = $this->owner();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$e->id}/decision", ['status' => 'done', 'action_tag' => 'reconnect_account'])->assertOk();

        $this->assertSame('not_measurable', $this->actingAs($op, 'sanctum')->getJson("/api/v1/alerts/events/{$e->id}")->json('data.impact.state'));
    }

    // ---- the exception-first board ------------------------------------------------------------------------------------------

    public function test_the_matrix_calls_a_cell_healthy_only_with_recent_data_and_ranks_only_exceptions(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $p = (string) $this->projectA->id;
        $fine = $this->campaign($p, 'Fine', ['meta', 'tiktok']);
        $bad = $this->campaign($p, 'Bad', ['meta']);
        $this->metric($fine, '2026-11-08', 'spend', 10, 'meta');
        $this->metric($fine, '2026-10-01', 'spend', 10, 'tiktok');   // tiktok is stale → no data, NOT healthy
        $this->metric($bad, '2026-11-08', 'spend', 10, 'meta');

        $p1 = $this->event($p, 'cpa_increase', [], 'warning', $bad);                                            // P1 by the 2x rule
        $p3 = $this->event($p, 'cpa_increase', ['cpa_current' => 31.0], 'info', $bad);                          // P3
        $this->event($p, 'token_expiry', ['provider' => 'tiktok', 'expires_at' => '2026-11-12T00:00:00Z'], 'info');

        $board = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data');
        $cells = collect($board['rows'])->keyBy('name');

        $this->assertSame('healthy', $cells['Fine']['cells']['meta']['status']);
        $this->assertSame('no_data', $cells['Fine']['cells']['tiktok']['status']);
        $this->assertSame('problem', $cells['Bad']['cells']['meta']['status']);
        $this->assertSame(['problem' => 1, 'no_data' => 1, 'watch' => 0, 'healthy' => 1], $board['counts']);
        $this->assertSame(1, $board['healthy_count']);

        $ids = array_column($board['needs_decision'], 'id');
        $this->assertSame((string) $p1->id, $ids[0], 'P1 first');
        $this->assertSame('P1', $board['needs_decision'][0]['priority']);
        $this->assertSame('/app/campaigns/'.$p.'/'.$bad->id, $board['needs_decision'][0]['path']);
        $this->assertCount(3, $ids);
        $this->assertNotContains('healthy', array_column($board['needs_decision'], 'decision_status'));

        // Once decided it leaves the queue.
        $this->actingAs($this->owner(), 'sanctum')->postJson("/api/v1/alerts/events/{$p1->id}/decision", ['status' => 'dismissed', 'reason' => 'planned promo'])->assertOk();
        $after = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->json('data');
        $this->assertNotContains((string) $p1->id, array_column($after['needs_decision'], 'id'));
    }

    public function test_a_capped_matrix_hides_healthy_rows_never_an_exception(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $p = (string) $this->projectA->id;
        for ($i = 0; $i < 64; $i++) {
            $c = $this->campaign($p, sprintf('A-%02d', $i), ['meta']);
            $this->metric($c, '2026-11-08', 'spend', 1);
        }
        $last = $this->campaign($p, 'Zzz-last-by-name', ['meta']);   // no data at all → an exception, though it sorts last
        $this->event($p, 'cpa_increase', [], 'warning', $last);

        $board = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data');

        $this->assertCount(60, $board['rows']);
        $this->assertSame(65, $board['rows_total']);
        $this->assertTrue($board['truncated']);
        $this->assertSame('Zzz-last-by-name', $board['rows'][0]['name']);
        $this->assertSame(65, $board['cells_total'], 'the counts cover every campaign, not just the rows shown');
        $this->assertSame(64, $board['healthy_count']);
    }

    public function test_every_active_project_is_accounted_for_once_and_a_quiet_one_is_unchecked_not_omitted(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $c = $this->campaign((string) $this->projectA->id, 'Live', ['meta']);
        $this->metric($c, '2026-11-08', 'spend', 5);
        // Project B has no active campaign naming a platform: nothing to judge, so it must be LISTED as unchecked, not left out.
        $board = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data');

        $this->assertSame(['total' => 2, 'checked' => 1], $board['coverage']);
        $this->assertSame([(string) $this->projectB->id], array_column($board['unchecked'], 'id'));
        $this->assertSame('no_active_campaign_with_platform', $board['unchecked'][0]['reason']);
        $ids = array_column($board['projects'], 'id');
        $this->assertCount(2, $ids);
        $this->assertSame(2, count(array_unique($ids)), 'each project exactly once');
        $byId = array_column($board['projects'], 'status', 'id');
        $this->assertSame('healthy', $byId[(string) $this->projectA->id]);
        $this->assertSame('unchecked', $byId[(string) $this->projectB->id], 'never «healthy» without a judged cell');
    }

    public function test_a_campaign_alert_shows_what_the_automation_proposed_without_changing_anything(): void
    {
        $p = (string) $this->projectA->id;
        $c = $this->campaign($p, 'Auto', ['meta']);
        // The proposal only names the provider's campaign id; the account behind it is not what is under test.
        DB::statement('SET session_replication_role = replica');
        ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'project_id' => $p, 'unified_campaign_id' => $c->id, 'external_account_id' => (string) Str::uuid(),
            'provider' => 'meta', 'external_id' => 'ext-1', 'name' => 'Auto', 'status' => 'ACTIVE',
        ]);
        DB::statement('SET session_replication_role = origin');
        ActionProposal::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'project_id' => $p, 'agent_key' => 'campaign', 'provider' => 'meta', 'external_account_id' => (string) Str::uuid(),
            'target_type' => 'campaign', 'target_external_id' => 'ext-1', 'action' => 'pause', 'reason' => 'CPA above target', 'status' => 'pending', 'idempotency_key' => hash('sha256', 'x'),
        ]);
        $this->event($p, 'cpa_increase', [], 'warning', $c);
        $other = $this->campaign($p, 'Plain', ['meta']);
        $this->event($p, 'cpa_increase', [], 'warning', $other);

        $items = collect($this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data.needs_decision'))->keyBy('campaign_name');
        $this->assertSame('awaiting_approval', $items['Auto']['automation']['state']);
        $this->assertSame('pause', $items['Auto']['automation']['proposals'][0]['action']);
        $this->assertSame('needs_decision', $items['Plain']['automation']['state']);
        $this->assertSame('pending', ActionProposal::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_the_board_respects_the_clients_ceiling(): void
    {
        $this->campaign((string) $this->projectA->id, 'A-camp');
        $this->campaign((string) $this->projectB->id, 'B-camp');
        $op = $this->scopedTo($this->clientB, ['alerts.view']);

        $board = $this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data');
        $this->assertSame(['B-camp'], array_column($board['rows'], 'name'));
        $this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/attention?project='.$this->projectA->id)->assertNotFound();
    }
}
