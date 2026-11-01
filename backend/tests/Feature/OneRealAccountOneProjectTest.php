<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\CRM\Models\Lead;
use App\Domains\Integrations\Leads\IngestProviderLeads;
use App\Domains\Integrations\Leads\ProviderLead;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * SEC-CLUSTER-2 — one REAL provider account feeds at most one project, however many rows represent it.
 *
 * The same advertiser authorised under two connections is two `external_accounts` rows (the unique key
 * contains the connection). The «already connected elsewhere» refusal compared row ids, so the second
 * row bound to a second project and one advertiser's spend reported into two projects.
 *
 * The rule chosen: refuse (409) an active binding of any row that represents a provider account
 * (tenant, provider, external_id, account_type) already actively bound to a DIFFERENT project. No
 * explicit-sharing mode exists, so none is offered.
 */
final class OneRealAccountOneProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $rowA;

    private ExternalAccount $twinRow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);

        // Tenant-level connections (no client workspace), the case where mayAssign() lets any project in.
        $this->projectA = $this->project('Alpha');
        $this->projectB = $this->project('Beta');
        $this->rowA = $this->account($this->tenant, 'act_123');
        $this->twinRow = $this->account($this->tenant, 'act_123');

        $this->bindDirect($this->rowA, $this->projectA);
        app(TenantContext::class)->forget();
    }

    public function test_the_same_provider_account_cannot_be_bound_to_a_second_project_through_a_twin_row(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
                'external_account_id' => $this->twinRow->id, 'purpose' => 'advertising',
            ])
            ->assertStatus(409);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->count());
    }

    public function test_the_batch_confirm_refuses_a_twin_row_and_binds_nothing(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings/batch", [
                'connection_id' => $this->twinRow->provider_connection_id,
                'external_account_ids' => [$this->twinRow->id],
                'purpose' => 'advertising',
            ])
            ->assertStatus(409);

        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->count());
    }

    public function test_after_detaching_it_the_account_can_move_to_the_other_project(): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->update(['is_active' => false]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
                'external_account_id' => $this->twinRow->id, 'purpose' => 'advertising',
            ])
            ->assertStatus(201);
    }

    public function test_a_different_account_or_another_tenants_identical_id_is_not_a_conflict(): void
    {
        $other = $this->account($this->tenant, 'act_other');
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", ['external_account_id' => $other->id, 'purpose' => 'advertising'])
            ->assertStatus(201);

        $this->assertNull(
            app(AccountAssignment::class)->activeBindingElsewhere(
                $this->foreignTenantTwin(), (string) $this->projectB->id,
            ),
            "another company's account with the same provider id was treated as the same account",
        );
    }

    public function test_a_lead_from_an_account_held_by_two_projects_is_left_unfiled_not_guessed(): void
    {
        // A double that predates the rule: both rows actively bound, to different projects.
        $this->bindDirect($this->twinRow, $this->projectB);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [new ProviderLead(
            provider: 'meta', providerLeadId: 'l-1', providerCreatedAt: Carbon::now(), name: 'Buyer',
            email: 'b@example.test', phone: null, externalAccountId: 'act_123',
        )]);

        $this->assertNull(Lead::withoutGlobalScopes()->first()->project_id);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function project(string $name): Project
    {
        $ws = ClientWorkspace::create(['name' => $name, 'slug' => strtolower($name), 'mode' => 'managed']);

        return Project::create(['client_workspace_id' => $ws->id, 'name' => $name, 'status' => 'active']);
    }

    private function account(Tenant $tenant, string $externalId): ExternalAccount
    {
        $credential = new IntegrationCredential(['tenant_id' => $tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'c-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
        ]);
    }

    private function foreignTenantTwin(): ExternalAccount
    {
        $tenant = Tenant::create(['name' => 'Other', 'slug' => 'other', 'status' => 'active']);

        return $this->account($tenant, 'act_123');
    }

    private function bindDirect(ExternalAccount $account, Project $project): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);
    }
}
