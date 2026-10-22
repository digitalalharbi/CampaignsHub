<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Sections\ReportSectionSurfaces;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\SeedsReportAccount;
use Tests\TestCase;

/**
 * REPORT-TABLES-OWNER-COLUMNS-001 — the SAVED report carries the Owner's column choice itself.
 *
 * A link stores the columns it shows per table; a snapshot had no link to carry the choice, so its
 * page, its PDF and its files were the surfaces the Owner could not restrict. The report now keeps
 * the choice in `config['columns']` (cut to the known tables and keys on the way in), its page
 * payload attaches `report_columns` from it, and every surface built through
 * `ReportSectionSurfaces::apply` falls back to it when the link states no choice of its own.
 */
final class ReportColumnsSavedTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReportAccount;

    private Tenant $agency;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create(['name' => 'Agency', 'slug' => 'ag-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => uniqid().'@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->agency);
        $this->owner->assignRole($role);
        $client = ClientWorkspace::create(['name' => 'Client', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $client->id, 'name' => 'P', 'status' => 'active']);
        app(TenantContext::class)->forget();
    }

    public function test_the_choice_is_cut_to_the_known_tables_and_keys_on_creation_and_travels_with_the_page(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", [
                'name' => 'R', 'type' => 'executive', 'scope' => $this->reportScopeFor((string) $this->project->id),
                'config' => ['columns' => [
                    'platforms' => ['results', 'spend', 'bogus'],
                    'days' => ['clicks'],
                    'nonsense' => ['spend'],
                ]],
            ])->assertCreated()->json('data.id');

        $report = Report::withoutGlobalScopes()->findOrFail($id);
        // Known tables only, known keys only, in the table's own order (jsonb does not keep the table order, so equals, not same).
        $this->assertEquals(['platforms' => ['spend', 'results'], 'days' => ['clicks']], $report->config['columns']);

        $page = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/reports/{$id}")->assertOk()->json('data');
        $this->assertEquals(['platforms' => ['spend', 'results'], 'days' => ['clicks']], $page['data']['report_columns']);
    }

    /** A choice naming no known table restricts nothing and is not stored, so an untouched report stays exactly as before. */
    public function test_an_empty_choice_is_not_stored_and_an_edit_is_cut_the_same_way(): void
    {
        $id = $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/reports", [
                'name' => 'R', 'type' => 'executive', 'scope' => $this->reportScopeFor((string) $this->project->id),
                'config' => ['columns' => ['nonsense' => ['spend']]],
            ])->assertCreated()->json('data.id');
        $this->assertArrayNotHasKey('columns', Report::withoutGlobalScopes()->findOrFail($id)->config ?? []);

        $this->actingAs($this->owner, 'sanctum')
            ->patchJson("/api/v1/projects/{$this->project->id}/reports/{$id}", ['config' => ['columns' => ['accounts' => ['cost', 'platform', 'x']]]])
            ->assertOk();
        $this->assertSame(['accounts' => ['platform', 'cost']], Report::withoutGlobalScopes()->findOrFail($id)->config['columns']);
    }

    /** Every surface built through the resolver reads the report's choice when the link states none; a link's own choice wins. */
    public function test_the_surfaces_fall_back_to_the_report_choice_and_a_link_choice_wins(): void
    {
        app(TenantContext::class)->setTenantId((string) $this->agency->id);
        $report = Report::create([
            'tenant_id' => $this->agency->id, 'project_id' => $this->project->id,
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
            'config' => ['columns' => ['platforms' => ['spend']]],
            'data' => ['period' => ['from' => '2026-08-01', 'to' => '2026-08-31'], 'currency' => 'SAR', 'kpis' => [], 'platforms' => [], 'slides' => []],
        ]);

        $surfaces = app(ReportSectionSurfaces::class);
        $this->assertSame(['platforms' => ['spend']], $surfaces->apply($report->data, $report, null, 'print')['report_columns']);

        [$silent] = app(ShareService::class)->create($report, ['allow_download' => true], null);
        $this->assertSame(['platforms' => ['spend']], $surfaces->apply($report->data, $report, $silent, 'page')['report_columns']);

        [$stated] = app(ShareService::class)->create($report, ['allow_download' => true, 'settings' => ['columns' => ['platforms' => ['results']]]], null);
        $this->assertSame(['platforms' => ['results']], $surfaces->apply($report->data, $report, $stated, 'page')['report_columns']);
    }
}
