<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Billing\Models\Invoice;
use App\Domains\Billing\Models\Quote;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-USERS-001 — the agency gives a client's people their portal: an existing account is granted (never
 * duplicated), a new address is invited and the acceptance proves it, a role limits what they may do, a
 * project ceiling limits what they may read, and revoking takes only this client away.
 */
final class ClientPortalUsersTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private User $owner;

    private User $readonly;

    private ClientWorkspace $workspace;

    private Project $a;

    private Project $b;

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
        $viewRole->givePermissionTo('clients.view', 'clients.view_all');
        $this->owner = User::create(['name' => 'Owner', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($ownerRole);
        $this->readonly = User::create(['name' => 'RO', 'email' => 'ro@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->readonly, $this->tenant, Portal::Agency);
        $this->readonly->assignRole($viewRole);

        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Bravo', 'status' => 'active']);
        foreach ([[$this->a, 'Alpha campaign'], [$this->b, 'Bravo campaign']] as [$p, $n]) {
            UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $p->id, 'client_workspace_id' => $this->workspace->id, 'name' => $n, 'objective' => 'conversions', 'status' => 'active']);
        }
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/app/clients/{$this->workspace->id}/portal-users{$suffix}";
    }

    public function test_an_existing_account_is_granted_the_scoped_membership_and_never_duplicated(): void
    {
        $existing = User::create(['name' => 'Sara', 'email' => 'Sara@Client.test', 'password' => 'her-own-password']);
        $before = User::query()->count();

        $res = $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['email' => 'sara@client.test', 'role' => 'client_approver', 'project_ids' => [$this->a->id]])
            ->assertOk()->assertJsonPath('data.state', 'granted');

        $this->assertSame($before, User::query()->count(), 'no second user for the same address');
        $this->assertSame($existing->id, $res->json('data.user_id'));
        $membership = Membership::query()->where('user_id', $existing->id)->where('portal', 'portal')->firstOrFail();
        $this->assertSame('client_approver', $membership->role);
        $this->assertSame([(string) $this->workspace->id], $membership->clientScopeIds());
        $this->assertSame([(string) $this->a->id], $membership->projectScopeIds());
        $this->assertTrue(\Hash::check('her-own-password', $existing->fresh()->password), 'the account itself is untouched');
    }

    public function test_a_grant_replaces_the_ceiling_it_is_not_added_to_the_old_one(): void
    {
        $user = User::create(['name' => 'Sara', 'email' => 'sara@client.test', 'password' => 'x12345678']);
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['email' => 'sara@client.test', 'role' => 'client_viewer', 'project_ids' => [$this->a->id]])->assertOk();
        $this->postJson($this->url(), ['email' => 'sara@client.test', 'role' => 'client_viewer', 'project_ids' => [$this->b->id]])->assertOk();

        $this->assertSame([(string) $this->b->id], Membership::query()->where('user_id', $user->id)->firstOrFail()->projectScopeIds());

        $this->postJson($this->url(), ['email' => 'sara@client.test', 'role' => 'client_viewer'])->assertOk();   // the whole client
        $this->assertSame([], Membership::query()->where('user_id', $user->id)->firstOrFail()->projectScopeIds());
    }

    public function test_a_new_address_is_invited_and_accepting_creates_one_verified_account_with_only_that_client(): void
    {
        $res = $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['email' => 'New@Client.test', 'role' => 'client_viewer', 'project_ids' => [$this->a->id]])
            ->assertCreated()->assertJsonPath('data.state', 'invited');
        $link = $res->json('data.dev_link');
        $this->assertNotNull($link, 'the test environment exposes the link');
        $token = substr($link, strpos($link, 'token=') + 6);

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.workspace_name', 'Razzah');
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'New Client', 'password' => 'a-long-password'])->assertCreated();

        $user = User::query()->where('email', 'new@client.test')->firstOrFail();
        $this->assertNotNull($user->email_verified_at, 'the link proves the address — no second step');
        $this->assertSame(1, User::query()->where('email', 'new@client.test')->count());
        $membership = Membership::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('portal', $membership->portal->value);
        $this->assertSame([(string) $this->workspace->id], $membership->clientScopeIds());
        $this->assertSame([(string) $this->a->id], $membership->projectScopeIds());
        $this->assertCount(0, $user->roles, 'no staff role came with a client invitation');
        $this->assertSame(1, Membership::query()->where('user_id', $user->id)->count(), 'and no agency membership either');

        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'name' => 'Again', 'password' => 'a-long-password'])->assertStatus(422);
    }

    public function test_client_administrators_are_grantable_only_where_the_agency_enabled_them(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['email' => 'boss@client.test', 'role' => 'client_admin'])->assertStatus(422);

        $this->workspace->update(['settings' => ['portal' => ['capabilities' => ['team_admin' => true]]]]);
        $this->postJson($this->url(), ['email' => 'boss@client.test', 'role' => 'client_admin'])->assertCreated();
    }

    public function test_a_project_must_belong_to_the_client_and_an_unknown_role_is_refused(): void
    {
        $other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Other', 'slug' => 'other', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $foreign = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $other->id, 'name' => 'Foreign', 'status' => 'active']);

        $this->actingAs($this->owner, 'sanctum');
        $this->postJson($this->url(), ['email' => 'x@client.test', 'role' => 'client_viewer', 'project_ids' => [$foreign->id]])->assertStatus(422);
        $this->postJson($this->url(), ['email' => 'x@client.test', 'role' => 'owner'])->assertStatus(422);
    }

    public function test_only_an_operator_who_may_manage_this_clients_team_can_do_any_of_it(): void
    {
        $this->actingAs($this->readonly, 'sanctum');

        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url(), ['email' => 'x@client.test', 'role' => 'client_viewer'])->assertForbidden();
    }

    public function test_the_list_shows_members_and_pending_invitations_and_revoking_takes_only_this_client(): void
    {
        $user = User::create(['name' => 'Sara', 'email' => 'sara@client.test', 'password' => 'x12345678']);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson($this->url(), ['email' => 'sara@client.test', 'role' => 'client_viewer'])->assertOk();
        $this->postJson($this->url(), ['email' => 'later@client.test', 'role' => 'client_viewer'])->assertCreated();

        $list = collect($this->getJson($this->url())->assertOk()->json('data.users'));
        $this->assertSame(['active', 'invited'], $list->pluck('state')->sort()->values()->all());

        $membershipId = $list->firstWhere('state', 'active')['membership_id'];
        $this->deleteJson($this->url("/{$membershipId}"))->assertOk();

        $this->assertSame(0, Membership::query()->where('user_id', $user->id)->where('portal', 'portal')->count());
        $this->assertNotNull(User::query()->find($user->id), 'the account survives');
    }

    public function test_a_view_only_user_reads_but_cannot_approve_pay_or_message_while_an_approver_can(): void
    {
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'QUO-U-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'status' => 'sent']);
        $invoice = Invoice::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'INV-U-1', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'amount_paid' => 0, 'status' => 'issued']);

        $viewer = User::create(['name' => 'V', 'email' => 'v@client.test', 'password' => 'x12345678']);
        $approver = User::create(['name' => 'A', 'email' => 'ap@client.test', 'password' => 'x12345678']);
        $this->actingAs($this->owner, 'sanctum');
        $this->postJson($this->url(), ['email' => 'v@client.test', 'role' => 'client_viewer'])->assertOk();
        $this->postJson($this->url(), ['email' => 'ap@client.test', 'role' => 'client_approver'])->assertOk();

        $this->actingAs($viewer);
        $this->getJson('/api/v1/client/invoices', $this->spa)->assertOk()->assertJsonCount(1, 'data.invoices');
        $this->postJson("/api/v1/client/quotes/{$quote->id}/approve", [], $this->spa)->assertForbidden();
        $this->postJson("/api/v1/client/invoices/{$invoice->id}/pay", [], $this->spa)->assertForbidden();
        $this->postJson('/api/v1/client/messages', ['subject' => 'Hi', 'body' => 'Hello'], $this->spa)->assertForbidden();
        $this->assertSame('sent', Quote::withoutGlobalScopes()->find($quote->id)->status, 'nothing changed');

        $this->actingAs($approver);
        $this->postJson("/api/v1/client/quotes/{$quote->id}/approve", [], $this->spa)->assertStatus(201);
    }

    public function test_a_role_nobody_chose_does_not_restrict_anyone(): void
    {
        $quote = Quote::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'number' => 'QUO-U-2', 'currency' => 'SAR', 'subtotal' => 100, 'tax' => 15, 'total' => 115, 'line_items' => [], 'status' => 'sent']);

        // A membership the system provisioned (migration, seeding): `client_viewer` only because a row needs a role.
        $legacy = User::create(['name' => 'Legacy', 'email' => 'legacy@client.test', 'password' => 'x12345678']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $legacy, tenant: $this->tenant, portal: Portal::ClientPortal, role: 'client_viewer', clientScopeIds: [(string) $this->workspace->id],
        ));

        $this->actingAs($legacy);
        $this->postJson("/api/v1/client/quotes/{$quote->id}/approve", [], $this->spa)->assertStatus(201);
    }
}
