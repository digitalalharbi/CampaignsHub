<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\CRM\Models\Opportunity;
use App\Domains\CRM\Models\Pipeline;
use App\Domains\CRM\Models\PipelineStage;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
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
 * SEC-1 — CRM leads, the follow-up dashboards, opportunities and lead owners trusted a request
 * project_id (or none) and spanned the whole tenant.
 */
final class Sec1CrmReachTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private Project $projectA;

    private Project $projectB;

    private ClientWorkspace $clientA;

    private ClientWorkspace $clientB;

    private Lead $leadA;

    private Lead $leadB;

    private Opportunity $oppA;

    private Opportunity $oppB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Crm Agency', 'slug' => 'crm-agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);

        $this->clientA = $this->client('Alpha');
        $this->clientB = $this->client('Bravo');
        $this->projectA = $this->project($this->clientA, 'A1');
        $this->projectB = $this->project($this->clientB, 'B1');
        $this->leadA = $this->lead($this->projectA, 'Alice Alpha');
        $this->leadB = $this->lead($this->projectB, 'Bob Bravo');

        $pipeline = Pipeline::create(['tenant_id' => $this->agency->id, 'name' => 'Default', 'is_default' => true]);
        $stage = PipelineStage::create(['tenant_id' => $this->agency->id, 'pipeline_id' => $pipeline->id, 'name' => 'New', 'sort' => 1, 'probability' => 10]);
        $this->oppA = $this->opportunity($pipeline, $stage, $this->leadA);
        $this->oppB = $this->opportunity($pipeline, $stage, $this->leadB);
    }

    private function client(string $name): ClientWorkspace
    {
        return ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => $name, 'slug' => str($name)->slug().'-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
    }

    private function project(ClientWorkspace $client, string $name): Project
    {
        return Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => $name, 'status' => 'active']);
    }

    private function lead(Project $project, string $name): Lead
    {
        return Lead::create(['tenant_id' => $this->agency->id, 'project_id' => $project->id, 'name' => $name, 'source' => 'manual', 'status' => 'new', 'received_at' => now()]);
    }

    private function opportunity(Pipeline $pipeline, PipelineStage $stage, Lead $lead): Opportunity
    {
        return Opportunity::create([
            'tenant_id' => $this->agency->id, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'lead_id' => $lead->id,
            'name' => $lead->name, 'amount' => 100, 'currency' => 'SAR', 'probability' => 10, 'status' => 'open',
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>|null  $clients
     */
    private function user(string $email, array $permissions, ?array $clients = null, ?Tenant $tenant = null): User
    {
        $tenant ??= $this->agency;
        $user = User::create(['name' => 'N '.$email, 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member', clientScopeIds: $clients,
        ));

        return $user;
    }

    /** @var list<string> */
    private const CRM = ['leads.view', 'leads.update', 'leads.assign', 'leads.create', 'leads.pii.view', 'projects.view', 'projects.view.all'];

    /** @return list<string> */
    private function leadNames(User $user, string $query = ''): array
    {
        $names = array_column($this->actingAs($user, 'sanctum')->getJson('/api/v1/leads'.$query)->assertOk()->json('data'), 'name');
        sort($names);

        return $names;
    }

    public function test_the_lead_list_is_confined_to_the_reachable_projects(): void
    {
        $manager = $this->user('mgr@crm.dev', self::CRM, [(string) $this->clientA->id]);

        $this->assertSame(['Alice Alpha'], $this->leadNames($manager));
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/leads?project_id='.$this->projectB->id)->assertForbidden();
        $this->assertSame(['Alice Alpha'], $this->leadNames($manager, '?project_id='.$this->projectA->id));
    }

    public function test_a_reader_with_no_project_reach_gets_no_leads_and_a_refusal_for_a_named_project(): void
    {
        $plain = $this->user('plain@crm.dev', ['leads.view', 'projects.view']);

        $this->assertSame([], $this->leadNames($plain));
        $this->actingAs($plain, 'sanctum')->getJson('/api/v1/leads?project_id='.$this->projectA->id)->assertForbidden();
    }

    public function test_a_project_member_reads_only_the_leads_of_a_current_membership(): void
    {
        $member = $this->user('sales@crm.dev', ['leads.view', 'projects.view']);
        ProjectMembership::create(['project_id' => $this->projectA->id, 'user_id' => $member->id, 'role' => ProjectRole::SALES_MANAGER, 'status' => 'active']);
        ProjectMembership::create(['project_id' => $this->projectB->id, 'user_id' => $member->id, 'role' => ProjectRole::SALES_MANAGER, 'status' => 'active', 'expires_at' => now()->subDay()]);

        $this->assertSame(['Alice Alpha'], $this->leadNames($member));
    }

    public function test_an_unrestricted_reader_still_sees_every_lead(): void
    {
        $owner = $this->user('owner@crm.dev', [...self::CRM, ClientScopeResolver::ALL_CLIENTS]);

        $this->assertSame(['Alice Alpha', 'Bob Bravo'], $this->leadNames($owner));
        $this->assertSame(['Bob Bravo'], $this->leadNames($owner, '?project_id='.$this->projectB->id));
    }

    public function test_the_follow_up_workspace_and_executive_dashboard_do_not_span_the_tenant(): void
    {
        $manager = $this->user('dash@crm.dev', self::CRM, [(string) $this->clientA->id]);
        $owner = $this->user('dashowner@crm.dev', [...self::CRM, ClientScopeResolver::ALL_CLIENTS]);

        foreach (['workspace', 'executive'] as $route) {
            $this->actingAs($manager, 'sanctum')->getJson("/api/v1/leads/{$route}?project_id=".$this->projectB->id)->assertForbidden();
        }

        $this->assertSame(1, $this->actingAs($manager, 'sanctum')->getJson('/api/v1/leads/workspace')->assertOk()->json('data.summary.received'));
        $this->assertSame(2, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/leads/workspace')->assertOk()->json('data.summary.received'));
        $this->actingAs($manager, 'sanctum')->getJson('/api/v1/leads/executive')->assertOk();
    }

    public function test_opportunities_follow_the_project_of_their_lead(): void
    {
        $manager = $this->user('opp@crm.dev', self::CRM, [(string) $this->clientA->id]);

        $ids = array_column($this->actingAs($manager, 'sanctum')->getJson('/api/v1/opportunities')->assertOk()->json('data'), 'id');
        $this->assertSame([(string) $this->oppA->id], $ids);

        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/opportunities/{$this->oppA->id}")->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson("/api/v1/opportunities/{$this->oppB->id}")->assertForbidden();

        $stage = PipelineStage::query()->firstOrFail();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/opportunities/{$this->oppB->id}/stage", ['stage_id' => $stage->id])->assertForbidden();
        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/opportunities/{$this->oppA->id}/stage", ['stage_id' => $stage->id])->assertOk();
    }

    public function test_an_unrestricted_reader_still_lists_every_opportunity(): void
    {
        $owner = $this->user('oppowner@crm.dev', [...self::CRM, ClientScopeResolver::ALL_CLIENTS]);

        $this->assertCount(2, $this->actingAs($owner, 'sanctum')->getJson('/api/v1/opportunities')->assertOk()->json('data'));
    }

    public function test_an_opportunity_named_after_a_lead_hides_the_name_from_a_reader_without_identity_access(): void
    {
        $viewer = $this->user('noid@crm.dev', ['leads.view', 'projects.view', 'projects.view.all']);

        $row = $this->actingAs($viewer, 'sanctum')->getJson("/api/v1/opportunities/{$this->oppA->id}")->assertOk()->json('data');
        $this->assertStringNotContainsString('Alice', (string) $row['name']);
    }

    public function test_a_lead_owner_must_be_an_active_member_of_this_tenant_who_can_work_the_project(): void
    {
        $manager = $this->user('assign@crm.dev', self::CRM, [(string) $this->clientA->id]);
        $colleague = $this->user('colleague@crm.dev', self::CRM, [(string) $this->clientA->id]);

        $rival = Tenant::create(['name' => 'Rival', 'slug' => 'rival', 'status' => 'active', 'account_type' => 'agency']);
        $stranger = $this->user('stranger@rival.dev', self::CRM, null, $rival);

        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/leads/{$this->leadA->id}/assign", ['owner_id' => $stranger->id])->assertStatus(422);
        $this->actingAs($manager, 'sanctum')->postJson('/api/v1/leads', ['name' => 'N', 'source' => 'manual', 'owner_id' => $stranger->id])->assertStatus(422);
        $this->actingAs($manager, 'sanctum')->putJson("/api/v1/leads/{$this->leadA->id}", ['owner_id' => $stranger->id])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')->postJson("/api/v1/leads/{$this->leadA->id}/assign", ['owner_id' => $colleague->id])->assertOk();
    }
}
