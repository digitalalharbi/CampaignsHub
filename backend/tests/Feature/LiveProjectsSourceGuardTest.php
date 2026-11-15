<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PROJECT-LIFECYCLE-001 rule 5 — «live projects» come from ONE source (`LiveProjects` / `Project::live()|running()|listed()|
 * inLifecycle()` / the `live_projects` view). A hand-written project status filter anywhere else in app/ fails here.
 *
 * Comments are stripped before matching, so prose may still name a state.
 */
final class LiveProjectsSourceGuardTest extends TestCase
{
    private const SOURCE = 'app/Domains/Projects/Lifecycle/';

    public function test_no_hand_written_project_status_filter_outside_the_shared_scope(): void
    {
        $offenders = [];
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
            if (str_starts_with($rel, self::SOURCE)) {
                continue;
            }
            $code = $this->stripComments((string) File::get($file->getPathname()));

            foreach (explode(';', $code) as $stmt) {
                $aboutProjects = preg_match("/Project::|table\\('projects'\\)|from\\('projects'\\)|join\\('projects'|\\\$\\w*project\\w*->/i", $stmt) === 1;
                $hit = str_contains($stmt, "'projects.status'")
                    || ($aboutProjects && preg_match("/(Project::|table\\('projects'\\)|from\\('projects'\\))(?:(?!DB::table|=>|::query|::withoutGlobalScopes).)*->(where|whereIn|whereNotIn|orWhere)\\(\\s*'status'/s", $stmt))
                    || preg_match("/\\\$\\w*project\\w*->status\\s*(===|!==|==|!=)/i", $stmt)
                    || preg_match("/in_array\\(\\s*\\\$\\w*project\\w*->status/i", $stmt)
                    || preg_match('/PROJECT_STATUSES|ACTIVE_PROJECT\b/', $stmt);
                if ($hit) {
                    $offenders[] = $rel.': '.trim(preg_replace('/\s+/', ' ', substr($stmt, 0, 200)) ?? '');
                }
            }
        }

        $this->assertSame([], $offenders, "Hand-written project status filters — use LiveProjects / Project::live():\n".implode("\n", $offenders));
    }

    public function test_the_guard_catches_the_shapes_it_names(): void
    {
        $samples = [
            "Project::query()->where('status', 'active')->get()",
            "DB::table('projects')->whereNotIn('status', ['archived'])->count()",
            "\$q->where('projects.status', '!=', 'archived')",
            "if (\$project->status !== 'active') { return; }",
        ];
        foreach ($samples as $sample) {
            $stmt = $this->stripComments($sample);
            $hit = str_contains($stmt, "'projects.status'")
                || preg_match("/(Project::|table\\('projects'\\)|from\\('projects'\\))(?:(?!DB::table|=>|::query|::withoutGlobalScopes).)*->(where|whereIn|whereNotIn|orWhere)\\(\\s*'status'/s", $stmt)
                || preg_match("/\\\$\\w*project\\w*->status\\s*(===|!==|==|!=)/i", $stmt);
            $this->assertTrue((bool) $hit, "guard misses: {$sample}");
        }
    }

    private function stripComments(string $src): string
    {
        $out = '';
        foreach (token_get_all(str_starts_with(ltrim($src), '<?php') ? $src : "<?php {$src}") as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $out .= $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }
}
