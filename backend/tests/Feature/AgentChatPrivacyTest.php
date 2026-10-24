<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Jobs\RunAgentTurnJob;
use App\Domains\Agents\Models\AgentMessage;
use App\Domains\Agents\Models\AgentThread;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AGENT-CHAT-PRIVACY-001 — an agent conversation belongs to the person who had it.
 *
 * Before: every list, transcript and reply asked only «is it in this project?», so a colleague with `ai.view` could read what
 * another had asked, and send into their thread (which then ran the agent as the colleague). Now: private to the author, with
 * search, filters, deletion, a clear-my-history action and a stated retention that a daily command enforces.
 */
final class AgentChatPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $ana;

    private User $ben;

    private User $admin;

    private Project $project;

    private Project $other;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $full = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $full->givePermissionTo(...Permission::pluck('key')->all());
        $member = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Member', 'slug' => 'member']);
        // Everything a working member has, except managing the agents — what the scheduled-run removal rule needs to tell apart.
        $member->givePermissionTo(...Permission::where('key', '!=', 'ai.manage')->pluck('key')->all());
        $this->ana = $this->user('ana@a.test', $member);
        $this->ben = $this->user('ben@a.test', $member);
        $this->admin = $this->user('admin@a.test', $full);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'Q', 'status' => 'active']);
    }

    private function user(string $email, Role $role): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($u, $this->tenant, Portal::Agency);
        $u->assignRole($role);

        return $u;
    }

    private function thread(?User $by, string $title, string $body = 'hello', ?Project $project = null, string $agent = 'orchestrator', ?Carbon $at = null, string $status = 'idle'): AgentThread
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $t = AgentThread::query()->create(['project_id' => ($project ?? $this->project)->id, 'agent_key' => $agent, 'title' => $title, 'status' => $status, 'created_by' => $by?->id]);
        AgentMessage::query()->create(['thread_id' => $t->id, 'role' => 'user', 'content' => [['type' => 'text', 'text' => $body]], 'text' => $body]);
        if ($at !== null) {
            AgentThread::query()->whereKey($t->id)->toBase()->update(['updated_at' => $at]);
        }

        return $t;
    }

    private function list(User $as, string $qs = '')
    {
        return $this->actingAs($as, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/agent-threads{$qs}");
    }

    public function test_a_colleague_sees_neither_the_title_nor_the_transcript_of_my_conversation(): void
    {
        $mine = $this->thread($this->ana, 'Budget question', 'what is our cost per lead');

        $this->assertSame([(string) $mine->id], array_column($this->list($this->ana)->json('data.threads'), 'id'));
        $this->assertSame([], $this->list($this->ben)->json('data.threads'));
        $this->actingAs($this->ben, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/agent-threads/{$mine->id}")->assertNotFound();
        $this->actingAs($this->ana, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/agent-threads/{$mine->id}")->assertOk()->assertJsonPath('data.messages.0.text', 'what is our cost per lead');
    }

    public function test_a_colleague_cannot_write_into_my_thread_and_nothing_runs_as_them(): void
    {
        $mine = $this->thread($this->ana, 'Private');

        $this->actingAs($this->ben, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/agent-threads", ['thread_id' => $mine->id, 'message' => 'inject'])->assertNotFound();
        $this->assertSame(1, AgentMessage::withoutGlobalScopes()->where('thread_id', $mine->id)->count());
        Queue::assertNotPushed(RunAgentTurnJob::class);

        $this->actingAs($this->ana, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/agent-threads", ['thread_id' => $mine->id, 'message' => 'follow up'])->assertStatus(202);
        Queue::assertPushed(RunAgentTurnJob::class);
    }

    public function test_search_reads_titles_and_message_text_and_filters_by_agent_status_and_date(): void
    {
        $this->thread($this->ana, 'Weekly report', 'summarise snapchat results');
        $this->thread($this->ana, 'Other', 'how is the 100% budget', agent: 'orchestrator');
        $old = $this->thread($this->ana, 'Old', 'ancient', at: Carbon::parse('2026-01-02'));
        $failed = $this->thread($this->ana, 'Broken', 'x', status: 'failed');

        $titles = fn (string $qs) => array_column($this->list($this->ana, $qs)->json('data.threads'), 'title');
        $this->assertSame(['Weekly report'], $titles('?q=snapchat'));
        $this->assertSame(['Weekly report'], $titles('?q=WEEKLY'), 'case-insensitive');
        $this->assertSame(['Other'], $titles('?q='.urlencode('100%')), 'a percent sign is text, not a wildcard');
        $this->assertSame(['Other'], $titles('?q='.urlencode('%')), 'a percent sign matches only text that contains one');
        $this->assertSame([], $titles('?q='.urlencode('_')), 'an underscore is text, not «any character»');
        $this->assertSame(['Broken'], $titles('?status=failed'));
        $this->assertSame(['Old'], $titles('?from=2026-01-01&to=2026-01-31'));
        $this->assertSame(4, $this->list($this->ana)->json('data.total'));
        $this->assertSame(90, $this->list($this->ana)->json('data.retention_days'));
        $this->assertContains((string) $failed->id, array_column($this->list($this->ana, '?status=failed')->json('data.threads'), 'id'));
        unset($old);
    }

    public function test_my_conversation_is_deleted_with_its_messages_and_a_colleagues_cannot_be(): void
    {
        $mine = $this->thread($this->ana, 'Mine');
        $his = $this->thread($this->ben, 'His');

        $this->actingAs($this->ben, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$mine->id}")->assertNotFound();
        $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($mine->id));

        $this->actingAs($this->ana, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$mine->id}")->assertOk()->assertJsonPath('data.deleted', 1);
        $this->assertNull(AgentThread::withoutGlobalScopes()->find($mine->id));
        $this->assertSame(0, AgentMessage::withoutGlobalScopes()->where('thread_id', $mine->id)->count());
        $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($his->id), 'a colleague\'s conversation is untouched');
    }

    public function test_a_conversation_still_being_answered_is_not_deleted(): void
    {
        $busy = $this->thread($this->ana, 'Busy', status: 'thinking');
        $this->actingAs($this->ana, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$busy->id}")->assertStatus(409);
        $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($busy->id));
    }

    public function test_clearing_my_history_removes_only_mine_in_this_project(): void
    {
        $a1 = $this->thread($this->ana, 'A1');
        $a2 = $this->thread($this->ana, 'A2');
        $busy = $this->thread($this->ana, 'Busy', status: 'thinking');
        $b1 = $this->thread($this->ben, 'B1');
        $elsewhere = $this->thread($this->ana, 'A elsewhere', project: $this->other);
        $routine = $this->thread(null, 'Scheduled run');

        $this->actingAs($this->ana, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads")->assertOk()->assertJsonPath('data.deleted', 2);

        foreach ([$a1, $a2] as $gone) {
            $this->assertNull(AgentThread::withoutGlobalScopes()->find($gone->id));
        }
        foreach ([$busy, $b1, $elsewhere, $routine] as $kept) {
            $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($kept->id), $kept->title);
        }
    }

    public function test_a_scheduled_run_is_readable_by_the_project_but_only_a_manager_removes_it_and_nobody_writes_into_it(): void
    {
        $run = $this->thread(null, 'Scheduled run', 'instruction');

        $this->assertContains((string) $run->id, array_column($this->list($this->ben)->json('data.threads'), 'id'));
        $this->assertTrue($this->list($this->ben)->json('data.threads.0.shared'));
        $this->actingAs($this->ben, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/agent-threads/{$run->id}")->assertOk();
        $this->actingAs($this->ben, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/agent-threads", ['thread_id' => $run->id, 'message' => 'x'])->assertNotFound();
        $this->actingAs($this->ben, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$run->id}")->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$run->id}")->assertOk();
    }

    public function test_another_projects_threads_are_not_reachable_through_this_projects_url(): void
    {
        $theirs = $this->thread($this->ana, 'Elsewhere', project: $this->other);
        $this->actingAs($this->ana, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/agent-threads/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->ana, 'sanctum')->deleteJson("/api/v1/projects/{$this->project->id}/agent-threads/{$theirs->id}")->assertNotFound();
    }

    public function test_the_retention_command_deletes_old_idle_conversations_with_their_messages_and_spares_the_rest(): void
    {
        $now = Carbon::parse('2026-10-25 12:00');
        $old = $this->thread($this->ana, 'Old', at: $now->copy()->subDays(91));
        $edge = $this->thread($this->ana, 'Edge', at: $now->copy()->subDays(89));
        $stuck = $this->thread($this->ana, 'Old but answering', at: $now->copy()->subDays(200), status: 'thinking');
        $routine = $this->thread(null, 'Old routine', at: $now->copy()->subDays(120));

        $this->artisan('agents:purge-chat', ['--now' => $now->toIso8601String()])->expectsOutputToContain('2 conversation(s) past their workspace')->assertSuccessful();

        foreach ([$old, $routine] as $gone) {
            $this->assertNull(AgentThread::withoutGlobalScopes()->find($gone->id), $gone->title);
            $this->assertSame(0, AgentMessage::withoutGlobalScopes()->where('thread_id', $gone->id)->count());
        }
        foreach ([$edge, $stuck] as $kept) {
            $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($kept->id), $kept->title);
        }
    }

    // ── AGENT-CHAT-RETENTION-001 — the workspace chooses how long conversations are kept ───────────────────────────────────

    private function retention(User $as, ?int $days = null)
    {
        $c = $this->actingAs($as, 'sanctum');

        return $days === null ? $c->getJson('/api/v1/ai/chat-retention') : $c->putJson('/api/v1/ai/chat-retention', ['days' => $days]);
    }

    public function test_the_workspace_default_is_ninety_days_and_anyone_who_may_view_the_agents_can_read_it(): void
    {
        $this->retention($this->ben)->assertOk()->assertJsonPath('data.days', 90)->assertJsonPath('data.min', 7)->assertJsonPath('data.max', 730);
    }

    public function test_only_a_manager_sets_it_within_seven_and_seven_hundred_thirty_days_and_there_is_no_forever(): void
    {
        $this->retention($this->ben, 30)->assertForbidden();
        $this->retention($this->admin)->assertJsonPath('data.days', 90);

        $this->retention($this->admin, 30)->assertOk()->assertJsonPath('data.days', 30);
        foreach ([0, 6, 731, 100000] as $bad) {
            $this->retention($this->admin, $bad)->assertStatus(422);
        }
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/ai/chat-retention', ['days' => null])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/ai/chat-retention', ['days' => 'forever'])->assertStatus(422);
        $this->retention($this->ben)->assertJsonPath('data.days', 30);
    }

    public function test_the_chat_screen_reports_the_workspaces_value_and_the_purge_uses_it(): void
    {
        $this->retention($this->admin, 30)->assertOk();
        $now = Carbon::parse('2026-10-25 12:00');
        $old = $this->thread($this->ana, 'Old by 30', at: $now->copy()->subDays(31));
        $kept = $this->thread($this->ana, 'Kept by 30', at: $now->copy()->subDays(29));

        $this->assertSame(30, $this->list($this->ana)->json('data.retention_days'));
        $this->artisan('agents:purge-chat', ['--now' => $now->toIso8601String()])->assertSuccessful();

        $this->assertNull(AgentThread::withoutGlobalScopes()->find($old->id));
        $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($kept->id));
    }

    public function test_one_workspaces_choice_never_changes_another_workspaces_conversations(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $ws = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'C2', 'slug' => 'c2', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $theirProject = Project::create(['tenant_id' => $other->id, 'client_workspace_id' => $ws->id, 'name' => 'T', 'status' => 'active']);
        $theirs = AgentThread::query()->create(['project_id' => $theirProject->id, 'agent_key' => 'orchestrator', 'title' => 'Theirs', 'status' => 'idle', 'created_by' => null]);
        $now = Carbon::parse('2026-10-25 12:00');
        AgentThread::withoutGlobalScopes()->whereKey($theirs->id)->toBase()->update(['updated_at' => $now->copy()->subDays(60)]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        // This workspace shortens to 7 days; the other keeps the 90-day default, so its 60-day-old thread survives.
        $this->retention($this->admin, 7)->assertOk();
        $mineOld = $this->thread($this->ana, 'Mine old', at: $now->copy()->subDays(60));
        $this->artisan('agents:purge-chat', ['--now' => $now->toIso8601String()])->assertSuccessful();

        $this->assertNull(AgentThread::withoutGlobalScopes()->find($mineOld->id));
        $this->assertNotNull(AgentThread::withoutGlobalScopes()->find($theirs->id), 'another workspace keeps its own retention');
    }
}
