<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\CreativeGroup;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SEC-CLUSTER-2 — the two TENANT-level lists (creative library, integrations inventory) stop at the
 * projects the reader may reach.
 *
 * An account manager scoped to client C is a member of project P1 only. P2 is another project of the
 * same client. `/projects/P2/...` refuses them; the tenant-level routes carry no project, so they
 * answered for every project of the client (and, with `clients.view_all`, of the tenant).
 */
final class TenantLevelListsHonourProjectReachTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    private User $manager;

    private Project $p1;

    private Project $p2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->agency->id);

        $client = ClientWorkspace::create([
            'tenant_id' => $this->agency->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->p1 = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => 'Project One', 'status' => 'active']);
        $this->p2 = Project::create(['tenant_id' => $this->agency->id, 'client_workspace_id' => $client->id, 'name' => 'Project Two', 'status' => 'active']);

        // The seeded account-manager shape: tenant view permissions, no projects.view.all, client scope [C].
        $this->manager = User::create(['name' => 'AM', 'email' => 'am@agency.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'AM', 'slug' => 'am-'.uniqid()]);
        $role->givePermissionTo('campaigns.view', 'campaigns.link', 'projects.view', 'integrations.view', 'clients.view');
        $this->manager->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $this->manager, tenant: $this->agency, portal: Portal::Agency, role: 'member', clientScopeIds: [$client->id],
        ));
        ProjectMembership::create(['project_id' => $this->p1->id, 'user_id' => $this->manager->id, 'role' => ProjectRole::MARKETING_MANAGER, 'status' => 'active']);
    }

    // ── creative library (finding: tenant-level library ignores project membership) ──────────

    public function test_the_library_lists_only_creatives_of_projects_the_reader_belongs_to(): void
    {
        $this->creative($this->p1, 'CR-ONE');
        $this->creative($this->p2, 'CR-TWO');

        $listed = (string) $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/creatives?'.$this->window())->assertOk()->getContent();

        $this->assertStringContainsString('CR-ONE', $listed);
        $this->assertStringNotContainsString('CR-TWO', $listed, 'a creative of a project the reader is not a member of is in the library');
    }

    public function test_naming_another_project_in_the_filter_does_not_reach_it(): void
    {
        $this->creative($this->p2, 'CR-TWO');

        $listed = (string) $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/creatives?'.$this->window().'&project_ids[]='.$this->p2->id)->getContent();

        $this->assertStringNotContainsString('CR-TWO', $listed);
    }

    public function test_the_detail_of_another_projects_creative_is_not_served(): void
    {
        $other = $this->creative($this->p2, 'CR-TWO');

        $res = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/creatives/{$other->id}?".$this->window());

        $this->assertContains($res->status(), [403, 404]);
        $this->assertStringNotContainsString('CR-TWO', (string) $res->getContent());
    }

    public function test_creatives_of_another_project_cannot_be_grouped_through_the_tenant_route(): void
    {
        $a = $this->creative($this->p2, 'CR-TWO-A');
        $b = $this->creative($this->p2, 'CR-TWO-B');

        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/creatives/group', ['creative_ids' => [$a->id, $b->id]]);

        $this->assertSame(0, CreativeGroup::withoutGlobalScopes()->count(), "another project's creatives were merged by a non-member");
        $this->assertNull(ExternalCreative::withoutGlobalScopes()->find($a->id)->creative_group_id);
    }

    // ── integrations inventory (finding: tenant-wide inventory ignores client scope) ─────────

    public function test_the_estate_lists_only_projects_the_reader_reaches(): void
    {
        $this->bound($this->p1, 'act-one');
        $this->bound($this->p2, 'act-two');

        $names = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/estate')->assertOk()->json('data.projects.*.project_name');

        $this->assertSame(['Project One'], $names);
    }

    public function test_the_account_inventory_hides_accounts_of_unreachable_projects_and_refuses_their_filter(): void
    {
        $this->bound($this->p1, 'act-one');
        $this->bound($this->p2, 'act-two');

        $body = (string) $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/accounts')->assertOk()->getContent();
        $this->assertStringContainsString('act-one', $body);
        $this->assertStringNotContainsString('act-two', $body);
        $this->assertStringNotContainsString('Project Two', $body);

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/accounts?project='.$this->p2->id)->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/accounts?project='.$this->p1->id)->assertOk();
    }

    public function test_the_hub_does_not_name_an_authorisation_that_only_serves_an_unreachable_project(): void
    {
        $this->bound($this->p1, 'act-one', 'conn-one');
        $this->bound($this->p2, 'act-two', 'conn-two');

        $body = (string) $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/integrations/hub')->assertOk()->getContent();

        $this->assertStringContainsString('conn-one', $body);
        $this->assertStringNotContainsString('conn-two', $body);
        $this->assertStringNotContainsString('Project Two', $body);
    }

    public function test_an_agency_wide_reader_keeps_the_whole_inventory(): void
    {
        $this->bound($this->p1, 'act-one');
        $this->bound($this->p2, 'act-two');
        $admin = User::create(['name' => 'Adm', 'email' => 'adm@agency.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'Adm', 'slug' => 'adm-'.uniqid()]);
        $role->givePermissionTo('integrations.view', 'projects.view', 'projects.view.all', 'clients.view_all');
        $admin->assignRole($role);
        app(GrantMembership::class)->execute(new MembershipGrant(user: $admin, tenant: $this->agency, portal: Portal::Agency, role: 'member'));

        $names = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/estate')->assertOk()->json('data.projects.*.project_name');

        $this->assertEqualsCanonicalizing(['Project One', 'Project Two'], $names);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────

    private function window(): string
    {
        return 'from='.now()->subDays(29)->toDateString().'&to='.now()->toDateString();
    }

    private function creative(Project $project, string $name): ExternalCreative
    {
        $campaign = UnifiedCampaign::create([
            'tenant_id' => $this->agency->id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'name' => 'Camp '.$name, 'objective' => 'sales', 'status' => 'active',
        ]);
        $creative = ExternalCreative::create([
            'tenant_id' => $this->agency->id, 'project_id' => $project->id, 'campaign_id' => $campaign->id, 'provider' => 'meta',
            'external_creative_id' => 'cr-'.Str::random(6), 'name' => $name, 'format' => 'image', 'status' => 'active', 'last_active_at' => now(),
        ]);
        DB::table('creative_daily_metrics')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->agency->id, 'project_id' => $project->id, 'creative_id' => $creative->id,
            'campaign_id' => $campaign->id, 'metric_date' => now()->subDay()->toDateString(), 'spend' => 100, 'impressions' => 1000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $creative;
    }

    private function bound(Project $project, string $externalId, ?string $connectionName = null): ExternalAccount
    {
        $credential = new IntegrationCredential(['tenant_id' => $this->agency->id, 'provider' => 'meta', 'credential_scope' => 'project_only', 'credential_type' => 'oauth', 'status' => 'active']);
        $credential->setPayload('token');
        $credential->save();
        $connection = ProviderConnection::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'credential_id' => $credential->id, 'provider' => 'meta',
            'connection_name' => $connectionName ?? 'c-'.uniqid(), 'scope' => 'project_only', 'status' => 'connected',
        ]);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'provider_connection_id' => $connection->id, 'provider' => 'meta',
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $externalId, 'status' => 'active',
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->agency->id, 'project_id' => $project->id, 'external_account_id' => $account->id,
            'provider' => 'meta', 'purpose' => 'advertising', 'is_active' => true,
        ]);

        return $account;
    }
}
