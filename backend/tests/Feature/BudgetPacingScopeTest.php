<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Services\MetricsAggregator;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * CAMPAIGN-BUDGET-TRUTH-001 — budget pacing names only the campaigns of the scope it was asked for.
 *
 * The budgeted-campaign set is read with `DB::table`, which bypasses `ProjectScope`. The multi-project
 * read bounded it by its project list; the single-project read — the campaigns page — bounded it by
 * nothing, so one project's budget total carried every budgeted campaign in the table. Observed on
 * the preview as 795K SAR of budget on a project whose own campaigns hold 305K.
 */
final class BudgetPacingScopeTest extends TestCase
{
    use RefreshDatabase;

    private Project $mine;

    private Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);
        $a = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'A', 'slug' => 'a-'.uniqid(), 'mode' => 'managed']);
        $b = ClientWorkspace::create(['tenant_id' => $tenant->id, 'name' => 'B', 'slug' => 'b-'.uniqid(), 'mode' => 'managed']);
        $this->mine = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $a->id, 'name' => 'Mine', 'status' => 'active']);
        $this->theirs = Project::create(['tenant_id' => $tenant->id, 'client_workspace_id' => $b->id, 'name' => 'Theirs', 'status' => 'active']);

        $this->campaign($this->mine, 'Mine — launch', 300_000);
        $this->campaign($this->theirs, 'Theirs — sale', 490_000);
    }

    private function campaign(Project $project, string $name, float $budget): UnifiedCampaign
    {
        return UnifiedCampaign::create([
            'tenant_id' => $project->tenant_id, 'client_workspace_id' => $project->client_workspace_id,
            'project_id' => $project->id, 'name' => $name, 'objective' => 'sales',
            'status' => 'active', 'total_budget' => $budget, 'budget_currency' => 'SAR',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function pacing(MetricsAggregator $agg): array
    {
        return $agg->budgetPacing(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), Carbon::parse('2026-07-15'));
    }

    /** The campaigns page's read: the active project, no explicit list. This is the path that leaked. */
    public function test_the_single_project_read_names_only_the_active_projects_campaigns(): void
    {
        app(ProjectContext::class)->setProjectId((string) $this->mine->id);

        $rows = $this->pacing(app(MetricsAggregator::class));

        $this->assertSame(['Mine — launch'], array_column($rows, 'campaign_name'));
        $this->assertSame(300_000.0, (float) $rows[0]['budget']);
    }

    public function test_an_explicit_project_list_is_still_its_own_bound(): void
    {
        $rows = $this->pacing(app(MetricsAggregator::class)->forProjects([$this->theirs->id]));

        $this->assertSame(['Theirs — sale'], array_column($rows, 'campaign_name'));
    }

    public function test_a_campaign_read_names_that_campaign_alone(): void
    {
        app(ProjectContext::class)->setProjectId((string) $this->mine->id);
        $second = $this->campaign($this->mine, 'Mine — always on', 10_000);

        $rows = $this->pacing(app(MetricsAggregator::class)->forCampaign((string) $second->id));

        $this->assertSame(['Mine — always on'], array_column($rows, 'campaign_name'));
    }

    /** No project to state and no list given: nothing is named, because «cannot say» is not «all». */
    public function test_a_read_with_no_statable_scope_names_nothing(): void
    {
        app(ProjectContext::class)->forget();

        $this->assertSame([], $this->pacing(app(MetricsAggregator::class)));
    }
}
