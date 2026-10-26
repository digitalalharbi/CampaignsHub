<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * INTEGRATION-UNLINK-001 — an ad account linked to the wrong client can be taken off it, by the account, after it has synced.
 *
 * The Owner linked Nasl Real Estate's Snapchat account («Nasel») to Razzah Avenue by mistake and found no way back. Removing it is the
 * binding's deactivation — never a delete — so the history stays, the sweep stops filing under the project, the account's figures leave
 * THAT project's totals, every other project and the provider authorisation are untouched, and linking it again restores it.
 */
final class UnlinkAccountFromProjectTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $razzah;

    private Project $other;

    private ExternalAccount $nasel;

    private ExternalAccount $razzahOwn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ul-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'ul-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->razzah = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'razzahavenu', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'henka', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'snapchat', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'snap');
        $make = fn (string $name): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => $name.'-ad', 'name' => $name, 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->nasel = $make('Nasel');
        $this->razzahOwn = $make('RazzahAvenu Self Service');
        foreach ([$this->nasel, $this->razzahOwn] as $account) {
            $this->bind($this->razzah, $account);
            $this->spend($this->razzah, $account, 100.0);
        }
        app(TenantContext::class)->forget();
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => true,
        ]);
    }

    private function spend(Project $project, ExternalAccount $account, float $value): void
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $account->name, 'status' => 'active', 'objective' => 'sales']);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'snapchat', 'metric_key' => 'spend',
            'metric_date' => '2026-10-20', 'value' => $value,
        ]);
    }

    private function unlink(Project $project, ExternalAccount $account, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson("/api/v1/projects/{$project->id}/integrations/accounts/{$account->id}/unlink");
    }

    private function projectSpend(): float
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return (float) (app(MetricsAggregator::class)->forProjects([(string) $this->razzah->id])->totals(Carbon::parse('2026-10-18'), Carbon::parse('2026-10-24'))['spend'] ?? 0);
    }

    public function test_removing_the_wrong_account_takes_its_figures_out_of_the_project_keeps_its_history_and_leaves_the_other_account(): void
    {
        $this->assertEquals(200.0, $this->projectSpend(), 'the mistaken account feeds the project before');

        $res = $this->unlink($this->razzah, $this->nasel)->assertOk();

        $this->assertEquals(1, $res->json('data.history_rows_kept'));
        $this->assertEquals(100.0, $this->projectSpend(), 'only the project’s own account remains in its totals');
        $this->assertSame(2, DailyMetric::withoutGlobalScopes()->where('project_id', $this->razzah->id)->count(), 'nothing was deleted');

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->get()->keyBy('external_account_id');
        $this->assertFalse((bool) $bindings[$this->nasel->id]->is_active);
        $this->assertFalse((bool) $bindings[$this->nasel->id]->is_primary);
        $this->assertTrue((bool) $bindings[$this->razzahOwn->id]->is_active);

        // The sweep reads active bindings only: the removed account is no longer filed under any project.
        $this->assertNull(app(AccountAssignment::class)->projectIdFor($this->nasel->fresh()));
        $this->assertSame((string) $this->razzah->id, app(AccountAssignment::class)->projectIdFor($this->razzahOwn->fresh()));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'integration.account.unlinked')->where('entity_id', (string) $this->nasel->id)->exists());
    }

    public function test_linking_it_again_brings_the_history_back(): void
    {
        $this->unlink($this->razzah, $this->nasel)->assertOk();
        ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->where('external_account_id', $this->nasel->id)->update(['is_active' => true]);

        $this->assertEquals(200.0, $this->projectSpend());
    }

    public function test_it_only_touches_this_project_and_is_idempotent_as_a_refusal(): void
    {
        $this->bind($this->other, $this->nasel);
        app(TenantContext::class)->forget();

        $this->unlink($this->razzah, $this->nasel)->assertOk();

        $this->assertTrue(ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->other->id)->where('external_account_id', $this->nasel->id)->value('is_active'), 'another project keeps the account');
        $this->unlink($this->razzah, $this->nasel)->assertNotFound();
    }

    public function test_a_stranger_or_an_unauthorised_member_cannot_unlink(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant, Portal::Agency);
        $this->unlink($this->razzah, $this->nasel, $viewer)->assertForbidden();

        $foreign = Tenant::create(['name' => 'B', 'slug' => 'fb-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($foreign->id);
        $role = Role::create(['tenant_id' => $foreign->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $spy = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@x.test', 'password' => 'secret123']);
        $this->grantMembership($spy, $foreign, Portal::Agency);
        $spy->assignRole($role);
        app(TenantContext::class)->forget();

        $this->assertContains($this->unlink($this->razzah, $this->nasel, $spy)->status(), [403, 404]);
        $this->assertTrue((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->where('external_account_id', $this->nasel->id)->value('is_active'));
    }

    /** INTEGRATION-ESTATE-001 — the project-first view lists what feeds each project, and a removed account leaves it. */
    public function test_the_estate_lists_each_project_with_its_feeders_and_a_removed_account_leaves_it(): void
    {
        $this->bind($this->other, $this->razzahOwn);
        $this->razzahOwn->forceFill(['last_synced_at' => Carbon::parse('2026-10-25 10:00:00')])->save();

        $rows = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/estate')->assertOk()->json('data.projects'))->keyBy('project_name');
        $this->assertSame(2, $rows['razzahavenu']['accounts']);
        $this->assertSame(['snapchat'], $rows['razzahavenu']['providers']);
        $this->assertSame(1, $rows['henka']['accounts']);
        $this->assertNotNull($rows['razzahavenu']['last_synced_at']);

        $this->unlink($this->razzah, $this->nasel)->assertOk();

        $after = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/estate')->json('data.projects'))->keyBy('project_name');
        $this->assertSame(1, $after['razzahavenu']['accounts'], 'the removed account no longer feeds the project');
        $this->assertSame(1, $after['henka']['accounts'], 'another project is untouched');
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Services\AccountAssignment;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * INTEGRATION-UNLINK-001 — an ad account linked to the wrong client can be taken off it, by the account, after it has synced.
 *
 * The Owner linked Nasl Real Estate's Snapchat account («Nasel») to Razzah Avenue by mistake and found no way back. Removing it is the
 * binding's deactivation — never a delete — so the history stays, the sweep stops filing under the project, the account's figures leave
 * THAT project's totals, every other project and the provider authorisation are untouched, and linking it again restores it.
 */
final class UnlinkAccountFromProjectTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $razzah;

    private Project $other;

    private ExternalAccount $nasel;

    private ExternalAccount $razzahOwn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'ul-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'ul-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->razzah = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'razzahavenu', 'status' => 'active']);
        $this->other = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'henka', 'status' => 'active']);

        $connection = app(TokenVault::class)->open(tenantId: (string) $this->tenant->id, provider: 'snapchat', tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: 'snap');
        $make = fn (string $name): ExternalAccount => ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => 'snapchat', 'account_type' => 'ad_account',
            'external_id' => $name.'-ad', 'name' => $name, 'currency' => 'USD', 'status' => 'active',
        ]);
        $this->nasel = $make('Nasel');
        $this->razzahOwn = $make('RazzahAvenu Self Service');
        foreach ([$this->nasel, $this->razzahOwn] as $account) {
            $this->bind($this->razzah, $account);
            $this->spend($this->razzah, $account, 100.0);
        }
        app(TenantContext::class)->forget();
    }

    private function bind(Project $project, ExternalAccount $account): ProjectIntegrationBinding
    {
        return ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $project->client_workspace_id, 'project_id' => $project->id,
            'external_account_id' => $account->id, 'provider' => 'snapchat', 'purpose' => 'reporting', 'is_active' => true, 'is_primary' => true,
        ]);
    }

    private function spend(Project $project, ExternalAccount $account, float $value): void
    {
        $campaign = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'name' => $account->name, 'status' => 'active', 'objective' => 'sales']);
        DailyMetric::withoutGlobalScopes()->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->id, 'provider' => 'snapchat', 'metric_key' => 'spend',
            'metric_date' => '2026-10-20', 'value' => $value,
        ]);
    }

    private function unlink(Project $project, ExternalAccount $account, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson("/api/v1/projects/{$project->id}/integrations/accounts/{$account->id}/unlink");
    }

    private function projectSpend(): float
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);

        return (float) (app(MetricsAggregator::class)->forProjects([(string) $this->razzah->id])->totals(Carbon::parse('2026-10-18'), Carbon::parse('2026-10-24'))['spend'] ?? 0);
    }

    public function test_removing_the_wrong_account_takes_its_figures_out_of_the_project_keeps_its_history_and_leaves_the_other_account(): void
    {
        $this->assertEquals(200.0, $this->projectSpend(), 'the mistaken account feeds the project before');

        $res = $this->unlink($this->razzah, $this->nasel)->assertOk();

        $this->assertEquals(1, $res->json('data.history_rows_kept'));
        $this->assertEquals(100.0, $this->projectSpend(), 'only the project’s own account remains in its totals');
        $this->assertSame(2, DailyMetric::withoutGlobalScopes()->where('project_id', $this->razzah->id)->count(), 'nothing was deleted');

        $bindings = ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->get()->keyBy('external_account_id');
        $this->assertFalse((bool) $bindings[$this->nasel->id]->is_active);
        $this->assertFalse((bool) $bindings[$this->nasel->id]->is_primary);
        $this->assertTrue((bool) $bindings[$this->razzahOwn->id]->is_active);

        // The sweep reads active bindings only: the removed account is no longer filed under any project.
        $this->assertNull(app(AccountAssignment::class)->projectIdFor($this->nasel->fresh()));
        $this->assertSame((string) $this->razzah->id, app(AccountAssignment::class)->projectIdFor($this->razzahOwn->fresh()));
        $this->assertTrue(AuditLog::withoutGlobalScopes()->where('action', 'integration.account.unlinked')->where('entity_id', (string) $this->nasel->id)->exists());
    }

    public function test_linking_it_again_brings_the_history_back(): void
    {
        $this->unlink($this->razzah, $this->nasel)->assertOk();
        ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->where('external_account_id', $this->nasel->id)->update(['is_active' => true]);

        $this->assertEquals(200.0, $this->projectSpend());
    }

    public function test_it_only_touches_this_project_and_is_idempotent_as_a_refusal(): void
    {
        $this->bind($this->other, $this->nasel);
        app(TenantContext::class)->forget();

        $this->unlink($this->razzah, $this->nasel)->assertOk();

        $this->assertTrue(ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->other->id)->where('external_account_id', $this->nasel->id)->value('is_active'), 'another project keeps the account');
        $this->unlink($this->razzah, $this->nasel)->assertNotFound();
    }

    public function test_a_stranger_or_an_unauthorised_member_cannot_unlink(): void
    {
        $viewer = User::create(['name' => 'V', 'email' => 'v-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant, Portal::Agency);
        $this->unlink($this->razzah, $this->nasel, $viewer)->assertForbidden();

        $foreign = Tenant::create(['name' => 'B', 'slug' => 'fb-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($foreign->id);
        $role = Role::create(['tenant_id' => $foreign->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $spy = User::create(['name' => 'S', 'email' => 's-'.uniqid().'@x.test', 'password' => 'secret123']);
        $this->grantMembership($spy, $foreign, Portal::Agency);
        $spy->assignRole($role);
        app(TenantContext::class)->forget();

        $this->assertContains($this->unlink($this->razzah, $this->nasel, $spy)->status(), [403, 404]);
        $this->assertTrue((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->razzah->id)->where('external_account_id', $this->nasel->id)->value('is_active'));
    }
}
