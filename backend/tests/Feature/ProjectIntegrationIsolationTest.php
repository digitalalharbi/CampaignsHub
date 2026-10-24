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
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CP-ISOLATION-001 / PROJECTS-RBAC-CLOSURE-001 — a binding belongs to ONE project, and no other project's route can touch it.
 *
 * A person who may manage integrations in project A names a binding id that lives in project B (another client's) through
 * A's own URL. The route must answer «not found» and leave B's binding exactly as it was; an account of B's client must not
 * be bindable into A, singly or inside a batch (a batch is one decision: one refused account refuses all).
 */
final class ProjectIntegrationIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Project $alpha;

    private Project $beta;

    private ProjectIntegrationBinding $betaBinding;

    private ExternalAccount $betaAccount;

    private ExternalAccount $alphaAccount;

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

        $wsA = $this->workspace('Alpha');
        $wsB = $this->workspace('Beta');
        $this->alpha = Project::create(['client_workspace_id' => $wsA->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->beta = Project::create(['client_workspace_id' => $wsB->id, 'name' => 'Beta', 'status' => 'active']);
        $this->alphaAccount = $this->account('act-alpha', $wsA);
        $this->betaAccount = $this->account('act-beta', $wsB);
        $this->betaBinding = ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $wsB->id, 'project_id' => $this->beta->id,
            'external_account_id' => $this->betaAccount->id, 'provider' => 'sandbox', 'purpose' => 'advertising',
            'is_active' => true, 'sync_enabled' => true, 'campaign_management_enabled' => true,
        ]);

        app(TenantContext::class)->forget();
    }

    private function workspace(string $name): ClientWorkspace
    {
        return ClientWorkspace::create(['name' => $name, 'slug' => strtolower($name), 'mode' => 'managed']);
    }

    private function account(string $externalId, ClientWorkspace $ws): ExternalAccount
    {
        $credential = new IntegrationCredential(['provider' => 'sandbox', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'sandbox',
            'connection_name' => $externalId, 'scope' => 'project_only', 'status' => 'connected',
        ]);

        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'provider_connection_id' => $connection->id,
            'provider' => 'sandbox', 'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
        ]);
    }

    private function betaBindingState(): array
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->whereKey($this->betaBinding->id)
            ->first(['project_id', 'external_account_id', 'is_active', 'is_primary', 'sync_enabled', 'updated_at'])->toArray();
    }

    public function test_another_projects_binding_cannot_be_detached_or_synced_through_my_project(): void
    {
        $before = $this->betaBindingState();

        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->alpha->id}/integrations/bindings/{$this->betaBinding->id}")
            ->assertNotFound();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->alpha->id}/integrations/bindings/{$this->betaBinding->id}/sync")
            ->assertNotFound();

        $this->assertSame($before, $this->betaBindingState(), 'Beta\'s binding must be byte-identical after both attempts.');
        $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$this->alpha->id}/integrations")->assertJsonCount(0, 'data');
    }

    public function test_the_owning_project_can_still_detach_its_own_binding(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/projects/{$this->beta->id}/integrations/bindings/{$this->betaBinding->id}")
            ->assertOk();

        $this->assertFalse((bool) ProjectIntegrationBinding::withoutGlobalScopes()->find($this->betaBinding->id)->is_active);
    }

    public function test_another_clients_account_cannot_be_bound_alone_or_inside_a_batch_and_nothing_partial_is_left(): void
    {
        $single = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->alpha->id}/integrations/bindings", ['external_account_id' => $this->betaAccount->id, 'purpose' => 'advertising']);
        $this->assertContains($single->status(), [403, 404, 409, 422], 'another client\'s account is refused, whatever the refusal code');

        $before = ProjectIntegrationBinding::withoutGlobalScopes()->count();
        $res = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->alpha->id}/integrations/bindings/batch", [
                'accounts' => [
                    ['external_account_id' => $this->alphaAccount->id, 'purpose' => 'advertising'],
                    ['external_account_id' => $this->betaAccount->id, 'purpose' => 'advertising'],
                ],
            ]);

        $this->assertGreaterThanOrEqual(400, $res->status(), 'a batch containing another client\'s account is refused');
        $this->assertSame($before, ProjectIntegrationBinding::withoutGlobalScopes()->count(), 'no binding — not even Alpha\'s own account — was created by the refused batch');
        $this->assertSame($this->beta->id, ProjectIntegrationBinding::withoutGlobalScopes()->find($this->betaBinding->id)->project_id);
    }

    public function test_binding_an_unowned_account_does_not_move_another_clients_binding(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->alpha->id}/integrations/bindings", ['external_account_id' => $this->alphaAccount->id, 'purpose' => 'advertising'])
            ->assertCreated();

        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->alpha->id)->count());
        $this->assertSame(1, ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->beta->id)->where('is_active', true)->count());
    }
}
