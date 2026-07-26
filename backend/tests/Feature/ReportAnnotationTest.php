<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportAnnotation;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Recommendation approval lifecycle: only approved reaches a client; transitions gated + audited. */
final class ReportAnnotationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $project;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->owner->assignRole($role);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->project = Project::create(['client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        $this->report = Report::create([
            'project_id' => $this->project->id, 'name' => 'R', 'type' => 'executive', 'audience' => 'client',
            'status' => 'completed', 'currency' => 'SAR', 'data' => ['currency' => 'SAR', 'kpis' => ['spend' => 100]],
        ]);
    }

    private function annotation(string $status): ReportAnnotation
    {
        return ReportAnnotation::create([
            'tenant_id' => $this->tenant->id, 'report_id' => $this->report->id, 'annotation_id' => 'aid123',
            'type' => 'recommendation', 'text_ar' => 'زيادة الميزانية', 'status' => $status, 'is_ai_generated' => true,
        ]);
    }

    public function test_approve_transition_stamps_approver_and_regenerates(): void
    {
        Sanctum::actingAs($this->owner);
        $ann = $this->annotation('draft');

        Queue::fake();
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/annotations/{$ann->id}/status", ['status' => 'approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $ann->refresh();
        $this->assertSame('approved', $ann->status);
        $this->assertEquals($this->owner->id, $ann->approved_by);
        $this->assertNotNull($ann->approved_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.annotation.approved']);
        // Report is regenerated so the client snapshot reflects the approval.
        Queue::assertPushed(GenerateReportJob::class);
    }

    public function test_approval_requires_permission(): void
    {
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('reports.view', 'projects.view', 'projects.view.all');
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $viewer->assignRole($role);
        Sanctum::actingAs($viewer);
        $ann = $this->annotation('draft');

        // Viewer can list but not approve.
        $this->getJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/annotations")->assertOk();
        $this->postJson("/api/v1/projects/{$this->project->id}/reports/{$this->report->id}/annotations/{$ann->id}/status", ['status' => 'approved'])
            ->assertForbidden();
    }
}
