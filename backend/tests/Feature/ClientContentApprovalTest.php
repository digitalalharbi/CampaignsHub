<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\ClientWorkspaces\Models\ContentApproval;
use App\Domains\ClientWorkspaces\Services\ContentApprovals;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CLIENT-CONTENT-APPROVAL-001 — «بانتظار اعتمادك». The agency puts content before its client; the client's
 * approver answers once; a viewer reads only; another client, another project and another workspace see
 * nothing; every step is audited.
 */
final class ClientContentApprovalTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private User $readonly;

    private ClientWorkspace $client;

    private ClientWorkspace $otherClient;

    private Project $a;

    private Project $b;

    private Project $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ownerRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $ownerRole->givePermissionTo(...Permission::pluck('key')->all());
        $viewRole = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'ReadOnly', 'slug' => 'readonly']);
        $viewRole->givePermissionTo('clients.view', 'clients.view_all', 'content.view');

        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->readonly = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->readonly, $this->tenant, Portal::Agency);
        $this->readonly->assignRole($viewRole);

        $this->client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->otherClient = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Henka', 'slug' => 'henka', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->client->id, 'name' => 'Bravo', 'status' => 'active']);
        $this->foreign = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->otherClient->id, 'name' => 'Foreign', 'status' => 'active']);
    }

    private function url(ClientWorkspace $c, string $suffix = ''): string
    {
        return "/api/v1/app/clients/{$c->id}/content-approvals{$suffix}";
    }

    /** @param  list<string>|null  $projects */
    private function portalUser(string $email, string $role, ?ClientWorkspace $space = null, ?array $projects = null): User
    {
        $user = User::create(['name' => 'Client', 'email' => $email, 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: $role,
            clientScopeIds: [(string) ($space ?? $this->client)->id], projectScopeIds: $projects, grantedBy: $this->owner,
            enrolNotifications: false,
        ));

        return $user;
    }

    private function ask(?Project $project = null, array $extra = []): string
    {
        $project ??= $this->a;
        $client = $project->client_workspace_id === $this->client->id ? $this->client : $this->otherClient;

        return (string) $this->actingAs($this->owner, 'sanctum')
            ->postJson($this->url($client), ['project_id' => (string) $project->id, 'title' => 'Ramadan story v2', 'caption' => 'Offer copy'] + $extra)
            ->assertCreated()->json('data.approval.id');
    }

    public function test_the_agency_asks_and_the_ask_is_audited(): void
    {
        $id = $this->ask();

        $row = ContentApproval::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('pending', $row->status);
        $this->assertSame((string) $this->tenant->id, (string) $row->tenant_id);
        $this->assertTrue(AuditLog::query()->where('action', 'content_approval.created')->where('entity_id', $id)->exists());
        $this->getJson($this->url($this->client))->assertOk()->assertJsonCount(1, 'data.approvals');
    }

    public function test_a_project_or_creative_from_elsewhere_cannot_be_stitched_in(): void
    {
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson($this->url($this->client), ['project_id' => (string) $this->foreign->id, 'title' => 'X1'])->assertStatus(422);

        $creative = ExternalCreative::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->foreign->id, 'provider' => 'meta', 'external_creative_id' => 'c-1', 'name' => 'Foreign ad']);
        $this->postJson($this->url($this->client), ['project_id' => (string) $this->a->id, 'title' => 'X2', 'creative_id' => (string) $creative->id])->assertStatus(422);
        $this->assertSame(0, ContentApproval::withoutGlobalScopes()->count());
    }

    public function test_an_operator_without_content_create_cannot_ask(): void
    {
        $this->actingAs($this->readonly, 'sanctum');
        $this->getJson($this->url($this->client))->assertOk();
        $this->postJson($this->url($this->client), ['project_id' => (string) $this->a->id, 'title' => 'Nope'])->assertForbidden();
    }

    public function test_another_workspace_cannot_read_or_write_this_clients_approvals(): void
    {
        $this->ask();
        $other = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active']);
        $role = Role::create(['tenant_id' => $other->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $rival = User::create(['name' => 'Rival', 'email' => 'r@b.test', 'password' => 'secret123']);
        $this->grantMembership($rival, $other, Portal::Agency);
        app(TenantContext::class)->setTenantId($other->id);
        $rival->assignRole($role);
        app(TenantContext::class)->forget();

        $this->actingAs($rival, 'sanctum');
        $headers = ['X-Tenant-Id' => (string) $other->id];
        $this->getJson($this->url($this->client), $headers)->assertNotFound();
        $this->postJson($this->url($this->client), ['project_id' => (string) $this->a->id, 'title' => 'Steal'], $headers)->assertNotFound();
        $this->assertSame(1, ContentApproval::withoutGlobalScopes()->count());
    }

    public function test_an_approver_approves_once_and_a_viewer_only_reads(): void
    {
        $id = $this->ask();
        $viewer = $this->portalUser('v@client.test', 'client_viewer');
        $approver = $this->portalUser('ap@client.test', 'client_approver');

        $this->actingAs($viewer);
        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()
            ->assertJsonCount(1, 'data.approvals')->assertJsonPath('data.approvals.0.can_decide', false)
            ->assertJsonMissingPath('data.approvals.0.tenant_id');
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertForbidden();
        $this->assertSame('pending', ContentApproval::withoutGlobalScopes()->findOrFail($id)->status);

        $this->actingAs($approver);
        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->assertJsonPath('data.approvals.0.can_decide', true);
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertOk()->assertJsonPath('data.approval.status', 'approved');
        $this->postJson("/api/v1/client/approvals/{$id}/request-changes", ['note' => 'Too late'], $this->spa)->assertStatus(422);

        $row = ContentApproval::withoutGlobalScopes()->findOrFail($id);
        $this->assertSame('approved', $row->status);
        $this->assertSame($approver->id, $row->decided_by_user_id);
        $this->assertTrue(AuditLog::query()->where('action', 'content_approval.approved')->where('entity_id', $id)->exists());
    }

    public function test_asking_for_changes_needs_a_note(): void
    {
        $id = $this->ask();
        $this->actingAs($this->portalUser('ap@client.test', 'client_approver'));

        $this->postJson("/api/v1/client/approvals/{$id}/request-changes", [], $this->spa)->assertStatus(422);
        $this->postJson("/api/v1/client/approvals/{$id}/request-changes", ['note' => 'Use the new logo'], $this->spa)->assertOk()
            ->assertJsonPath('data.approval.status', 'changes_requested')->assertJsonPath('data.approval.decision_note', 'Use the new logo');
    }

    public function test_another_clients_approver_sees_nothing_and_cannot_answer(): void
    {
        $id = $this->ask();
        $this->actingAs($this->portalUser('h@other.test', 'client_approver', $this->otherClient));

        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->assertJsonCount(0, 'data.approvals');
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertNotFound();
        $this->assertSame('pending', ContentApproval::withoutGlobalScopes()->findOrFail($id)->status);
    }

    public function test_a_project_confined_approver_reaches_only_their_project(): void
    {
        $mine = $this->ask($this->a);
        $theirs = $this->ask($this->b);
        $this->actingAs($this->portalUser('a@client.test', 'client_approver', null, [(string) $this->a->id]));

        $list = $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->json('data.approvals');
        $this->assertSame([$mine], array_column($list, 'id'));
        $this->postJson("/api/v1/client/approvals/{$theirs}/approve", [], $this->spa)->assertNotFound();
    }

    public function test_the_agency_switches_decide_reading_and_answering(): void
    {
        $id = $this->ask();
        $approver = $this->portalUser('ap@client.test', 'client_approver');

        $this->client->update(['settings' => ['portal' => ['capabilities' => ['approve_content' => false]]]]);
        $this->actingAs($approver);
        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->assertJsonPath('data.approvals.0.can_decide', false);
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertForbidden();

        $this->client->update(['settings' => ['portal' => ['visibility' => ['content' => false]]]]);
        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->assertJsonCount(0, 'data.approvals');
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertNotFound();
    }

    public function test_a_withdrawn_ask_leaves_the_portal(): void
    {
        $id = $this->ask();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url($this->client, "/{$id}/withdraw"))->assertOk()
            ->assertJsonPath('data.approval.status', 'withdrawn');

        $this->actingAs($this->portalUser('ap@client.test', 'client_approver'));
        $this->getJson('/api/v1/client/approvals', $this->spa)->assertOk()->assertJsonCount(0, 'data.approvals');
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertNotFound();
    }

    public function test_a_signed_out_visitor_gets_nothing(): void
    {
        $id = $this->ask();
        auth()->forgetGuards();
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->getJson('/api/v1/client/approvals', $this->spa)->assertUnauthorized();
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertUnauthorized();
    }

    public function test_the_new_portal_page_path_can_never_be_a_client_slug(): void
    {
        $c = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Approvals', 'slug' => 'approvals', 'mode' => 'managed', 'status' => 'active']);

        $this->assertSame('approvals-client', $c->slug);
    }

    public function test_a_withdraw_cannot_overwrite_an_answer_the_client_already_gave(): void
    {
        $id = $this->ask();
        $this->actingAs($this->portalUser('ap@client.test', 'client_approver'));
        $this->postJson("/api/v1/client/approvals/{$id}/approve", [], $this->spa)->assertOk();

        $stale = ContentApproval::withoutGlobalScopes()->findOrFail($id);
        $stale->status = 'pending'; // the copy the agency loaded before the client answered
        $this->expectException(\InvalidArgumentException::class);
        try {
            app(ContentApprovals::class)->withdraw($stale, $this->owner);
        } finally {
            $this->assertSame('approved', ContentApproval::withoutGlobalScopes()->findOrFail($id)->status);
        }
    }
}
