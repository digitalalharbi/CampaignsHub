<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Metrics\Services\AccountMetricsSyncer;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\MetricDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsBoundaryWorld;
use Tests\TestCase;

/**
 * INTEG-UNIFIED-BOUNDARIES-001 (case 7) — one real provider account authorised in TWO tenants (the situation Production audited:
 * 244 accounts held by an agency and by its advertiser): tenant A's reads never return tenant B's rows, in either direction.
 *
 * Each tenant holds its own `external_accounts` row for the same `(provider, external_id)` under its own connection and binds it to
 * its own project; both hold a campaign `cmp-X`. Neighbours credited and not repeated: `WebhookAccountHeldByTwoTenantsTest` (one
 * delivery reaches each tenant's copy through its own assignment) and `ProjectIntegrationIsolationTest`/`BindRaceGuardTest` (one
 * tenant cannot bind it to two projects). Asserted here: the WRITE path (each tenant's sync stores under its own tenant only),
 * every READ surface of each tenant, the hub/estate lists, and the DENIAL of the other tenant's project and files.
 */
final class IntegrationBoundarySharedAccountTwoTenantsTest extends TestCase
{
    use BuildsBoundaryWorld;
    use RefreshDatabase;

    protected bool $selectsSeededAccounts = false;

    /** @var array<string, array{tenant: Tenant, project: Project, user: User, account: ExternalAccount, connection: ProviderConnection, rows: array<string,string>}> */
    private array $side = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(MetricDefinitionSeeder::class);
        $this->bwConfigure('meta');
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00', 'UTC'));

        foreach (['A' => [100.0, 'AT-A'], 'B' => [7.0, 'AT-B']] as $key => [$spend, $token]) {
            $tenant = $this->bwTenant('Tenant'.$key);
            $workspace = $this->bwWorkspace($tenant, 'Client'.$key);
            $project = $this->bwProject($tenant, $workspace, 'Project'.$key);
            $user = $this->bwOperator($tenant);
            $connection = $this->bwConnection($tenant, 'meta', null, $token);
            $account = $this->bwAccount($connection, 'act_777000'); // THE SAME real account
            $this->bwBind($account, $project);
            $rows = $this->bwFigures($account, $project, 'cmp-X', $spend, 'Mark'.$key);
            $this->side[$key] = compact('tenant', 'project', 'user', 'account', 'connection', 'rows');
        }

        app(TenantContext::class)->forget();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_tenants_sync_stores_only_under_its_own_tenant_and_its_own_connection_token(): void
    {
        DB::table('daily_metrics')->delete();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());
            if (! str_contains($url, '/insights')) {
                return Http::response(['data' => []], 200);
            }
            // The provider answers by the token it is shown: tenant A's grant sees 111, tenant B's grant sees 9.
            $spend = ($request->header('Authorization')[0] ?? '') === 'Bearer AT-A' ? '111' : '9';

