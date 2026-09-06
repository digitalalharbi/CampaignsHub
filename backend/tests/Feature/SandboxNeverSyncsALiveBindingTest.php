<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SANDBOX-PROD-001 — the WRITE path. A live binding must never run the sandbox connector.
 *
 * ## What production showed
 *
 * `integrations:diagnose` on the live estate reported TWO sandbox campaigns under the bound Snapchat
 * account, and TWO more under the bound Meta account — `sbx-cmp-1` and `sbx-cmp-2`, the pair
 * `SandboxAdvertisingConnector` seeds. On Meta they were the ONLY campaigns the product held, so a
 * real, authorised, correctly-bound Meta account read as «2 campaigns discovered» while its own
 * structure sweep returned `no_data records=0`.
 *
 * ## Where they came from
 *
 * `ProjectIntegrationController::sync()` — the «Sync now» button on a binding — called
 * `(new SandboxAdvertisingConnector)->syncCampaigns(...)` UNCONDITIONALLY and imported the result
 * under `$model->externalAccount`. Whatever the binding was. So pressing sync on a real Snapchat or
 * Meta binding wrote sandbox campaigns into that live account, and every later metrics sweep then
 * asked the live provider about them: 48 sweeps a day producing refusals the provider was right to
 * give.
 *
 * #292 stopped those outbound calls. That is containment. THIS is the cause: the rows must never be
 * written in the first place, and a demo connector must never be reachable from a real provider's
 * binding.
 *
 * ## Why fail-closed rather than a provider allow-list
 *
 * The connector is resolved from the account's own provider through the canonical registry, and a
 * provider the registry cannot serve returns a recorded failure rather than falling back to
 * anything. A fallback is how this defect existed: the sandbox was the only connector this endpoint
 * knew, so every account got it.
 */
final class SandboxNeverSyncsALiveBindingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $ws = ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->project = Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner-sbx']);
        // Every permission: this file is about the CONNECTOR the endpoint chooses, not about who may press it.
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->user = User::create(['name' => 'Ops', 'email' => 'ops-'.uniqid().'@sbx.test', 'password' => 'secret123']);
        Membership::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'portal' => 'agency', 'status' => 'active',
        ]);
        $this->user->assignRole($role);
        $this->user = $this->user->fresh();
    }

    /**
     * A REAL Meta binding syncs through the Meta connector, and writes no sandbox campaign.
     *
     * Asserted on the STORED ROWS rather than on the HTTP call, because that is the damage: an
     * outbound request can be filtered later (#292 does exactly that), and a row written into a live
     * account survives every filter and is still there on the next sweep.
     */
    public function test_a_live_meta_binding_never_writes_a_sandbox_campaign(): void
    {
        // No credentials configured, so Meta's own connector cannot reach anywhere: the point is that
        // the sync FAILS honestly rather than succeeding with somebody else's data.
        Http::fake(['*' => Http::response([], 500)]);

        $binding = $this->binding('meta', 'act_1234567890');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/{$binding->id}/sync")
            ->assertOk();

        $this->assertSame(
            0,
            ExternalCampaign::withoutGlobalScopes()->whereJsonContains('raw->sandbox', true)->count(),
            'A live Meta binding wrote sandbox campaigns into a real account.',
        );

        // ...and specifically not the two the sandbox seeds.
        $this->assertSame(
            0,
            ExternalCampaign::withoutGlobalScopes()->whereIn('external_id', ['sbx-cmp-1', 'sbx-cmp-2'])->count(),
        );
    }

    /** The same rule on the provider that is currently feeding the product. */
    public function test_a_live_snapchat_binding_never_writes_a_sandbox_campaign(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        $binding = $this->binding('snapchat', 'act-1');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/{$binding->id}/sync")
            ->assertOk();

        $this->assertSame(0, ExternalCampaign::withoutGlobalScopes()->whereJsonContains('raw->sandbox', true)->count());
    }

    /**
     * And the sandbox still works where it is meant to.
     *
     * Removing the fallback must not remove the demo: a sandbox BINDING is the context the connector
     * exists for, and it keeps writing its two campaigns there.
     */
    public function test_a_sandbox_binding_still_syncs_its_own_campaigns(): void
    {
        $binding = $this->binding('sandbox', 'sbx-act-1');

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/integrations/bindings/{$binding->id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertSame(
            2,
            ExternalCampaign::withoutGlobalScopes()->whereIn('external_id', ['sbx-cmp-1', 'sbx-cmp-2'])->count(),
            'The sandbox binding must still produce the demo estate it exists to produce.',
        );
    }

    private function binding(string $provider, string $externalId): ProjectIntegrationBinding
    {
        $credential = IntegrationCredential::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider' => $provider,
            'credential_scope' => 'tenant', 'credential_type' => 'oauth',
            'encrypted_payload' => json_encode(['access_token' => 'tok']), 'status' => 'active',
        ]);

        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider,
            'connection_name' => $provider.' — '.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);

        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id,
            'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => 'An account', 'status' => 'active',
        ]);

        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_account_id' => $account->id, 'provider' => $provider,
            'purpose' => 'advertising', 'is_active' => true,
        ]);
    }
}
