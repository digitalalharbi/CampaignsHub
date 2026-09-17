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
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — leads follow the selection like every figure does.
 *
 * A lead from an account that is deselected, or selected for another project, is neither listed nor
 * actionable in the project (404). It is never deleted, and it comes back when the account is
 * selected again. Provider leads are filed to the project the account is actively selected for.
 */
final class LeadsHonourTheBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Project $projectA;

    private Project $projectB;

    private ExternalAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->user, $this->tenant);
        $this->user->assignRole($role);

        $ws = ClientWorkspace::create(['name' => 'Client', 'slug' => 'client-'.uniqid(), 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);

        $credential = new IntegrationCredential(['provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('t');
        $credential->save();
        $connection = ProviderConnection::create(['credential_id' => $credential->id, 'provider' => 'meta', 'connection_name' => 'm', 'scope' => 'project_only', 'status' => 'connected']);
        $this->account = ExternalAccount::create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => 'act_9', 'name' => 'A', 'status' => 'active',
            // The discovery-time column the ingester used to trust — pointing at the project the
            // account is NOT selected for.
            'project_id' => $this->projectA->id,
        ]);

        app(TenantContext::class)->forget();
    }

    public function test_a_provider_lead_is_filed_to_the_project_its_account_is_selected_for(): void
    {
        $this->binding($this->projectA, active: false);
        $this->binding($this->projectB, active: true);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [$this->providerLead('lead-1')]);

        $lead = Lead::withoutGlobalScopes()->firstOrFail();
        $this->assertSame((string) $this->projectB->id, (string) $lead->project_id, 'the lead was filed where the account once pointed');
        $this->assertSame((string) $this->account->id, (string) $lead->external_account_id, 'the lead stores the provider id instead of our account');
    }

    public function test_a_provider_lead_from_an_account_selected_nowhere_waits_unfiled(): void
    {
        $this->binding($this->projectA, active: false);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        app(IngestProviderLeads::class)->handle((string) $this->tenant->id, [$this->providerLead('lead-2')]);

        $this->assertNull(Lead::withoutGlobalScopes()->firstOrFail()->project_id);
    }

    public function test_a_lead_from_a_deselected_account_is_neither_listed_nor_actionable_and_returns_on_reselection(): void
    {
        $binding = $this->binding($this->projectA, active: true);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $fromAccount = Lead::create([
            'project_id' => $this->projectA->id, 'name' => 'From ad', 'source' => 'paid', 'status' => 'new',
            'provider' => 'meta', 'external_account_id' => $this->account->id,
        ]);
        $typedIn = Lead::create(['project_id' => $this->projectA->id, 'name' => 'Walk-in', 'source' => 'manual', 'status' => 'new']);
        app(TenantContext::class)->forget();

        $binding->update(['is_active' => false]);

        $ids = collect($this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/leads?project_id={$this->projectA->id}")->assertOk()->json('data'))->pluck('id');
        $this->assertNotContains($fromAccount->id, $ids, 'a deselected account\'s lead is still listed');
        $this->assertContains($typedIn->id, $ids, 'a lead with no account was hidden');

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/leads/{$fromAccount->id}")->assertNotFound();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/leads/{$fromAccount->id}/assign", ['owner_id' => $this->user->id])->assertNotFound();
        $this->actingAs($this->user, 'sanctum')->deleteJson("/api/v1/leads/{$fromAccount->id}")->assertNotFound();
        $this->assertNotNull(Lead::withoutGlobalScopes()->find($fromAccount->id), 'a hidden lead was deleted');

        $binding->update(['is_active' => true]);

        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/leads/{$fromAccount->id}")->assertOk();
    }

    private function binding(Project $project, bool $active): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }

    private function providerLead(string $id): ProviderLead
    {
        return new ProviderLead(
            provider: 'meta', providerLeadId: $id, providerCreatedAt: Carbon::now(),
            name: 'Buyer', email: 'buyer-'.$id.'@example.test', phone: '0501234567',
            externalAccountId: 'act_9',
        );
    }
}
