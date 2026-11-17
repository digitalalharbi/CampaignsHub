<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MCP-OAUTH-001 — the whole «Add custom connector» path: discovery, self-registration, the person's «سماح», the code with
 * PKCE, the token that then reads the person's own projects, and a refresh that rotates.
 */
final class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    private const REDIRECT = 'https://claude.ai/api/mcp/auth_callback';

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active', 'account_type' => 'agency']);
        $this->holdingTenant((string) $this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Tenant Owner', 'slug' => 'tenant-owner', 'is_system' => true]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'o', 'email' => 'o@oauth.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency, 'member');
        $this->owner->roles()->syncWithoutDetaching([$role->id]);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Rose Avenue', 'slug' => 'rose', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Rose', 'status' => 'active']);
    }

    private function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function register(): string
    {
        return (string) $this->postJson('/api/v1/mcp/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [self::REDIRECT]])
            ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none')->json('client_id');
    }

    /** @return array{0: string, 1: string} code and verifier */
    private function approve(string $clientId): array
    {
        $verifier = str_repeat('v', 64);
        $redirect = $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/mcp/oauth/approve', [
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT, 'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256', 'state' => 'xyz',
        ])->assertOk()->json('redirect');
        $this->assertStringStartsWith(self::REDIRECT.'?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        $this->assertSame('xyz', $query['state']);
        $this->app['auth']->forgetGuards();

        return [(string) $query['code'], $verifier];
    }

    public function test_a_refused_request_names_where_to_sign_in_and_discovery_answers(): void
    {
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="https://mcp.campaignshub.io/.well-known/oauth-protected-resource"');

        $this->getJson('/api/v1/mcp/oauth/protected-resource')->assertOk()
            ->assertJsonPath('resource', 'https://mcp.campaignshub.io')
            ->assertJsonPath('authorization_servers.0', 'https://mcp.campaignshub.io');
        $this->getJson('/api/v1/mcp/oauth/metadata')->assertOk()
            ->assertJsonPath('authorization_endpoint', 'https://mcp.campaignshub.io/authorize')
            ->assertJsonPath('code_challenge_methods_supported', ['S256']);
    }

    public function test_the_whole_connection_reads_the_persons_projects_and_refresh_rotates(): void
    {
        $clientId = $this->register();

        $this->get('/api/v1/mcp/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
            'code_challenge' => $this->challenge('x'), 'code_challenge_method' => 'S256', 'state' => 's',
        ]))->assertRedirectContains('/oauth/mcp?');
        $this->getJson('/api/v1/mcp/oauth/clients/'.$clientId)->assertOk()->assertJsonPath('name', 'Claude');

        [$code, $verifier] = $this->approve($clientId);
        $tokens = $this->post('/api/v1/mcp/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
        ])->assertOk()->assertJsonPath('token_type', 'Bearer')->json();

        $text = $this->withHeader('Authorization', 'Bearer '.$tokens['access_token'])
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_projects', 'arguments' => []]])
            ->assertOk()->json('result.content.0.text');
        $this->assertStringContainsString((string) $this->project->id, (string) $text);

        // The code is single-use.
        $this->post('/api/v1/mcp/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier,
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        // A refresh gives a new pair; the old refresh token is spent.
        $this->app['auth']->forgetGuards();
        $next = $this->post('/api/v1/mcp/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])
            ->assertOk()->json();
        $this->assertNotSame($tokens['refresh_token'], $next['refresh_token']);
        $this->post('/api/v1/mcp/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId])
            ->assertStatus(400);

        // The connection is listed under «ربط MCP» by the app's name, and revocable there.
        $this->app['auth']->forgetGuards();
        $names = collect($this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/mcp-tokens')->assertOk()->json('data.tokens'))->pluck('name')->all();
        $this->assertSame(['Claude'], $names);
    }

    public function test_a_wrong_verifier_burns_the_code_and_a_foreign_redirect_is_refused(): void
    {
        $clientId = $this->register();
        [$code] = $this->approve($clientId);

        $this->post('/api/v1/mcp/oauth/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => 'wrong',
            'client_id' => $clientId, 'redirect_uri' => self::REDIRECT,
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/mcp/oauth/approve', [
            'client_id' => $clientId, 'redirect_uri' => 'https://evil.example/cb', 'code_challenge' => $this->challenge(str_repeat('v', 64)),
            'code_challenge_method' => 'S256',
        ])->assertStatus(422);
        $this->postJson('/api/v1/mcp/oauth/register', ['client_name' => 'X', 'redirect_uris' => ['http://evil.example/cb']])
            ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }
}
