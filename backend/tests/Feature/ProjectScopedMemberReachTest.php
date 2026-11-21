<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Delivery\Models\DeliveryPack;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PROJECTS-RBAC-CLOSURE-001 — a project-scoped member reaches only what their project membership allows, on read AND write,
 * through the TENANT-level surfaces as well as the project URLs.
 *
 * `ProjectIsolationProbeTest` proves every `/projects/{project}/…` route refuses ANOTHER project's URL. That leaves two ways
 * to reach another project's data it does not exercise, and this file does:
 *
 *  - the tenant-level lists (tasks, conversations, delivery packs, the project list and its counts), which carry no project in
 *    the URL and were narrowed by client only — so a member of ONE project of a client read the client's OTHER projects;
 *  - a CHILD id guessed through one's OWN project URL (a report, a campaign, a task of another project).
 *
 * Cast: `member` holds a tenant role with the view/update permissions and a membership in P1 only, confined to client C1.
 * P2 is ANOTHER project of the SAME client (the hard case — client scope cannot separate them); P3 belongs to another client
 * of the same tenant; PB belongs to another TENANT. `viewer` is the same shape with a tenant role holding almost nothing.
 */
final class ProjectScopedMemberReachTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Tenant $rival;

    private ClientWorkspace $c1;

    private ClientWorkspace $c2;

    private Project $p1;

    private Project $p2;

    private Project $p3;

    private Project $pb;

    private User $member;

    private User $bare;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active', 'account_type' => 'agency']);

        $this->c1 = $this->client($this->agency, 'C1');
        $this->c2 = $this->client($this->agency, 'C2');
        $cb = $this->client($this->rival, 'CB');
        $this->p1 = $this->project($this->agency, $this->c1, 'P1');
        $this->p2 = $this->project($this->agency, $this->c1, 'P2');
        $this->p3 = $this->project($this->agency, $this->c2, 'P3');
        $this->pb = $this->project($this->rival, $cb, 'PB');

        $this->member = $this->staff('member@agency.test', [
            'projects.view', 'tasks.view', 'tasks.create', 'tasks.update', 'messaging.view', 'messaging.manage',
            'campaigns.view', 'campaigns.update', 'campaigns.create', 'reports.view', 'reports.export', 'reports.create', 'reports.share', 'clients.view',
            'integrations.view', 'clients.view_reports', 'clients.share_reports', 'clients.create_reports', 'clients.manage_files', 'clients.view_analytics',
        ]);
        $this->bare = $this->staff('bare@agency.test', ['projects.view']);

        app(TenantContext::class)->forget();
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────

    private function client(Tenant $tenant, string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $tenant->id, 'name' => $name, 'slug' => strtolower($name).'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(Tenant $tenant, ClientWorkspace $client, string $name): Project
    {
        return Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    /** @param  list<string>  $permissions */
    private function staff(string $email, array $permissions): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => $email, 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Agency, role: 'member', clientScopeIds: [(string) $this->c1->id],
        ));
        ProjectMembership::create([
            'tenant_id' => $this->agency->id, 'project_id' => $this->p1->id, 'user_id' => $user->id,
            'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active',
        ]);

        return $user;
    }

    private function task(Tenant $tenant, Project $project, string $title): Task
    {
        return Task::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'title' => $title, 'status' => 'todo', 'priority' => 'normal',
        ]);
    }

    private function thread(Tenant $tenant, Project $project, string $subject): MessageThread
    {
        return MessageThread::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'subject' => $subject, 'status' => 'open',
        ]);
    }

    private function pack(Tenant $tenant, Project $project): DeliveryPack
    {
        return DeliveryPack::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-07', 'status' => 'draft', 'components' => [],
        ]);
    }

    private function report(Tenant $tenant, Project $project, string $name): Report
    {
        return Report::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'name' => $name, 'type' => 'performance',
            'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-28', 'currency' => 'SAR', 'data' => ['sections' => []],
            'generated_at' => now(),
        ]);
    }

    private function campaign(Tenant $tenant, Project $project, string $name): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'name' => $name, 'objective' => 'sales', 'status' => 'draft', 'total_budget' => 1000, 'budget_currency' => 'SAR',
        ]);
    }

    private function as(User $user): self
    {
        app(TenantContext::class)->forget();
        app(ProjectContext::class)->forget();
        $this->actingAs($user, 'sanctum');

        return $this;
    }

    // ── tenant-level lists: another project of the SAME client ──────────────────────────────

    public function test_the_task_list_and_its_counts_stop_at_the_members_projects(): void
    {
        $this->task($this->agency, $this->p1, 'T-P1');
        $this->task($this->agency, $this->p2, 'T-P2');
        $this->task($this->agency, $this->p3, 'T-P3');
        $this->task($this->rival, $this->pb, 'T-PB');

        $body = $this->as($this->member)->getJson('/api/v1/tasks')->assertOk()->json();

        $this->assertSame(['T-P1'], array_column($body['data'], 'title'));
        $this->assertSame(1, $body['meta']['total'], 'the total must describe what the member may see');
        $this->assertSame(1, $body['meta']['counts']['open'], 'the open count must describe what the member may see');
        $this->assertStringNotContainsString('T-P2', json_encode($body));
    }

    public function test_a_task_of_another_project_cannot_be_rewritten_by_guessing_its_id(): void
    {
        $theirs = $this->task($this->agency, $this->p2, 'T-P2');
        $foreign = $this->task($this->rival, $this->pb, 'T-PB');

        $this->as($this->member)->putJson("/api/v1/tasks/{$theirs->id}", ['title' => 'pwned'])->assertStatus(403);
        $this->as($this->member)->putJson("/api/v1/tasks/{$foreign->id}", ['title' => 'pwned'])->assertStatus(404);

        $this->assertSame('T-P2', Task::withoutGlobalScopes()->find($theirs->id)->title);
        $this->assertSame('T-PB', Task::withoutGlobalScopes()->find($foreign->id)->title);
    }

    public function test_a_task_cannot_be_filed_under_a_project_the_member_does_not_belong_to(): void
    {
        $this->as($this->member)->postJson('/api/v1/tasks', [
            'title' => 'sneaky', 'project_id' => (string) $this->p2->id,
        ])->assertStatus(422);

        $this->as($this->member)->postJson('/api/v1/tasks', [
            'title' => 'sneaky', 'project_id' => (string) $this->pb->id,
        ])->assertStatus(422);

        $this->assertSame(0, Task::withoutGlobalScopes()->where('title', 'sneaky')->count());

        $this->as($this->member)->postJson('/api/v1/tasks', [
            'title' => 'fine', 'project_id' => (string) $this->p1->id,
        ])->assertCreated();
    }

    public function test_the_conversation_list_and_detail_stop_at_the_members_projects(): void
    {
        $this->thread($this->agency, $this->p1, 'M-P1');
        $other = $this->thread($this->agency, $this->p2, 'M-P2');
        $foreign = $this->thread($this->rival, $this->pb, 'M-PB');

        $list = $this->as($this->member)->getJson('/api/v1/messaging/threads')->assertOk()->json('data');
        $this->assertSame(['M-P1'], array_column($list, 'subject'));

        $this->as($this->member)->getJson("/api/v1/messaging/threads/{$other->id}")->assertStatus(403);
        $this->as($this->member)->postJson("/api/v1/messaging/threads/{$other->id}/messages", ['author_type' => 'team', 'body' => 'hi'])->assertStatus(403);
        $this->as($this->member)->getJson("/api/v1/messaging/threads/{$foreign->id}")->assertStatus(404);
    }

    public function test_the_delivery_pack_list_and_detail_stop_at_the_members_projects(): void
    {
        $mine = $this->pack($this->agency, $this->p1);
        $other = $this->pack($this->agency, $this->p2);

        $ids = array_column($this->as($this->member)->getJson('/api/v1/delivery/packs')->assertOk()->json('data'), 'id');

        $this->assertContains((string) $mine->id, $ids);
        $this->assertNotContains((string) $other->id, $ids);
        $this->as($this->member)->getJson("/api/v1/delivery/packs/{$other->id}")->assertStatus(403);
        $this->as($this->member)->postJson("/api/v1/delivery/packs/{$other->id}/approve")->assertStatus(403);
    }

    public function test_the_project_list_and_its_counts_name_only_the_members_projects(): void
    {
        $rows = $this->as($this->member)->getJson('/api/v1/projects')->assertOk()->json('data');
        $this->assertSame(['P1'], array_column($rows, 'name'));

        $this->as($this->member)->getJson("/api/v1/projects/{$this->p2->id}")->assertStatus(403);
        $this->as($this->member)->getJson("/api/v1/projects/{$this->p3->id}")->assertStatus(403);
        $this->as($this->member)->getJson("/api/v1/projects/{$this->pb->id}")->assertStatus(404);

        $overview = $this->as($this->member)->getJson('/api/v1/portfolio/overview')->assertOk();
        $this->assertStringNotContainsString('P2', json_encode($overview->json()));
        $this->assertStringNotContainsString('P3', json_encode($overview->json()));
    }

    // ── child ids guessed through one's OWN project URL ──────────────────────────────────────

    public function test_a_report_of_another_project_is_not_reached_through_the_members_own_project_url(): void
    {
        $other = $this->report($this->agency, $this->p2, 'R-P2');
        $foreign = $this->report($this->rival, $this->pb, 'R-PB');
        $mine = $this->report($this->agency, $this->p1, 'R-P1');

        $base = "/api/v1/projects/{$this->p1->id}/reports";
        $list = $this->as($this->member)->getJson($base)->assertOk()->json('data');
        $this->assertSame(['R-P1'], array_column($list['reports'], 'name'));
        $this->assertSame(1, $list['summary']['total'], 'the count must describe the project, not the tenant');

        foreach ([$other, $foreign] as $report) {
            $this->as($this->member)->getJson("{$base}/{$report->id}")->assertNotFound();
            $this->as($this->member)->postJson("{$base}/{$report->id}/export", ['format' => 'pdf'])->assertNotFound();
            $this->as($this->member)->postJson("{$base}/{$report->id}/print-token")->assertNotFound();
            $this->as($this->member)->getJson("{$base}/{$report->id}/shares")->assertNotFound();
            $this->as($this->member)->deleteJson("{$base}/{$report->id}")->assertNotFound();
        }

        $this->assertNotNull(Report::withoutGlobalScopes()->find($other->id), 'a refused delete must leave the other project report standing');
        $this->as($this->member)->getJson("{$base}/{$mine->id}")->assertOk();
    }

    public function test_a_campaign_of_another_project_is_not_reached_through_the_members_own_project_url(): void
    {
        $this->campaign($this->agency, $this->p1, 'K-P1');
        $other = $this->campaign($this->agency, $this->p2, 'K-P2');
        $foreign = $this->campaign($this->rival, $this->pb, 'K-PB');

        $base = "/api/v1/projects/{$this->p1->id}/campaigns";
        $list = $this->as($this->member)->getJson($base)->assertOk()->json('data');
        $this->assertSame(['K-P1'], array_column($list, 'name'));

        foreach ([$other, $foreign] as $campaign) {
            $this->as($this->member)->getJson("{$base}/{$campaign->id}")->assertNotFound();
            $this->as($this->member)->putJson("{$base}/{$campaign->id}", ['name' => 'pwned'])->assertNotFound();
            $this->as($this->member)->deleteJson("{$base}/{$campaign->id}")->assertNotFound();
        }

        $this->assertSame('K-P2', UnifiedCampaign::withoutGlobalScopes()->find($other->id)->name);
    }

    public function test_the_client_report_list_hides_reports_of_projects_the_member_is_not_in(): void
    {
        $this->report($this->agency, $this->p1, 'R-P1');
        $this->report($this->agency, $this->p2, 'R-P2');

        $body = $this->as($this->member)->getJson("/api/v1/app/clients/{$this->c1->id}/reports")->assertOk()->json();

        $this->assertStringContainsString('R-P1', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('R-P2', json_encode($body, JSON_UNESCAPED_UNICODE));
    }

    // ── a role without the permission ────────────────────────────────────────────────────────

    public function test_a_tenant_role_without_the_permission_is_refused_even_on_its_own_project(): void
    {
        $this->task($this->agency, $this->p1, 'T-P1');
        $report = $this->report($this->agency, $this->p1, 'R-P1');

        $this->as($this->bare)->getJson('/api/v1/tasks')->assertForbidden();
        $this->as($this->bare)->postJson('/api/v1/tasks', ['title' => 'x', 'project_id' => (string) $this->p1->id])->assertForbidden();
        $this->as($this->bare)->getJson('/api/v1/messaging/threads')->assertForbidden();
        $this->as($this->bare)->getJson('/api/v1/delivery/packs')->assertForbidden();
        $this->as($this->bare)->postJson("/api/v1/projects/{$this->p1->id}/reports/{$report->id}/export", ['format' => 'pdf'])->assertForbidden();
    }

    public function test_a_project_viewer_may_read_but_not_export_or_delete_a_report_of_their_project(): void
    {
        $report = $this->report($this->agency, $this->p1, 'R-P1');
        ProjectMembership::withoutGlobalScopes()->where('user_id', $this->member->id)->update(['role' => ProjectRole::VIEWER]);

        $base = "/api/v1/projects/{$this->p1->id}/reports";
        $this->as($this->member)->getJson("{$base}/{$report->id}")->assertOk();
        $this->as($this->member)->postJson("{$base}/{$report->id}/export", ['format' => 'pdf'])->assertForbidden();
        $this->as($this->member)->postJson("{$base}/{$report->id}/print-token")->assertForbidden();
        $this->as($this->member)->deleteJson("{$base}/{$report->id}")->assertForbidden();
        $this->as($this->member)->putJson("{$base}/{$report->id}", ['name' => 'renamed'])->assertForbidden();

        $this->assertSame('R-P1', Report::withoutGlobalScopes()->find($report->id)->name);
    }

    public function test_an_expired_or_removed_membership_reaches_nothing_further(): void
    {
        $this->task($this->agency, $this->p1, 'T-P1');
        ProjectMembership::withoutGlobalScopes()->where('user_id', $this->member->id)->update(['status' => 'removed']);

        $this->assertSame([], $this->as($this->member)->getJson('/api/v1/tasks')->assertOk()->json('data'));
        $this->as($this->member)->getJson("/api/v1/projects/{$this->p1->id}/reports")->assertForbidden();
    }

    // ── the client command centre: reports, share links, export files, history, analytics ────────

    public function test_the_client_centre_does_not_share_or_revoke_a_report_of_another_project(): void
    {
        $mine = $this->report($this->agency, $this->p1, 'R-P1');
        $other = $this->report($this->agency, $this->p2, 'R-P2');
        $base = "/api/v1/app/clients/{$this->c1->id}/reports";

        $this->as($this->member)->postJson("{$base}/{$other->id}/share")->assertNotFound();
        $this->as($this->member)->postJson("{$base}/{$other->id}/shares/".Str::uuid().'/revoke')->assertNotFound();
        $this->assertSame(0, ReportShare::withoutGlobalScopes()->where('report_id', $other->id)->count(), 'no link may be minted for a project the member is not in');

        $this->as($this->member)->postJson("{$base}/{$mine->id}/share")->assertCreated();

        $this->as($this->member)->postJson($base, [
            'project_id' => (string) $this->p2->id, 'name' => 'sneaky', 'type' => 'monthly',
        ])->assertStatus(422);
        $this->assertSame(0, Report::withoutGlobalScopes()->where('name', 'sneaky')->count());
    }

    public function test_the_client_centre_lists_and_serves_only_export_files_of_the_members_projects(): void
    {
        Storage::fake('local');
        foreach (['P1' => $this->p1, 'P2' => $this->p2] as $label => $project) {
            $report = $this->report($this->agency, $project, "R-{$label}");
            Storage::disk('local')->put("exports/{$label}.csv", "secret-{$label}");
            DB::table('report_exports')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'report_id' => $report->id,
                'format' => 'csv', 'status' => 'completed', 'disk' => 'local', 'path' => "exports/{$label}.csv", 'size' => 12,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $ids = DB::table('report_exports')->pluck('id', 'path');

        $list = $this->as($this->member)->getJson("/api/v1/app/clients/{$this->c1->id}/files")->assertOk()->json('data.files');
        $names = array_column($list, 'name');
        $this->assertContains('R-P1.csv', $names);
        $this->assertNotContains('R-P2.csv', $names);

        $this->as($this->member)->get("/api/v1/app/clients/{$this->c1->id}/files/report/{$ids['exports/P1.csv']}/download")->assertOk();
        $this->as($this->member)->get("/api/v1/app/clients/{$this->c1->id}/files/report/{$ids['exports/P2.csv']}/download")->assertNotFound();

        $library = $this->as($this->member)->getJson('/api/v1/files/library')->assertOk()->json();
        $this->assertStringNotContainsString('R-P2', json_encode($library));
        $this->assertStringContainsString('R-P1', json_encode($library));
    }

    public function test_the_client_centre_analytics_and_history_describe_only_the_members_projects(): void
    {
        foreach ([$this->p1, $this->p2] as $project) {
            $this->campaign($this->agency, $project, 'K-'.$project->name);
            DB::table('daily_metrics')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $project->id,
                'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(),
                'provider' => 'meta', 'metric_key' => 'spend', 'metric_date' => now()->toDateString(), 'value' => 100,
                'project_currency' => 'SAR', 'attribution_window' => 'default', 'source_type' => 'api',
                'data_freshness_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $analytics = $this->as($this->member)->getJson("/api/v1/app/clients/{$this->c1->id}/analytics")->assertOk()->json('data');
        $this->assertSame(['P1'], array_column($analytics['projects'], 'name'));
        $this->assertSame(1, array_sum(array_column($analytics['objective_mix'], 'count')), 'the objective mix counted the other project campaign');

        DB::table('audit_logs')->insert([
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'action' => 'project.touched', 'entity_type' => 'project', 'entity_id' => (string) $this->p1->id, 'created_at' => now()],
            ['id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'action' => 'secret.p2.touched', 'entity_type' => 'project', 'entity_id' => (string) $this->p2->id, 'created_at' => now()],
        ]);
        $history = json_encode($this->as($this->member)->getJson("/api/v1/app/clients/{$this->c1->id}/activity")->assertOk()->json());
        $this->assertStringContainsString('project.touched', $history);
        $this->assertStringNotContainsString('secret.p2.touched', $history);
    }

    public function test_a_project_less_row_keeps_following_the_client_ceiling_as_before(): void
    {
        Task::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'project_id' => null, 'client_workspace_id' => $this->c1->id,
            'title' => 'T-client-only', 'status' => 'todo', 'priority' => 'normal',
        ]);

        $titles = array_column($this->as($this->member)->getJson('/api/v1/tasks')->assertOk()->json('data'), 'title');
        $this->assertSame(['T-client-only'], $titles);
    }

    public function test_a_tenant_administrator_keeps_the_whole_tenant(): void
    {
        $admin = $this->staff('admin@agency.test', ['projects.view', 'tasks.view', 'settings.manage', 'projects.view.all']);
        $this->task($this->agency, $this->p1, 'T-P1');
        $this->task($this->agency, $this->p2, 'T-P2');
        $this->task($this->agency, $this->p3, 'T-P3');
        $this->task($this->rival, $this->pb, 'T-PB');

        // The admin's membership is confined to C1 like everyone's here: C1's two projects, never C2's or the rival's.
        $titles = array_column($this->as($admin)->getJson('/api/v1/tasks')->assertOk()->json('data'), 'title');
        sort($titles);
        $this->assertSame(['T-P1', 'T-P2'], $titles);
    }
}
