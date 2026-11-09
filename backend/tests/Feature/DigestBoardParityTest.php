<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Notifications\Services\DailyDigest;
use App\Domains\Notifications\Support\DigestStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * DIGESTS-VISUAL-V2-001 — the email and the in-app board agree: for the same alert ledger, the digest reads each event's
 * priority through `AttentionBoard::anatomised` and `DigestStatus::alertState` returns the state the board's cell shows
 * (open P1/P2 → problem/bad, open P3 → watch).
 */
final class DigestBoardParityTest extends TestCase
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

    private function event(string $projectId, UnifiedCampaign $c, array $context, string $severity): AlertEvent
    {
        $rule = AlertRule::create(['tenant_id' => $this->agency->id, 'project_id' => $projectId, 'type' => 'cpa_increase', 'name' => 'R', 'active' => true]);

        return AlertEvent::create([
            'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'rule_id' => $rule->id, 'type' => 'cpa_increase',
            'entity_type' => UnifiedCampaign::class, 'entity_id' => $c->id, 'dedup_key' => hash('sha256', (string) Str::uuid()),
            'status' => 'open', 'severity' => $severity, 'context' => $context + ['cpa_current' => 50.0, 'cpa_previous' => 30.0, 'threshold_pct' => 25.0, 'window_days' => 7],
            'last_triggered_at' => now(),
        ]);
    }

    private function campaign(string $projectId, string $name): UnifiedCampaign
    {
        $c = UnifiedCampaign::create(['tenant_id' => $this->agency->id, 'project_id' => $projectId, 'name' => $name, 'objective' => 'sales', 'status' => 'active', 'platforms' => ['meta'], 'budget_currency' => 'SAR']);
        DailyMetric::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $c->id, 'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => '2026-11-08', 'value' => 10, 'project_currency' => 'SAR']);

        return $c;
    }

    /** @return list<array<string,mixed>> */
    private function openAlerts(string $projectId): array
    {
        $m = new ReflectionMethod(DailyDigest::class, 'openAlerts');

        return $m->invoke(app(DailyDigest::class), $projectId);
    }

    public function test_the_digest_cell_state_is_the_boards_cell_status(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $a = (string) $this->projectA->id;
        $b = (string) $this->projectB->id;
        $cA = $this->campaign($a, 'Hot');
        $cB = $this->campaign($b, 'Mild');
        $this->event($a, $cA, [], 'warning');                      // 50 vs 30 against a 25% rule: P1
        $this->event($b, $cB, ['cpa_current' => 31.0], 'info');    // P3

        $board = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/attention')->assertOk()->json('data');
        $rows = collect($board['rows'])->keyBy('name');

        $hot = $this->openAlerts($a);
        $mild = $this->openAlerts($b);
        $this->assertSame('P1', $hot[0]['priority']);
        $this->assertSame('P3', $mild[0]['priority']);
        $this->assertSame('problem', $rows['Hot']['cells']['meta']['status']);
        $this->assertSame('bad', DigestStatus::alertState($hot));
        $this->assertSame('watch', $rows['Mild']['cells']['meta']['status']);
        $this->assertSame('watch', DigestStatus::alertState($mild));
    }

    public function test_the_rank_follows_priority_then_severity_then_newest(): void
    {
        $mk = static fn (string $project, string $p, int $sev, int $fired): array => ['state' => $p === 'P3' ? 'watch' : 'bad', 'priority' => $p, 'sev' => $sev, 'fired' => $fired, 'reason' => 'alert_bad', 'at_stake' => 0, 'project' => $project, 'order' => 0];
        $items = [$mk('d', 'P3', 0, 900), $mk('c', 'P1', 1, 500), $mk('b', 'P1', 0, 100), $mk('a', 'P1', 0, 800)];
        usort($items, [DigestStatus::class, 'compare']);
        $this->assertSame(['a', 'b', 'c', 'd'], array_column($items, 'project'), 'P1 critical newest, P1 critical older, P1 warning, then P3');
    }
}
