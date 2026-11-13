<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Alerts\Models\AlertEvent;
use App\Domains\Alerts\Models\AlertRule;
use App\Domains\Alerts\Services\AlertRuleRecipients;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCeilingFixture;
use Tests\TestCase;

/**
 * ALERT-RULES-MANAGE-001 — master task 3: an alert rule can be edited, paused and removed, and names who hears it.
 *
 * The API had GET and POST only, so a rule somebody regretted could neither be changed nor switched off. Every
 * mutation here keeps the same tenant + client-ceiling boundary as the ledger: a rule outside the reader's reach is
 * a 404, never a 403 that confirms it exists.
 */
final class AlertRulesManagementTest extends TestCase
{
    use BuildsCeilingFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCeilingFixture();
    }

    private function rule(?string $projectId, array $over = []): AlertRule
    {
        return AlertRule::create(array_merge([
            'tenant_id' => $this->agency->id, 'project_id' => $projectId, 'type' => 'cpa_increase', 'name' => 'CPA watch',
            'threshold' => ['pct' => 20], 'cooldown_minutes' => 720, 'channels' => ['in_app', 'email'], 'severity' => 'warning', 'active' => true,
        ], $over));
    }

    private function event(AlertRule $rule, string $status = 'open'): AlertEvent
    {
        return AlertEvent::create(['tenant_id' => $this->agency->id, 'project_id' => $rule->project_id, 'rule_id' => $rule->id, 'type' => $rule->type,
            'dedup_key' => hash('sha256', (string) Str::uuid()), 'status' => $status, 'severity' => 'warning',
            'context' => ['title' => 'x'], 'last_triggered_at' => now()]);
    }

    public function test_a_rule_is_edited_in_place_and_only_what_is_sent_changes(): void
    {
        $rule = $this->rule((string) $this->projectA->id);

        $res = $this->actingAs($this->owner(), 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", [
            'name' => 'تكلفة الطلب', 'threshold' => ['pct' => 35], 'severity' => 'critical', 'cooldown_minutes' => 1440, 'channels' => ['in_app'],
        ])->assertOk();

        $this->assertSame('تكلفة الطلب', $res->json('data.name'));
        $fresh = $rule->fresh();
        $this->assertSame(['pct' => 35], $fresh->threshold);
        $this->assertSame('critical', $fresh->severity);
        $this->assertSame(1440, $fresh->cooldown_minutes);
        $this->assertSame(['in_app'], $fresh->channels);
        $this->assertSame('cpa_increase', $fresh->type);
        $this->assertSame((string) $this->projectA->id, (string) $fresh->project_id);
        $this->assertTrue($fresh->active);
    }

    public function test_pausing_stops_the_sweep_and_keeps_the_alerts_already_raised(): void
    {
        $rule = $this->rule((string) $this->projectA->id);
        $open = $this->event($rule);

        $this->actingAs($this->owner(), 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['active' => false])->assertOk();

        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $this->assertFalse($rule->fresh()->active);
        $this->assertSame(1, AlertRule::query()->count());
        $this->assertSame(0, AlertRule::query()->where('active', true)->count(), 'the evaluator sweeps active rules only');
        $this->assertSame('open', $open->fresh()->status);

        $this->actingAs($this->owner(), 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['active' => true])->assertOk();
        $this->assertTrue($rule->fresh()->active);
    }

    public function test_an_edit_cannot_change_the_type_or_store_a_threshold_that_silences_the_rule(): void
    {
        $rule = $this->rule((string) $this->projectA->id);
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['type' => 'roas_drop'])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['threshold' => ['pct' => 0]])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['threshold' => ['pctt' => 20]])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['cooldown_minutes' => 1])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['name' => 'x'])->assertStatus(422);

        $this->assertSame(['pct' => 20], $rule->fresh()->threshold);
        $this->assertSame('CPA watch', $rule->fresh()->name);
    }

    public function test_removing_a_rule_keeps_its_history_and_closes_its_open_alerts_silently(): void
    {
        $rule = $this->rule((string) $this->projectA->id);
        $open = $this->event($rule);
        $snoozed = $this->event($rule, 'snoozed');
        $resolved = $this->event($rule, 'resolved');

        $this->actingAs($this->owner(), 'sanctum')->deleteJson("/api/v1/alerts/rules/{$rule->id}")->assertOk();
        app(TenantContext::class)->setTenantId((string) $this->agency->id); // the request leaves the context cleared

        $row = DB::table('alert_rules')->where('id', $rule->id)->first();
        $this->assertNotNull($row?->deleted_at, 'removed from every list');
        $trashed = AlertRule::withoutGlobalScopes()->withTrashed()->find($rule->id);
        $this->assertNotNull($trashed, 'soft-deleted, so the alerts it raised survive the FK cascade');
        $this->assertFalse($trashed->active, 'switched off, so the evaluator (which reads active = true) never sweeps it');

        $this->assertSame(3, AlertEvent::query()->where('rule_id', $rule->id)->count());
        foreach ([$open, $snoozed] as $e) {
            $this->assertSame('resolved', $e->fresh()->status);
            $this->assertSame('rule_removed', $e->fresh()->context['closed_reason']);
        }
        $this->assertArrayNotHasKey('closed_reason', (array) $resolved->fresh()->context);

        $names = array_column($this->actingAs($this->owner(), 'sanctum')->getJson('/api/v1/alerts/rules')->assertOk()->json('data'), 'id');
        $this->assertNotContains($rule->id, $names);
        $this->actingAs($this->owner(), 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['name' => 'back'])->assertNotFound();
    }

    public function test_view_only_cannot_edit_or_remove(): void
    {
        $rule = $this->rule((string) $this->projectA->id);
        $viewer = $this->operatorIn($this->agency, ['alerts.view', 'clients.view_all']);

        $this->actingAs($viewer, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['active' => false])->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->deleteJson("/api/v1/alerts/rules/{$rule->id}")->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/alerts/rules/recipients')->assertForbidden();
        $this->assertTrue($rule->fresh()->active);
    }

    public function test_a_scoped_member_cannot_touch_a_sibling_or_workspace_wide_rule_nor_move_one_out_of_reach(): void
    {
        $ruleA = $this->rule((string) $this->projectA->id);
        $wide = $this->rule(null);
        $ruleB = $this->rule((string) $this->projectB->id);
        $op = $this->scopedTo($this->clientB, ['alerts.view', 'alerts.manage']);

        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/alerts/rules/{$ruleA->id}", ['active' => false])->assertNotFound();
        $this->actingAs($op, 'sanctum')->deleteJson("/api/v1/alerts/rules/{$ruleA->id}")->assertNotFound();
        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/alerts/rules/{$wide->id}", ['active' => false])->assertNotFound();
        $this->assertTrue($ruleA->fresh()->active);
        $this->assertTrue($wide->fresh()->active);

        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/alerts/rules/{$ruleB->id}", ['project_id' => (string) $this->projectA->id])->assertStatus(422);
        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/alerts/rules/{$ruleB->id}", ['project_id' => null])->assertForbidden();
        $this->assertSame((string) $this->projectB->id, (string) $ruleB->fresh()->project_id);

        $this->actingAs($op, 'sanctum')->patchJson("/api/v1/alerts/rules/{$ruleB->id}", ['active' => false])->assertOk();
    }

    public function test_another_workspaces_rule_does_not_exist_here(): void
    {
        $rule = $this->rule((string) $this->projectA->id);
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        $stranger = $this->operatorIn($other);

        $this->actingAs($stranger, 'sanctum')->patchJson("/api/v1/alerts/rules/{$rule->id}", ['active' => false])->assertNotFound();
        $this->actingAs($stranger, 'sanctum')->deleteJson("/api/v1/alerts/rules/{$rule->id}")->assertNotFound();
        $this->assertTrue($rule->fresh()->active);
        $this->assertNull($rule->fresh()->deleted_at);
    }

    public function test_recipients_name_members_who_reach_the_project_and_nobody_else(): void
    {
        $owner = $this->owner();
        $memberA = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $memberB = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientB->id]);
        $clientLogin = User::create(['name' => 'Client', 'email' => 'client-'.uniqid().'@ceiling.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($clientLogin, $this->agency, Portal::ClientPortal, 'member', [(string) $this->clientA->id]);
        $other = Tenant::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active', 'account_type' => 'agency',
            'enabled_modules' => ['paid_media'], 'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        $stranger = $this->operatorIn($other);

        $options = array_column($this->actingAs($owner, 'sanctum')->getJson('/api/v1/alerts/rules/recipients?project='.$this->projectA->id)->assertOk()->json('data'), 'id');
        $this->assertContains((int) $memberA->id, $options);
        $this->assertNotContains((int) $memberB->id, $options, 'cannot see project A');
        $this->assertNotContains((int) $clientLogin->id, $options, 'a client-portal login is never an alert recipient');
        $this->assertNotContains((int) $stranger->id, $options, 'another workspace');

        $rule = $this->rule((string) $this->projectA->id);
        $url = "/api/v1/alerts/rules/{$rule->id}";
        foreach ([$memberB, $clientLogin, $stranger] as $refused) {
            $this->actingAs($owner, 'sanctum')->patchJson($url, ['recipients' => ['user_ids' => [(int) $refused->id]]])
                ->assertStatus(422)->assertJsonValidationErrors('recipients.user_ids');
        }
        $this->actingAs($owner, 'sanctum')->patchJson($url, ['recipients' => ['user_ids' => [999999]]])->assertStatus(422);
        $this->actingAs($owner, 'sanctum')->patchJson($url, ['recipients' => ['emails' => ['x@y.z']]])->assertStatus(422);
        $this->assertNull($rule->fresh()->recipients);

        $this->actingAs($owner, 'sanctum')->patchJson($url, ['recipients' => ['project_manager' => true, 'user_ids' => [(int) $memberA->id]]])->assertOk();
        $this->assertEquals(['project_manager' => true, 'user_ids' => [(int) $memberA->id]], $rule->fresh()->recipients);

        // Moving the rule to a project memberA cannot see refuses, rather than carrying them along.
        $this->actingAs($owner, 'sanctum')->patchJson($url, ['project_id' => (string) $this->projectB->id])->assertStatus(422);
        $this->assertSame((string) $this->projectA->id, (string) $rule->fresh()->project_id);

        // Naming nobody and not asking for the manager is the default again.
        $this->actingAs($owner, 'sanctum')->patchJson($url, ['recipients' => ['project_manager' => false, 'user_ids' => []]])->assertOk();
        $this->assertNull($rule->fresh()->recipients);
    }

    public function test_a_new_rule_can_name_its_recipients_and_they_are_checked_too(): void
    {
        $owner = $this->owner();
        $memberA = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $memberB = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientB->id]);
        $body = ['type' => 'sync_failure', 'name' => 'Sync', 'project_id' => (string) $this->projectA->id];

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/alerts/rules', $body + ['recipients' => ['user_ids' => [(int) $memberB->id]]])->assertStatus(422);
        $res = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/alerts/rules', $body + ['recipients' => ['user_ids' => [(int) $memberA->id]]])->assertCreated();
        $this->assertEquals(['project_manager' => false, 'user_ids' => [(int) $memberA->id]], $res->json('data.recipients'));
    }

    public function test_at_send_time_the_rule_names_the_projects_manager_and_drops_anybody_who_lost_access(): void
    {
        $manager = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $named = $this->operatorIn($this->agency, ['alerts.view'], [(string) $this->clientA->id]);
        $this->projectA->forceFill(['account_manager_id' => $manager->id])->save();
        $rule = $this->rule((string) $this->projectA->id, ['recipients' => ['project_manager' => true, 'user_ids' => [(int) $named->id]]]);
        $service = app(AlertRuleRecipients::class);

        $ids = array_map(fn (User $u) => (int) $u->id, $service->resolve($rule, (string) $this->projectA->id));
        sort($ids);
        $expected = [(int) $manager->id, (int) $named->id];
        sort($expected);
        $this->assertSame($expected, $ids);

        DB::table('memberships')->where('user_id', $named->id)->update(['status' => 'suspended']);
        $ids = array_map(fn (User $u) => (int) $u->id, $service->resolve($rule, (string) $this->projectA->id));
        $this->assertSame([(int) $manager->id], $ids);

        $this->assertNull($service->resolve($this->rule(null), null), 'a rule naming nobody keeps the default path');
    }
}
