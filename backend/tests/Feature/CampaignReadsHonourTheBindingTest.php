<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalAdSet;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * SEC-CLUSTER-2 — a project lists the platform campaigns of the accounts it is bound to NOW.
 *
 * `external_campaigns` rows keep their project after an account is detached (history is never moved),
 * so a reader that narrows by project alone keeps showing the detached account's campaign names,
 * budgets, ad sets, targeting and ads. The figure readers already ask `BoundAccountVisibility`; the
 * structure readers did not.
 */
final class CampaignReadsHonourTheBindingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Project $project;

    private UnifiedCampaign $unified;

    private ExternalCampaign $kept;

    private ExternalCampaign $detached;

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

        $ws = ClientWorkspace::create(['name' => 'Alpha', 'slug' => 'alpha', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'Alpha', 'status' => 'active']);
        $this->unified = UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'client_workspace_id' => $ws->id,
            'name' => 'Launch', 'status' => 'active',
        ]);

        $connection = $this->connection();
        $keptAccount = $this->account($connection, 'act-kept');
        $detachedAccount = $this->account($connection, 'act-detached');
        $this->bind($keptAccount, true);
        $this->bind($detachedAccount, false);

        $this->kept = $this->campaign($keptAccount, 'cmp-kept');
        $this->detached = $this->campaign($detachedAccount, 'cmp-detached');

        $set = ExternalAdSet::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_campaign_id' => $this->detached->id,
            'provider' => 'meta', 'external_id' => 'set-detached', 'name' => 'Detached set', 'status' => 'active',
        ]);
        ExternalAd::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_ad_set_id' => $set->id,
            'external_campaign_id' => $this->detached->id, 'provider' => 'meta', 'external_id' => 'ad-detached', 'name' => 'Detached ad', 'status' => 'active',
        ]);

        app(TenantContext::class)->forget();
    }

    public function test_the_campaign_list_hides_a_detached_accounts_campaigns(): void
    {
        $names = $this->readJson('/external-campaigns')->json('data.*.name');

        $this->assertContains('cmp-kept', $names);
        $this->assertNotContains('cmp-detached', $names, 'a detached account\'s campaign is still listed');
    }

    public function test_the_linked_campaigns_of_a_unified_campaign_hide_a_detached_account(): void
    {
        $this->link();

        $names = $this->readJson("/campaigns/{$this->unified->id}/external")->json('data.*.name');

        $this->assertContains('cmp-kept', $names);
        $this->assertNotContains('cmp-detached', $names);
    }

    public function test_link_suggestions_hide_a_detached_account(): void
    {
        $names = $this->readJson("/campaigns/{$this->unified->id}/suggestions")->json('data.*.name');

        $this->assertNotContains('cmp-detached', $names);
    }

    public function test_the_structure_tab_shows_no_ad_sets_or_ads_of_a_detached_account(): void
    {
        $this->link();

        $body = json_encode($this->readJson("/campaigns/{$this->unified->id}/structure")->json('data'));

        $this->assertStringNotContainsString('Detached set', (string) $body);
        $this->assertStringNotContainsString('Detached ad', (string) $body);
        $this->assertStringNotContainsString('cmp-detached', (string) $body);
    }

    public function test_the_related_entities_do_not_name_a_detached_account(): void
    {
        $this->link();

        $body = (string) json_encode($this->readJson("/campaigns/{$this->unified->id}/related")->json('data'));

        $this->assertStringNotContainsString('act-detached', $body);
        $this->assertStringNotContainsString('Detached set', $body);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function readJson(string $path): TestResponse
    {
        $res = $this->actingAs($this->user, 'sanctum')->getJson("/api/v1/projects/{$this->project->id}{$path}");
        $res->assertOk();

        return $res;
    }

    private function link(): void
    {
        ExternalCampaign::withoutGlobalScopes()->whereIn('id', [$this->kept->id, $this->detached->id])->update(['unified_campaign_id' => $this->unified->id]);
    }

    private function connection(): ProviderConnection
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();

        return ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => 'c-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
    }

    private function account(ProviderConnection $connection, string $externalId): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
        ]);
    }

    private function bind(ExternalAccount $account, bool $active): void
    {
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => $active, 'campaign_management_enabled' => true,
        ]);
    }

    private function campaign(ExternalAccount $account, string $name): ExternalCampaign
    {
        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'external_id' => $name, 'name' => $name, 'status' => 'active',
        ]);
    }
}
