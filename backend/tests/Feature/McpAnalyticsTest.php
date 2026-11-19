<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Agents\Mcp\AnalyticsMcpTools;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MCP-ANALYTICS-001 — the analytics tools are read-only, equal the board, never cross a workspace, are audited and bounded.
 */
final class McpAnalyticsTest extends TestCase
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
        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'Rose', 'slug' => 'rose', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $client->id, 'name' => 'Rose', 'status' => 'active']);
    }

    private function token(bool $readOnly = true): string
    {
        return $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/me/mcp-tokens', ['name' => 'Claude', 'read_only' => $readOnly])->assertCreated()->json('data.token');
    }

    private function rpc(string $token, string $method, array $params = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    /** @return array<string, mixed> */
    private function tool(string $token, string $tool, array $args): array
    {
        $r = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $args])->assertOk();

        return ['error' => (bool) $r->json('result.isError'), 'body' => json_decode((string) $r->json('result.content.0.text'), true) ?? []];
    }

    public function test_a_read_only_token_lists_only_read_tools_and_every_other_method_is_refused(): void
    {
        $token = $this->token();
        $tools = $this->rpc($token, 'tools/list')->assertOk()->json('result.tools');
        $names = array_column($tools, 'name');

        $this->assertNotContains('propose_change', $names);
        foreach (array_keys(AnalyticsMcpTools::CATALOG) as $t) {
            $this->assertContains($t, $names);
        }
        // Whatever is listed either is an analytics reading, a plain list/get, or carries a read-only hint.
        foreach ($names as $n) {
            $this->assertMatchesRegularExpression('/^(list_|get_|compare_|analytics_)/', $n, "{$n} does not look like a read tool");
        }
        foreach ($tools as $tool) {
            if (str_starts_with((string) $tool['name'], 'analytics_')) {
                $this->assertTrue($tool['annotations']['readOnlyHint']);
            }
        }

        // Named anyway, the proposal tool is refused; and no method outside the read ones exists.
        $this->assertTrue($this->tool($token, 'propose_change', ['project_id' => (string) $this->project->id, 'provider' => 'meta', 'target_external_id' => 'x', 'action' => 'pause', 'reason' => 'r'])['error']);
        foreach (['resources/read', 'prompts/get', 'tools/create', 'completion/complete', 'sampling/createMessage'] as $method) {
            $this->rpc($token, $method)->assertOk()->assertJsonPath('error.code', -32601);
        }
        // The endpoint answers POST only; a GET stream and a write verb are not routes.
        $this->withHeader('Authorization', 'Bearer '.$token)->putJson('/api/v1/mcp', [])->assertStatus(405);
        $this->withHeader('Authorization', 'Bearer '.$token)->deleteJson('/api/v1/mcp')->assertStatus(405);
    }

    public function test_a_token_made_with_proposals_still_gets_them_and_says_so_in_the_list(): void
    {
        $token = $this->token(false);
        $this->assertContains('propose_change', array_column($this->rpc($token, 'tools/list')->json('result.tools'), 'name'));
        $list = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/mcp-tokens')->json('data.tokens');
        $this->assertFalse($list[0]['read_only']);
        $this->assertStringNotContainsString($token, (string) json_encode($list));
    }

    public function test_the_summary_and_platforms_equal_the_board_to_the_digit(): void
    {
        $campaign = UnifiedCampaign::create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => 'C', 'status' => 'active', 'objective' => 'sales']);
        DailyMetric::create([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->getKey(), 'external_account_id' => (string) Str::uuid(),
            'external_campaign_id' => (string) Str::uuid(), 'unified_campaign_id' => $campaign->getKey(), 'provider' => 'meta',
            'metric_key' => 'spend', 'metric_date' => now()->subDay()->toDateString(), 'value' => 123.45,
        ]);
        $from = now()->subDays(6)->toDateString();
        $to = now()->subDay()->toDateString();
        $base = "/api/v1/projects/{$this->project->id}";

        $board = $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/metrics/summary?from={$from}&to={$to}")->assertOk()->json('data');
        $this->assertEquals(123.45, $board['current']['spend']);
        $boardPlatforms = $this->actingAs($this->owner, 'sanctum')->getJson("{$base}/metrics/platforms?from={$from}&to={$to}")->assertOk()->json('data');

        $token = $this->token();
        $sum = $this->tool($token, 'analytics_summary', ['project_id' => (string) $this->project->id, 'from' => $from, 'to' => $to]);
        $this->assertFalse($sum['error']);
        $this->assertSame('ok', $sum['body']['status']);
        $this->assertSame($board['current'], $sum['body']['data']['figures']['current']);
        $this->assertSame($board['previous'], $sum['body']['data']['figures']['previous']);
        $this->assertSame(['from' => $from, 'to' => $to], $sum['body']['period']);
        $this->assertNotEmpty($sum['body']['source']);

        $plat = $this->tool($token, 'analytics_platforms', ['project_id' => (string) $this->project->id, 'from' => $from, 'to' => $to]);
        $this->assertSame($boardPlatforms, $plat['body']['data']['figures']);
    }

    public function test_cross_workspace_and_unknown_projects_are_refused_for_every_project_tool(): void
    {
        $other = Tenant::create(['name' => 'B', 'slug' => 'b', 'status' => 'active', 'account_type' => 'agency']);
        $client = ClientWorkspace::create(['tenant_id' => $other->id, 'name' => 'Other', 'slug' => 'other', 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $foreign = Project::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'client_workspace_id' => $client->id, 'name' => 'Foreign', 'status' => 'active']);

        $token = $this->token();
        foreach (AnalyticsMcpTools::CATALOG as $tool => $def) {
            if (! $def['project']) {
                continue;
            }
            foreach ([(string) $foreign->id, (string) Str::uuid(), ''] as $id) {
                $r = $this->tool($token, $tool, ['project_id' => $id]);
                $this->assertTrue($r['error'], "{$tool} answered for {$id}");
                $this->assertStringNotContainsString('Foreign', (string) json_encode($r['body']));
            }
        }
    }

    public function test_a_revoked_token_stops_at_once_and_a_missing_permission_hides_the_tools(): void
    {
        $token = $this->token();
        $id = $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/me/mcp-tokens')->json('data.tokens.0.id');
        $this->rpc($token, 'tools/list')->assertOk();
        $this->actingAs($this->owner, 'sanctum')->deleteJson('/api/v1/me/mcp-tokens/'.$id)->assertOk();
        $this->rpc($token, 'tools/list')->assertUnauthorized();

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Narrow', 'slug' => 'narrow']);
        $role->givePermissionTo('budget.view', 'clients.view');
        $narrow = User::create(['name' => 'n', 'email' => 'n@mcp.test', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($narrow, $this->tenant, Portal::Agency, 'member');
        $narrow->roles()->syncWithoutDetaching([$role->id]);
        $this->app['auth']->forgetGuards();
        $t = $this->actingAs($narrow, 'sanctum')->postJson('/api/v1/me/mcp-tokens', ['name' => 'Narrow one'])->assertCreated()->json('data.token');
        $names = array_column($this->rpc($t, 'tools/list')->json('result.tools'), 'name');
        $this->assertSame([], array_values(array_filter($names, static fn ($n) => str_starts_with($n, 'analytics_'))));
        $this->assertTrue($this->tool($t, 'analytics_summary', ['project_id' => (string) $this->project->id])['error']);
    }

    public function test_sections_not_built_yet_say_so_and_the_call_is_audited_without_payload(): void
    {
        $token = $this->token();
        $r = $this->tool($token, 'analytics_site', ['project_id' => (string) $this->project->id]);
        $this->assertContains($r['body']['status'] ?? 'error', ['ok', 'not_available_yet']);

        $row = AuditLog::query()->where('action', 'mcp.tool.called')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('analytics_site', $row->after['tool']);
        $this->assertNotEmpty($row->after['token_id']);
        $this->assertStringNotContainsString((string) $this->project->id, (string) json_encode($row->after));
    }

    public function test_a_reading_is_rate_limited_per_token(): void
    {
        $token = $this->token();
        for ($i = 0; $i < 30; $i++) {
            $this->assertFalse($this->tool($token, 'analytics_summary', ['project_id' => (string) $this->project->id])['error']);
        }
        $this->assertStringContainsString('Too many', (string) json_encode($this->tool($token, 'analytics_summary', ['project_id' => (string) $this->project->id])['body']));
    }

    public function test_one_reading_runs_a_bounded_number_of_queries(): void
    {
        $token = $this->token();
        $this->tool($token, 'analytics_summary', ['project_id' => (string) $this->project->id]); // warm
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->tool($token, 'analytics_summary', ['project_id' => (string) $this->project->id]);
        $board = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(120, $board, 'one MCP reading must stay in the board\'s own query budget');
    }
}
