<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Enums\ClientStatus;
use App\Domains\Projects\Lifecycle\LiveProjects;
use App\Domains\Projects\Lifecycle\ProjectStatus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 rule 3/4 — `projects.status` and `client_workspaces.client_status` are written by ONE function
 * each (`ProjectStateMachine::transition()` / `transitionClient()`). This fails the build on any other writer in app/.
 *
 * Creation is not a transition: `Project::create([... 'status' => …])` and `ClientWorkspace::create([... 'client_status'
 * => …])` set the starting state and are allowed.
 */
final class ProjectLifecycleSourceGuardTest extends TestCase
{
    private const WRITER = 'app/Domains/Projects/Lifecycle/ProjectStateMachine.php';

    /**
     * Files that fill `client_status` on a model that may be NEW (firstOrNew of a demo/test workspace) — creation, with
     * the reason. Adding a file here needs the same justification.
     */
    private const CLIENT_CREATION_FILLS = [
        'app/Domains/Tenancy/Support/ReviewAccountProvisioner.php' => 'firstOrNew of the Salla review demo client',
        'app/Domains/Integrations/MetaCandidate/MetaSaasTestWorkspace.php' => 'firstOrNew of the Meta SaaS test client',
    ];

    public function test_no_code_writes_a_project_status_outside_the_transition_function(): void
    {
        $offenders = [];
        foreach ($this->statements() as [$file, $line, $stmt]) {
            // The writer itself, and the model's `creating` normaliser (a starting state, not a transition).
            if ($file === self::WRITER || ($file === 'app/Domains/Projects/Models/Project.php' && str_contains($stmt, 'self::creating('))) {
                continue;
            }
            $projectTableUpdate = str_contains($stmt, "table('projects')") && str_contains($stmt, '->update(') && preg_match("/'status'\\s*=>/", $stmt);
            $projectModelWrite = preg_match('/\$\w*project\w*->(update|forceFill|fill|setAttribute)\(\s*(\[[^\]]*)?\'status\'/i', $stmt)
                || preg_match('/\$\w*project\w*->status\s*=[^=>]/i', $stmt)
                || preg_match("/Project::[^;]*->update\\(\\s*\\[[^\\]]*'status'\\s*=>/", $stmt);
            if ($projectTableUpdate || $projectModelWrite) {
                $offenders[] = "{$file}:{$line}";
            }
        }

        $this->assertSame([], $offenders, "projects.status written outside ProjectStateMachine::transition():\n".implode("\n", $offenders));
    }

    public function test_no_code_writes_a_client_status_outside_the_client_transition_function(): void
    {
        $offenders = [];
        foreach ($this->statements() as [$file, $line, $stmt]) {
            if ($file === self::WRITER || array_key_exists($file, self::CLIENT_CREATION_FILLS)) {
                continue;
            }
            $write = (preg_match("/'client_status'\\s*=>/", $stmt) && preg_match('/->(update|forceFill|fill|updateOrInsert)\(/', $stmt) && ! preg_match('/::(query\(\)->)?create\(|firstOrCreate\(|->insert\(/', $stmt))
                || preg_match('/->client_status\s*=[^=>]/', $stmt);
            if ($write) {
                $offenders[] = "{$file}:{$line}";
            }
        }

        $this->assertSame([], $offenders, "client_status written outside ProjectStateMachine::transitionClient():\n".implode("\n", $offenders));
    }

    public function test_the_transition_tables_are_closed_and_consistent(): void
    {
        foreach (ProjectStatus::cases() as $from) {
            $this->assertNotContains($from, $from->allowedTargets(), "{$from->value} → itself is not a transition");
            $this->assertTrue($from === ProjectStatus::Deleted || $from->canBecome(ProjectStatus::Deleted), "{$from->value} can be deleted");
        }
        $this->assertFalse(ProjectStatus::Draft->canBecome(ProjectStatus::Paused));
        $this->assertTrue(ProjectStatus::Archived->canBecome(ProjectStatus::Active));
        $this->assertSame(['active', 'paused'], LiveProjects::STATUSES);
        foreach (ClientStatus::cases() as $from) {
            $this->assertNotContains($from, $from->allowedTargets());
        }
        $this->assertSame(ClientStatus::all(), [...ClientStatus::values(), 'deleted']);
    }

    public function test_the_sql_view_and_the_php_scope_name_the_same_states(): void
    {
        $migration = (string) File::get(base_path('database/migrations/2026_11_18_100000_project_and_client_lifecycle.php'));
        $this->assertStringContainsString("status IN ('".implode("', '", LiveProjects::STATUSES)."')", $migration);
        $this->assertStringContainsString('deleted_at IS NULL', $migration);
    }

    /**
     * @return iterable<array{0: string, 1: int, 2: string}>
     */
    private function statements(): iterable
    {
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            $src = (string) File::get($file->getPathname());
            $offset = 0;
            foreach (explode(';', $src) as $stmt) {
                $line = substr_count(substr($src, 0, $offset), "\n") + 1 + (strlen($stmt) - strlen(ltrim($stmt)) > 0 ? substr_count($stmt, "\n", 0, strlen($stmt) - strlen(ltrim($stmt))) : 0);
                $offset += strlen($stmt) + 1;
                yield [$rel, $line, $stmt];
            }
        }
    }
}
