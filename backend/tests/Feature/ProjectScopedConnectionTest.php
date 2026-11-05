<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * INTEG-PROJECT-LINK-001 (Owner 2026-11-06) — «عزل كامل لكل مصدر ولكل مشروع».
 *
 * A connection opened by a project's link journey belongs to that ONE project. Before this, the vault
 * re-credentialed «the connection for this tenant, workspace and platform», so two projects that signed in
 * shared one row: the second sign-in replaced the first project's tokens, and removing the source from one
 * project took it from the other.
 *
 * What these prove: two projects hold two connections; a project signing in again re-credentials only its
 * own; a workspace-wide connection from before the rule is neither adopted nor disturbed; the engine refuses
 * to bind an account of one project's connection to another project; and removing one project's connection
 * leaves the other's connected, credentialed and bound.
 */
final class ProjectScopedConnectionTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->owner = $this->member($this->tenant, Permission::pluck('key')->all(), 'Owner One');
        // One client workspace on purpose: the project fence must hold where the client-workspace fence does not.
        $workspace = ClientWorkspace::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $this->projectA = $this->project($workspace, 'Project A');
        $this->projectB = $this->project($workspace, 'Project B');

        foreach (PlatformCredentials::for('meta')->requires() as $key) {
            config()->set("ad_platforms.platforms.meta.{$key}", "test-{$key}");
        }
        Queue::fake();
        $this->fakeMeta();
    }

    public function test_two_projects_signing_in_to_the_same_platform_hold_two_connections(): void
    {
        $a = $this->signIn($this->projectA, 'code-a');
        $b = $this->signIn($this->projectB, 'code-b');

        $this->assertNotSame((string) $a->id, (string) $b->id, 'two projects shared one connection');
        $this->assertSame((string) $this->projectA->id, (string) $a->project_id);
        $this->assertSame((string) $this->projectB->id, (string) $b->project_id);
        $this->assertSame('project_only', (string) $a->scope);
        $this->assertNotSame((string) $a->credential_id, (string) $b->credential_id);

        // Each keeps the token ITS sign-in returned.
        $vault = app(TokenVault::class);
        $this->assertSame('AT-code-a', $vault->stored($a->refresh())->accessToken);
        $this->assertSame('AT-code-b', $vault->stored($b->refresh())->accessToken);
    }

    public function test_a_project_signing_in_again_re_credentials_only_its_own_connection(): void
    {
        $a = $this->signIn($this->projectA, 'code-a');
        $b = $this->signIn($this->projectB, 'code-b');

        $again = $this->signIn($this->projectA, 'code-a2');

        $this->assertSame((string) $a->id, (string) $again->id, 'a second sign-in for the same project opened a second connection');
        $this->assertSame(2, ProviderConnection::withoutGlobalScopes()->count());

        $vault = app(TokenVault::class);
        $this->assertSame('AT-code-a2', $vault->stored($again->refresh())->accessToken);
        $this->assertSame('AT-code-b', $vault->stored($b->refresh())->accessToken, 'project B was re-credentialed by project A signing in');
    }

    public function test_a_workspace_wide_connection_is_neither_adopted_nor_disturbed_by_a_project_sign_in(): void
    {
        $legacy = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('LEGACY', null, null), connectionName: 'Meta',
        );
        $this->assertNull($legacy->project_id);

        $a = $this->signIn($this->projectA, 'code-a');

        $this->assertNotSame((string) $legacy->id, (string) $a->id, 'the project journey adopted the workspace-wide connection');
        $this->assertSame('LEGACY', app(TokenVault::class)->stored($legacy->refresh())->accessToken);
        $this->assertNull($legacy->refresh()->project_id);

        // And the other way round: a workspace-wide connect lands on the workspace-wide row, never on a project's.
        $wide = app(TokenVault::class)->open(
            tenantId: $this->tenant->id, provider: 'meta', tokens: new OAuthTokens('LEGACY-2', null, null), connectionName: 'Meta',
        );
        $this->assertSame((string) $legacy->id, (string) $wide->id);
        $this->assertSame('AT-code-a', app(TokenVault::class)->stored($a->refresh())->accessToken);
    }

    public function test_the_engine_refuses_to_bind_an_account_of_one_projects_connection_to_another_project(): void
    {
        $a = $this->signIn($this->projectA, 'code-a');
        $account = $this->account($a, 'act_1', 'Main');

        // Not bound anywhere yet, same client workspace — only the project fence can refuse this.
        $this->confirm($this->projectB, $a, [$account->id])->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $this->projectB->id)
            ->postJson("/api/v1/projects/{$this->projectB->id}/integrations/bindings", [
                'external_account_id' => $account->id, 'purpose' => 'advertising',
            ])->assertForbidden();
        $this->assertSame(0, ProjectIntegrationBinding::withoutGlobalScopes()->count());

        // Its own project binds it as before.
        $this->confirm($this->projectA, $a, [$account->id])->assertCreated();
        $this->assertSame((string) $this->projectA->id, (string) ProjectIntegrationBinding::withoutGlobalScopes()->value('project_id'));
    }

    public function test_removing_one_projects_connection_leaves_the_other_projects_connected_credentialed_and_bound(): void
    {
        $a = $this->signIn($this->projectA, 'code-a');
        $b = $this->signIn($this->projectB, 'code-b');
        $ofA = $this->account($a, 'act_a', 'Account of A');
        $ofB = $this->account($b, 'act_b', 'Account of B');
        $this->confirm($this->projectA, $a, [$ofA->id])->assertCreated();
        $this->confirm($this->projectB, $b, [$ofB->id])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/connections/{$a->id}/remove")->assertSuccessful();

        $this->assertNotNull($a->refresh()->removed_at);
        $this->assertFalse((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectA->id)->value('is_active'));

        $b->refresh();
        $this->assertNull($b->removed_at);
        $this->assertSame('connected', (string) $b->status);
        $this->assertSame('AT-code-b', app(TokenVault::class)->stored($b)->accessToken);
        $this->assertTrue((bool) ProjectIntegrationBinding::withoutGlobalScopes()->where('project_id', $this->projectB->id)->value('is_active'));

        // Signing A in again restores A's own row and still does not touch B's.
        $restored = $this->signIn($this->projectA, 'code-a3');
        $this->assertSame((string) $a->id, (string) $restored->id);
        $this->assertSame('AT-code-b', app(TokenVault::class)->stored($b->refresh())->accessToken);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────────────────────

    private function signIn(Project $project, string $code): ProviderConnection
    {
        $url = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/meta/oauth/start', ['project_id' => $project->id, 'return_portal' => 'agency'])
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url((string) $url, PHP_URL_QUERY), $query);

        $location = (string) $this->get("/api/v1/oauth/ads/meta/callback?code={$code}&state={$query['state']}")->headers->get('Location');
        $this->assertStringContainsString('outcome=connected', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);

        return ProviderConnection::withoutGlobalScopes()->findOrFail((string) $back['link']);
    }

    /** @param  list<string>  $accountIds */
    private function confirm(Project $project, ProviderConnection $connection, array $accountIds)
    {
        return $this->actingAs($this->owner, 'sanctum')->withHeader('X-Project-Id', $project->id)
            ->postJson("/api/v1/projects/{$project->id}/integrations/bindings/batch", [
                'connection_id' => $connection->id, 'external_account_ids' => $accountIds, 'purpose' => 'advertising',
            ]);
    }

    /** Each authorisation code is answered with its own token, so «whose token is stored» is provable. */
    private function fakeMeta(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/oauth/access_token')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $code = (string) ($query['code'] ?? $request['code'] ?? 'none');

                return Http::response(['access_token' => 'AT-'.$code, 'expires_in' => 5184000]);
            }

            return Http::response(['data' => []]);
        });
    }

    /** @param  list<string>  $permissions */
    private function member(Tenant $tenant, array $permissions, string $name): User
    {
        $user = User::create(['name' => $name, 'email' => 'u-'.uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $name, 'slug' => 'r-'.uniqid()]);
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

    private function account(ProviderConnection $connection, string $externalId, string $name): ExternalAccount
    {
        return ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $connection->tenant_id, 'provider_connection_id' => $connection->getKey(), 'provider' => $connection->provider,
            'account_type' => 'ad_account', 'external_id' => $externalId, 'name' => $name, 'currency' => 'USD',
            'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);
    }
}
