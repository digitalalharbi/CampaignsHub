<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Requests\Models\ExternalRequest;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\RequestCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-REQ-ONE-001 — a client opens a request from inside their portal. It lands as the SAME entity the
 * agency inbox reads, in the right client and project, and nothing the client sends can move it
 * somewhere else.
 */
final class ClientPortalCreateRequestTest extends TestCase
{
    use RefreshDatabase;

    private array $spa = ['Origin' => 'http://localhost:5173'];

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private ClientWorkspace $other;

    private Project $a;

    private Project $b;

    private Project $foreign;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RequestCatalogSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'is_default_portal' => true]);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->workspace = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Razzah', 'slug' => 'razzah', 'mode' => 'managed', 'status' => 'active']);
        $this->other = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Henka', 'slug' => 'henka', 'mode' => 'managed', 'status' => 'active']);
        $this->a = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->b = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Bravo', 'status' => 'active']);
        $this->foreign = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->other->id, 'name' => 'Foreign', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->a->id, 'client_workspace_id' => $this->workspace->id, 'name' => 'Alpha campaign', 'objective' => 'sales', 'status' => 'active']);
    }

    private function user(string $email, ?array $projects = null, ?User $grantedBy = null, string $role = 'client_admin'): User
    {
        $user = User::create(['name' => 'Client', 'email' => $email, 'password' => 'secret123']);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->tenant, portal: Portal::ClientPortal, role: $role,
            clientScopeIds: [(string) $this->workspace->id], projectScopeIds: $projects, grantedBy: $grantedBy,
        ));

        return $user;
    }

    private function open(array $body)
    {
        return $this->postJson('/api/v1/client/requests', $body + ['type' => 'consulting', 'message' => 'Please review our June plan.'], $this->spa);
    }

    public function test_a_client_opens_a_request_that_the_agency_inbox_reads_as_its_own(): void
    {
        $this->actingAs($this->user('c@x.test'));

        $res = $this->open(['project_id' => (string) $this->a->id, 'related' => ['kind' => 'campaign', 'id' => (string) $this->campaign->id]])
            ->assertCreated();

        $row = ExternalRequest::withoutGlobalScopes()->where('reference', $res->json('data.reference'))->firstOrFail();
        $this->assertSame((string) $this->workspace->id, (string) $row->client_id);
        $this->assertSame((string) $this->a->id, (string) $row->project_id);
        $this->assertSame((string) $this->campaign->id, (string) $row->campaign_id);
        $this->assertSame('client_portal', $row->source);
        $this->assertSame('new', $row->status->key);

        // The client sees it in their own list, from the same entity.
        $this->getJson('/api/v1/client/requests', $this->spa)->assertOk()
            ->assertJsonPath('data.requests.0.reference', $res->json('data.reference'));
    }

    public function test_a_project_of_another_client_is_refused_like_an_unknown_one(): void
    {
        $this->actingAs($this->user('c@x.test'));

        $this->open(['project_id' => (string) $this->foreign->id])->assertNotFound();
        $this->open(['project_id' => '00000000-0000-0000-0000-000000000000'])->assertNotFound();
        $this->assertSame(0, ExternalRequest::withoutGlobalScopes()->count());
    }

    public function test_a_project_restricted_user_cannot_ask_about_a_project_outside_their_reach(): void
    {
        $this->actingAs($this->user('a@x.test', [(string) $this->a->id]));

        $this->open(['project_id' => (string) $this->b->id])->assertNotFound();

        // With no project named, a user confined to exactly one is asking about that one.
        $res = $this->open([])->assertCreated();
        $row = ExternalRequest::withoutGlobalScopes()->where('reference', $res->json('data.reference'))->firstOrFail();
        $this->assertSame((string) $this->a->id, (string) $row->project_id);
    }

    public function test_a_related_record_that_is_not_this_clients_is_refused(): void
    {
        $other = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->foreign->id, 'client_workspace_id' => $this->other->id, 'name' => 'Foreign campaign', 'objective' => 'sales', 'status' => 'active']);
        $this->actingAs($this->user('c@x.test'));

        $this->open(['related' => ['kind' => 'campaign', 'id' => (string) $other->id]])->assertNotFound();
        $this->open(['related' => ['kind' => 'invoice', 'id' => '99999']])->assertNotFound();
    }

    public function test_a_read_only_user_cannot_open_a_request(): void
    {
        $granter = User::create(['name' => 'Agency', 'email' => 'agency@x.test', 'password' => 'secret123']);
        $this->actingAs($this->user('v@x.test', null, $granter, 'client_viewer'));

        $this->open([])->assertForbidden();
    }

    public function test_the_agency_can_switch_new_requests_off_for_a_space(): void
    {
        $this->workspace->forceFill(['settings' => ['portal' => ['capabilities' => ['create_requests' => false]]]])->save();
        $this->actingAs($this->user('c@x.test'));

        $this->open([])->assertForbidden();
        $this->assertSame(0, ExternalRequest::withoutGlobalScopes()->count());
    }

    public function test_the_message_and_type_are_required(): void
    {
        $this->actingAs($this->user('c@x.test'));

        $this->postJson('/api/v1/client/requests', ['type' => 'consulting'], $this->spa)->assertStatus(422);
        $this->postJson('/api/v1/client/requests', ['type' => 'not-a-type', 'message' => 'Hello there friend'], $this->spa)->assertStatus(422);
    }
}
