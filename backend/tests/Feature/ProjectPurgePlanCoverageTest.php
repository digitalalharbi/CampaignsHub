<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Lifecycle\ProjectMover;
use App\Domains\Projects\Lifecycle\ProjectPurgePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 §4 (N2) — the permanent delete's plan covers EVERY project-owned table, read from the live
 * schema. A new table with `project_id` fails here until somebody decides its strategy (purge / detach / keep_log /
 * never); a PURGE table that gains a cascade child must name it; no purge may cascade into a log; and every table with
 * both `project_id` and `client_workspace_id` must be classified for «نقل لعميل آخر».
 */
final class ProjectPurgePlanCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function baseTablesWith(string $column): array
    {
        return DB::table('information_schema.columns as c')
            ->join('information_schema.tables as t', fn ($j) => $j->on('t.table_name', '=', 'c.table_name')->on('t.table_schema', '=', 'c.table_schema'))
            ->where('c.table_schema', 'public')->where('t.table_type', 'BASE TABLE')->where('c.column_name', $column)
            ->orderBy('c.table_name')->pluck('c.table_name')->map(fn ($t) => (string) $t)->all();
    }

    public function test_every_table_with_a_project_id_has_a_purge_strategy(): void
    {
        $missing = array_values(array_diff($this->baseTablesWith('project_id'), array_keys(ProjectPurgePlan::TABLES)));

        $this->assertSame([], $missing, 'New project-owned table(s) not covered by ProjectPurgePlan::TABLES — choose purge/detach/keep_log/never: '.implode(', ', $missing));
    }

    public function test_the_plan_names_no_table_that_does_not_exist(): void
    {
        $stale = array_values(array_diff(array_keys(ProjectPurgePlan::TABLES), $this->baseTablesWith('project_id')));

        $this->assertSame([], $stale, 'ProjectPurgePlan names table(s) without project_id: '.implode(', ', $stale));
    }

    public function test_detached_tables_accept_null_and_purge_never_cascades_into_a_log(): void
    {
        foreach (ProjectPurgePlan::tablesWith(ProjectPurgePlan::DETACH) as $table) {
            $nullable = DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->where('column_name', 'project_id')->value('is_nullable');
            $this->assertSame('YES', $nullable, "{$table}.project_id must be nullable to be detached");
        }

        $fks = collect(DB::select(<<<'SQL'
            SELECT ccu.table_name AS parent, tc.table_name AS child, rc.delete_rule AS rule
            FROM information_schema.table_constraints tc
            JOIN information_schema.referential_constraints rc ON rc.constraint_name = tc.constraint_name
            JOIN information_schema.constraint_column_usage ccu ON ccu.constraint_name = tc.constraint_name
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
        SQL));

        foreach (ProjectPurgePlan::tablesWith(ProjectPurgePlan::PURGE) as $parent) {
            $cascading = $fks->where('parent', $parent)->where('rule', 'CASCADE')->pluck('child')->unique()->sort()->values()->all();
            foreach ($cascading as $child) {
                $this->assertNotContains($child, ProjectPurgePlan::LOGS, "purging {$parent} would cascade into the log {$child}");
                $this->assertContains($child, ProjectPurgePlan::CASCADES[$parent] ?? [], "{$parent} cascades into {$child}; list it in ProjectPurgePlan::CASCADES (or choose another strategy)");
            }
            $restricting = $fks->where('parent', $parent)->whereNotIn('rule', ['CASCADE', 'SET NULL'])->pluck('child')->unique()->values()->all();
            foreach ($restricting as $child) {
                $this->assertContains($child, ProjectPurgePlan::tablesWith(ProjectPurgePlan::PURGE), "{$child} restricts deleting {$parent} and is not purged itself");
            }
        }

        // The `projects` row is a tombstone: no plan deletes it, so its CASCADE children (logs among them) stay.
        $this->assertArrayNotHasKey('projects', ProjectPurgePlan::TABLES);
        foreach (ProjectPurgePlan::tablesWith(ProjectPurgePlan::KEEP_LOG) as $log) {
            $this->assertNotContains($log, array_merge(...array_values(ProjectPurgePlan::CASCADES)), "{$log} is kept but a purge cascades into it");
        }
    }

    public function test_every_table_with_project_and_client_columns_is_classified_for_a_move(): void
    {
        $both = array_values(array_intersect($this->baseTablesWith('project_id'), $this->baseTablesWith('client_workspace_id')));

        $this->assertSame([], array_values(array_diff($both, array_keys(ProjectMover::TABLES))), 'Classify the new table(s) in ProjectMover::TABLES');
        $this->assertSame([], array_values(array_diff(array_keys(ProjectMover::TABLES), $both)));
    }
}
