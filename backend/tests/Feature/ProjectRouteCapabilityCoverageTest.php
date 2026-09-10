<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Projects\Access\ProjectCapability;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 — every project-scoped route says which capability it needs.
 *
 * The engine shipped in #247 and was wired to four spend-limit routes. Everything else under
 * `projects/{project}` answered to the TENANT role, which is the wrong question: agency staff hold
 * tenant permissions across every client, and a project membership exists precisely to narrow that.
 * A media buyer entitled to manage the agency's own staff could add themselves to any client's
 * project and read its leads.
 *
 * ## Why a coverage guard and not more tests per route
 *
 * A per-route test proves one route. What actually fails in this codebase is a route being ADDED
 * without anybody remembering the middleware — the defect is absence, and absence is only visible
 * against a list. So this enumerates the registered routes and fails on any that carries no
 * capability and is not named as deliberately open.
 *
 * The exemption list is the interesting part: every entry is a route that must answer BEFORE a
 * capability can be resolved, or one whose own controller makes a narrower decision than a route
 * middleware could.
 */
final class ProjectRouteCapabilityCoverageTest extends TestCase
{
    /**
     * Open on purpose, each with the reason.
     *
     * `projects.show` and the context/switcher routes resolve WHICH project the reader is asking
     * about; a capability check on them would need the answer they are computing. The metrics
     * family is guarded inside `MetricsController` by `authorizeView`, on every action rather than
     * on eighteen route lines — see the note on `CONTROLLER_GUARDED`, and the reason that entry is
     * now checked instead of believed.
     *
     * @var list<string>
     */
    private const OPEN_BY_DESIGN = [
        /*
         * The project LIFECYCLE — these act on the project rather than inside it.
         *
         * `index` and `store` have no project to resolve a capability against: one lists what the
         * reader can reach and the other creates the thing a membership would attach to. The rest —
         * show, update, archive, restore, clone, pause, resume — are governed by the TENANT
         * permissions `projects.*`, which is the correct layer for «may this person run clients at
         * all». A project capability answers «what may they do inside THIS client», and asking it
         * about the act of creating that client is circular.
         */
        'api.v1.projects.index',
        'api.v1.projects.store',
        'api.v1.projects.show',
        'api.v1.projects.update',
        'api.v1.projects.archive',
        'api.v1.projects.restore',
        'api.v1.projects.clone',
        'api.v1.projects.pause',
        'api.v1.projects.resume',

        /*
         * The capability SET itself — the endpoint a rail reads to decide what to draw.
         *
         * Every capability it could be gated on is one it exists to report on, so gating it would
         * make the rail undrawable for exactly the people whose rail differs from everybody else's.
         * The `project` middleware has already established that the reader may reach the project, and
         * the answer is about themselves: it names no other member and no other project.
         *
         * This is not a hole in the wall. Nothing is authorised by it — every route it describes
         * states and enforces its own capability, and none of them consults this.
         */
        'api.v1.projects.scoped.capabilities',

        /*
         * The methodology copy, open to any project member and filed here rather than under
         * CONTROLLER_GUARDED, where it used to sit.
         *
         * `DisclaimerController::resolve` makes no authorisation decision at all — it reads the
         * effective disclaimer text for the active project — so calling it a controller that «holds a
         * narrower check than a middleware could» was the same kind of untrue claim as the metrics
         * entry beside it, in miniature. What is true is that this is the product's own methodology
         * wording, carrying no client figures and no identities, and `ResolveProject` has already
         * established that the reader may reach the project it belongs to.
         */
        'api.v1.projects.scoped.disclaimer.resolve',
    ];

