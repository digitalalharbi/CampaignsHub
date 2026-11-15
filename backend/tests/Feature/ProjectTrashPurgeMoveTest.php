<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Lifecycle\LifecycleNotices;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectPurger;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\LifecycleFixtures;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §4–§5, §10 (builder N2) — soft delete, «المحذوفات», restore (with re-link), permanent delete
 * (manual + automatic), the client choice, move inside the workspace, client archive — on agency, direct-business and
 * client-portal fixtures. Generated fixtures only; no production id.
 */
final class ProjectTrashPurgeMoveTest extends TestCase
{
    use LifecycleFixtures;
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function workspaceTypes(): array
    {
        return ['agency' => ['agency'], 'direct business' => ['business'], 'client portal' => ['portal']];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // No ad platform is ever called by delete/purge/move: any outbound request fails the test.
        Http::preventStrayRequests();
    }

    private function deleteAsOwner(array $ws, Project $project, array $extra = []): TestResponse
    {
        return $this->actingAs($ws['owner'], 'sanctum')->deleteJson("/api/v1/projects/{$project->id}", ['confirm_name' => $project->name] + $extra);
    }

    private function metric(Project $project): void
    {
        $account = $this->lifecycleAccount(Tenant::withoutGlobalScopes()->findOrFail($project->tenant_id));
        $campaign = (string) Str::uuid();
        DB::table('external_campaigns')->insert([
            'id' => $campaign, 'tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => 'snapchat', 'external_id' => 'c-'.Str::random(6), 'name' => 'C', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $project->tenant_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'external_campaign_id' => $campaign,
            'provider' => 'snapchat', 'metric_key' => 'spend', 'metric_date' => '2026-11-10', 'value' => 12.5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    #[DataProvider('workspaceTypes')]
    public function test_soft_delete_hides_the_project_and_restore_returns_everything(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Doomed');
        $ops = $this->lifecycleOperational($project);
        $binding = $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $project);

        $this->deleteAsOwner($ws, $project)->assertOk()->assertJsonPath('data.client_deleted', false)
            ->assertJsonPath('data.lifecycle.effects.binding_unlinked', 1)
            ->assertJsonPath('data.lifecycle.effects.report_schedule_paused', 1);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}")->assertNotFound();
        $row = Project::withoutGlobalScopes()->find($project->id);
        $this->assertSame('deleted', $row->status);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $row->purge_after->timestamp, 5);
        $this->assertFalse((bool) DB::table('project_integration_bindings')->where('id', $binding)->value('is_active'));
        $this->assertSame('cancelled', DB::table('tasks')->where('id', $ops['task'])->value('status'));
        $this->assertFalse((bool) DB::table('alert_rules')->where('id', $ops['alert_rule'])->value('active'));

        // «المحذوفات» lists it; the normal lists do not.
        $this->actingAs($ws['owner'], 'sanctum')->getJson('/api/v1/projects?include_archived=1')->assertJsonCount(0, 'data');
        $this->actingAs($ws['owner'], 'sanctum')->getJson('/api/v1/projects?status=deleted')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.lifecycle_status', 'deleted')->assertJsonPath('data.0.id', (string) $project->id);

        // The owner is told (internal notice, owner only).
        $this->assertTrue(DB::table('app_notifications')->where('type', LifecycleNotices::DELETED)->where('user_id', $ws['owner']->id)->exists());
        $this->assertFalse(DB::table('app_notifications')->where('type', LifecycleNotices::DELETED)->where('user_id', $ws['member']->id)->exists());

        // Restore window: what comes back, and the account still available.
        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/restore-impact")->assertOk()
            ->assertJsonPath('data.restores_to', 'active')->assertJsonPath('data.relink_accounts.0.available', true);
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'active');

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->assertTrue((bool) DB::table('project_integration_bindings')->where('id', $binding)->value('is_active'));
        $this->assertTrue((bool) DB::table('project_integration_bindings')->where('id', $binding)->value('sync_enabled'));
        $this->assertSame('in_progress', DB::table('tasks')->where('id', $ops['task'])->value('status'));
        $this->assertTrue((bool) DB::table('alert_rules')->where('id', $ops['alert_rule'])->value('active'));
        $this->assertTrue((bool) DB::table('report_schedules')->where('id', $ops['schedule'])->value('active'));
    }

    public function test_restore_without_relink_keeps_the_accounts_unlinked_and_a_removed_account_is_not_relinked(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'P');
        $kept = $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $project);
        $this->deleteAsOwner($ws, $project)->assertOk();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true, 'relink_accounts' => false])->assertOk();
        $this->assertFalse((bool) DB::table('project_integration_bindings')->where('id', $kept)->value('is_active'));

        $other = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Q');
        $account = $this->lifecycleAccount($ws['tenant']);
        $gone = $this->lifecycleBind($account, $other);
        $this->deleteAsOwner($ws, $other)->assertOk();
        DB::table('external_accounts')->where('id', $account->id)->update(['status' => 'removed']);
        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$other->id}/restore-impact")->assertJsonPath('data.relink_accounts.0.available', false);
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$other->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle.skipped', fn ($s) => isset($s['binding_unlinked:skipped_unavailable']));
        $this->assertFalse((bool) DB::table('project_integration_bindings')->where('id', $gone)->value('is_active'));
    }

    public function test_an_archived_project_deleted_and_restored_returns_to_archived_and_then_to_its_state_before(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Paused one', 'paused');
        $ops = $this->lifecycleOperational($project);
        app(ProjectLifecycle::class)->archive($project, $ws['owner']);
        $this->assertFalse((bool) DB::table('agent_routines')->where('id', $ops['routine'])->value('enabled'));

        $this->deleteAsOwner($ws, $project)->assertOk();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'archived');
        // Still archived: the archive's own effects stay until it is un-archived.
        $this->assertFalse((bool) DB::table('agent_routines')->where('id', $ops['routine'])->value('enabled'));
        $this->assertSame('paused', Project::withoutGlobalScopes()->find($project->id)->previous_status);

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle_status', 'paused');
        $this->assertTrue((bool) DB::table('agent_routines')->where('id', $ops['routine'])->value('enabled'));
    }

    #[DataProvider('workspaceTypes')]
    public function test_permanent_delete_removes_the_data_and_keeps_the_logs_with_the_name_snapshot(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Gone for good');
        $neighbour = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Neighbour');
        $account = $this->lifecycleAccount($ws['tenant']);
        $this->lifecycleBind($account, $project);
        $theirs = $this->lifecycleBind($account, $neighbour); // «مشترك»
        $ops = $this->lifecycleOperational($project);
        $this->lifecycleOperational($neighbour);
        $this->metric($project);
        $this->metric($neighbour);
        DB::table('invoices')->insert(['tenant_id' => $ws['tenant']->id, 'client_workspace_id' => $ws['client']->id, 'project_id' => $project->id, 'number' => 'INV-T-1', 'status' => 'draft', 'currency' => 'SAR', 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteAsOwner($ws, $project)->assertOk();

        // Not before the retention, not by somebody without projects.purge, not with the wrong name.
        $this->actingAs($ws['member'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/purge", ['confirm_name' => $project->name])->assertForbidden();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/purge", ['confirm_name' => 'nope'])->assertUnprocessable();
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $project->id)->count());

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/purge", ['confirm_name' => $project->name])
            ->assertOk()->assertJsonPath('data.purged', true)->assertJsonPath('data.ad_platforms_untouched', true);

        // Data gone.
        foreach (['daily_metrics', 'reports', 'report_schedules', 'alert_rules', 'alert_events', 'tasks', 'automation_rules', 'agent_routines', 'project_integration_bindings'] as $table) {
            $this->assertSame(0, DB::table($table)->where('project_id', $project->id)->count(), "{$table} rows of the purged project remain");
        }
        $this->assertFalse(DB::table('report_shares')->where('report_id', $ops['report'])->exists());
        // Client-level record detached, not destroyed.
        $this->assertNull(DB::table('invoices')->where('number', 'INV-T-1')->value('project_id'));
        // Logs kept, with the name snapshot.
        $this->assertSame('Gone for good', DB::table('project_status_transitions')->where('project_id', $project->id)->where('to_status', 'deleted')->value('name_snapshot'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'project.purged')->where('entity_id', $project->id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'project.deleted')->where('entity_id', $project->id)->exists());
        $this->assertTrue(DB::table('app_notifications')->where('project_id', $project->id)->where('type', LifecycleNotices::DELETED)->exists());
        $this->assertGreaterThan(0, DB::table('project_archive_effects')->where('project_id', $project->id)->count());
        // Tombstone: never listed again, never restorable.
        $this->assertNotNull(DB::table('projects')->where('id', $project->id)->value('purged_at'));
        $this->actingAs($ws['owner'], 'sanctum')->getJson('/api/v1/projects?status=deleted')->assertJsonCount(0, 'data');
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])->assertNotFound();

        // The neighbour on the same account is untouched; the account itself is untouched.
        $this->assertTrue((bool) DB::table('project_integration_bindings')->where('id', $theirs)->value('is_active'));
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $neighbour->id)->count());
        $this->assertSame(1, DB::table('reports')->where('project_id', $neighbour->id)->count());
        $this->assertTrue(DB::table('external_accounts')->where('id', $account->id)->exists());
        $this->assertTrue(DB::table('provider_connections')->where('id', $account->provider_connection_id)->exists());
    }

    public function test_purge_refuses_a_project_that_is_not_in_the_trash(): void
    {
        $ws = $this->lifecycleWorkspace();
        $live = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Live');
        $archived = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Archived');
        app(ProjectLifecycle::class)->archive($archived, $ws['owner']);
        $this->metric($live);

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$live->id}/purge", ['confirm_name' => 'Live'])->assertUnprocessable();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$archived->id}/purge", ['confirm_name' => 'Archived'])->assertUnprocessable();
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $live->id)->count());

        app(TenantContext::class)->setTenantId((string) $ws['tenant']->id);
        $this->assertSame('not_in_trash', app(ProjectPurger::class)->refusal($live, null, true));
        $this->assertSame('not_in_trash', app(ProjectPurger::class)->refusal($archived, $ws['owner'], false));
    }

    public function test_the_scheduled_job_warns_three_days_before_and_purges_only_what_has_passed(): void
    {
        $ws = $this->lifecycleWorkspace();
        $due = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Due');
        $soon = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Soon');
        $fresh = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Fresh');
        $legacy = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Legacy trash');
        $live = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Live');
        foreach ([$due, $soon, $fresh, $legacy, $live] as $p) {
            $this->metric($p);
        }
        foreach ([$due, $soon, $fresh, $legacy] as $p) {
            $this->deleteAsOwner($ws, $p)->assertOk();
        }
        DB::table('projects')->where('id', $due->id)->update(['purge_after' => now()->subHour()]);
        DB::table('projects')->where('id', $soon->id)->update(['purge_after' => now()->addDays(2)]);
        DB::table('projects')->where('id', $legacy->id)->update(['purge_after' => null]); // soft-deleted before the lifecycle

        // Dry run (the default): nothing changes.
        $this->artisan('lifecycle:purge-expired')->assertSuccessful();
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $due->id)->count());
        $this->assertFalse(DB::table('app_notifications')->where('type', LifecycleNotices::PURGE_SOON)->exists());

        $this->artisan('lifecycle:purge-expired --apply')->assertSuccessful();
        $this->assertSame(0, DB::table('daily_metrics')->where('project_id', $due->id)->count());
        foreach ([$soon, $fresh, $legacy, $live] as $p) {
            $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $p->id)->count(), "{$p->name} was purged");
        }
        $this->assertSame(1, DB::table('app_notifications')->where('type', LifecycleNotices::PURGE_SOON)->where('project_id', $soon->id)->count());

        // Idempotent: a second run purges nothing more and does not warn twice.
        $this->artisan('lifecycle:purge-expired --apply')->assertSuccessful();
        $this->assertSame(1, DB::table('app_notifications')->where('type', LifecycleNotices::PURGE_SOON)->where('project_id', $soon->id)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'project.purged')->count());
    }

    #[DataProvider('workspaceTypes')]
    public function test_deleting_a_project_of_a_client_with_another_project_leaves_the_client_and_the_other_project(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'One');
        $other = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Two');
        $this->lifecycleOperational($other);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/deletion-impact")->assertOk()
            ->assertJsonPath('data.client.other_projects', 1)->assertJsonPath('data.client.can_delete_client', false)
            ->assertJsonPath('data.ad_platforms_untouched', true);

        // Server-enforced, not UI-only: «project and client» is refused while the client has another project.
        $this->deleteAsOwner($ws, $project, ['client_action' => 'project_and_client', 'confirm_client_name' => $ws['client']->name])
            ->assertUnprocessable()->assertJsonValidationErrors('client_action');
        $this->assertNull(Project::withoutGlobalScopes()->find($project->id)->deleted_at);

        $this->deleteAsOwner($ws, $project)->assertOk()->assertJsonPath('data.client_deleted', false);
        $client = ClientWorkspace::withoutGlobalScopes()->find($ws['client']->id);
        $this->assertNull($client->deleted_at);
        $this->assertNotSame('deleted', $client->client_status);
        $this->assertSame('active', Project::withoutGlobalScopes()->find($other->id)->status);
        $this->assertSame(1, DB::table('alert_rules')->where('project_id', $other->id)->where('active', true)->count());
    }

    public function test_the_last_project_may_take_its_client_with_it_only_with_the_client_name_and_restore_brings_both_back(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Only');

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/deletion-impact")
            ->assertJsonPath('data.client.can_delete_client', true);
        $this->deleteAsOwner($ws, $project, ['client_action' => 'project_and_client', 'confirm_client_name' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_client_name');

        $this->deleteAsOwner($ws, $project, ['client_action' => 'project_and_client', 'confirm_client_name' => $ws['client']->name])
            ->assertOk()->assertJsonPath('data.client_deleted', true);
        $client = ClientWorkspace::withoutGlobalScopes()->withTrashed()->find($ws['client']->id);
        $this->assertSame('deleted', $client->client_status);
        $this->assertNotNull($client->deleted_at);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/restore-impact")->assertJsonPath('data.client_restored', true);
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])
            ->assertOk()->assertJsonPath('data.lifecycle.client_restored', true);
        $client->refresh();
        $this->assertNull($client->deleted_at);
        $this->assertSame('onboarding', $client->client_status);
    }

    public function test_a_shared_account_is_unaffected_for_the_other_project_on_archive_and_delete(): void
    {
        $ws = $this->lifecycleWorkspace();
        $a = $this->lifecycleProject($ws['tenant'], $ws['client'], 'A');
        $b = $this->lifecycleProject($ws['tenant'], $ws['client'], 'B');
        $account = $this->lifecycleAccount($ws['tenant']);
        $this->lifecycleBind($account, $a);
        $theirs = $this->lifecycleBind($account, $b);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$a->id}/deletion-impact")
            ->assertJsonPath('data.shared_accounts.0.other_projects.0.name', 'B');
        // §8 — the integrations page carries the «مشترك» data for the warning.
        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$a->id}/integrations")
            ->assertOk()->assertJsonPath('data.0.shared_with.0.name', 'B');
        $this->deleteAsOwner($ws, $a)->assertOk()->assertJsonPath('data.lifecycle.shared_accounts.0.external_account_id', (string) $account->id);

        $row = DB::table('project_integration_bindings')->where('id', $theirs)->first();
        $this->assertTrue((bool) $row->is_active);
        $this->assertTrue((bool) $row->sync_enabled);
    }

    #[DataProvider('workspaceTypes')]
    public function test_move_inside_the_workspace_moves_all_data_and_across_workspaces_is_rejected(string $type): void
    {
        $ws = $this->lifecycleWorkspace($type);
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Mover');
        $binding = $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $project);
        $ops = $this->lifecycleOperational($project);
        $this->metric($project);
        app(TenantContext::class)->setTenantId((string) $ws['tenant']->id);
        $target = ClientWorkspace::create(['name' => 'Target client', 'slug' => 'target-'.Str::lower(Str::random(5)), 'mode' => 'managed', 'client_status' => 'active']);
        app(TenantContext::class)->forget();
        $foreign = $this->lifecycleWorkspace('agency', 'x');

        // Permissions: a member without projects.move is refused.
        $this->actingAs($ws['member'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/move", ['client_workspace_id' => $target->id, 'confirm_name' => 'Mover'])->assertForbidden();
        // Cross-workspace: refused server-side, nothing moved.
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/move", ['client_workspace_id' => $foreign['client']->id, 'confirm_name' => 'Mover'])
            ->assertUnprocessable()->assertJsonValidationErrors('client_workspace_id');
        $this->assertSame((string) $ws['client']->id, (string) Project::withoutGlobalScopes()->find($project->id)->client_workspace_id);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/move-impact?client_workspace_id={$target->id}")
            ->assertOk()->assertJsonPath('data.moves.project_integration_bindings', DB::table('project_integration_bindings')->where('project_id', $project->id)->count())
            ->assertJsonPath('data.refusal', null);
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/projects/{$project->id}/move", ['client_workspace_id' => $target->id, 'confirm_name' => 'Mover'])
            ->assertOk()->assertJsonPath('data.client_workspace_id', (string) $target->id);

        $this->assertSame((string) $target->id, (string) DB::table('project_integration_bindings')->where('id', $binding)->value('client_workspace_id'));
        $this->assertSame((string) $target->id, (string) DB::table('tasks')->where('id', $ops['task'])->value('client_workspace_id'));
        $this->assertSame(1, DB::table('daily_metrics')->where('project_id', $project->id)->count());
        $this->assertTrue(DB::table('project_status_transitions')->where('project_id', $project->id)->where('reason', 'moved')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'project.moved')->where('entity_id', $project->id)->exists());
    }

    public function test_no_permission_user_is_rejected_by_every_destructive_endpoint(): void
    {
        $ws = $this->lifecycleWorkspace('portal');
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Guarded');

        foreach ([$ws['member'], $ws['portal_user']] as $who) {
            $this->actingAs($who, 'sanctum')->deleteJson("/api/v1/projects/{$project->id}", ['confirm_name' => 'Guarded'])->assertForbidden();
            $this->actingAs($who, 'sanctum')->getJson("/api/v1/projects/{$project->id}/deletion-impact")->assertForbidden();
            $this->actingAs($who, 'sanctum')->getJson("/api/v1/projects/{$project->id}/move-impact")->assertForbidden();
        }
        $this->deleteAsOwner($ws, $project)->assertOk();
        foreach ([$ws['member'], $ws['portal_user']] as $who) {
            $this->actingAs($who, 'sanctum')->postJson("/api/v1/projects/{$project->id}/restore", ['confirm' => true])->assertForbidden();
            $this->actingAs($who, 'sanctum')->getJson("/api/v1/projects/{$project->id}/restore-impact")->assertForbidden();
            $this->actingAs($who, 'sanctum')->postJson("/api/v1/projects/{$project->id}/purge", ['confirm_name' => 'Guarded'])->assertForbidden();
        }
        $this->assertSame('deleted', Project::withoutGlobalScopes()->find($project->id)->status);
    }

    public function test_client_archive_lists_its_projects_needs_the_name_archives_them_and_restore_brings_them_back(): void
    {
        $ws = $this->lifecycleWorkspace();
        $a = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Alpha');
        $b = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Beta', 'paused');
        $ops = $this->lifecycleOperational($a);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/app/clients/{$ws['client']->id}/archive-impact")
            ->assertOk()->assertJsonCount(2, 'data.projects')->assertJsonPath('data.projects.0.name', 'Alpha');
        $this->actingAs($ws['member'], 'sanctum')->getJson("/api/v1/app/clients/{$ws['client']->id}/archive-impact")->assertForbidden();
        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/app/clients/{$ws['client']->id}/archive", ['confirm_name' => 'x'])->assertUnprocessable();
        $this->assertSame('active', $a->refresh()->status);

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/app/clients/{$ws['client']->id}/archive", ['confirm_name' => $ws['client']->name])
            ->assertOk()->assertJsonCount(2, 'data.projects_archived');
        $this->assertSame('archived', $a->refresh()->status);
        $this->assertSame('archived', $b->refresh()->status);
        $this->assertFalse((bool) DB::table('alert_rules')->where('id', $ops['alert_rule'])->value('active'));

        $this->actingAs($ws['owner'], 'sanctum')->postJson("/api/v1/app/clients/{$ws['client']->id}/restore")->assertOk();
        $this->assertSame('active', $a->refresh()->status);
        $this->assertSame('paused', $b->refresh()->status);
        $this->assertTrue((bool) DB::table('alert_rules')->where('id', $ops['alert_rule'])->value('active'));
    }

    public function test_an_archived_project_is_still_readable_view_only(): void
    {
        $ws = $this->lifecycleWorkspace();
        $project = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Readable');
        app(ProjectLifecycle::class)->archive($project, $ws['owner']);

        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.lifecycle_status', 'archived');
        $this->actingAs($ws['owner'], 'sanctum')->getJson("/api/v1/projects/{$project->id}/lifecycle")->assertOk()
            ->assertJsonPath('data.status', 'archived');
        $this->assertTrue(Carbon::now()->greaterThan(Carbon::parse(Project::withoutGlobalScopes()->find($project->id)->archived_at)->subSecond()));
    }
}
