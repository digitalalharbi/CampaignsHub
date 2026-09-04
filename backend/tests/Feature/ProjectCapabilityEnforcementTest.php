<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Access\ProjectCapability;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — the mirror of the route-coverage guard.
 *
 * `ProjectRouteCapabilityCoverageTest` catches a route that requires NOTHING. This catches the other
 * direction: a capability that GRANTS nothing — defined, handed out by a preset, listed on a team
 * screen, and enforced nowhere.
 *
 * That is worse than it sounds, because both readings are wrong in opposite directions. An operator
 * reading a preset believes they have restricted something they have not; and the day somebody builds
 * the missing feature, nothing reminds them that a capability was reserved for it — so the export
 * ships ungated and the capability stays decorative.
 *
 * ## What counts as enforcement
 *
 * A route stating `project.can:<capability>`, or a call to `ProjectAbilities::allows()` naming the
 * constant somewhere outside the access layer itself. The second is how the lead surfaces guard
 * theirs: per-record visibility is narrower than a middleware can express, so `LeadVisibility` asks
 * directly.
 */
final class ProjectCapabilityEnforcementTest extends TestCase
{
    /**
     * Capabilities that deliberately enforce nothing YET, each with the reason and what will use it.
     *
     * A capability belongs here for as long as the feature it guards does not exist. It comes OFF the
     * list the moment something enforces it — the second test makes leaving it here impossible then.
     */
    private const RESERVED = [
        /*
         * There is no lead export. Not an endpoint, not a button — the capability was defined with the
         * rest of the lead vocabulary and the feature was never built.
         *
         * It stays defined on purpose: a preset already withholds it from a media buyer, which is the
         * decision somebody made about who may take a client's contact list off the platform, and
         * deleting it would lose that decision. The day an export exists it must state this, and this
         * list is what says so.
         */
        'leads.export' => 'no lead export exists — the endpoint that adds one must state this capability',
        /*
         * Task writes are tenant-scoped routes (`workspaces.php`), gated by the tenant permissions
         * `tasks.create` / `tasks.update` plus a scope check on the row. That is the arrangement this
         * requirement is migrating away from, not a hole — the writes are refused today, by an older
         * layer.
         */
        'tasks.manage' => 'task writes are tenant-scoped routes gated by tasks.create/tasks.update; they move here when the routes do',
        /*
         * Project SETTINGS are edited through the project lifecycle routes, which are governed by the
         * tenant `projects.*` permissions — the correct layer for «may this person run clients at
         * all». A project-scoped settings surface would state this.
         */
        'settings.manage' => 'project settings are edited through the lifecycle routes under the tenant projects.* permissions',
    ];

    /** @return array<string, bool> capability → whether anything enforces it */
    private function enforcement(): array
    {
        $routes = '';
        foreach (File::allFiles(base_path('routes')) as $file) {
            $routes .= $file->getContents();
        }

        $app = '';
        foreach (File::allFiles(app_path()) as $file) {
            // The access layer DEFINES the vocabulary; a mention there is not a use of it.
            if (str_contains($file->getPathname(), '/Projects/Access/')) {
                continue;
            }
            $app .= $file->getContents();
        }

        $out = [];
        foreach (ProjectCapability::ALL as $capability) {
            $constant = 'ProjectCapability::'.strtoupper(str_replace('.', '_', $capability));

            /*
             * The CONSTANT, never the bare string — the two vocabularies share names.
             *
             * `settings.manage` is both a tenant permission and a project capability, and half a dozen
             * settings controllers check `$user->hasPermission('settings.manage')`, which is the
             * tenant one. Matching the literal counted those as enforcement of the PROJECT capability
             * and the guard reported a reservation as stale — the first thing it did was mislead about
             * exactly the distinction this requirement exists to draw.
             */
            $out[$capability] = str_contains($routes, "project.can:{$capability}")
                || str_contains($app, $constant);
        }

        return $out;
    }

    public function test_every_capability_either_guards_something_or_says_why_not(): void
    {
        $idle = array_keys(array_filter($this->enforcement(), static fn (bool $used): bool => ! $used));
        $undeclared = array_values(array_diff($idle, array_keys(self::RESERVED)));

        $this->assertSame(
            [],
            $undeclared,
            "These capabilities grant nothing: no route states them and no controller asks for them.\n"
            ."A preset that withholds one is withholding nothing, and an operator reading it believes\n"
            ."otherwise. Enforce it, or add it to RESERVED with the reason and what will use it:\n  "
            .implode("\n  ", $undeclared),
        );
    }

    /** A reservation is a statement about work not yet done, and it has to stop being true. */
    public function test_no_reservation_outlives_the_thing_it_was_waiting_for(): void
    {
        $enforced = array_keys(array_filter($this->enforcement()));
        $stale = array_values(array_intersect(array_keys(self::RESERVED), $enforced));

        $this->assertSame(
            [],
            $stale,
            'these are enforced now — take them off RESERVED, so the list stays a count of what is left: '
            .implode(', ', $stale),
        );
    }
}
