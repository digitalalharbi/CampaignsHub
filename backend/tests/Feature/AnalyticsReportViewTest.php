<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Analytics\Support\AnalyticsReadCache;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Models\DailyMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\LiveReportService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ANALYTICS-REPORT-LOGIC-001 — the board reads the report's own payload, inside one workspace only.
 *
 * Three claims: the board's figures ARE the report's (same builder, compared against a stored share), a campaign
 * filter narrows by what the project owns and nothing else, and a project of another workspace is refused outright —
 * which is what keeps «مشروع مقابل مشروع» inside one workspace.
 */
final class AnalyticsReportViewTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    private User $owner;

    private User $stranger;

    private Project $project;

    private Project $sibling;

    private Project $foreign;

    private UnifiedCampaign $one;

    private UnifiedCampaign $two;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'S', 'slug' => 's-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->id);
        $this->owner = $this->userIn($this->tenant, 'owner');
        $this->project = $this->projectIn($this->tenant, 'P');
        $this->sibling = $this->projectIn($this->tenant, 'Q');

        $this->other = Tenant::create(['name' => 'T2', 'slug' => 't2-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->other->id);
        $this->stranger = $this->userIn($this->other, 'stranger');
        $this->foreign = $this->projectIn($this->other, 'R');

        $this->holdingTenant((string) $this->tenant->id);
        $this->one = $this->campaign($this->project, 'One');
        $this->two = $this->campaign($this->project, 'Two');
        $this->row($this->one, 'meta', 'spend', 1_000.0, 2);
        $this->row($this->one, 'meta', 'clicks', 400.0, 2);
        $this->row($this->one, 'meta', 'impressions', 20_000.0, 2);
        $this->row($this->two, 'snapchat', 'spend', 600.0, 3);
        $this->row($this->two, 'snapchat', 'clicks', 150.0, 3);
        $this->row($this->two, 'snapchat', 'impressions', 9_000.0, 3);
        app(TenantContext::class)->forget();
    }

    public function test_the_board_payload_carries_the_same_figures_as_the_stored_report_builder(): void
    {
        $board = $this->read($this->project, $this->query());

        $this->holdingTenant((string) $this->tenant->id);
        $report = Report::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(), 'name' => 'R', 'type' => 'live', 'status' => 'completed',
            'period_start' => Carbon::today()->subDays(10)->toDateString(), 'period_end' => Carbon::today()->toDateString(), 'currency' => 'SAR',
            'config' => ['live' => true, 'metrics' => [], 'period_kind' => 'custom', 'period_mode' => 'fixed'],
        ]);
        $share = ReportShare::create([
            'tenant_id' => $this->tenant->getKey(), 'report_id' => $report->getKey(), 'mode' => 'live', 'token_hash' => hash('sha256', 'x'.uniqid()),
            'hide_spend' => false, 'scope' => [
                'project_id' => (string) $this->project->getKey(), 'campaign_ids' => [(string) $this->one->getKey(), (string) $this->two->getKey()],
                'earliest' => Carbon::today()->subDays(10)->toDateString(), 'latest' => Carbon::today()->toDateString(),
            ],
        ]);
        $report->unsetRelation('share');
        $stored = app(LiveReportService::class)->build($share, ['from' => Carbon::today()->subDays(10)->toDateString(), 'to' => Carbon::today()->toDateString()], 'SAR');
        app(TenantContext::class)->forget();

        foreach (['spend', 'clicks', 'impressions'] as $key) {
            $this->assertEquals($stored['totals'][$key], $board['totals'][$key], "the board's {$key} differs from the report's");
        }
        $this->assertEquals(1_600.0, $board['totals']['spend']);
        $this->assertSame(
            collect($stored['platforms'])->pluck('provider')->sort()->values()->all(),
            collect($board['platforms'])->pluck('provider')->sort()->values()->all(),
        );
    }

    public function test_a_platform_and_a_campaign_filter_narrow_the_same_payload(): void
    {
        $meta = $this->read($this->project, $this->query().'&providers[]=meta');
        $this->assertEquals(1_000.0, $meta['totals']['spend']);

        $two = $this->read($this->project, $this->query().'&campaigns[]='.$this->two->id);
        $this->assertEquals(600.0, $two['totals']['spend']);

        // Both axes: Meta never ran campaign Two, so the intersection is empty — an empty report, not the whole project.
        $none = $this->read($this->project, $this->query().'&providers[]=meta&campaigns[]='.$this->two->id);
        $this->assertEquals(0.0, (float) ($none['totals']['spend'] ?? 0));
    }

    public function test_a_campaign_of_another_project_never_enters_the_ceiling(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $elsewhere = $this->campaign($this->sibling, 'Elsewhere');
        $this->row($elsewhere, 'meta', 'spend', 5_000.0, 2);
        app(TenantContext::class)->forget();

        $payload = $this->read($this->project, $this->query().'&campaigns[]='.$elsewhere->id);
        $this->assertEquals(0.0, (float) ($payload['totals']['spend'] ?? 0));
    }

    public function test_two_projects_of_one_workspace_each_answer_for_themselves(): void
    {
        $this->holdingTenant((string) $this->tenant->id);
        $other = $this->campaign($this->sibling, 'Q-camp');
        $this->row($other, 'meta', 'spend', 250.0, 2);
        app(TenantContext::class)->forget();

        $this->assertEquals(1_600.0, $this->read($this->project, $this->query())['totals']['spend']);
        $this->assertEquals(250.0, $this->read($this->sibling, $this->query())['totals']['spend']);
    }

    public function test_a_project_of_another_workspace_is_refused_for_comparison(): void
    {
        $this->app->forgetScopedInstances();
        $response = $this->actingAs($this->owner, 'sanctum')->getJson($this->url($this->foreign, $this->query()));
        $this->assertContains($response->status(), [403, 404], 'a project of another workspace must never answer');
        $this->assertArrayNotHasKey('totals', (array) $response->json('data'));

        $this->app->forgetScopedInstances();
        $reverse = $this->actingAs($this->stranger, 'sanctum')->getJson($this->url($this->project, $this->query()));
        $this->assertContains($reverse->status(), [403, 404]);
    }

    public function test_the_cache_is_filed_per_tenant_and_per_project(): void
    {
        $a = AnalyticsReadCache::key('report-view', (string) $this->tenant->id, (string) $this->project->id, 'v');
        $b = AnalyticsReadCache::key('report-view', (string) $this->tenant->id, (string) $this->sibling->id, 'v');
        $c = AnalyticsReadCache::key('report-view', (string) $this->other->id, (string) $this->project->id, 'v');
        $this->assertCount(3, array_unique([$a, $b, $c]));

        $first = $this->read($this->project, $this->query());
        $this->assertEquals($first['totals'], $this->read($this->project, $this->query())['totals'], 'a repeat open answers the same, from the cache');
        $this->assertEquals(0.0, (float) ($this->read($this->sibling, $this->query())['totals']['spend'] ?? 0), 'a second project never reads the first one\'s entry');
    }

    private function query(): string
    {
        return 'from='.Carbon::today()->subDays(10)->toDateString().'&to='.Carbon::today()->toDateString();
    }

    private function url(Project $project, string $query): string
    {
        return '/api/v1/projects/'.$project->id.'/analytics/report-view?'.$query;
    }

    /** @return array<string, mixed> */
    private function read(Project $project, string $query): array
    {
        $this->app->forgetScopedInstances();

        return $this->actingAs($this->owner, 'sanctum')->getJson($this->url($project, $query))->assertOk()->json('data');
    }

    private function projectIn(Tenant $tenant, string $name): Project
    {
        $this->holdingTenant((string) $tenant->id);
        $workspace = ClientWorkspace::create(['name' => 'W'.$name, 'slug' => 'w-'.uniqid(), 'mode' => 'managed']);

        return Project::create(['client_workspace_id' => $workspace->id, 'name' => $name, 'status' => 'active']);
    }

    private function campaign(Project $project, string $name): UnifiedCampaign
    {
        return UnifiedCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $project->tenant_id, 'project_id' => $project->id, 'client_workspace_id' => $project->client_workspace_id,
            'name' => $name, 'objective' => 'sales', 'status' => 'active',
        ]);
    }

    private function row(UnifiedCampaign $campaign, string $provider, string $key, float $value, int $daysAgo): void
    {
        DailyMetric::withoutGlobalScopes()->create([
            'tenant_id' => $campaign->tenant_id, 'project_id' => $campaign->project_id, 'unified_campaign_id' => $campaign->getKey(),
            'external_account_id' => (string) Str::uuid(), 'external_campaign_id' => (string) Str::uuid(), 'provider' => $provider,
            'metric_key' => $key, 'metric_date' => Carbon::today()->subDays($daysAgo)->toDateString(), 'value' => $value,
            'original_amount' => $key === 'spend' ? $value : null, 'original_currency' => 'SAR', 'project_currency' => 'SAR', 'exchange_rate' => 1,
        ])->forceFill(['is_demo' => false])->saveQuietly();
    }

    private function userIn(Tenant $tenant, string $slug): User
    {
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => $slug, 'slug' => $slug]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user = User::create(['name' => $slug, 'email' => $slug.'-'.uniqid().'@s.test', 'password' => 'secret123']);
        $this->grantMembership($user, $tenant);
        $user->assignRole($role);

        return $user;
    }
}