    /**
     * Route-name prefixes whose controllers hold a narrower check than a middleware could.
     *
     * ## An exemption is a claim, and this list once carried a false one
     *
     * The reason recorded against the metrics family said `authorizeView` «additionally narrows the
     * FIGURES to the reader's scope — a route-level check would be coarser, not stricter». It did
     * not. It read `hasPermission('campaigns.view')`: a TENANT permission, with no project in the
     * question and nothing narrowed. Eighteen routes were exempted from the guard on the strength of
     * a sentence, and a lead agent — whose preset grants no campaigns, dashboard or analytics
     * capability — could read a project's spend, revenue, campaign breakdown, platform performance,
     * funnel and entity drill-down while being refused its creative library and its budget.
     *
     * The guard was working. It had been told something untrue. So the two tests below check what
     * the list asserts rather than trusting it: an entry that matches no route is dead and fails,
     * and `MetricsProjectAuthorizationTest` holds the controller check this entry now names
     * truthfully.
     *
     * @var list<string>
     */
    private const CONTROLLER_GUARDED = [
        /*
         * `MetricsController::authorizeView()` resolves the capability through `ProjectAbilities`
         * against the project `ResolveProject` has set — the same question the middleware asks, on
         * every one of this controller's actions, so a route added here inherits it instead of
         * needing somebody to remember. Held by `MetricsProjectAuthorizationTest`.
         */
        'api.v1.projects.scoped.metrics.',
    ];

    public function test_every_project_route_states_a_capability_or_is_named_as_open(): void
    {
        $offenders = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || ! str_starts_with($name, 'api.v1.projects.')) {
                continue;
            }

            if (in_array($name, self::OPEN_BY_DESIGN, true)) {
                continue;
            }

            foreach (self::CONTROLLER_GUARDED as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    continue 2;
                }
            }

            $states = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'project.can:')) {
                    $states = true;
                    break;
                }
            }

            if (! $states) {
                $offenders[] = $name;
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "These project routes answer to the tenant role alone. Add `project.can:<capability>`,\n"
            ."or name the route in OPEN_BY_DESIGN / CONTROLLER_GUARDED with the reason:\n  "
            .implode("\n  ", $offenders),
        );
    }

    /**
     * A capability named on a route must be one the vocabulary actually has.
     *
     * `project.can:reports.veiw` would not throw: the middleware would look it up, find nothing, and
     * — in a system whose unknown case is «deny» — refuse everybody, which reads as a permissions
     * bug rather than a typo and is debugged as one.
     */
    public function test_no_route_names_a_capability_that_does_not_exist(): void
    {
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (! is_string($middleware) || ! str_starts_with($middleware, 'project.can:')) {
                    continue;
                }

                $capability = substr($middleware, strlen('project.can:'));

                if (! in_array($capability, ProjectCapability::ALL, true)) {
                    $unknown[] = ($route->getName() ?? $route->uri()).' → '.$capability;
                }
            }
        }

        $this->assertSame([], $unknown, 'a route asks for a capability the vocabulary does not define');
    }

    /**
     * An exemption that matches no route is not an exemption — it is a hole waiting for a name.
     *
     * Three of the five entries here matched nothing: `leads.`, `integrations.` and `taxonomy.`
     * named prefixes that had been renamed or moved out from under `projects.scoped`. A dead entry
     * costs nothing until the day a route is registered that happens to match it, and then it
     * exempts that route silently, with a reason written for something else entirely.
     *
     * The same is true of `OPEN_BY_DESIGN`, and for the same reason.
     */
    public function test_every_exemption_still_names_a_route_that_exists(): void
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            if (($name = $route->getName()) !== null) {
                $names[] = $name;
            }
        }

        $dead = [];

        foreach (self::OPEN_BY_DESIGN as $name) {
            if (! in_array($name, $names, true)) {
                $dead[] = "OPEN_BY_DESIGN: {$name}";
            }
        }

        foreach (self::CONTROLLER_GUARDED as $prefix) {
            $matched = false;

            foreach ($names as $name) {
                if (str_starts_with($name, $prefix)) {
                    $matched = true;
                    break;
                }
            }

            if (! $matched) {
                $dead[] = "CONTROLLER_GUARDED: {$prefix}";
            }
        }

        $this->assertSame(
            [],
            $dead,
            "These exemptions name nothing. Remove them, or fix the name:\n  ".implode("\n  ", $dead),
        );
    }
}
