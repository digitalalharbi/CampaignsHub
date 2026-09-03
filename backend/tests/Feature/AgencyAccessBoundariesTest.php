<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Messaging\Models\MessageThread;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tasks\Models\Task;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AGENCY-PERMS — the nine boundaries of the agency portal, asserted as behaviour.
 *
 * Written after three separate reports of «تعذّر تحميل المهام / المحادثات / لوحة الوكالة» that were
 * not defects at all: the operator was signed in as the platform admin, who holds no tenant, so
 * every tenant-scoped endpoint refused them — correctly — and every page called that refusal a
 * failure to load. The refusals were right; the *sentence* was wrong.
 *
 * So these tests pin down two things at once. First, that each boundary really is where it should
 * be (an owner passes, a scoped manager passes only within their scope, a member without the
 * permission is refused, another agency gets nothing). Second — and this is what was actually
 * broken — that a refusal arrives as **403 with the refusal sentence**, not as the generic «تعذّر
 * تنفيذ الطلب» that a message-less `abort(403)` used to produce. A client cannot distinguish a
 * boundary from an outage if the server describes them identically.
 */
final class AgencyAccessBoundariesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Boundary Agency', 'slug' => 'boundary-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function client(string $name, ?Tenant $tenant = null): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => ($tenant ?? $this->agency)->id, 'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(ClientWorkspace $client, string $name, ?Tenant $tenant = null): Project
    {
        return Project::create([
            'tenant_id' => ($tenant ?? $this->agency)->id,
            'client_workspace_id' => $client->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions, ?array $clientScope = null, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->agency;

        $user = User::create([
            'name' => 'Op '.$email, 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member', clientScopeIds: $clientScope,
        ));

        return $user;
    }

    /** The demo agency's owner: every permission, no client scope — the ceiling does not apply. */
    private function owner(): User
    {
        return $this->operator('owner@boundary.dev', [
            'clients.view', ClientScopeResolver::ALL_CLIENTS, 'workspaces.view', 'projects.view', 'projects.view.all',
            'tasks.view', 'messaging.view', 'billing.view', 'requests.view', 'campaigns.view', 'reports.view',
        ]);
    }

    // ---- 1. the owner reaches everything the portal offers ------------------------------------

    public function test_the_agency_owner_reaches_the_dashboard_tasks_conversations_and_finance(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/agency/dashboard')->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/tasks')->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/messaging/threads')->assertOk();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/billing/overview')->assertOk();
    }

    // ---- 2. a scoped manager reaches exactly what their permissions allow ----------------------

    public function test_a_client_scoped_manager_reaches_what_they_hold_and_is_refused_the_rest(): void
    {
        $alpha = $this->client('Alpha');

        // The shape of the seeded `manager@demo-agency.local`: client work, no finance, no inbox.
        $manager = $this->operator('manager@boundary.dev', [
            'clients.view', 'projects.view', 'tasks.view', 'requests.view', 'campaigns.view', 'reports.view',
        ], [(string) $alpha->id]);

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/tasks')->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/agency/dashboard')->assertOk();

        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/messaging/threads')->assertForbidden();
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/billing/overview')->assertForbidden();
    }

    // ---- 3 & 4. one permission is the whole difference -----------------------------------------

    public function test_a_member_holding_billing_view_reads_finance_and_one_without_it_is_refused(): void
    {
        $withBilling = $this->operator('finance@boundary.dev', ['clients.view', 'billing.view']);
        $withoutBilling = $this->operator('nofinance@boundary.dev', ['clients.view']);

        $this->actingAs($withBilling, 'sanctum')->getJson('/api/v1/billing/overview')->assertOk();
        $this->actingAs($withoutBilling, 'sanctum')->getJson('/api/v1/billing/overview')->assertForbidden();
    }

    /**
     * The refusal SAYS it is a refusal.
     *
     * This is the defect the whole unit came from. `abort_unless($user->hasPermission(…), 403)`
     * carries no message, and the renderer's fallback was `api.failed` — «تعذّر تنفيذ الطلب» — so
     * the client had no way to tell "you are not allowed" from "it broke", and every page printed
     * the second. Asserted in Arabic because that is the language the reports came in.
     */
    public function test_a_refusal_reads_as_a_refusal_and_not_as_a_failed_request(): void
    {
        $member = $this->operator('mute@boundary.dev', ['clients.view']);

        $response = $this->actingAs($member, 'sanctum')
            ->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/messaging/threads')
            ->assertForbidden();

        $this->assertSame(__('api.unauthorized', locale: 'ar'), $response->json('message'));
        $this->assertNotSame(__('api.failed', locale: 'ar'), $response->json('message'));
    }

    // ---- 5. a project-scoped member sees their own project and no other ------------------------

    public function test_a_project_scoped_member_reads_their_own_project_and_is_refused_another(): void
    {
        $client = $this->client('Alpha');
        $mine = $this->project($client, 'Mine');
        $theirs = $this->project($client, 'Theirs');

        // `projects.view` WITHOUT `projects.view.all` — the combination the project ceiling applies to.
        $member = $this->operator('scoped@boundary.dev', ['clients.view', 'projects.view', 'tasks.view']);

        /*
         * TEAM-PROJECT-RBAC-001 — the role is named, and it is the one whose access this asserts.
         *
         * This wrote `'member'`, a string `ProjectRole` has never had. It resolved through the legacy
         * fallback to the VIEWER preset, which deliberately grants dashboard, analytics, campaigns
         * and reports and NOT tasks: a client viewer is not entitled to the agency's internal work
         * list, and `client_viewer` maps to that preset for exactly that reason.
         *
         * So the route capability was correct and the fixture was asking the wrong person. The
         * assertion here is about ISOLATION — own project yes, another project no — and the task list
         * was only ever the surface it used to ask. `marketing_manager` is a role that genuinely has
         * `tasks.view`, so the isolation claim is now made by somebody who would otherwise be allowed.
         */
        ProjectMembership::create([
            'project_id' => $mine->id,
            'user_id' => $member->id,
            'role' => ProjectRole::MARKETING_MANAGER,
            'status' => 'active',
        ]);

        $this->actingAs($member, 'sanctum')->getJson("/api/v1/projects/{$mine->id}/tasks")->assertOk();
        $this->actingAs($member, 'sanctum')->getJson("/api/v1/projects/{$theirs->id}/tasks")->assertForbidden();
    }

    // ---- 6 & 9. another agency gets nothing, and leaks nothing ---------------------------------

    public function test_another_agencys_operator_cannot_reach_this_agencys_project_or_see_its_tasks(): void
    {
        $client = $this->client('Alpha');
        $project = $this->project($client, 'Ours');
        Task::create([
            'tenant_id' => $this->agency->id, 'project_id' => $project->id,
            'title' => 'Secret internal task', 'status' => 'todo', 'priority' => 'normal',
        ]);

        $other = Tenant::create([
            'name' => 'Rival Agency', 'slug' => 'rival-agency', 'status' => 'active', 'account_type' => 'agency',
        ]);
        $otherClient = $this->client('Rival Client', $other);
        $rival = $this->operator('rival@boundary.dev', [
            'clients.view', ClientScopeResolver::ALL_CLIENTS, 'projects.view', 'projects.view.all', 'tasks.view',
        ], null, $other);
        $this->project($otherClient, 'Theirs', $other);

        // 404, not 403: the identifier must not confirm that the project exists somewhere else.
        $this->actingAs($rival, 'sanctum')->getJson("/api/v1/projects/{$project->id}/tasks")->assertNotFound();

        // And the tenant-wide list carries none of our rows.
        $body = $this->actingAs($rival, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json('data');
        $this->assertSame([], array_values(array_filter(
            $body, static fn (array $row): bool => $row['title'] === 'Secret internal task',
        )));
    }

    /**
     * The client ceiling reaches the tenant-wide lists too.
     *
     * Found by signing in as the scoped fixture and reading the number on the card: the demo
     * manager is responsible for ONE of five clients and the tasks page said 2105 — the whole
     * agency. Tenant isolation was the only filter these two lists had, and a client scope is a
     * second, narrower one that nothing applied. Every test the project had asserted the tenant
     * boundary, which is precisely the boundary that was not leaking.
     */
    public function test_a_client_scoped_manager_sees_only_their_clients_tasks_and_conversations(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');

        Task::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $mine->id, 'title' => 'Mine', 'status' => 'todo', 'priority' => 'normal']);
        Task::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $theirs->id, 'title' => 'Theirs', 'status' => 'todo', 'priority' => 'normal']);

        $manager = $this->operator('scopedlist@boundary.dev', [
            'clients.view', 'tasks.view', 'messaging.view',
        ], [(string) $mine->id]);

        MessageThread::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $mine->id, 'subject' => 'Mine', 'status' => 'open']);
        MessageThread::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $theirs->id, 'subject' => 'Theirs', 'status' => 'open']);

        $tasks = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json('data');
        $this->assertSame(['Mine'], array_column($tasks, 'title'));

        $threads = $this->actingAs($manager, 'sanctum')->getJson('/api/v1/messaging/threads')->assertOk()->json('data');
        $this->assertSame(['Mine'], array_column($threads, 'subject'));
    }

    /**
     * A row with no client is not everybody's row.
     *
     * The plain ceiling — `whereIn(client_workspace_id, …)` — would have dropped a scoped manager's
     * own internal task off their own screen, because an internal task has no client by design.
     * So client-less rows are included when they are demonstrably the caller's, and only then.
     */
    public function test_an_internal_task_is_visible_to_its_owner_and_to_nobody_else_who_is_scoped(): void
    {
        $mine = $this->client('Mine');
        $author = $this->operator('author@boundary.dev', ['clients.view', 'tasks.view'], [(string) $mine->id]);
        $other = $this->operator('other@boundary.dev', ['clients.view', 'tasks.view'], [(string) $mine->id]);

        Task::create([
            'tenant_id' => $this->agency->id, 'client_workspace_id' => null, 'created_by' => $author->id,
            'title' => 'My own note', 'status' => 'todo', 'priority' => 'normal',
        ]);

        $ownList = $this->actingAs($author, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json('data');
        $this->assertSame(['My own note'], array_column($ownList, 'title'));

        $otherList = $this->actingAs($other, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json('data');
        $this->assertSame([], array_column($otherList, 'title'));
    }

    /** Hidden from the list must also mean unreachable by id — otherwise the ceiling is decoration. */
    public function test_a_scoped_manager_cannot_open_or_write_to_a_thread_outside_their_scope(): void
    {
        $mine = $this->client('Mine');
        $theirs = $this->client('Theirs');
        $manager = $this->operator('guess@boundary.dev', [
            'clients.view', 'messaging.view', 'messaging.manage',
        ], [(string) $mine->id]);

        $thread = MessageThread::create([
            'tenant_id' => $this->agency->id, 'client_workspace_id' => $theirs->id,
            'subject' => 'Not yours', 'status' => 'open',
        ]);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/messaging/threads/{$thread->id}")->assertForbidden();
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/messaging/threads/{$thread->id}/messages", ['author_type' => 'team', 'body' => 'hello'])
            ->assertForbidden();

        // And a new thread cannot be filed against a client the membership does not reach.
        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/messaging/threads', ['subject' => 'Sneaking in', 'client_workspace_id' => (string) $theirs->id])
            ->assertForbidden();
    }

    // ---- 7. no context is guidance, not an error -----------------------------------------------

    /**
     * Nothing selected yet is a 200 with an empty list, never a failure.
     *
     * A brand-new agency has no clients and no projects. If the roster answered an error, the first
     * thing a paying customer would see is a broken product — so the "nothing here yet" case has to
     * be a successful, empty answer that the interface can turn into guidance.
     */
    public function test_an_agency_with_no_clients_or_projects_gets_an_empty_list_not_an_error(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/client-workspaces')
            ->assertOk()->assertJsonPath('data', []);
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/tasks')
            ->assertOk()->assertJsonPath('data', []);
    }

    // ---- 8. switching the project changes the data ---------------------------------------------

    public function test_switching_the_project_changes_which_tasks_are_returned(): void
    {
        $client = $this->client('Alpha');
        $one = $this->project($client, 'One');
        $two = $this->project($client, 'Two');

        Task::create(['tenant_id' => $this->agency->id, 'project_id' => $one->id, 'title' => 'Task in one', 'status' => 'todo', 'priority' => 'normal']);
        Task::create(['tenant_id' => $this->agency->id, 'project_id' => $two->id, 'title' => 'Task in two', 'status' => 'todo', 'priority' => 'normal']);

        $owner = $this->owner();

        $first = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$one->id}/tasks")->assertOk()->json('data');
        $second = $this->actingAs($owner, 'sanctum')->getJson("/api/v1/projects/{$two->id}/tasks")->assertOk()->json('data');

        $this->assertSame(['Task in one'], array_column($first, 'title'));
        $this->assertSame(['Task in two'], array_column($second, 'title'));
    }
}
