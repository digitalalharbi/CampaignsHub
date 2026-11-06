<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * INTEG-PROJECT-SPACE-001 (Owner 2026-11-06) — «شاشة التكاملات هي مساحة المشروع المحدد».
 *
 * Seen in Production: a project's row exposed the whole discovered estate («الكل 1247 / مرتبطة 18 / غير
 * مرتبطة 1229»), dead and zero-account sources were listed by default, and several rows of one platform
 * could not be told apart. Asked for ONE project, the hub answers with that project's sources and nothing
 * else: no other project's connection, account, name or count; no source nothing of this project is linked
 * to; and each row names the accounts and organisations it reads so two rows of one platform differ.
 */
final class ProjectIntegrationSpaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->seed(MetricDefinitionSeeder::class);
        Queue::fake();

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->owner = $this->member(Permission::pluck('key')->all());

        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->projectA = $this->project($workspace, 'Project A');
        $this->projectB = $this->project($workspace, 'Project B');
    }

    public function test_one_projects_space_holds_its_own_sources_and_nothing_of_another_project_or_of_the_unbound_estate(): void
    {
        // A workspace-wide connection with a large estate: one account for A, one for B, the rest never linked.
        $wide = $this->open('snapchat', null);
        $ofA = $this->stored($wide, 'acct-a', 'Riyadh Retail');
        $ofB = $this->stored($wide, 'acct-b', 'Secret Client B');
        foreach (range(1, 12) as $n) {
            $this->stored($wide, "acct-x{$n}", "Never linked {$n}");
        }
        $this->bind($this->projectA, $wide, $ofA);
        $this->bind($this->projectB, $wide, $ofB);

        // B's own connection, a second Snapchat source of A's own, and a sign-in for A that nobody finished.
        $ownB = $this->open('snapchat', $this->projectB);
        $this->bind($this->projectB, $ownB, $this->stored($ownB, 'acct-b2', 'Secret Client B Two'));
        $ownA = $this->open('snapchat', $this->projectA);
        $this->bind($this->projectA, $ownA, $this->stored($ownA, 'acct-a2', 'Jeddah Outlet', 'Nova Group', 'org-2'));
        $this->open('meta', $this->projectA);

        $response = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/integrations/hub?project_id='.$this->projectA->id)->assertOk();
        $rows = collect($response->json('data.connections'));

        // Two sources, both A's; the unfinished Meta sign-in and B's own connection are not rows.
        $this->assertEqualsCanonicalizing([(string) $wide->id, (string) $ownA->id], $rows->pluck('id')->all());

        // Each row counts and names ONLY what this project reads through it — the estate is not a number here.
        $byId = $rows->keyBy('id');
        $this->assertSame(1, $byId[(string) $wide->id]['selected_accounts']);
        $this->assertSame(1, $byId[(string) $wide->id]['discovered_accounts'], 'the unbound estate was counted on a project row');
        $this->assertSame([['name' => 'Riyadh Retail', 'organisation' => 'Acme Group']], array_map(
            static fn (array $a): array => ['name' => $a['name'], 'organisation' => $a['organisation']],
            $byId[(string) $wide->id]['accounts'],
        ));
        // Two rows of one platform are told apart by what they read.
        $this->assertSame('Jeddah Outlet', $byId[(string) $ownA->id]['accounts'][0]['name']);
        $this->assertSame('Nova Group', $byId[(string) $ownA->id]['accounts'][0]['organisation']);
        $this->assertSame([['id' => (string) $this->projectA->id, 'name' => 'Project A']], $byId[(string) $wide->id]['projects']);

        // Nothing of project B, by name or by id, anywhere in the answer.
        $body = (string) $response->getContent();
        foreach (['Secret Client B', 'Project B', (string) $this->projectB->id, (string) $ownB->id, (string) $ofB->id, 'Never linked'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "the project's space leaked: {$leak}");
        }
    }

    public function test_a_project_of_another_tenant_or_an_unknown_one_is_not_found(): void
    {
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid(), 'status' => 'active']);
        $foreign = Project::withoutGlobalScopes()->create([
            'tenant_id' => $other->id,
            'client_workspace_id' => ClientWorkspace::withoutGlobalScopes()->create([
                'tenant_id' => $other->id, 'name' => 'X', 'slug' => 'x-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
            ])->id,
            'name' => 'Foreign', 'status' => 'active',
        ]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/integrations/hub?project_id='.$foreign->id)->assertNotFound();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/integrations/hub?project_id=not-a-uuid')->assertStatus(422);
    }

    private function open(string $provider, ?Project $project): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('T-'.uniqid(), null, null),
            connectionName: ucfirst($provider), projectId: $project?->id,
        );
    }

    private function bind(Project $project, ProviderConnection $connection, ExternalAccount $account): void
    {
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id, 'external_account_ids' => [$account->id], 'purpose' => 'advertising',
            ])->assertCreated();
    }

    private function stored(ProviderConnection $connection, string $externalId, string $name, string $organisation = 'Acme Group', string $organisationId = 'org-1'): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'currency' => 'SAR',
            'parent_external_id' => $organisationId, 'parent_name' => $organisation, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }

    /** @param  list<string>  $permissions */
    private function member(array $permissions): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'u-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        return $user;
    }

    private function project(ClientWorkspace $workspace, string $name): Project
    {
        return Project::withoutGlobalScopes()->create([
            'tenant_id' => $workspace->tenant_id, 'client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active',
        ]);
    }
}
