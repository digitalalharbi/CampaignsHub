<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coordinator decision — per-link switches stay, as an OFF-ONLY restriction, rendered from the same
 * registry as the report's. A link can hide a section the report shows (an executive link with no
 * drill-down); it can never show a section the report hides, and it says «hidden by the report».
 */
final class ShareSectionOverridesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $operator;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Links', 'slug' => 'links-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $all = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $all->givePermissionTo(...Permission::pluck('key')->all());
        $read = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'V', 'slug' => 'v-'.uniqid()]);
        $read->givePermissionTo('projects.view', 'projects.view.all', 'reports.view', ClientScopeResolver::ALL_CLIENTS);

        $this->operator = User::create(['name' => 'Op', 'email' => 'op@links.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($all);
        $this->viewer = User::create(['name' => 'V', 'email' => 'v@links.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->viewer, $this->tenant);
        $this->viewer->assignRole($read);

        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
    }

    /** @return array{0: Report, 1: ReportShare, 2: string} */
    private function reportWithLink(): array
    {
        $data = [
            'kpis' => ['spend' => 100.0],
            'timeseries' => [['date' => '2026-08-01', 'spend' => 50.0], ['date' => '2026-08-02', 'spend' => 50.0]],
            'platforms' => [['provider' => 'meta', 'spend' => 100.0]],
            'budget' => [['provider' => 'meta', 'budget' => 500.0, 'spent' => 100.0]],
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
            'section_settings' => ['sections' => ['trends' => false]],
        ]);
        [$share, $raw] = app(ShareService::class)->create($report, [], null);

        return [$report, $share, $raw];
    }

    private function url(Report $report, ReportShare $share): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/shares/{$share->getKey()}/sections";
    }

    public function test_the_link_list_is_the_registry_and_says_what_the_report_already_hides(): void
    {
        [$report, $share] = $this->reportWithLink();

        $rows = collect($this->actingAs($this->viewer, 'sanctum')->getJson($this->url($report, $share))->assertOk()->json('data.sections'))->keyBy('key');

        $this->assertSame(
            ['kpis', 'trends', 'platform_comparison', 'budget_pacing', 'funnel', 'content_performance', 'recommendations', 'detailed_tables', 'objective_breakdown', 'advanced_segmentation'],
            $rows->keys()->all(),
        );
        $this->assertSame('hidden_by_report', $rows['trends']['state']);
        $this->assertSame('hidden_by_report', $rows['detailed_tables']['state'], 'a client report hides the tables by default');
        $this->assertSame('shown', $rows['budget_pacing']['state']);
    }

    public function test_a_link_hides_a_section_the_report_shows_and_every_link_surface_obeys(): void
    {
        [$report, $share, $raw] = $this->reportWithLink();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url($report, $share), ['sections' => ['budget_pacing' => false]])
            ->assertOk()
            ->assertJsonPath('data.sections.3.state', 'hidden_by_link');

        $shared = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');
        $this->assertNotContains('budget_pacing', $shared['report_sections']);
        $this->assertArrayNotHasKey('budget', $shared);

        // The report itself, and its other surfaces, are untouched by one link's restriction.
        $this->assertContains('budget_pacing', $this->actingAs($this->viewer, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections")->json('data.visible'));
    }

    public function test_a_link_cannot_show_what_the_report_hides(): void
    {
        [$report, $share, $raw] = $this->reportWithLink();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url($report, $share), ['sections' => ['trends' => true, 'detailed_tables' => true]])
            ->assertOk();

        $shared = $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data');
        $this->assertNotContains('trends', $shared['report_sections']);
        $this->assertNotContains('detailed_tables', $shared['report_sections']);
    }

    public function test_switching_a_link_section_back_on_clears_the_older_flag_too(): void
    {
        [$report, $share, $raw] = $this->reportWithLink();
        $share->settings = ['sections' => ['budget' => false]];
        $share->save();

        $rows = collect($this->actingAs($this->viewer, 'sanctum')->getJson($this->url($report, $share))->json('data.sections'))->keyBy('key');
        $this->assertSame('hidden_by_link', $rows['budget_pacing']['state'], 'the older per-link flag reads in the same list');

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url($report, $share), ['sections' => ['budget_pacing' => true]])
            ->assertOk();

        $this->assertContains('budget_pacing', $this->getJson("/api/v1/reports/shared/{$raw}")->json('data.data.report_sections'));
    }

    public function test_only_a_report_manager_changes_a_link_and_unknown_sections_are_refused(): void
    {
        [$report, $share] = $this->reportWithLink();

        $this->actingAs($this->viewer, 'sanctum')
            ->putJson($this->url($report, $share), ['sections' => ['budget_pacing' => false]])
            ->assertForbidden();
        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url($report, $share), ['sections' => ['campaigns' => false]])
            ->assertUnprocessable();

        $this->assertArrayNotHasKey('section_overrides', (array) $share->fresh()->settings);
    }

    public function test_a_link_of_another_report_is_not_reachable_through_this_one(): void
    {
        [$report] = $this->reportWithLink();
        [, $otherShare] = $this->reportWithLink();

        $this->actingAs($this->operator, 'sanctum')
            ->getJson($this->url($report, $otherShare))
            ->assertNotFound();
    }

    /** The live-link builder sends its switches in the same vocabulary, and they are stored — not a placebo. */
    public function test_the_live_link_builder_stores_its_section_switches(): void
    {
        UnifiedCampaign::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'C1', 'status' => 'active', 'objective' => 'sales',
        ]);
        $url = "/api/v1/projects/{$this->project->getKey()}/reports/live";
        $body = ['name' => 'Exec link', 'from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString(), 'providers' => ['meta']];

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $body + ['section_overrides' => ['campaign_names']])
            ->assertUnprocessable();

        $id = $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $body + ['section_overrides' => ['content_performance', 'detailed_tables']])
            ->assertCreated()->json('data.share_id');

        $this->assertSame(['content_performance', 'detailed_tables'], ReportShare::withoutGlobalScopes()->findOrFail($id)->sectionOverrides());
    }
}