            return Http::response(['data' => [['campaign_id' => 'cmp-X', 'date_start' => '2026-08-01', 'spend' => $spend, 'impressions' => '10', 'clicks' => '1']]], 200);
        });

        foreach (['A', 'B'] as $key) {
            app(AccountMetricsSyncer::class)->sync($this->side[$key]['account']->refresh(), Carbon::parse('2026-08-01'), Carbon::parse('2026-08-01'));
        }

        foreach (['A' => 111.0, 'B' => 9.0] as $key => $expected) {
            $rows = DB::table('daily_metrics')->where('metric_key', 'spend')->where('tenant_id', $this->side[$key]['tenant']->id)->get();
            $this->assertCount(1, $rows, "tenant {$key}");
            $this->assertEqualsWithDelta($expected, (float) $rows[0]->value, 0.001, "tenant {$key} stored the other tenant's figure");
            $this->assertSame($this->side[$key]['account']->id, $rows[0]->external_account_id);
            $this->assertSame($this->side[$key]['project']->id, $rows[0]->project_id);
            $own = ExternalCampaign::withoutGlobalScopes()->where('external_account_id', $this->side[$key]['account']->id)->firstOrFail();
            $this->assertSame($own->id, $rows[0]->external_campaign_id, "tenant {$key}'s figure is filed under the other tenant's campaign");
        }
    }

    public function test_every_read_surface_of_each_tenant_returns_only_that_tenants_rows(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['data' => []], 200)]);

        // Two days each: A 200, B 14.
        foreach (['A' => [200.0, 'MarkB'], 'B' => [14.0, 'MarkA']] as $key => [$expected, $foreign]) {
            $side = $this->side[$key];

            // A test process keeps the previous request's project context (a real request is its own process): start each tenant clean.
            app(ProjectContext::class)->forget();
            app(TenantContext::class)->forget();
            $client = $this->actingAs($side['user'], 'sanctum')
                ->getJson('/api/v1/app/clients/'.$side['project']->client_workspace_id.'/analytics?from=2026-08-01&to=2026-08-31')->assertOk()->json('data');
            $this->assertEqualsWithDelta($expected, (float) ($client['totals']['spend'] ?? -1), 0.001, "tenant {$key}: client analytics");

            $summary = $this->bwAsOperator($side['user'], $side['project'], 'metrics/summary?from=2026-08-01&to=2026-08-31');
            $this->assertEqualsWithDelta($expected, (float) ($summary['current']['spend'] ?? -1), 0.001, "tenant {$key}: project summary");

            $campaigns = $this->bwAsOperator($side['user'], $side['project'], 'metrics/campaigns?from=2026-08-01&to=2026-08-31');
            $this->assertCount(1, $campaigns, "tenant {$key}: campaign rows");
            $this->assertStringNotContainsString($foreign, (string) json_encode($campaigns), "tenant {$key} names the other tenant's campaign");

            foreach (['ads' => 'ad', 'creatives' => 'creative'] as $axis => $field) {
                $offered = array_column($this->bwAsOperator($side['user'], $side['project'], "reports/scope/options?axis={$axis}")[$axis] ?? [], 'id');
                $other = $this->side[$key === 'A' ? 'B' : 'A']['rows'][$field];
                $this->assertContains($side['rows'][$field], $offered);
                $this->assertNotContains($other, $offered, "tenant {$key}: the other tenant's {$axis} are offered");
            }

            // The public link and its files.
            $token = $this->bwLiveLink($side['project'], $side['user'], ['meta']);
            $page = $this->getJson("/api/v1/reports/shared/{$token}/live?from=2026-08-01&to=2026-08-31")->assertOk()->json('data');
            $this->assertEqualsWithDelta($expected, (float) ($page['totals']['spend'] ?? -1), 0.001, "tenant {$key}: live report");
            $this->assertStringNotContainsString($foreign, (string) json_encode($page));
            $csv = $this->get("/api/v1/reports/shared/{$token}/download/csv")->assertOk()->streamedContent();
            $this->assertMatchesRegularExpression('/^spend,'.preg_quote((string) (int) $expected, '/').'(\.0+)?$/m', $csv, "tenant {$key}: CSV");
            $this->assertStringNotContainsString($foreign, $csv);
        }
    }

    public function test_the_hub_and_the_estate_list_only_the_tenants_own_authorisation_and_account(): void
    {
        foreach (['A' => 'B', 'B' => 'A'] as $key => $otherKey) {
            $user = $this->side[$key]['user'];

            $hub = $this->actingAs($user, 'sanctum')->getJson('/api/v1/integrations/hub')->assertOk()->json('data');
            $ids = array_column($hub['connections'] ?? $hub, 'id');
            $this->assertContains((string) $this->side[$key]['connection']->id, $ids);
            $this->assertNotContains((string) $this->side[$otherKey]['connection']->id, $ids, "tenant {$key}'s hub lists tenant {$otherKey}'s authorisation");

            $estate = (string) json_encode($this->actingAs($user, 'sanctum')->getJson('/api/v1/estate')->assertOk()->json());
            $this->assertStringNotContainsString((string) $this->side[$otherKey]['account']->id, $estate, "tenant {$key}'s estate holds tenant {$otherKey}'s account row");
            $this->assertStringNotContainsString('Project'.$otherKey, $estate);
        }
    }

    public function test_one_tenant_cannot_read_the_other_tenants_project_or_files(): void
    {
        foreach (['A' => 'B', 'B' => 'A'] as $key => $otherKey) {
            $user = $this->side[$key]['user'];
            $theirs = $this->side[$otherKey]['project'];

            foreach (['metrics/summary?from=2026-08-01&to=2026-08-31', 'metrics/campaigns?from=2026-08-01&to=2026-08-31', 'integrations', 'reports/scope/options?axis=campaigns'] as $path) {
                $response = $this->actingAs($user, 'sanctum')->withHeader('X-Project-Id', $theirs->id)->getJson("/api/v1/projects/{$theirs->id}/{$path}");
                $this->flushHeaders();
                $this->assertContains($response->status(), [403, 404], "tenant {$key} read tenant {$otherKey}'s project at {$path} (got {$response->status()})");
                $this->assertStringNotContainsString('Mark'.$otherKey, (string) $response->getContent());
            }

            $client = $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients/'.$this->side[$otherKey]['project']->client_workspace_id.'/analytics?from=2026-08-01&to=2026-08-31');
            $this->assertContains($client->status(), [403, 404], "tenant {$key} read tenant {$otherKey}'s client analytics");
        }
    }
}
