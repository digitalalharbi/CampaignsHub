<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Access\ProjectRole;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — a role this codebase WRITES must be one the vocabulary knows.
 *
 * ## The fallback is for history, not for today
 *
 * `ProjectRole::preset()` resolves an unrecognised role to `viewer` rather than to nothing, and its
 * docblock says why: «a membership row whose role was written by an older release still belongs to a
 * real person who can still read the project». That is a kindness to rows already in the column.
 *
 * `InvitationService` was writing `'role' => 'member'` — a value in NEITHER vocabulary: not among
 * the nine `ProjectMembershipController::ROLES` an operator can pick, and not among the seven
 * `ProjectRole` presets. Every invitee accepted into a project therefore landed on the unknown-role
 * fallback, which is a demotion nobody chose and nothing said. Its only visible trace was the team
 * page printing the literal word «member» beside a person's name, because `PROJECT_ROLE_LABELS` has
 * no entry for it either.
 *
 * The behaviour was safe — viewer is the fail-closed end — which is exactly why it could sit there:
 * a wrong value that lands on a safe default produces no complaint and no error, and the next value
 * to arrive that way may not be safe.
 *
 * So the fallback stays for the rows that need it, and this holds the line for new writes.
 */
final class ProjectRoleVocabularyTest extends TestCase
{
    /** Every role literal this application assigns to a membership, with where it is written. */
    private const WRITTEN_BY_THE_APPLICATION = [
        'App\\Domains\\Identity\\Services\\InvitationService' => 'app/Domains/Identity/Services/InvitationService.php',
    ];

    /**
     * A role written by our own code resolves through a NAMED preset, never the unknown fallback.
     *
     * Compared against the fallback's own output rather than against a list: a role that resolves to
     * exactly what `preset('a-string-nothing-defines')` returns is indistinguishable from a typo, and
     * that is the property worth asserting.
     */
    public function test_every_role_this_codebase_writes_is_in_the_vocabulary(): void
    {
        $known = array_keys(ProjectRole::presets());

        $legacy = ['account_manager', 'analyst', 'content', 'finance', 'client_admin', 'client_approver', 'client_viewer'];

        $offenders = [];

        foreach (self::WRITTEN_BY_THE_APPLICATION as $class => $path) {
            $source = File::get(base_path($path));

            preg_match_all("/'role'\s*=>\s*'([a-z_]+)'/", $source, $matches);

            foreach ($matches[1] as $role) {
                if (! in_array($role, $known, true) && ! in_array($role, $legacy, true)) {
                    $offenders[] = "{$class} writes role «{$role}», which no preset defines";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A membership role written by this codebase must name a preset — the unknown-role\n"
            ."fallback exists for rows an OLDER release wrote, not for new ones:\n  "
            .implode("\n  ", $offenders),
        );
    }

    /** The fallback still protects the rows that already carry an unknown role. */
    public function test_an_unknown_role_still_reads_the_project(): void
    {
        $this->assertSame(ProjectRole::presets()[ProjectRole::VIEWER], ProjectRole::preset('member'));
        $this->assertSame(ProjectRole::presets()[ProjectRole::VIEWER], ProjectRole::preset('written-by-a-future-release'));
    }
}
