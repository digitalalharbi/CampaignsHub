<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * SEC-CLUSTER-4 — the alert ledger and rules honour the membership's client ceiling: the list, the project
 * filter, the rule's project and the resolve/snooze actions.
 */
final class AlertsClientCeilingTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    private function event(?string $projectId, string $title): AlertEvent
    {
        $rule = AlertRule::create(['tenant_id' => $this->agency->id, 'project_id' => $projectId, 'type' => 'sync_failure', 'name' => 'R '.$title, 'active' => true]);

        return AlertEvent::create(['tenant_id' => $this->agency->id, 'project_id' => $projectId, 'rule_id' => $rule->id, 'type' => 'sync_failure',
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => 'open', 'severity' => 'warning',
            'context' => ['title' => $title], 'last_triggered_at' => now()]);
    }

    public function test_a_scoped_member_reads_only_their_clients_alerts_and_rules(): void
    {
        $this->event((string) $this->projectA->id, 'A-alert');
        $this->event((string) $this->projectB->id, 'B-alert');
        $this->event(null, 'tenant-wide');
        $op = $this->scopedTo($this->clientB, ['alerts.view', 'alerts.manage']);

        $events = $this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();
        $titles = array_map(fn ($e) => $e['context']['title'], $events->json('data'));
        $this->assertSame(['B-alert'], $titles);
        $this->assertSame(1, $events->json('meta.counts.open'));

        $rules = array_column($this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/rules')->assertOk()->json('data'), 'name');
        $this->assertSame(['R B-alert'], $rules);

        $owner = $this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/events')->assertOk();
        $this->assertCount(3, $owner->json('data'));
    }

    public function test_a_sibling_project_filter_is_not_honoured(): void
    {
        $this->event((string) $this->projectA->id, 'A-alert');
        $op = $this->scopedTo($this->clientB, ['alerts.view']);

        $this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/events?project='.$this->projectA->id)->assertNotFound();
        $this->actingAs($op, 'sanctum')->getJson('/api/v1/alerts/events?project='.$this->projectB->id)->assertOk();
    }

    public function test_a_rule_cannot_be_created_for_a_sibling_or_foreign_project_or_for_nobody(): void
    {
        $op = $this->scopedTo($this->clientB, ['alerts.view', 'alerts.manage']);
        $body = ['type' => 'sync_failure', 'name' => 'Rule'];

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/alerts/rules', $body + ['project_id' => (string) $this->projectA->id])->assertStatus(422);
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/alerts/rules', $body + ['project_id' => (string) Str::uuid()])->assertStatus(422);
        $this->actingAs($op, 'sanctum')->postJson('/api/v1/alerts/rules', $body)->assertForbidden();
        $this->assertSame(0, AlertRule::query()->where('name', 'Rule')->count());

        $this->actingAs($op, 'sanctum')->postJson('/api/v1/alerts/rules', $body + ['project_id' => (string) $this->projectB->id])->assertCreated();
        $this->actingAs($this->owner(), 'sanctum')->postJson('/api/v1/alerts/rules', $body)->assertCreated();
    }

    public function test_a_scoped_member_cannot_resolve_or_snooze_a_sibling_clients_event(): void
    {
        $eventA = $this->event((string) $this->projectA->id, 'A-alert');
        $eventB = $this->event((string) $this->projectB->id, 'B-alert');
        $op = $this->scopedTo($this->clientB, ['alerts.view', 'alerts.manage']);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$eventA->id}/resolve")->assertNotFound();
        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$eventA->id}/snooze", ['minutes' => 30])->assertNotFound();
        $this->assertSame('open', $eventA->fresh()->status);

        $this->actingAs($op, 'sanctum')->postJson("/api/v1/alerts/events/{$eventB->id}/resolve")->assertOk();
    }
}
