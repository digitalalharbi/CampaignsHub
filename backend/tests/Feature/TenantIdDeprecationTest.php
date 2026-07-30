<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * ADR 0002 — `users.tenant_id` is legacy data, not an authorisation input.
 *
 * The column still exists so migration and factories have somewhere to say "this user belongs to
 * tenant X", but nothing may derive scope, permission, portal or routing from it. These tests exist
 * to make a return to it fail loudly: the behavioural one proves a membership-less user gets nothing,
 * and the source scans catch a reintroduction at the places it would most plausibly reappear.
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
            'tenant_id' => $tenant->id, 'name' => 'Legacy', 'email' => 'legacy@test.dev',
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
            'tenant_id' => $tenant->id, 'name' => 'Auto', 'email' => 'auto@test.dev', 'password' => 'secret123',
        ]);

        $this->assertSame(0, $user->memberships()->count());
    }

    /** A platform user belongs to no tenant, so it gets no membership and that is correct. */
    public function test_a_platform_user_gets_no_membership(): void
    {
        $user = User::create([
            'tenant_id' => null, 'name' => 'Platform', 'email' => 'platform@test.dev',
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
            if (str_contains($source, 'user()->tenant_id') || str_contains($source, 'user->tenant_id')) {
                /*
                 * ONE exception, and it is the migration path itself: MembershipProvisioner reads
                 * the column to move a legacy user INTO a membership. Everything else that used to
                 * be excused here — suspension, the sign-in check, the onboarding step, the audit
                 * stamp — now reads the membership, so the allowlist is a single named file rather
                 * than a list that quietly grows.
                 */
                if (str_contains($file, 'MembershipProvisioner.php')) {
                    continue;
                }
                $offenders[] = str_replace(app_path(), '', $file);
            }
        }

        $this->assertSame([], $offenders,
            'these read users.tenant_id; scope must come from MembershipContext/TenantContext instead');
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
