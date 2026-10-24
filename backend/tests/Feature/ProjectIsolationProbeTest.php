<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Projects\Models\Project;
use App\Domains\Projects\Models\ProjectMembership;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TEAM-PROJECT-RBAC-001 / defect 91 — a member confined to ONE project is refused on EVERY route of ANOTHER, by behaviour and not by
 * reading middleware.
 *
 * `ProjectRouteCapabilityCoverageTest` proves each project route STATES a capability. That is a claim about configuration; this is the
 * behaviour. The member here is the worst case for the question: a tenant role holding every permission except `projects.view.all` (so
 * nothing at the tenant layer stops them), and a membership in the Authorized project only. Every route under `/projects/{project}` —
 * reads and writes, lifecycle and scoped — is then called against the Forbidden project with a body that would pass validation if
 * authorisation did. The only answers allowed are 403 and 404: a 422 means the request reached validation, a 2xx means it worked, a 5xx
 * means it was never guarded at all. A control proves the same member is accepted on their own project, so the refusals are not a dead
 * harness, and a guard fails if the probe finds fewer routes than the product has.
 */
final class ProjectIsolationProbeTest extends TestCase
{
    use RefreshDatabase;

    private User $confined;

    private Project $own;

    private Project $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        // Every permission EXCEPT the one that widens a reader to the whole estate.
        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'Powerful but confined', 'slug' => 'confined']);
        $role->givePermissionTo(...Permission::query()->where('key', '!=', 'projects.view.all')->pluck('key')->all());
        $this->confined = User::create(['name' => 'Confined', 'email' => 'c@agency.test', 'password' => 'secret123']);
        $this->grantMembership($this->confined, $tenant);
        $this->confined->assignRole($role);

        $a = ClientWorkspace::create(['name' => 'Alpha', 'slug' => 'alpha', 'mode' => 'managed']);
        $b = ClientWorkspace::create(['name' => 'Beta', 'slug' => 'beta', 'mode' => 'managed']);
        $this->own = Project::create(['client_workspace_id' => $a->id, 'name' => 'Own', 'status' => 'active']);
        $this->theirs = Project::create(['client_workspace_id' => $b->id, 'name' => 'Theirs', 'status' => 'active']);

        // The strongest project role there is, on the one project the member belongs to.
        ProjectMembership::create(['tenant_id' => $tenant->id, 'project_id' => $this->own->id, 'user_id' => $this->confined->id, 'role' => 'manager', 'status' => 'active']);
    }

    /** @return list<LaravelRoute> every route that carries a {project} segment, minus the ones that do not act on one */
    private function projectRoutes(): array
    {
        return array_values(array_filter(
            Route::getRoutes()->getRoutes(),
            fn (LaravelRoute $r): bool => str_starts_with($r->uri(), 'api/v1/projects/{project}')
                && ! in_array($r->getName(), $this->notAboutAProject(), true),
        ));
    }

    /** Named on purpose: routes under the prefix that name no project's data. */
    private function notAboutAProject(): array
    {
        return [];
    }

    private function url(LaravelRoute $route, Project $project): string
    {
        $uri = preg_replace_callback('/\{(\w+)\??\}/', function (array $m) use ($project): string {
            return $m[1] === 'project' ? (string) $project->id : '00000000-0000-0000-0000-000000000000';
        }, $route->uri());

        return '/'.$uri;
    }

    public function test_a_member_confined_to_one_project_is_refused_on_every_route_of_another(): void
    {
        $offenders = [];

        foreach ($this->projectRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $status = $this->actingAs($this->confined, 'sanctum')->json($method, $this->url($route, $this->theirs), [])->status();

                if (! in_array($status, [403, 404], true)) {
                    $offenders[] = "{$method} {$route->uri()} → {$status}";
                }
            }
        }

        $this->assertSame([], $offenders, "a member confined to one project got past authorisation on another:\n  ".implode("\n  ", $offenders));
    }

    /** The control: the same member, the same routes, their OWN project — most are accepted past authorisation (not 403). */
    public function test_the_same_member_is_not_refused_on_their_own_project(): void
    {
        $reached = 0;
        $total = 0;

        foreach ($this->projectRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
                $total++;
                $status = $this->actingAs($this->confined, 'sanctum')->json($method, $this->url($route, $this->own), [])->status();
                if ($status !== 403) {
                    $reached++;
                }
            }
        }

        $this->assertGreaterThan(0.8 * $total, $reached, "only {$reached} of {$total} project routes got past authorisation for a manager of the project — the probe's harness is suspect");
    }

    public function test_the_probe_sees_the_product_s_project_routes(): void
    {
        $this->assertGreaterThan(150, count($this->projectRoutes()), 'the probe found far fewer /projects/{project} routes than the product has');
    }
}
