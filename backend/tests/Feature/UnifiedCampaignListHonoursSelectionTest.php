<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** ACCOUNT-SCOPE-ISOLATION-001 — the campaign list names only what selected accounts delivered. */
final class UnifiedCampaignListHonoursSelectionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Project $project;

    private Tenant $tenant;

    private ClientWorkspace $workspace;

    private ExternalAccount $brandAccount;

    private ExternalAccount $retailAccount;

    private ExternalAccount $deselectedAccount;

    private ExternalAccount $unbound;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ap-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'ap-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        $this->workspace = ClientWorkspace::create(['name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create([
            'client_workspace_id' => $this->workspace->id, 'name' => 'P', 'status' => 'active',
        ]);

        /*
         * TWO ad accounts on ONE provider — the case the platform filter cannot separate, and the
         * reason this test exists. A third is bound and then DESELECTED.
         */
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id, provider: 'meta',
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'meta',
        );

        $this->brandAccount = $this->account($connection->getKey(), 'Brand — Meta', 'meta-brand');
        $this->retailAccount = $this->account($connection->getKey(), 'Retail — Meta', 'meta-retail');
        $this->deselectedAccount = $this->account($connection->getKey(), 'Old agency — Meta', 'meta-old');

        $this->unbound = $this->account($connection->getKey(), 'Elsewhere — Meta', 'meta-elsewhere');
        $other = Project::create(['client_workspace_id' => $this->workspace->id, 'name' => 'Other', 'status' => 'active']);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'project_id' => $other->id, 'external_account_id' => $this->unbound->getKey(),
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);
        $this->bind($this->brandAccount, true);
        $this->bind($this->retailAccount, true);
        $this->bind($this->deselectedAccount, false);

        app(TenantContext::class)->forget();
    }

    public function test_list_and_show_honour_account_selection(): void
    {
        $a = $this->campaignOn('Active one', [$this->brandAccount]);
        $b = $this->campaignOn('Deselected one', [$this->deselectedAccount]);
        $c = $this->campaignOn('Bound elsewhere', [$this->unbound]);
        $d = $this->campaignOn('No links', []);

        $this->assertEqualsCanonicalizing([(string) $a->id, (string) $d->id], $this->listedIds());
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$b->id}")->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$c->id}")->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$a->id}")->assertOk();

        ProjectIntegrationBinding::withoutGlobalScopes()->where('external_account_id', $this->deselectedAccount->getKey())->update(['is_active' => true]);

        $this->assertContains((string) $b->id, $this->listedIds());
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}/campaigns/{$b->id}")->assertOk();
    }

    /** @return list<string> */
    private function listedIds(): array
    {
        return array_map('strval', array_column($this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/campaigns?per_page=100")->assertOk()->json('data'), 'id'));
    }

    /** @param  list<ExternalAccount>  $accounts */
    private function campaignOn(string $name, array $accounts): UnifiedCampaign
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'name' => $name, 'status' => 'active', 'objective' => 'sales', 'platforms' => ['meta'],
        ]);

        foreach ($accounts as $account) {
            ExternalCampaign::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
                'external_account_id' => $account->getKey(),
                'unified_campaign_id' => $campaign->id,
                'provider' => 'meta', 'external_id' => 'e-'.uniqid(), 'name' => $name, 'status' => 'active',
            ]);
        }

        app(TenantContext::class)->forget();

        return $campaign;
    }

    private function account(string $connectionId, string $name, string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connectionId,
            'provider' => 'meta', 'account_type' => 'ad_account',
            'external_id' => $externalId.'-'.uniqid(), 'name' => $name,
            'currency' => 'SAR', 'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id, 'external_account_id' => $account->getKey(),
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active,
        ]);
    }
}
