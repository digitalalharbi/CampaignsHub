<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Metrics\Actions\UpsertDailyMetrics;
use App\Domains\Metrics\DTO\NormalizedMetric;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportExportJob;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportExport;
use App\Domains\Reports\Services\ReportExporter;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

/** Reports: queued generation from real metrics, real file exports + signed download, RBAC, isolation. */
final class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private Project $projectA;

    private Project $projectB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->tenant = Tenant::create(['name' => 'A', 'slug' => 'a', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $owner = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'slug' => 'o']);
        $owner->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['tenant_id' => $this->tenant->id, 'name' => 'O', 'email' => 'o@a.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($owner);
        $ws = ClientWorkspace::create(['name' => 'C', 'slug' => 'c', 'mode' => 'managed']);
        $this->projectA = Project::create(['client_workspace_id' => $ws->id, 'name' => 'A', 'status' => 'active']);
        $this->projectB = Project::create(['client_workspace_id' => $ws->id, 'name' => 'B', 'status' => 'active']);
        app(TenantContext::class)->forget();

        $uid = fn (string $s) => (string) Uuid::uuid5(Uuid::NAMESPACE_DNS, $s);
        $m = fn (string $k, float $v) => new NormalizedMetric(
            tenantId: $this->tenant->id, projectId: $this->projectA->id, externalAccountId: $uid('acc'),
            externalCampaignId: $uid('camp'), provider: 'meta', metricKey: $k, metricDate: Carbon::parse('2026-06-15'), value: $v,
        );
        app(UpsertDailyMetrics::class)->handle([
            $m('impressions', 1000), $m('clicks', 50), $m('conversions', 10), $m('spend', 100), $m('revenue', 400),
        ]);
    }

    private function report(array $over = []): Report
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $r = Report::create(array_merge([
            'project_id' => $this->projectA->id, 'name' => 'R', 'type' => 'executive', 'status' => 'draft',
            'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'currency' => 'SAR',
        ], $over));
        app(TenantContext::class)->forget();

        return $r;
    }

    public function test_create_report_queues_generation(): void
    {
        Queue::fake();
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/reports", ['name' => 'Weekly', 'type' => 'weekly'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'processing');
        Queue::assertPushed(GenerateReportJob::class);
    }

    public function test_generation_snapshots_real_metrics(): void
    {
        $report = $this->report();
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        $report->refresh();
        $this->assertSame('completed', $report->status);
        $this->assertEquals(4.0, $report->data['kpis']['roas']); // 400 / 100
        $this->assertNotEmpty($report->data['summary']);
    }

    public function test_export_produces_downloadable_file(): void
    {
        Storage::fake('local');
        $report = $this->report();
        (new GenerateReportJob((string) $report->id))->handle(app(ReportGenerator::class));

        app(TenantContext::class)->setTenantId($this->tenant->id);
        $export = ReportExport::create(['report_id' => $report->id, 'format' => 'csv', 'status' => 'processing']);
        app(TenantContext::class)->forget();
        (new GenerateReportExportJob((string) $export->id))->handle(app(ReportExporter::class));

        $export->refresh();
        $this->assertSame('completed', $export->status);
        $this->assertNotNull($export->signed_token);
        Storage::disk('local')->assertExists($export->path);

        // Public signed download works; a bad token 404s.
        $this->get("/api/v1/reports/download/{$export->signed_token}")->assertOk();
        $this->get('/api/v1/reports/download/nope')->assertNotFound();
    }

    public function test_export_requires_export_permission(): void
    {
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'slug' => 'v']);
        $role->givePermissionTo('projects.view', 'projects.view.all', 'reports.view'); // no reports.export
        $viewer = User::create(['tenant_id' => $this->tenant->id, 'name' => 'V', 'email' => 'v@a.test', 'password' => 'secret123']);
        $this->grantMembership($viewer, $this->tenant);
        $viewer->assignRole($role);
        app(TenantContext::class)->forget();
        $report = $this->report(['status' => 'completed', 'data' => ['kpis' => []]]);

        $this->actingAs($viewer, 'sanctum')
            ->postJson("/api/v1/projects/{$this->projectA->id}/reports/{$report->id}/export", ['format' => 'pdf'])
            ->assertForbidden();
    }

    public function test_demo_remove_deletes_only_demo_reports(): void
    {
        Tenant::create(['name' => 'Demo', 'slug' => 'demo-agency', 'status' => 'active']);
        $demo = $this->report(['name' => 'Demo R', 'is_demo' => true]);
        $real = $this->report(['name' => 'Real R', 'is_demo' => false]);

        $this->artisan('demo:remove')->assertSuccessful();

        $this->assertDatabaseMissing('reports', ['id' => $demo->id]);
        $this->assertDatabaseHas('reports', ['id' => $real->id]); // real data untouched
    }

    public function test_reports_isolated_per_project(): void
    {
        $this->report(['name' => 'In A']);
        $this->report(['project_id' => $this->projectB->id, 'name' => 'In B']);

        $names = $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/projects/{$this->projectA->id}/reports")
            ->assertOk()->json('data.reports.*.name');
        $this->assertContains('In A', $names);
        $this->assertNotContains('In B', $names);
    }
}
