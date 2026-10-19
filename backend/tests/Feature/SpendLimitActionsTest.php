<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Metrics\Models\SpendLimit;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * BUDGET-LIMITS-ACTIONS-001 — a limit offers an action only where the provider capability, the person's
 * permission and execution for that provider all allow it, and says which one is missing otherwise.
 */
final class SpendLimitActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private UnifiedCampaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = $this->member('o@a.test', $role);

        // Meta's app credentials, as an installation that can reach Meta has them (CampaignProviderWriteTest does the same).
        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }

        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'Spring', 'status' => 'active', 'objective' => 'sales']);

        foreach ([['meta', 'act_1', 'm_1', 'active'], ['linkedin', 'li_1', 'l_1', 'active'], ['google', 'g_1', 'g_c', 'paused']] as [$provider, $acct, $ext, $status]) {
            $account = $this->account($provider, $acct);
            ExternalCampaign::create([
                'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'unified_campaign_id' => $this->campaign->id,
                'external_account_id' => $account->id, 'provider' => $provider, 'external_id' => $ext, 'name' => "Spring {$provider}", 'status' => $status,
            ]);
        }

        SpendLimit::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'scope' => 'campaign', 'scope_id' => $this->campaign->id,
            'amount' => 100, 'currency' => 'SAR', 'starts_on' => Carbon::today()->startOfMonth(), 'ends_on' => Carbon::today()->endOfMonth(), 'active' => true,
        ]);
        SpendLimit::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'scope' => 'project', 'scope_id' => null,
            'amount' => 1000, 'currency' => 'SAR', 'starts_on' => Carbon::today()->startOfMonth(), 'ends_on' => Carbon::today()->endOfMonth(), 'active' => true,
        ]);
    }

    private function actions(?User $as = null): array
    {
        $rows = $this->actingAs($as ?? $this->owner)->getJson("/api/v1/projects/{$this->project->id}/spend-limits")->assertOk()->json('data');

        return collect($rows)->keyBy('scope')->map(fn (array $r): array => collect($r['actions'])->keyBy('provider')->all())->all();
    }

    public function test_a_campaign_limit_offers_a_pause_only_where_every_gate_opens(): void
    {
        $actions = $this->actions();

        $this->assertSame([], $actions['project'], 'a project limit names no single object to pause');
        $this->assertTrue($actions['campaign']['meta']['can_propose']);
        $this->assertSame('m_1', $actions['campaign']['meta']['target_external_id']);
        $this->assertFalse($actions['campaign']['linkedin']['can_propose']);
        $this->assertSame('not_implemented', $actions['campaign']['linkedin']['why_not'], 'LinkedIn has no write adapter');
        $this->assertFalse($actions['campaign']['google']['can_propose']);
        $this->assertSame('already_paused', $actions['campaign']['google']['why_not']);
    }

    public function test_a_provider_whose_execution_is_off_says_so(): void
    {
        config(['agents.execute_providers' => []]);

        $this->assertSame('execution_not_enabled_for_provider', $this->actions()['campaign']['meta']['why_not']);
    }

    public function test_a_person_without_the_pause_permission_is_told_why(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'B', 'slug' => 'b']);
        $role->givePermissionTo(...Permission::where('key', '!=', 'campaigns.pause')->pluck('key')->all());

        $this->assertSame('no_permission', $this->actions($this->member('b@a.test', $role))['campaign']['meta']['why_not']);
    }

    private function member(string $email, Role $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function account(string $provider, string $externalId): ExternalAccount
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->tenant->id, 'provider' => $provider, 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'credential_id' => $credential->id, 'provider' => $provider, 'connection_name' => $provider.$externalId, 'scope' => 'project_only', 'status' => 'connected']);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->id, 'provider' => $provider, 'account_type' => 'ad_account',
            'external_id' => $externalId, 'name' => $externalId, 'status' => 'active', 'currency' => 'SAR',
        ]);
        ProjectIntegrationBinding::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => $provider, 'purpose' => 'ads', 'is_active' => true]);

        return $account;
    }

    public function test_a_pause_asked_from_a_limit_is_a_pending_request_for_someone_else_to_approve(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'P', 'slug' => 'p']);
        $role->givePermissionTo(...Permission::whereNotIn('key', ['ai.use', 'ai.manage'])->pluck('key')->all());
        $pauser = $this->member('p@a.test', $role);
        $meta = $this->actions($pauser)['campaign']['meta'];

        $id = $this->actingAs($pauser)->postJson("/api/v1/projects/{$this->project->id}/agent-proposals", [
            'provider' => 'meta', 'external_account_id' => $meta['external_account_id'], 'target_type' => 'campaign',
            'target_external_id' => $meta['target_external_id'], 'action' => 'pause', 'reason' => 'Spend limit passed',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        // The person who asked cannot approve their own request.
        $this->actingAs($pauser)->postJson("/api/v1/projects/{$this->project->id}/agent-proposals/{$id}/approve")->assertForbidden();
    }
}
