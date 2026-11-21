<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Audit\Models\AuditLog;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ExportReadinessGate;
use App\Domains\Reports\Services\ReportGenerator;
use App\Domains\Reports\Services\ReportVersions;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * REPORT-SNAPSHOT-PROTECT-001 — a stored copy of a report is never lost.
 *
 * Every path that rebuilds a report goes through `GenerateReportJob`, and it replaced `data` with nothing kept. So the protection
 * lives in the job, whichever path asked, and the row's regenerate button asks before it replaces anything.
 */
final class ReportSnapshotProtectionTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Keep', 'slug' => 'keep-'.uniqid(), 'status' => 'active']);
        $this->holdingTenant((string) $this->tenant->getKey());
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed']);
        $this->project = Project::create(['tenant_id' => $this->tenant->getKey(), 'client_workspace_id' => $ws->getKey(), 'name' => 'P', 'status' => 'active']);
        app(ProjectContext::class)->setProjectId((string) $this->project->getKey());

        $this->owner = $this->userWith(Permission::pluck('key')->all(), 'owner');
    }

    /** @param  list<string>  $permissions */
    private function userWith(array $permissions, string $slug): User
    {
        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => $slug, 'slug' => $slug.'-'.uniqid()]);
        $role->givePermissionTo(...array_unique(array_merge($permissions, ['projects.view', 'projects.view.all'])));
        $user = User::create(['name' => $slug, 'email' => $slug.'-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($user, $this->tenant);
        $user->assignRole($role);

        return $user;
    }

    private function report(array $over = []): Report
    {
        $data = [
            'period' => ['from' => '2026-08-01', 'to' => '2026-08-31'], 'currency' => 'SAR', 'objective' => 'sales',
            'kpis' => ['spend' => 1200.0, 'conversions' => 30.0, 'impressions' => 90000.0, 'clicks' => 900.0],
            'marker' => 'the copy the client saw',
        ];
        $data['checksum'] = ExportReadinessGate::checksum($data);

        return Report::create($over + [
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'completed',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR', 'data' => $data,
            'generated_at' => '2026-09-01 10:00:00',
        ]);
    }

    private function as(User $user): self
    {
        $this->actingAs($user, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        return $this;
    }

    private function url(Report $report, string $suffix = 'regenerate'): string
    {
        return "/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/{$suffix}";
    }

    private function versionsOf(Report $report): array
    {
        return ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->orderBy('generated_at')->get()->all();
    }

    public function test_regenerating_a_completed_report_without_confirming_changes_nothing(): void
    {
        Queue::fake();
        $report = $this->report();

        $this->as($this->owner)->postJson($this->url($report))
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'report_regenerate_needs_confirmation');

        $fresh = $report->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('the copy the client saw', $fresh->data['marker']);
        $this->assertSame([], $this->versionsOf($report));
        Queue::assertNothingPushed();
    }

    public function test_a_report_that_never_completed_regenerates_without_asking(): void
    {
        Queue::fake();
        $report = $this->report(['status' => 'failed', 'data' => null, 'generated_at' => null]);

        $this->as($this->owner)->postJson($this->url($report))->assertOk();

        Queue::assertPushed(GenerateReportJob::class);
    }

    public function test_a_confirmed_regenerate_keeps_the_original_and_records_who_did_it(): void
    {
        $report = $this->report();
        $original = $report->data;

        $this->as($this->owner)->postJson($this->url($report), ['confirm' => true])->assertOk();

        $versions = $this->versionsOf($report);
        $this->assertCount(1, $versions);
        $this->assertEquals($original, $versions[0]->data, 'the stored copy was not kept as it was');
        $this->assertSame('2026-09-01 10:00:00', $versions[0]->generated_at->format('Y-m-d H:i:s'));
        $this->assertNotSame('the copy the client saw', $report->fresh()->data['marker'] ?? null, 'the fixture never reached the generator');

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'report.regenerated')->where('entity_id', (string) $report->getKey())->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $this->owner->id, (string) $audit->user_id);
    }

    public function test_every_path_through_the_job_keeps_the_copy_and_a_retry_does_not_duplicate_it(): void
    {
        $report = $this->report();
        $original = $report->data;

        (new GenerateReportJob((string) $report->getKey()))->handle(app(ReportGenerator::class));

        $versions = $this->versionsOf($report);
        $this->assertCount(1, $versions);
        $this->assertEquals($original, $versions[0]->data);

        // The rebuilt copy is archived once, however often the job is retried for the same document.
        $rebuilt = $report->fresh();
        app(ReportVersions::class)->keep($rebuilt);
        app(ReportVersions::class)->keep($rebuilt);
        $this->assertCount(2, $this->versionsOf($report));
    }

    public function test_a_failed_rebuild_leaves_the_copy_and_the_link_as_they_were(): void
    {
        $report = $this->report();
        [, $raw] = app(ShareService::class)->create($report, [], null);
        $report->update(['status' => 'processing']);

        // While it is being rebuilt, the stored copy keeps answering.
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->assertJsonPath('data.data.marker', 'the copy the client saw');

        (new GenerateReportJob((string) $report->getKey()))->failed(new RuntimeException('provider down'));

        $fresh = $report->fresh();
        $this->assertSame('completed', $fresh->status, 'a failed rebuild took the stored copy offline');
        $this->assertSame('provider down', $fresh->error);
        $this->assertSame('the copy the client saw', $fresh->data['marker']);
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk();
    }

    public function test_a_report_with_no_stored_copy_still_fails_as_failed(): void
    {
        $report = $this->report(['status' => 'processing', 'data' => null, 'generated_at' => null]);

        (new GenerateReportJob((string) $report->getKey()))->failed(new RuntimeException('provider down'));

        $this->assertSame('failed', $report->fresh()->status);
    }

    public function test_converting_a_link_leaves_the_stored_copy_and_its_history_alone(): void
    {
        $report = $this->report();
        $original = $report->data;
        [$share] = app(ShareService::class)->create($report, [], null);

        foreach (['live', 'snapshot'] as $to) {
            $this->as($this->owner)
                ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/shares/{$share->getKey()}/convert", ['to' => $to, 'confirm' => true])
                ->assertOk();
        }

        $fresh = $report->fresh();
        $this->assertEquals($original, $fresh->data);
        $this->assertSame('2026-09-01 10:00:00', $fresh->generated_at->format('Y-m-d H:i:s'));
        $this->assertSame([], $this->versionsOf($report), 'a conversion rewrote the document');
        $this->assertInstanceOf(ReportShare::class, $share->fresh());
    }

    public function test_the_history_lists_kept_copies_and_a_restore_keeps_the_one_it_replaces(): void
    {
        $report = $this->report();
        $this->as($this->owner)->postJson($this->url($report), ['confirm' => true])->assertOk();
        $rebuilt = $report->fresh()->data;
        $versionId = (string) $this->versionsOf($report)[0]->getKey();

        $this->as($this->owner)->getJson($this->url($report, 'versions'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $versionId)
            ->assertJsonMissingPath('data.0.data');

        $this->as($this->owner)->postJson($this->url($report, "versions/{$versionId}/restore"))
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'report_restore_needs_confirmation');
        $this->assertEquals($rebuilt, $report->fresh()->data);

        $this->as($this->owner)->postJson($this->url($report, "versions/{$versionId}/restore"), ['confirm' => true])->assertOk();

        $fresh = $report->fresh();
        $this->assertSame('the copy the client saw', $fresh->data['marker']);
        $this->assertSame('2026-09-01 10:00:00', $fresh->generated_at->format('Y-m-d H:i:s'));
        $this->assertCount(2, $this->versionsOf($report), 'the copy a restore replaced was lost');
        $this->assertNotNull(AuditLog::withoutGlobalScopes()->where('action', 'report.version_restored')->where('entity_id', (string) $report->getKey())->first());
    }

    public function test_history_needs_the_right_permission_and_never_crosses_reports(): void
    {
        $report = $this->report();
        $other = $this->report(['name' => 'Other']);
        $this->as($this->owner)->postJson($this->url($report), ['confirm' => true])->assertOk();
        $versionId = (string) $this->versionsOf($report)[0]->getKey();

        $viewer = $this->userWith(['reports.view'], 'viewer');
        $this->as($viewer)->postJson($this->url($report), ['confirm' => true])->assertForbidden();
        $this->as($viewer)->postJson($this->url($report, "versions/{$versionId}/restore"), ['confirm' => true])->assertForbidden();
        $this->as($viewer)->getJson($this->url($report, 'versions'))->assertOk();

        $this->as($this->owner)->postJson($this->url($other, "versions/{$versionId}/restore"), ['confirm' => true])->assertNotFound();
    }
}
