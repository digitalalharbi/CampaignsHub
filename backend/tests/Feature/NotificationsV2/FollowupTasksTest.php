<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationsV2;

use App\Domains\Integrations\Models\IntegrationSyncRun;
use App\Domains\Notifications\Engine\Contracts\EntitlementGate;
use App\Domains\Notifications\Engine\Enums\EntitlementKey;
use App\Domains\Notifications\Followup\FollowupTasks;
use App\Domains\Notifications\Followup\Models\FollowupTask;
use App\Domains\Notifications\Followup\Models\FollowupTaskEvent;
use App\Domains\Notifications\Services\NotificationDispatcher;
use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FundingFixtures;
use Tests\TestCase;

/**
 * NOTIF-V2-FOLLOWUP-001 — the follow-up task list (Owner spec §6, §16 «Follow-up»): opened automatically, closed by itself when
 * the thing happened, snoozed, assigned, with a full history; visible only to the people who reach the project; refused by the
 * plan key on the server (API and scheduled job) with the data kept.
 */
final class FollowupTasksTest extends TestCase
{
    use FundingFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-15 09:00:00', 'UTC'));
        $this->bootFunding();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function tasks(User $user, string $query = 'status=open'): array
    {
        return $this->actingAs($user, 'sanctum')->getJson('/api/v1/followup/tasks?'.$query)->assertOk()->json('data');
    }

    private function scan(): void
    {
        app()->forgetScopedInstances();
        Artisan::call('notifications:v2-internal-scan');
    }

    public function test_a_project_without_a_budget_opens_a_set_budget_task_and_adding_a_budget_closes_it_by_itself(): void
    {
        $project = $this->newProject('Launch');
        $this->scan();

        $open = $this->tasks($this->finance);
        $this->assertCount(1, $open);
        $this->assertSame('set_budget', $open[0]['kind']);
        $this->assertSame('Launch', $open[0]['project_name']);
        $this->assertSame('A', $open[0]['workspace_name']);
        $this->assertSame('2026-11-15', $open[0]['due_on']);
        $this->assertSame('/budgets?project='.$project->id, $open[0]['action_url']);
        $this->assertNotSame('', $open[0]['reason']);

        $this->scan(); // a second scan is the same fact, not a second task
        $this->assertCount(1, $this->tasks($this->finance));

        $this->addBudget($project, 5_000);

        $this->assertSame([], $this->tasks($this->finance));
        $done = $this->tasks($this->finance, 'status=done');
        $this->assertCount(1, $done);
        $this->assertSame('auto', $done[0]['closed_how']);

        $history = $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/followup/tasks/'.$done[0]['id'])->assertOk()->json('data.history');
        $this->assertSame(['opened', 'closed'], array_column($history, 'action'));
        $this->assertSame('النظام', $history[1]['by'], 'closed by the system, not by a person');
    }

    public function test_a_budget_covering_few_days_opens_a_renew_task_and_a_top_up_closes_it(): void
    {
        $project = $this->newProject();
        foreach ([3, 2, 1] as $ago) {
            $this->spendRow($project, (string) Str::uuid(), $ago, 300);
        }
        $this->addBudget($project, 1_000);
        $this->scan();
        $renew = collect($this->tasks($this->finance))->firstWhere('kind', 'renew_budget');
        $this->assertNotNull($renew);

        $this->addBudget($project, 20_000);
        $this->assertNull(collect($this->tasks($this->finance))->firstWhere('kind', 'renew_budget'));
    }

