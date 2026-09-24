<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportSchedule;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * PROJECT-DELETE-001 — the destructive action the product did not have.
 *
 * ## What existed, and why it was not enough
 *
 * A project could be created, renamed, cloned, archived, restored, paused and resumed. It could not
 * be DELETED. `projects.delete` was seeded into the permission catalogue and no route ever read it,
 * so an agency that took on a client and lost them again was left with the client's project in the
 * product for ever — and «أرشفة» was offered as if it were the answer, which is a different promise:
 * archive is reversible and keeps everything, and the person asking wanted the opposite.
 *
 * ## Why deletion is dangerous here specifically
 *
 * A project is not a folder. It sits in the middle of a chain — tenant → client → project →
 * connection → selected accounts → data → reports → links — and the two rungs ABOVE it are shared.
 * One Snapchat authorisation feeds several projects; one organisation's accounts are split between
 * clients. So the whole risk of this feature is over-reach: deleting a project must close what the
 * project owns and touch nothing that merely passes through it.
 *
 * The tests below are written from that risk rather than from the happy path:
 *
 *  - a neighbouring project on the SAME provider connection keeps its bindings, and the connection
 *    keeps its authorisation. Revoking OAuth because one of its consumers went away would take the
 *    other client's advertising offline.
 *  - the advertising account itself is untouched. Nothing here reaches into Snapchat.
 *  - a client-facing LIVE link stops serving. A share is an authorisation path that outlives the
 *    interface, and a deleted project whose link still answers is the deletion undone from outside.
 *  - a schedule stops. A deleted project that emails a client next Sunday is worse than one that was
 *    never deleted, because nobody is watching for it any more.
 *  - the name must be typed, server-side. A confirmation that only the browser enforces is a
 *    confirmation an API call skips.
 *  - `projects.update` is not enough. Renaming a client and destroying one are not the same decision
 *    and the catalogue already separates them.
 */
