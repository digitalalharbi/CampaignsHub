<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Projects\Lifecycle\InvalidLifecycleTransition;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Domains\Projects\Lifecycle\TrashRetention;
use App\Domains\Projects\Models\Project;
use App\Domains\Subscriptions\Models\SubscriptionPlan;
use App\Domains\Subscriptions\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LifecycleFixtures;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §0–§3 — the contract: one transition function, the log, the live scope, the permissions,
 * archive effects + exact restore, the shared-account rule — on agency, direct-business and client-portal fixtures.
 */
final class ProjectLifecycleContractTest extends TestCase
{
    use LifecycleFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function workspaceTypes(): array
    {
        return ['agency' => ['agency'], 'direct business' => ['business'], 'client portal' => ['portal']];
    }

    private function lifecycle(): ProjectLifecycle
    {
        return app(ProjectLifecycle::class);
    }

    public function test_every_transition_is_logged_with_actor_time_and_name_snapshot(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Launch');

        $this->lifecycle()->transition($project, ProjectStatus::Paused, $ws['owner'], 'test');
        $this->assertSame('paused', $project->refresh()->status);

        $row = DB::table('project_status_transitions')->where('project_id', $project->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(['active', 'paused', (int) $ws['owner']->id, 'Launch'], [$row->from_status, $row->to_status, (int) $row->actor_id, $row->name_snapshot]);
        $this->assertNotNull($row->created_at);
        $this->assertTrue(DB::table('audit_logs')->where('action', 'project.lifecycle.paused')->where('entity_id', $project->id)->exists());
    }

    public function test_a_disallowed_transition_is_refused_and_nothing_is_written(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Draft one', 'draft');

        try {
            $this->lifecycle()->transition($project, ProjectStatus::Paused, $ws['owner']);
            $this->fail('draft → paused must be refused');
        } catch (InvalidLifecycleTransition) {
        }
        $this->assertSame('draft', $project->refresh()->status);
        $this->assertSame(0, DB::table('project_status_transitions')->where('project_id', $project->id)->count());
    }

    public function test_writing_the_status_outside_the_transition_function_throws(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'P');

        $this->expectException(LogicException::class);
        $project->update(['status' => 'archived']);
    }

    public function test_legacy_creation_values_start_in_their_canonical_state(): void
    {
        $ws = $this->lifecycleWorkspace();
        $this->assertSame('active', $this->lifecycleProject($ws['tenant'], $ws['client'], 'Legacy', 'setup')->status);
        $this->assertSame('active', Project::withoutGlobalScopes()->create(['tenant_id' => $ws['tenant']->id, 'client_workspace_id' => $ws['client']->id, 'name' => 'No status'])->status);
    }

