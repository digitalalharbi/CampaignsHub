<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Access\ProjectCapability;
use App\Domains\Projects\Access\ProjectRole;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * MCP-SCOPE-001 / MCP-STORE-001 — what a connector sees is exactly what the person sees in the app: a member whose project
 * membership withholds money gets every MCP answer without it, and cannot propose a budget; the store sits beside the ads.
 */
final class McpScopeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    /** @param list<string> $denied */
    private function member(array $denied): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Staff', 'slug' => 'staff-'.uniqid()]);
        $role->givePermissionTo('projects.view', 'campaigns.view', 'analytics.view', 'ai.use');
        $user = User::create(['name' => 'M', 'email' => 'm-'.uniqid().'@t.test', 'password' => Hash::make('secret1234'), 'email_verified_at' => now()]);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);
        ProjectMembership::create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'user_id' => $user->id,
            'role' => ProjectRole::MEDIA_BUYER, 'status' => 'active', 'denied' => $denied,
        ]);

        return $user;
    }

    /** @param array<string, mixed> $arguments */
    private function mcp(User $user, string $tool, array $arguments = [])
    {
        $token = $user->createToken('mcp:Claude', ['mcp'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => ['project_id' => (string) $this->project->id] + $arguments],
        ])->assertOk();
    }

    public function test_money_withheld_in_the_app_is_withheld_from_the_connector_too(): void
    {
        $buyer = $this->member([ProjectCapability::MONEY_VIEW]);

        foreach (['list_campaigns', 'get_store_sales', 'compare_targets'] as $tool) {
            $text = (string) $this->mcp($buyer, $tool)->json('result.content.0.text');
            $this->assertDoesNotMatchRegularExpression('/"(spend|revenue|roas|cpa|cpc|cpm|budget|daily_budget)"/', $text, "{$tool} leaked a money key over MCP");
        }

        $this->mcp($buyer, 'propose_change', [
            'provider' => 'meta', 'target_external_id' => '1', 'action' => 'set_daily_budget', 'daily_budget' => 100, 'reason' => 'x',
        ])->assertJsonPath('result.isError', true);
    }

    public function test_the_store_sits_beside_the_ads_and_says_so_when_none_is_connected(): void
    {
        $peer = $this->member([]);

        $text = (string) $this->mcp($peer, 'get_store_sales', ['days' => 30])->assertJsonPath('result.isError', false)->json('result.content.0.text');
        $this->assertStringContainsString('"has_store":false', $text);
        $this->assertStringContainsString('"spend"', $text, 'a member who may see money gets the spend beside the (empty) store');
    }
}
