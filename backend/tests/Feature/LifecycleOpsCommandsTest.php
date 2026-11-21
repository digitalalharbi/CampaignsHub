<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Lifecycle\ProjectLifecycle;
use App\Domains\Projects\Lifecycle\ProjectSnapshot;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\LifecycleFixtures;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §PROTECTION — the two ops commands the lead runs in production: read-only snapshot, and archive
 * effects that are dry-run by default, refuse a live project, are idempotent, reversible and NEVER delete.
 */
final class LifecycleOpsCommandsTest extends TestCase
{
    use LifecycleFixtures;
    use RefreshDatabase;

    private const COUNTED = ['projects', 'project_integration_bindings', 'report_schedules', 'alert_rules', 'alert_events', 'tasks', 'automation_rules', 'agent_routines', 'client_workspaces'];

    public function test_the_snapshot_is_read_only_and_stable_across_an_unrelated_change(): void
    {
        $ws = $this->lifecycleWorkspace();
        $mine = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Mine');
        $other = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Other');
        $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $mine);
        $this->lifecycleOperational($mine);

        Artisan::call('lifecycle:snapshot', ['--project' => $mine->id]);
        $first = Artisan::output();
        $counts = $this->counts();

        // An unrelated change: another project gets operations and is archived with effects.
        $this->lifecycleOperational($other);
        app(ProjectLifecycle::class)->archive($other, $ws['owner'], true);

        Artisan::call('lifecycle:snapshot', ['--project' => $mine->id]);
        $this->assertSame($first, Artisan::output(), 'same project, same data → byte-identical JSON');
        $decoded = json_decode($first, true);
        $this->assertSame(1, $decoded['accounts']['bindings_syncing']);
        $this->assertSame(1, $decoded['scheduled_reports']['active']);
        $this->assertSame(1, $decoded['rules']['automation_rules_enabled']);
        $this->assertSame($counts['projects'], $this->counts()['projects']);

        $this->assertSame(2, Artisan::call('lifecycle:snapshot', []), 'the id is required');
    }

    public function test_apply_archive_effects_is_dry_run_by_default_refuses_live_projects_and_never_deletes(): void
    {
        $ws = $this->lifecycleWorkspace();
        $live = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Live');
        $old = $this->lifecycleProject($ws['tenant'], $ws['client'], 'Archived long ago');
        $this->lifecycleBind($this->lifecycleAccount($ws['tenant']), $old);
        $this->lifecycleOperational($old);
        $this->lifecycleOperational($live);
        // Archived before the effects existed: status only.
        app(ProjectLifecycle::class)->transition($old, ProjectStatus::Archived, null, 'legacy');
        $liveBefore = app(ProjectSnapshot::class)->for((string) $live->id);
        $counts = $this->counts();

        // Refused for a live project, nothing written.
        $this->assertSame(1, Artisan::call('lifecycle:apply-archive-effects', ['--project' => $live->id, '--apply' => true]));
        $this->assertSame($liveBefore, app(ProjectSnapshot::class)->for((string) $live->id));

        // Dry run (default): reports, writes nothing.
        $oldBefore = app(ProjectSnapshot::class)->for((string) $old->id);
        $this->assertSame(0, Artisan::call('lifecycle:apply-archive-effects', ['--project' => $old->id]));
        $this->assertStringContainsString('DRY RUN', Artisan::output());
        $this->assertSame($oldBefore, app(ProjectSnapshot::class)->for((string) $old->id));
        $this->assertSame(0, DB::table('project_archive_effects')->count());

        // Apply: effects recorded, open alert/task closed, nothing deleted, the live project untouched.
        $this->assertSame(0, Artisan::call('lifecycle:apply-archive-effects', ['--all-archived' => true, '--apply' => true]));
        $out = Artisan::output();
        $json = json_decode(substr($out, (int) strpos($out, '{'), (int) strrpos($out, '}') - (int) strpos($out, '{') + 1), true);
        $this->assertTrue($json['apply']);
        $this->assertCount(1, $json['projects']);
        $this->assertSame(1, $json['projects'][0]['effects']['task_closed']);
        $this->assertContains('open.alerts', $json['projects'][0]['changed']);
        $after = app(ProjectSnapshot::class)->for((string) $old->id);
        $this->assertSame([0, 0, 0], [$after['open']['alerts'], $after['open']['tasks'], $after['accounts']['bindings_syncing']]);
        $this->assertSame($counts, $this->counts(), 'no row of any table was deleted');
        $this->assertSame($liveBefore, app(ProjectSnapshot::class)->for((string) $live->id), 'the live project is byte-identical');

        // Idempotent: a second apply changes nothing.
        $effects = DB::table('project_archive_effects')->count();
        Artisan::call('lifecycle:apply-archive-effects', ['--all-archived' => true, '--apply' => true]);
        $this->assertSame($effects, DB::table('project_archive_effects')->count());

        // Reversible: un-archiving restores the switches.
        app(ProjectLifecycle::class)->unarchive($old, $ws['owner'], true);
        $restored = app(ProjectSnapshot::class)->for((string) $old->id);
        $this->assertSame($oldBefore['rules'], $restored['rules']);
        $this->assertSame($oldBefore['scheduled_reports'], $restored['scheduled_reports']);
        $this->assertSame(1, $restored['accounts']['bindings_syncing']);
    }

    /** @return array<string,int> */
    private function counts(): array
    {
        $out = [];
        foreach (self::COUNTED as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }
}