    public function test_the_first_linked_account_makes_a_draft_active_through_the_machine(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'New', 'draft');
        $account = $this->lifecycleAccount($ws['tenant']);

        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $ws['tenant']->id, 'client_workspace_id' => $ws['client']->id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'advertising', 'is_active' => true, 'sync_enabled' => true,
        ]);

        $this->assertSame('active', $project->refresh()->status);
        $this->assertSame('account_linked', DB::table('project_status_transitions')->where('project_id', $project->id)->value('reason'));
    }

    public function test_live_scope_is_active_and_paused_and_ignores_client_state(): void
    {
        $ws = $this->lifecycleWorkspace(); // client is «onboarding»
        $active = $this->lifecycleProject($ws['tenant'], $ws['client'], 'A');
        $paused = $this->lifecycleProject($ws['tenant'], $ws['client'], 'P', 'paused');
        $draft = $this->lifecycleProject($ws['tenant'], $ws['client'], 'D', 'draft');
        $archived = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Ar');
        $this->lifecycle()->archive($archived, $ws['owner']);
        $deleted = $this->lifecycleProject($ws['tenant'], $ws['client'], 'De');
        $this->lifecycle()->transition($deleted, ProjectStatus::Deleted, $ws['owner']);

        $expected = collect([(string) $active->id, (string) $paused->id])->sort()->values()->all();
        $this->assertSame($expected, collect(LiveProjects::ids((string) $ws['tenant']->id))->sort()->values()->all());
        $this->assertSame($expected, Project::withoutGlobalScopes()->live()->pluck('id')->map(fn ($i) => (string) $i)->sort()->values()->all());
        $this->assertSame($expected, DB::table('live_projects')->where('tenant_id', $ws['tenant']->id)->pluck('id')->map(fn ($i) => (string) $i)->sort()->values()->all());
        $this->assertFalse(LiveProjects::isLive((string) $draft->id));

        // Client state changes never move a project in or out of the scope (rule 4).
        $this->lifecycle()->transitionClient($ws['client'], ClientStatus::Paused, $ws['owner']);
        $this->assertCount(2, LiveProjects::ids((string) $ws['tenant']->id));
    }

    public function test_client_transitions_are_logged_and_checked(): void
    {
        $ws = $this->lifecycleWorkspace();
        $this->lifecycle()->transitionClient($ws['client'], ClientStatus::Active, $ws['owner'], 'kickoff');
        $this->lifecycle()->transitionClient($ws['client'], ClientStatus::Archived, $ws['owner']);
        $this->assertNotNull($ws['client']->refresh()->archived_at);
        $this->assertSame('active', $ws['client']->previous_status);

        $this->assertSame(['onboarding→active', 'active→archived'], DB::table('client_status_transitions')->where('client_workspace_id', $ws['client']->id)
            ->orderBy('created_at')->get()->map(fn ($r) => $r->from_status.'→'.$r->to_status)->all());

        $this->expectException(InvalidLifecycleTransition::class);
        $this->lifecycle()->transitionClient($ws['client'], ClientStatus::Archived, $ws['owner']);
    }

    #[DataProvider('workspaceTypes')]
    public function test_archive_stops_everything_and_unarchive_restores_it_exactly(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Spring');
        $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $project);
        $ids = $this->lifecycleOperational($project);
        $before = $this->operationalState($project);

        $result = $this->lifecycle()->archive($project, $ws['owner']);

        $this->assertSame('archived', $project->refresh()->status);
        $this->assertSame(1, $result['effects']['binding_sync_stopped']);
        $this->assertSame(1, $result['effects']['task_closed']);
        $this->assertSame(1, $result['effects']['alert_closed']);
        $after = $this->operationalState($project);
        $this->assertSame([false, true], [$after['binding_sync'], $after['binding_active']]);
        $this->assertFalse($after['schedule_active']);
        $this->assertNull($after['schedule_next_run']);
        $this->assertSame([false, false, false], [$after['alert_rule_active'], $after['automation_enabled'], $after['routine_enabled']]);
        $this->assertSame('cancelled', $after['task_status']);
        $this->assertSame('resolved', $after['alert_status']);
        $this->assertSame('المشروع مؤرشف', json_decode((string) DB::table('alert_events')->where('id', $ids['alert_event'])->value('context'), true)['closed_reason']);
        $this->assertSame('المشروع مؤرشف', json_decode((string) DB::table('tasks')->where('id', $ids['task'])->value('meta'), true)['closed_reason']);

        $this->lifecycle()->unarchive($project, $ws['owner'], true);

        $this->assertSame('active', $project->refresh()->status);
        $restored = $this->operationalState($project);
        // Exact: every switch is back; the alert stays closed (a point-in-time signal — the evaluator re-raises it).
        $this->assertSame(array_merge($before, ['alert_status' => 'resolved']), $restored);
        $this->assertNull(json_decode((string) DB::table('tasks')->where('id', $ids['task'])->value('meta'), true));
    }

    public function test_unarchive_needs_confirmation_and_returns_to_the_previous_state(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Paused one', 'paused');
        $this->lifecycle()->archive($project, $ws['owner']);

        try {
            $this->lifecycle()->unarchive($project, $ws['owner'], false);
            $this->fail('un-archive without confirmation must be refused');
        } catch (InvalidLifecycleTransition) {
        }
        $this->assertSame('archived', $project->refresh()->status);

        $this->lifecycle()->unarchive($project, $ws['owner'], true);
        $this->assertSame('paused', $project->refresh()->status);
    }

    public function test_unlink_option_unlinks_and_restore_relinks(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Unlinked');
        $binding = $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $project);

        $result = $this->lifecycle()->archive($project, $ws['owner'], true);
        $this->assertSame(1, $result['effects']['binding_unlinked']);
        $this->assertFalse((bool) DB::table('project_integration_bindings')->where('id', $binding)->value('is_active'));

        $this->lifecycle()->unarchive($project, $ws['owner'], true);
        $row = DB::table('project_integration_bindings')->where('id', $binding)->first();
        $this->assertSame([true, true], [(bool) $row->is_active, (bool) $row->sync_enabled]);
    }

    public function test_a_shared_account_warns_and_only_this_projects_link_stops(): void
    {
        $ws = $this->lifecycleWorkspace();
        $account = $this->lifecycleAccount($ws['tenant']);
        $mine = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Mine');
        $other = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Other');
        $myBinding = $this->lifecycleBind($account, $mine);
        $otherBinding = $this->lifecycleBind($account, $other);
        $otherOps = $this->lifecycleOperational($other);
        $otherBefore = $this->operationalState($other);

        $impact = $this->lifecycle()->archiveImpact($mine);
        $this->assertCount(1, $impact['shared_accounts']);
        $this->assertSame('Other', $impact['shared_accounts'][0]['other_projects'][0]['name']);

        $this->lifecycle()->archive($mine, $ws['owner'], true);

        $this->assertFalse((bool) DB::table('project_integration_bindings')->where('id', $myBinding)->value('is_active'));
        $o = DB::table('project_integration_bindings')->where('id', $otherBinding)->first();
        $this->assertSame([true, true], [(bool) $o->is_active, (bool) $o->sync_enabled]);
        $this->assertSame($otherBefore, $this->operationalState($other), 'nothing of the other project moved');
        $this->assertSame('active', $other->refresh()->status);
        $this->assertNotEmpty($otherOps);
    }

    #[DataProvider('workspaceTypes')]
    public function test_a_member_without_the_permission_is_refused_by_the_api(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Guarded');

        $this->actingAs($ws['member'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
        $this->actingAs($ws['member'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/archive-impact")->assertForbidden();
        $this->assertSame('active', $project->refresh()->status);

        if ($ws['portal_user'] !== null) {
            $this->actingAs($ws['portal_user'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
        }

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/archive", ['unlink_accounts' => false])
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'archived')->assertJsonPath('data.lifecycle.transition.to', 'archived');

        $this->actingAs($ws['member'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])->assertForbidden();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore")->assertUnprocessable();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'active');

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/lifecycle")
            ->assertOk()->assertJsonCount(2, 'data.transitions')->assertJsonPath('data.transitions.0.to', 'active');
    }

    public function test_a_project_of_another_workspace_is_not_reachable(): void
    {
        $a = $this->lifecycleWorkspace('agency', 'a');
        $b = $this->lifecycleWorkspace('agency', 'b');
        $project = $this->lifecycleProject($b['tenant'], $b['client'], 'Theirs');

        $this->actingAs($a['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/archive")->assertNotFound();
        $this->assertSame('active', $project->refresh()->status);
    }

    public function test_trash_retention_reads_the_plan_feature_with_a_30_day_default(): void
    {
        $ws = $this->lifecycleWorkspace();
        $this->assertSame(30, app(TrashRetention::class)->daysFor((string) $ws['tenant']->id));

        $plan = SubscriptionPlan::create(['code' => 'lc-'.uniqid(), 'name' => 'Plan', 'price_monthly' => 10, 'currency' => 'SAR', 'features' => [TrashRetention::FEATURE => 45], 'limits' => [], 'is_active' => true]);
        app(SubscriptionService::class)->assignPlan($ws['tenant'], $plan);
        $this->assertSame(45, app(TrashRetention::class)->daysFor((string) $ws['tenant']->id));

        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Trash');
        $this->lifecycle()->transition($project, ProjectStatus::Deleted, $ws['owner']);
        $row = Project::withoutGlobalScopes()->find($project->id);
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(45, (int) round($row->deleted_at->diffInDays($row->purge_after)));
    }

    public function test_trash_retention_reads_the_entitlement_table_first_then_a_workspace_override(): void
    {
        // RELEASE-INTEGRATION — builder M's plan-features table: an admin row's limit wins over the plan JSON and the default.
        $ws = $this->lifecycleWorkspace();
        $tenant = (string) $ws['tenant']->id;
        DB::table('notification_plan_features')->insert(['id' => (string) Str::uuid(), 'key' => TrashRetention::FEATURE, 'plan_code' => null, 'workspace_type' => null,
            'enabled' => true, 'limits' => json_encode([TrashRetention::LIMIT => 60]), 'grace_days' => 14, 'keep_existing' => true, 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        $this->assertSame(60, app(TrashRetention::class)->daysFor($tenant));

        DB::table('tenant_limit_overrides')->insert(['tenant_id' => $tenant, 'metric' => 'notif.'.TrashRetention::LIMIT, 'limit_value' => 90, 'reason' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        app()->forgetScopedInstances();
        $this->assertSame(90, app(TrashRetention::class)->daysFor($tenant));

        // Zero never empties the trash early: the default applies.
        DB::table('tenant_limit_overrides')->where('tenant_id', $tenant)->update(['limit_value' => 0]);
        app()->forgetScopedInstances();
        $this->assertSame(30, app(TrashRetention::class)->daysFor($tenant));
    }

    /** @return array<string,mixed> */
    private function operationalState(Project $project): array
    {
        $p = (string) $project->id;
        $binding = DB::table('project_integration_bindings')->where('project_id', $p)->first();
        $schedule = DB::table('report_schedules')->where('project_id', $p)->first();

        return [
            'binding_sync' => $binding === null ? null : (bool) $binding->sync_enabled,
            'binding_active' => $binding === null ? null : (bool) $binding->is_active,
            'schedule_active' => $schedule === null ? null : (bool) $schedule->active,
            'schedule_next_run' => $schedule?->next_run_at === null ? null : (string) Carbon::parse($schedule->next_run_at)->toIso8601String(),
            'alert_rule_active' => (bool) DB::table('alert_rules')->where('project_id', $p)->value('active'),
            'automation_enabled' => (bool) DB::table('automation_rules')->where('project_id', $p)->value('enabled'),
            'routine_enabled' => (bool) DB::table('agent_routines')->where('project_id', $p)->value('enabled'),
            'task_status' => DB::table('tasks')->where('project_id', $p)->value('status'),
            'alert_status' => DB::table('alert_events')->where('project_id', $p)->value('status'),
        ];
    }
}