    public function test_a_sync_failure_in_the_bell_opens_a_relink_task_and_a_successful_sync_closes_it(): void
    {
        $project = $this->newProject();
        $this->addBudget($project, 5_000);
        app(NotificationDispatcher::class)->dispatch([
            'tenant_id' => $this->tenant->id, 'user_id' => null, 'project_id' => $project->id, 'type' => 'sync_failed', 'kind' => 'sync_failed',
            'severity' => 'warning', 'title' => 'Sync failed', 'message' => 'x', 'source' => 'alerts', 'entity_type' => 'sync', 'entity_id' => 'x1',
            'action_url' => '/integrations?project='.$project->id,
        ]);
        $this->assertSame('relink_platform', $this->tasks($this->finance)[0]['kind']);

        Carbon::setTestNow(Carbon::now()->addMinutes(5));
        IntegrationSyncRun::create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'type' => 'insights', 'status' => 'success', 'records' => 5, 'started_at' => now(), 'finished_at' => now()]);

        $this->assertSame([], $this->tasks($this->finance));
    }

    public function test_snooze_hides_a_task_until_its_time_then_it_is_open_again_and_the_history_says_so(): void
    {
        $project = $this->newProject();
        $this->scan();
        $id = $this->tasks($this->finance)[0]['id'];

        $this->actingAs($this->finance, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/snooze", ['preset' => '1h'])->assertOk()->assertJsonPath('data.status', 'snoozed');
        $this->assertSame([], $this->tasks($this->finance));
        $this->assertCount(1, $this->tasks($this->finance, 'status=snoozed'));

        Carbon::setTestNow(Carbon::now()->addMinutes(61));
        $this->assertCount(1, $this->tasks($this->finance));
        $history = $this->actingAs($this->finance, 'sanctum')->getJson("/api/v1/followup/tasks/{$id}")->json('data.history');
        $this->assertSame(['opened', 'snoozed', 'reopened'], array_column($history, 'action'));
    }

    public function test_assigning_to_a_colleague_moves_the_task_to_them_and_only_eligible_people_qualify(): void
    {
        $project = $this->newProject();
        $this->scan();
        $id = $this->tasks($this->finance)[0]['id'];
        $colleague = $this->userWith(['projects.view', 'projects.view.all', 'clients.view_all']);

        $this->actingAs($this->finance, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/assign", ['user_id' => $colleague->id])->assertOk()
            ->assertJsonPath('data.assigned_user_id', $colleague->id);
        $this->assertCount(1, $this->tasks($colleague));
        $history = $this->actingAs($this->finance, 'sanctum')->getJson("/api/v1/followup/tasks/{$id}")->json('data.history');
        $this->assertSame('assigned', end($history)['action']);
        $this->assertSame($this->finance->name, end($history)['by']);

        // A user of another workspace cannot be assigned.
        $stranger = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@x.test', 'password' => 'secret1234', 'email_verified_at' => now()]);
        $this->actingAs($this->finance, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/assign", ['user_id' => $stranger->id])->assertStatus(422);
    }

    public function test_a_person_who_does_not_reach_the_project_does_not_see_its_tasks(): void
    {
        $this->newProject('Mine');
        $this->scan();
        $outsider = $this->userWith(['projects.view']); // no «all projects», no project membership
        $this->assertSame([], $this->tasks($outsider));

        $id = $this->tasks($this->finance)[0]['id'];
        $this->actingAs($outsider, 'sanctum')->getJson("/api/v1/followup/tasks/{$id}")->assertNotFound();
        $this->actingAs($outsider, 'sanctum')->postJson("/api/v1/followup/tasks/{$id}/close")->assertNotFound();
    }

    public function test_no_task_is_opened_for_an_archived_project_and_one_closes_when_its_project_is_archived(): void
    {
        $project = $this->newProject();
        $this->scan();
        $this->assertCount(1, $this->tasks($this->finance));

        // PROJECT-LIFECYCLE-001 — the status is written only by the state machine (the archive also runs its effects).
        app(ProjectLifecycle::class)->transition($project, ProjectStatus::Archived, null);
        $this->scan();
        $this->assertSame([], $this->tasks($this->finance));
        $this->assertSame(0, FollowupTask::query()->withoutGlobalScopes()->where('status', 'open')->count());
    }

    public function test_with_the_follow_up_key_off_the_api_and_the_scan_refuse_and_the_data_is_kept_then_restored(): void
    {
        $project = $this->newProject();
        $this->scan();
        $this->assertCount(1, $this->tasks($this->finance));

        $row = ['id' => (string) Str::uuid(), 'key' => 'followup.tasks', 'enabled' => false, 'grace_days' => 0, 'keep_existing' => false, 'paid_from' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()];
        DB::table('notification_plan_features')->insert($row);
        app()->forgetScopedInstances();
        $this->assertFalse(app(EntitlementGate::class)->allows($this->tenant->id, EntitlementKey::Tasks));

        $this->actingAs($this->finance, 'sanctum')->getJson('/api/v1/followup/tasks')->assertForbidden()->assertJsonPath('meta.upgrade', true);
        $second = $this->newProject('Second');
        $this->scan();
        $this->assertSame(1, FollowupTask::query()->withoutGlobalScopes()->count(), 'the scheduled job opened nothing');

        DB::table('notification_plan_features')->where('id', $row['id'])->update(['enabled' => true]);
        app()->forgetScopedInstances();
        $this->assertCount(1, $this->tasks($this->finance), 'the data was kept, and it is back');
        $this->scan();
        $this->assertCount(2, $this->tasks($this->finance));
    }

    public function test_a_client_portal_member_has_no_follow_up_list(): void
    {
        $this->newProject();
        $this->scan();
        $portal = $this->userWith(['projects.view']);
        DB::table('memberships')->where('user_id', $portal->id)->update(['portal' => 'portal']);

        $this->actingAs($portal, 'sanctum')->getJson('/api/v1/followup/tasks')->assertStatus(403);
        $this->assertFalse(app(FollowupTasks::class)->mayUse($portal, $this->tenant->id));
    }

    public function test_the_history_table_is_append_only(): void
    {
        $this->newProject();
        $this->scan();
        $event = FollowupTaskEvent::query()->withoutGlobalScopes()->firstOrFail();

        $this->expectException(\LogicException::class);
        $event->update(['action' => 'closed']);
    }
}
