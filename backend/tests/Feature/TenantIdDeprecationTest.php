<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ADR 0002 — `users.tenant_id` is GONE, and stays gone.
 *
 * Dropped in `2026_07_31_090000_grant_memberships_then_drop_users_tenant_id`, which first grants a
 * membership to every user that still had a tenant and none, then removes the column — and refuses
 * to remove it if anyone would be left stranded.
 *
 * These tests exist so bringing it back fails loudly. The behavioural ones prove a user without a
 * membership reaches nothing; the schema one proves the column is absent; the source scan catches a
 * reader reappearing, with no allowlist to hide behind.
 */
final class TenantIdDeprecationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The guarantee itself: a user who has `tenant_id` but no membership gets NO tenant scope. If
     * anyone reinstates the fallback, this fails — the request would come back scoped to that tenant.
     */
    public function test_a_user_with_tenant_id_but_no_membership_gets_no_scope(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('/__scope-probe', fn () => response()->json([
                'tenant' => app(TenantContext::class)->tenantId(),
            ]));

        $tenant = Tenant::create(['name' => 'Legacy Co', 'slug' => 'legacy-co', 'status' => 'active']);
        $user = User::create([
            'name' => 'Legacy', 'email' => 'legacy@test.dev',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $this->assertSame(0, $user->memberships()->count(), 'the fixture must have no membership');

        $this->actingAs($user, 'sanctum')->getJson('/__scope-probe')
            ->assertOk()
            // Not $tenant->id — the column must not be consulted.
            ->assertJsonPath('tenant', null);
    }

    /**
     * Creating a user is NOT granting them access.
     *
     * A model hook used to provision a membership whenever a user row appeared. It made the fallback
     * removable, and it was wrong: an imported contact, a half-finished admin form or a stray factory
     * call would silently have handed someone a workspace. Access is granted only by an explicit
     * grant, so a bare user row reaches nothing.
     */
    public function test_creating_a_user_grants_no_membership(): void
    {
        $tenant = Tenant::create([
            'name' => 'Auto Co', 'slug' => 'auto-co', 'status' => 'active', 'account_type' => 'agency',
        ]);

        $user = User::create([
            'name' => 'Auto', 'email' => 'auto@test.dev', 'password' => 'secret123',
        ]);

        $this->assertSame(0, $user->memberships()->count());
    }

    /** A platform user belongs to no tenant, so it gets no membership and that is correct. */
    public function test_a_platform_user_gets_no_membership(): void
    {
        $user = User::create([
            'name' => 'Platform', 'email' => 'platform@test.dev',
            'password' => 'secret123', 'is_platform_admin' => true,
        ]);

        $this->assertSame(0, $user->memberships()->count());
    }

    /**
     * The scope chokepoint must not read the column. Asserted against the source because the
     * behavioural test above can only prove today's path — this catches the fallback being added
     * back for some other branch.
     */
    public function test_the_membership_middleware_does_not_read_the_column(): void
    {
        $source = file_get_contents(app_path('Domains/Tenancy/Middleware/ResolveMembership.php'));

        $this->assertStringNotContainsString(
            '$user->tenant_id !== null) {'."\n".'            $this->tenants->setTenantId',
            $source,
            'ResolveMembership must not derive tenant scope from users.tenant_id',
        );
        $this->assertStringNotContainsString('setTenantId($user->tenant_id)', $source);
    }

    /**
     * Controllers must scope through the context, not the user row. This was a live bug once: seven
     * controllers validated foreign keys against `$request->user()->tenant_id`, which becomes the
     * WRONG tenant the moment a user switches workspace.
     */
    public function test_no_controller_scopes_by_the_user_column(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn(app_path('Domains')) as $file) {
            $source = file_get_contents($file);
            /*
             * Reads of the column AND queries against it.
             *
             * The first two patterns catch `$user->tenant_id`. They did NOT catch
             * `User::query()->where('tenant_id', …)`, and `ClientTaxonomyController` sat behind that
             * gap 500-ing on every call while this test stayed green — a whole endpoint broken by a
             * migration, invisible because the guard only knew one shape of the mistake.
             *
             * Scanned rather than pattern-matched in one expression, because a `tenant_id` filter
             * near a user query is usually SOMEBODY ELSE'S — a role, a membership subquery, a
             * `whereHas` closure — and all three of those are correct. What is wrong is a
             * `tenant_id` clause belonging to the user query itself, so the text between the two
             * must contain no statement break, no other class reference and no closure.
             */
            $queriesTheColumn = false;
            $offset = 0;

            while (($start = strpos($source, 'User::query()', $offset)) !== false) {
                $offset = $start + 13;
                $clause = strpos($source, "where('tenant_id'", $start);

                if ($clause === false) {
                    break;
                }

                $between = substr($source, $start + 13, $clause - $start - 13);

                $belongsToSomethingElse = str_contains($between, ';')
                    || str_contains($between, '::')
                    || str_contains($between, 'fn (')
                    || str_contains($between, 'function (');

                if (! $belongsToSomethingElse) {
                    $queriesTheColumn = true;
                    break;
                }
            }

            if (str_contains($source, 'user()->tenant_id') || str_contains($source, 'user->tenant_id') || $queriesTheColumn) {
                /*
                 * NO exceptions any more. MembershipProvisioner was the last one — it read the
                 * column to move a legacy user into a membership — and the upgrade migration took
                 * that job over before dropping the column. An empty allowlist is the point: there
                 * is nothing left for a new reader to hide behind.
                 */
                $offenders[] = str_replace(app_path(), '', $file);
            }
        }

        $this->assertSame([], $offenders,
            'these read users.tenant_id; scope must come from MembershipContext/TenantContext instead');
    }

    /**
     * The column is absent. Stated plainly, because every other test here would still pass against
     * a schema where it quietly came back and nothing read it YET.
     */
    public function test_the_column_does_not_exist(): void
    {
        $this->assertFalse(
            Schema::hasColumn('users', 'tenant_id'),
            'users.tenant_id is back; scope must come from memberships (ADR 0002)',
        );
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