final class ProjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private ClientWorkspace $workspace;

    private ProviderConnection $connection;

    private Project $doomed;

    private Project $neighbour;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@ag.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'Client', 'slug' => 'cl-'.uniqid(), 'mode' => 'managed']);

        $this->doomed = $this->project('رزة أفينيو');
        $this->neighbour = $this->project('عميل آخر');

        // ONE authorisation feeding TWO clients — the shape that makes deletion dangerous.
        $this->connection = $this->providerConnection();
        $this->bind($this->doomed, $this->account('act-doomed'));
        $this->bind($this->neighbour, $this->account('act-neighbour'));
    }

    // ── the impact summary ────────────────────────────────────────────────────────────────────

    /**
     * «Are you sure?» is not a question anybody can answer. This is what will happen, counted.
     */
    public function test_the_impact_summary_states_what_deletion_will_reach(): void
    {
        $report = $this->report($this->doomed);
        $this->liveShare($report);
        $this->schedule($this->doomed, $report);

        $impact = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->doomed->id}/deletion-impact")
            ->assertOk()
            ->json('data');

        $this->assertSame((string) $this->doomed->id, $impact['project']['id']);
        $this->assertSame('رزة أفينيو', $impact['project']['name']);
        $this->assertSame('Client', $impact['project']['client']);

        $this->assertSame(1, $impact['counts']['integration_bindings']);
        $this->assertSame(1, $impact['counts']['reports']);
        $this->assertSame(1, $impact['counts']['active_shares']);
        $this->assertSame(1, $impact['counts']['report_schedules']);

        /*
         * The sentence the dialog must be able to make: this does not reach the advertising account.
         * It is a fact about the deletion, so it is stated by the server rather than written into a
         * translation file where it can drift away from what the code does.
         */
        $this->assertFalse($impact['revokes_provider_authorisation']);
        $this->assertFalse($impact['deletes_advertising_accounts']);
    }

    /** The summary describes THIS project. A neighbour's report is not in this dialog. */
    public function test_the_impact_summary_does_not_count_a_neighbouring_project(): void
    {
        $this->liveShare($this->report($this->neighbour));

        $impact = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->doomed->id}/deletion-impact")
            ->assertOk()->json('data');

        $this->assertSame(0, $impact['counts']['reports']);
        $this->assertSame(0, $impact['counts']['active_shares']);
        $this->assertSame(1, $impact['counts']['integration_bindings']);
    }

    // ── the deletion ──────────────────────────────────────────────────────────────────────────

    /** The project leaves the product: the list, and every project-scoped route. */
    public function test_a_deleted_project_leaves_the_product(): void
    {
        $this->delete_it()->assertOk();

        $names = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/projects')->json('data'))
            ->pluck('name')->all();
        $this->assertSame(['عميل آخر'], $names);

        // Archived projects are still reachable with the flag; a deleted one is not there either.
        $this->assertCount(
            1,
            $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/projects?include_archived=1')->json('data'),
        );

        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->doomed->id}/overview")
            ->assertNotFound();
    }

    /**
     * **The risk, pinned.** The neighbour shares the connection and keeps everything.
     */
    public function test_a_neighbour_on_the_same_connection_is_untouched(): void
    {
        $this->delete_it()->assertOk();

        $neighbourBinding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $this->neighbour->id)->first();

        $this->assertNotNull($neighbourBinding);
        $this->assertTrue((bool) $neighbourBinding->is_active, 'deleting one client deactivated another client’s binding');

        $this->assertSame(
            'connected',
            (string) ProviderConnection::withoutGlobalScopes()->find($this->connection->id)->status,
            'deleting a project revoked the authorisation another project is still using',
        );
    }

    /** Nothing here reaches into the advertising platform. The accounts stay discovered. */
    public function test_the_advertising_accounts_are_not_deleted(): void
    {
        $this->delete_it()->assertOk();

        $this->assertSame(2, ExternalAccount::withoutGlobalScopes()->count());
    }

    /** The project's own binding stops contributing — it is the one thing that must go quiet. */
    public function test_the_projects_own_binding_stops_contributing(): void
    {
        $this->delete_it()->assertOk();

        $binding = ProjectIntegrationBinding::withoutGlobalScopes()
            ->where('project_id', $this->doomed->id)->first();

        $this->assertNotNull($binding, 'the binding row was destroyed rather than deactivated');
        $this->assertFalse((bool) $binding->is_active);
    }

    /**
     * A live link is an authorisation path that outlives the screen it was made on.
     */
    public function test_a_client_facing_link_stops_serving(): void
    {
        [, $token] = $this->liveShare($this->report($this->doomed));

        $this->getJson("/api/v1/reports/shared/{$token}")->assertOk();

        $this->delete_it()->assertOk();

        $this->getJson("/api/v1/reports/shared/{$token}")->assertNotFound();
    }

    /** And a neighbour's link keeps working. */
    public function test_a_neighbours_link_keeps_serving(): void
    {
        [, $token] = $this->liveShare($this->report($this->neighbour));

        $this->delete_it()->assertOk();

        $this->getJson("/api/v1/reports/shared/{$token}")->assertOk();
    }

    /** A deleted project that emails a client next Sunday is the deletion undone on a timer. */
    public function test_the_projects_schedules_stop(): void
    {
        $schedule = $this->schedule($this->doomed, $this->report($this->doomed));
        $neighbours = $this->schedule($this->neighbour, $this->report($this->neighbour));

        $this->delete_it()->assertOk();

        $this->assertFalse((bool) ReportSchedule::withoutGlobalScopes()->find($schedule->id)->active);
        $this->assertTrue((bool) ReportSchedule::withoutGlobalScopes()->find($neighbours->id)->active);
    }

    // ── refusals ──────────────────────────────────────────────────────────────────────────────

    /** A confirmation only the browser enforces is a confirmation an API call skips. */
    public function test_the_name_must_be_typed(): void
    {
        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", ['confirm_name' => 'رزة'])
            ->assertStatus(422);

        $this->assertNotNull(Project::withoutGlobalScopes()->find($this->doomed->id));

        $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", [])
            ->assertStatus(422);

        $this->assertNotNull(Project::withoutGlobalScopes()->find($this->doomed->id));
    }

    /** Renaming a client and destroying one are not the same decision. */
    public function test_renaming_permission_is_not_deleting_permission(): void
    {
        $manager = $this->userWith(['projects.view', 'projects.view.all', 'projects.update']);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", ['confirm_name' => 'رزة أفينيو'])
            ->assertForbidden();

        $this->assertNotNull(Project::withoutGlobalScopes()->find($this->doomed->id));
    }

    /** A member confined to one project cannot destroy the one next to it, UUID in hand. */
    public function test_a_member_of_one_project_cannot_delete_another(): void
    {
        $member = $this->userWith(['projects.view', 'projects.update', 'projects.delete']);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->neighbour->id,
            'user_id' => $member->id, 'role' => 'member', 'status' => 'active',
        ]);

        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", ['confirm_name' => 'رزة أفينيو'])
            ->assertForbidden();

        $this->assertNotNull(Project::withoutGlobalScopes()->find($this->doomed->id));
    }

    /** Another tenant's project is not confirmed to exist. */
    public function test_another_tenants_project_is_not_found(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'ot-'.uniqid(), 'status' => 'active']);
        $stranger = User::create(['name' => 'S', 'email' => 's@ot.test', 'password' => 'secret123']);
        $this->grantMembership($stranger, $other);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $stranger->assignRole($role);

        app(TenantContext::class)->setTenantId($other->id);

        $this->actingAs($stranger, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", ['confirm_name' => 'رزة أفينيو'])
            ->assertNotFound();

        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/projects/{$this->doomed->id}/deletion-impact")
            ->assertNotFound();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────────────────────

    private function delete_it(): TestResponse
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->doomed->id}", ['confirm_name' => 'رزة أفينيو']);
    }

    /** @param list<string> $permissions */
    private function userWith(array $permissions): User
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'R'.uniqid(), 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);

        $user = User::create(['name' => 'U', 'email' => uniqid().'@ag.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function project(string $name): Project
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return Project::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'name' => $name,
            'status' => 'active',
        ]);
    }

    private function report(Project $project): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'name' => 'تقرير '.$project->name,
            'type' => 'performance',
            'form' => 'detailed',
            'audience' => 'client',
            'mode' => 'snapshot',
            'status' => 'completed',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'currency' => 'SAR',
            'data' => ['sections' => []],
            'generated_at' => now(),
        ]);
    }

    /** @return array{0: ReportShare, 1: string} */
    private function liveShare(Report $report): array
    {
        return app(ShareService::class)->create($report, ['mode' => 'snapshot'], $this->owner->id);
    }

    private function schedule(Project $project, Report $report): ReportSchedule
    {
        return ReportSchedule::create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'report_id' => $report->id,
            'name' => 'أسبوعي',
            'type' => 'performance',
            'frequency' => 'weekly',
            'timezone' => 'Asia/Riyadh',
            'audience' => 'client',
            'language' => 'ar',
            'formats' => ['pdf'],
            'recipients' => ['client@example.test'],
            'active' => true,
        ]);
    }

    private function providerConnection(): ProviderConnection
    {
        $credential = new IntegrationCredential([
            'provider' => 'snapchat', 'credential_scope' => 'project_only',
            'credential_type' => 'oauth', 'status' => 'active',
        ]);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::create([
            'tenant_id' => $this->tenant->id,
            'credential_id' => $credential->id,
            'provider' => 'snapchat',
            'connection_name' => 'snapchat',
            'scope' => 'project_only',
            'status' => 'connected',
        ]);
    }

    private function account(string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection->id,
            'provider' => 'snapchat',
            'account_type' => 'ad_account',
            'external_id' => $externalId,
            'name' => $externalId,
            'status' => 'active',
            'discovered_at' => now(),
        ]);
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::create([
            'tenant_id' => $this->tenant->id,
            'client_workspace_id' => $this->workspace->id,
            'project_id' => $project->id,
            'external_account_id' => $account->id,
            'provider' => 'snapchat',
            'purpose' => 'reporting',
            'is_active' => true,
        ]);
    }
}
