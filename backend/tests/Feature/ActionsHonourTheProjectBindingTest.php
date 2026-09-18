<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\Integration;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Metrics\Jobs\SyncAccountMetricsJob;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ACCOUNT-SCOPE-ISOLATION-001 — an action taken from a project acts only for that project's selection.
 *
 * The jobs re-prove «actively assigned somewhere», because a job has no project of its own. The
 * buttons that queue them do, and asking only «somewhere» let project A fetch for project B's account.
 */
final class ActionsHonourTheProjectBindingTest extends TestCase
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
        ]);

        app(TenantContext::class)->forget();
    }

    public function test_a_manual_sync_from_one_project_refuses_an_account_selected_for_another(): void
    {
        Queue::fake();
        $this->bind($this->projectB);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/sync-runs", ['external_account_id' => $this->account->id])
            ->assertNotFound();

        Queue::assertNotPushed(SyncAccountMetricsJob::class);
    }

    public function test_a_manual_sync_queues_for_an_account_selected_for_this_project(): void
    {
        Queue::fake();
        $this->bind($this->projectA);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/sync-runs", ['external_account_id' => $this->account->id])
            ->assertSuccessful();

        Queue::assertPushed(SyncAccountMetricsJob::class, 1);
    }

    public function test_the_legacy_connector_sync_calls_no_provider_for_an_unselected_account(): void
    {
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }
        Http::fake();

        app(TenantContext::class)->setTenantId($this->tenant->id);
        Integration::create(['connector_key' => 'meta', 'status' => 'connected', 'ad_account_id' => 'act_9']);
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/integrations/meta/sync')->assertStatus(409);

        Http::assertNothingSent();
    }

    public function test_a_campaign_of_a_deselected_account_cannot_be_linked_and_its_creatives_cannot_be_grouped(): void
    {
        $this->bind($this->projectA, active: false);

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $unified = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'name' => 'U', 'objective' => 'sales', 'status' => 'active']);
        $external = ExternalCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'external_id' => 'c-1', 'name' => 'Ext', 'status' => 'active',
        ]);
        $creatives = collect([1, 2])->map(fn (int $i) => ExternalCreative::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->projectA->id, 'provider' => 'meta',
            'external_campaign_id' => $external->id, 'external_creative_id' => "cr-{$i}", 'name' => "C{$i}",
        ]));
        app(TenantContext::class)->forget();

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$unified->id}/external", ['external_campaign_id' => $external->id])
            ->assertNotFound();
        $this->assertNull($external->fresh()->unified_campaign_id);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/creatives/group", ['creative_ids' => $creatives->pluck('id')->all()])
            ->assertStatus(422);
        $this->assertSame(0, ExternalCreative::withoutGlobalScopes()->whereNotNull('creative_group_id')->count());

        // Re-selected, both actions work again: the rows were hidden, never taken away.
        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->account->id)->update(['is_active' => true]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/campaigns/{$unified->id}/external", ['external_campaign_id' => $external->id])
            ->assertCreated();
        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/creatives/group", ['creative_ids' => $creatives->pluck('id')->all()])
            ->assertSuccessful();
    }

    private function bind(Project $project, bool $active = true): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $this->account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }
}
