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
 * MCP-SERVER-001 — the MCP endpoint answers JSON-RPC as the token's own person: their projects only, nothing for a stranger.
 */
final class McpServerTest extends TestCase
{
    use RefreshDatabase;

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
        $this->owner = User::create(['name' => 'o', 'email' => 'o@mcp.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->owner, $this->tenant, Portal::Agency, 'member');
        $this->owner->roles()->syncWithoutDetaching([$role->id]);
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Rose Avenue', 'slug' => 'rose', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Rose', 'status' => 'active']);
    }

    private function token(): string
    {
        return $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/me/mcp-tokens', ['name' => 'Claude Desktop'])
            ->assertCreated()->json('data.token');
    }

    private function rpc(string $token, string $method, array $params = [], int $id = 1)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    public function test_without_a_token_nothing_answers(): void
    {
        $this->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
    }

    public function test_initialize_lists_tools_and_reads_the_persons_projects(): void
    {
        $token = $this->token();

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18'])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'campaignshub');

        $names = collect($this->rpc($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertContains('list_projects', $names);
        $this->assertContains('list_campaigns', $names);

        $text = $this->rpc($token, 'tools/call', ['name' => 'list_projects', 'arguments' => []])->assertOk()->json('result.content.0.text');
        $this->assertStringContainsString((string) $this->project->id, $text);
        $this->assertStringContainsString('Rose Avenue', $text);
    }

    public function test_a_project_outside_the_workspace_is_refused(): void
    {
        $token = $this->token();

        $this->rpc($token, 'tools/call', ['name' => 'list_campaigns', 'arguments' => ['project_id' => '00000000-0000-0000-0000-000000000000']])
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_a_notification_is_acknowledged_and_an_unknown_method_is_an_error(): void
    {
        $token = $this->token();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
        $this->rpc($token, 'no/such')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_a_token_is_shown_once_listed_and_revocable(): void
    {
        $this->token();
        $list = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/mcp-tokens')->assertOk()->json('data.tokens');
        $this->assertCount(1, $list);
        $this->assertSame('Claude Desktop', $list[0]['name']);
        $this->assertArrayNotHasKey('token', $list[0]);

        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/v1/me/mcp-tokens/'.$list[0]['id'])->assertOk()->assertJsonCount(0, 'data.tokens');
    }
}
