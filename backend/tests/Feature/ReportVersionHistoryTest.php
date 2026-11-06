<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Context\ProjectContext;
use App\Domains\Projects\Models\Project;
use App\Domains\Reports\Jobs\GenerateReportJob;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Models\ReportShare;
use App\Domains\Reports\Models\ReportVersion;
use App\Domains\Reports\Services\ShareService;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\GrantsMemberships;
use Tests\Concerns\KeepsStoredCopies;
use Tests\TestCase;

/**
 * REPORT-SNAPSHOT-PROTECT-001 — what #958 left open.
 *
 * While a stored copy is rebuilt the shared page answered from it and the three other doors of the same link refused; a
 * section save that rebuilds keeps the copy; a restored copy goes back into the report's own document, so it is read through
 * the same doors, with the same checks, as any stored copy.
 */
final class ReportVersionHistoryTest extends TestCase
{
    use GrantsMemberships;
    use KeepsStoredCopies;
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

        $role = Role::create(['tenant_id' => $this->tenant->getKey(), 'name' => 'owner', 'slug' => 'owner-'.uniqid()]);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $this->owner = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);
    }

    /** A completed snapshot report holding the copy a client saw. */
    private function report(): Report
    {
        $report = Report::create([
            'tenant_id' => $this->tenant->getKey(), 'project_id' => $this->project->getKey(),
            'name' => 'August', 'type' => 'monthly', 'form' => 'detailed', 'audience' => 'client', 'mode' => 'snapshot', 'status' => 'draft',
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'currency' => 'SAR',
        ]);
        $this->storeCopy((string) $report->getKey());

        return $report->fresh();
    }

    /** @return array{0: ReportShare, 1: string} a link that shows the attribution section and allows downloads */
    private function link(Report $report, array $opts = []): array
    {
        return app(ShareService::class)->create($report, $opts + ['allow_download' => true, 'settings' => ['sections' => ['attribution' => true]]], null);
    }

    /** @return array<string, string> the three doors that refused during a rebuild, by name */
    private function doors(string $raw): array
    {
        return [
            'attribution' => "/api/v1/reports/shared/{$raw}/attribution",
            'widgets' => "/api/v1/reports/shared/{$raw}/widgets",
            'download csv' => "/api/v1/reports/shared/{$raw}/download/csv",
            'download xlsx' => "/api/v1/reports/shared/{$raw}/download/xlsx",
        ];
    }

    private function as(User $user): self
    {
        $this->actingAs($user, 'sanctum')->withHeader('X-Project-Id', (string) $this->project->getKey());

        return $this;
    }

    // ------------------------------------------------------------------ the link's other doors during a rebuild

    public function test_the_attribution_section_answers_for_the_stored_copy_while_it_is_rebuilt(): void
    {
        $report = $this->report();
        [$share, $raw] = $this->link($report);
        $report->update(['status' => 'processing']);

        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertOk();

        $this->assertSame(1, $share->logs()->where('action', 'view')->where('detail', 'attribution')->count(), 'the access was not logged');
    }

    public function test_the_widgets_answer_for_the_stored_copy_while_it_is_rebuilt(): void
    {
        $report = $this->report();
        [, $raw] = $this->link($report);
        $report->update(['status' => 'processing']);

        $this->getJson("/api/v1/reports/shared/{$raw}/widgets")->assertOk()->assertJsonStructure(['data' => ['widgets']]);
    }

    public function test_the_files_are_made_from_the_stored_copy_while_it_is_rebuilt(): void
    {
        $report = $this->report();
        [$share, $raw] = $this->link($report);
        $before = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
        $report->update(['status' => 'processing']);

        $during = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();
        $this->assertSame($before, $during, 'the file made during a rebuild is not the stored copy');
        $this->assertNotSame('', $this->get("/api/v1/reports/shared/{$raw}/download/xlsx")->assertOk()->streamedContent());

        $this->assertSame(3, $share->logs()->where('action', 'download')->count(), 'a download was not logged');
        $this->assertSame('processing', $report->fresh()->status, 'serving the copy changed the row');
    }

    /** The PDF is printed by Chromium from the print route, which re-checks the link and reads the same document. */
    public function test_the_print_route_serves_a_links_stored_copy_while_it_is_rebuilt_and_an_operators_export_still_waits(): void
    {
        $report = $this->report();
        [$share] = $this->link($report);
        $report->update(['status' => 'processing']);

        $ctx = ['report_id' => (string) $report->getKey(), 'type' => 'presentation', 'theme' => 'light', 'audience' => 'client', 'watermark' => false];
        Cache::put('report-print:'.hash('sha256', 'link-token'), $ctx + ['share_id' => (string) $share->getKey()], 60);
        Cache::put('report-print:'.hash('sha256', 'operator-token'), $ctx, 60);

        $this->getJson('/api/v1/reports/print/link-token')->assertOk()->assertJsonPath('data.report_id', (string) $report->getKey());
        $this->getJson('/api/v1/reports/print/operator-token')->assertNotFound();

        $share->update(['paused_at' => now()]);
        $this->getJson('/api/v1/reports/print/link-token')->assertNotFound();
    }

    public function test_a_report_with_no_stored_copy_answers_nothing_on_any_door_while_it_is_built(): void
    {
        $report = $this->report();
        [, $raw] = $this->link($report);
        Report::withoutGlobalScopes()->whereKey($report->getKey())->update(['status' => 'processing', 'data' => null, 'generated_at' => null]);

        foreach ($this->doors($raw) as $name => $door) {
            $this->get($door, ['Accept' => 'application/json'])->assertNotFound();
            $this->assertNotNull($name);
        }
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertNotFound();
    }

    /** A half-built document is never served: the copy on the row stays the old one until the rebuild commits. */
    public function test_a_rebuild_that_fails_midway_leaves_every_door_on_the_stored_copy(): void
    {
        $report = $this->report();
        [, $raw] = $this->link($report);
        $before = $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent();

        $report->update(['status' => 'processing']);
        (new GenerateReportJob((string) $report->getKey()))->failed(new RuntimeException('provider down'));

        $this->assertSame($before, $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertOk()->streamedContent());
        $this->getJson("/api/v1/reports/shared/{$raw}/widgets")->assertOk();
        $this->getJson("/api/v1/reports/shared/{$raw}/attribution")->assertOk();
    }

    public function test_a_paused_link_stays_closed_on_every_door_during_a_rebuild(): void
    {
        $report = $this->report();
        [$share, $raw] = $this->link($report);
        $report->update(['status' => 'processing']);
        $share->update(['paused_at' => now()]);

        foreach ($this->doors($raw) as $door) {
            $this->get($door, ['Accept' => 'application/json'])->assertNotFound();
        }
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertNotFound();
        $this->assertSame(0, $share->logs()->whereIn('action', ['view', 'download'])->count());
    }

    public function test_a_revoked_link_stays_closed_on_every_door_during_a_rebuild(): void
    {
        $report = $this->report();
        [$share, $raw] = $this->link($report);
        $report->update(['status' => 'processing']);
        $share->update(['revoked_at' => now()]);

        foreach ($this->doors($raw) as $door) {
            $this->get($door, ['Accept' => 'application/json'])->assertNotFound();
        }
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertNotFound();
        $this->assertSame(0, $share->logs()->whereIn('action', ['view', 'download'])->count());
    }

    public function test_an_expired_link_and_a_wrong_password_are_refused_before_the_rebuild_is_looked_at(): void
    {
        $report = $this->report();
        [$expired, $rawExpired] = $this->link($report);
        [, $rawLocked] = $this->link($report, ['password' => 'open-sesame']);
        $report->update(['status' => 'processing']);
        $expired->update(['expires_at' => now()->subMinute()]);

        foreach ($this->doors($rawExpired) as $door) {
            $this->get($door, ['Accept' => 'application/json'])->assertNotFound();
        }
        foreach (['attribution', 'widgets'] as $door) {
            $this->getJson("/api/v1/reports/shared/{$rawLocked}/{$door}")->assertStatus(401);
            $this->withHeaders(['X-Report-Password' => 'open-sesame'])->getJson("/api/v1/reports/shared/{$rawLocked}/{$door}")->assertOk();
            $this->flushHeaders();
        }
    }

    public function test_a_link_that_does_not_allow_downloads_still_refuses_them_during_a_rebuild(): void
    {
        $report = $this->report();
        [, $raw] = $this->link($report, ['allow_download' => false]);
        $report->update(['status' => 'processing']);

        $this->get("/api/v1/reports/shared/{$raw}/download/csv")->assertForbidden();
    }

    // ------------------------------------------------------------------ an explicit edit that rebuilds keeps the copy

    public function test_a_section_save_that_rebuilds_the_report_keeps_the_copy_and_asks_nothing(): void
    {
        Queue::fake();
        $report = $this->report();
        $original = $report->data;

        $this->as($this->owner)
            ->putJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections", ['streams' => [['label' => 'Online sales', 'providers' => ['meta']]]])
            ->assertOk();

        Queue::assertPushed(GenerateReportJob::class, 1);
        $this->assertCopyKeptThroughRebuild((string) $report->getKey(), $original);
    }

    public function test_a_section_save_that_changes_no_stored_figure_does_not_rebuild_or_touch_the_copy(): void
    {
        Queue::fake();
        $report = $this->report();
        $original = $report->data;

        $this->as($this->owner)
            ->putJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/sections", ['sections' => ['trends' => false]])
            ->assertOk();

        Queue::assertNothingPushed();
        $fresh = $report->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertEquals($original, $fresh->data);
        $this->assertSame(0, ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->count());
    }

    // ------------------------------------------------------------------ a restored copy is read like any stored copy

    /**
     * A restore writes the kept copy back into the report's own document and changes nothing else about the report, so
     * every reader (the page, the files, the print route) reads it through the same code as the copy it replaced. Whatever
     * bounds a stored copy at read time therefore bounds a restored one: there is no second path from the history to a reader.
     */
    public function test_a_restored_copy_is_served_from_the_reports_own_document_and_leaves_its_sources_as_they_were(): void
    {
        $report = $this->report();
        $scope = ['account_ids' => ['11111111-1111-4111-8111-111111111111'], 'providers' => ['meta']];
        $report->forceFill(['scope' => $scope])->save();
        [, $raw] = $this->link($report);

        $this->as($this->owner)->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/regenerate", ['confirm' => true])->assertOk();
        $this->assertNull($this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->json('data.data.marker'));
        $kept = ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->firstOrFail();

        $this->as($this->owner)
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/versions/{$kept->getKey()}/restore", ['confirm' => true])
            ->assertOk();

        $fresh = $report->fresh();
        $this->assertEquals($scope, $fresh->scope, 'a restore changed what the report covers');
        $this->assertEquals($kept->data, $fresh->data);
        // The link is the same link and reads the restored copy from the report, not from the history.
        $this->getJson("/api/v1/reports/shared/{$raw}")->assertOk()->assertJsonPath('data.data.marker', 'the copy the client saw');
        $this->assertSame(1, ReportShare::withoutGlobalScopes()->where('report_id', $report->getKey())->count());

        // The history itself is never a reader's source: it lists dates and holds no document.
        $this->as($this->owner)->getJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/versions")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.data')->assertJsonMissingPath('data.1.data');
    }

    /** A restore during a rebuild would be replaced by that rebuild the moment it finished, with nobody told. It waits instead. */
    public function test_a_restore_is_refused_while_the_report_is_being_rebuilt_and_changes_nothing(): void
    {
        $report = $this->report();
        $this->as($this->owner)->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/regenerate", ['confirm' => true])->assertOk();
        $kept = ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->firstOrFail();
        $rebuilt = $report->fresh()->data;
        $report->update(['status' => 'processing']);

        $this->as($this->owner)
            ->postJson("/api/v1/projects/{$this->project->getKey()}/reports/{$report->getKey()}/versions/{$kept->getKey()}/restore", ['confirm' => true])
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'report_is_rebuilding');

        $fresh = $report->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertEquals($rebuilt, $fresh->data);
        $this->assertSame(1, ReportVersion::withoutGlobalScopes()->where('report_id', $report->getKey())->count());
    }
}
