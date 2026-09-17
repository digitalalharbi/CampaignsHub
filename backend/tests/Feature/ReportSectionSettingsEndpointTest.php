<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportScopeTemplate;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\ClientScopeResolver;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * REPORT-SECTION-MODEL-001 — section choices are saved per report and per template, and only an
 * operator allowed to manage reports can change them. Enforced by the backend, not by a hidden toggle.
 */
final class ReportSectionSettingsEndpointTest extends TestCase
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

        $this->tenant = Tenant::create(['name' => 'Sections', 'slug' => 'sections-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());

        $all = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Op', 'slug' => 'op-'.uniqid()]);
        $all->givePermissionTo(...Permission::pluck('key')->all());
        $readOnly = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'Viewer', 'slug' => 'v-'.uniqid()]);
        $readOnly->givePermissionTo('projects.view', 'projects.view.all', 'reports.view', ClientScopeResolver::ALL_CLIENTS);

        $this->operator = User::create(['name' => 'Op', 'email' => 'op@sections.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($all);

        $this->viewer = User::create(['name' => 'V', 'email' => 'v@sections.local', 'password' => 'secret123', 'email_verified_at' => now()]);
        $this->grantMembership($this->viewer, $this->tenant);
        $this->viewer->assignRole($readOnly);

        $client = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $client->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());
    }

    private function url(string $suffix): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports".$suffix;
    }

    private function report(string $audience = 'client', ?array $data = null): Report
    {
        return Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'R', 'type' => 'monthly', 'form' => 'detailed', 'audience' => $audience, 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
        ]);
    }

    public function test_the_registry_lists_every_section_with_its_defaults(): void
    {
        $sections = $this->actingAs($this->viewer, 'sanctum')->getJson($this->url('/sections'))->assertOk()->json('data.sections');

        $byKey = array_column($sections, null, 'key');
        $this->assertFalse($byKey['advanced_segmentation']['default_client']);
        $this->assertFalse($byKey['detailed_tables']['default_client']);
        $this->assertTrue($byKey['detailed_tables']['default_internal']);
        $this->assertTrue($byKey['kpis']['default_client']);
    }

    public function test_a_report_nobody_configured_resolves_to_the_client_defaults(): void
    {
        $report = $this->report();

        $data = $this->actingAs($this->viewer, 'sanctum')->getJson($this->url("/{$report->getKey()}/sections"))->assertOk()->json('data');

        $this->assertSame([], $data['chosen']);
        $this->assertFalse($data['effective']['advanced_segmentation']);
        $this->assertFalse($data['effective']['detailed_tables']);
        $this->assertTrue($data['effective']['budget_pacing']);
        $this->assertFalse($data['availability_judged'], 'No figures, so availability was not guessed.');
    }

    public function test_an_operator_saves_choices_and_they_persist_merged(): void
    {
        $report = $this->report();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/sections"), ['sections' => ['budget_pacing' => false]])
            ->assertOk();
        $data = $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/sections"), ['sections' => ['detailed_tables' => true]])
            ->assertOk()->json('data');

        // jsonb does not keep key order, so the comparison is by key.
        $this->assertEquals(['sections' => ['budget_pacing' => false, 'detailed_tables' => true]], $report->fresh()->section_settings);
        $this->assertFalse($data['effective']['budget_pacing']);
        $this->assertTrue($data['effective']['detailed_tables']);
        $this->assertNotContains('budget_pacing', $data['visible']);
    }

    public function test_the_preview_states_why_each_hidden_section_is_hidden(): void
    {
        $report = $this->report(data: [
            'kpis' => ['spend' => 100.0],
            'timeseries' => [['date' => '2026-08-01'], ['date' => '2026-08-02']],
            'platforms' => [['provider' => 'meta', 'spend' => 100.0]],
            'budget' => [],
            'ads' => [], 'ads_roster' => [], 'ads_absent_reason' => 'no_rankable_metric_for_this_objective',
        ]);
        $report->update(['section_settings' => ['sections' => ['trends' => false]]]);

        $resolved = collect($this->actingAs($this->viewer, 'sanctum')->getJson($this->url("/{$report->getKey()}/sections"))->assertOk()->json('data.resolved'))->keyBy('key');

        $this->assertSame('disabled_by_operator', $resolved['trends']['reason']);
        $this->assertSame('unsupported_by_provider_or_objective', $resolved['content_performance']['reason']);
        $this->assertSame('data_unavailable', $resolved['budget_pacing']['reason']);
        $this->assertTrue($resolved['kpis']['visible']);
    }

    public function test_an_unknown_section_is_refused(): void
    {
        $report = $this->report();

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/sections"), ['sections' => ['direct_vs_blended' => true]])
            ->assertUnprocessable();

        $this->assertNull($report->fresh()->section_settings);
    }

    public function test_a_viewer_can_read_but_not_change_sections(): void
    {
        $report = $this->report();
        $template = ReportScopeTemplate::create(['project_id' => $this->project->getKey(), 'name' => 'T', 'scope' => []]);

        $this->actingAs($this->viewer, 'sanctum')->getJson($this->url("/{$report->getKey()}/sections"))->assertOk();
        $this->actingAs($this->viewer, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/sections"), ['sections' => ['advanced_segmentation' => true]])
            ->assertForbidden();
        $this->actingAs($this->viewer, 'sanctum')
            ->putJson($this->url("/scope-templates/{$template->getKey()}/sections"), ['sections' => ['advanced_segmentation' => true]])
            ->assertForbidden();

        $this->assertNull($report->fresh()->section_settings);
        $this->assertNull($template->fresh()->section_settings);
    }

    public function test_a_template_saves_sections_and_a_report_can_start_from_them(): void
    {
        $template = ReportScopeTemplate::create(['project_id' => $this->project->getKey(), 'name' => 'T', 'scope' => []]);

        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/scope-templates/{$template->getKey()}/sections"), ['sections' => ['funnel' => false, 'detailed_tables' => true]])
            ->assertOk()
            ->assertJsonPath('data.effective.client.funnel', false)
            ->assertJsonPath('data.effective.client.detailed_tables', true);

        $report = $this->report();
        $this->actingAs($this->operator, 'sanctum')
            ->putJson($this->url("/{$report->getKey()}/sections"), ['template_id' => $template->getKey(), 'sections' => ['trends' => false]])
            ->assertOk();

        $this->assertEquals(
            ['sections' => ['funnel' => false, 'detailed_tables' => true, 'trends' => false]],
            $report->fresh()->section_settings,
        );
    }

    public function test_an_internal_report_defaults_differ_from_a_client_one(): void
    {
        $report = $this->report('internal');

        $this->actingAs($this->viewer, 'sanctum')->getJson($this->url("/{$report->getKey()}/sections"))
            ->assertOk()
            ->assertJsonPath('data.effective.advanced_segmentation', true)
            ->assertJsonPath('data.effective.detailed_tables', true);
    }
}
