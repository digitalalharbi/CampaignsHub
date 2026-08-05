<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E-ISO-001 — the purge removes fixtures and cannot reach anything else.
 *
 * A command whose job is `forceDelete` on `tenants` needs its refusals tested at least as carefully
 * as its deletions, so the negative cases here outnumber the positive one. Each names a specific way
 * a careless implementation would destroy real work: pattern-matching a name (the residue carries the
 * product's own default tenant names, «My Agency» and «BrandCo», so the database holds hundreds that
 * are indistinguishable from something a person typed), treating a shared workspace as a fixture
 * because a test account was invited into it, deleting the demo seed the whole live-review programme
 * signs in with, or writing before anybody has seen what it would write.
 */
final class PurgeE2eResidueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    private function tenant(string $slug, string $name = 'A Tenant'): Tenant
    {
        return Tenant::create(['name' => $name, 'slug' => $slug, 'status' => 'active', 'account_type' => 'agency']);
    }

    private function member(Tenant $tenant, string $email): User
    {
        $user = User::create([
            'name' => 'Member', 'email' => $email, 'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $tenant, portal: Portal::Agency, role: 'member',
        ));

        return $user;
    }

    /**
     * The positive case, and the reason the command exists: a whole registration journey — the
     * account, the tenant it created and the client space under it — goes in one pass.
     */
    public function test_it_removes_a_tenant_whose_every_member_is_a_reserved_domain_fixture(): void
    {
        $residue = $this->tenant('my-agency-1785954737622', 'My Agency');
        $this->member($residue, 'agency.chromium-1785954737622@example.com');
        ClientWorkspace::create([
            'tenant_id' => $residue->id, 'name' => 'CC Co chromium-1785954737622', 'slug' => 'cc-co-1785954737622',
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(0);

        $this->assertDatabaseMissing('tenants', ['id' => $residue->id]);
        $this->assertDatabaseMissing('users', ['email' => 'agency.chromium-1785954737622@example.com']);
        // The client space carries no fixture marking of its own — it goes because the tenant does.
        $this->assertDatabaseMissing('client_workspaces', ['slug' => 'cc-co-1785954737622']);
    }

    /**
     * One real member is enough to keep the whole workspace.
     *
     * The invitation spec adds a fixture account to a REAL agency, so «has a fixture member» is not
     * evidence of anything. Getting this backwards would delete a customer's workspace because
     * somebody once tested an invitation against it.
     */
    public function test_a_workspace_with_one_real_member_survives(): void
    {
        $shared = $this->tenant('real-agency', 'Real Agency');
        $this->member($shared, 'founder@bareeq-retail.sa');
        $this->member($shared, 'member.webkit-1785954565760@example.com');

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(0);

        $this->assertDatabaseHas('tenants', ['id' => $shared->id]);
        $this->assertDatabaseHas('users', ['email' => 'founder@bareeq-retail.sa']);
        // The fixture ACCOUNT still goes — it is provably a fixture. Only the workspace is spared.
        $this->assertDatabaseMissing('users', ['email' => 'member.webkit-1785954565760@example.com']);
    }

    /**
     * The demo seed is protected by slug, unconditionally.
     *
     * Its accounts are `@demo-agency.local` rather than a reserved domain, so the general rule
     * already spares it — but the E2E suite signs in AS the demo owner and creates rows inside that
     * tenant, and one future fixture seeded with an `@example.com` address would otherwise be enough
     * to delete every live-review account in the product.
     */
    public function test_it_never_touches_a_demo_tenant_even_when_every_member_is_a_fixture(): void
    {
        $demo = $this->tenant('demo-agency', 'Demo Agency');
        $this->member($demo, 'owner.chromium-1785954737622@example.com');

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(0);

        $this->assertDatabaseHas('tenants', ['id' => $demo->id]);
    }

    /** Nothing is written until somebody has read what would be written. */
    public function test_without_force_it_reports_and_deletes_nothing(): void
    {
        $residue = $this->tenant('residue-agency');
        $this->member($residue, 'brand.firefox-1785953875712@example.com');

        $this->artisan('db:purge-e2e-residue')
            ->expectsOutputToContain('Dry run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('tenants', ['id' => $residue->id]);
        $this->assertDatabaseHas('users', ['email' => 'brand.firefox-1785953875712@example.com']);
    }

    /**
     * A tenant with no members at all is NOT proof of anything — a registration being provisioned
     * right now looks identical — so it survives unless the operator asks for it by name.
     */
    public function test_a_member_less_tenant_survives_unless_orphans_are_asked_for(): void
    {
        $orphan = $this->tenant('half-finished-signup');

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(0);
        $this->assertDatabaseHas('tenants', ['id' => $orphan->id]);

        $this->artisan('db:purge-e2e-residue --force --include-orphans')->assertExitCode(0);
        $this->assertDatabaseMissing('tenants', ['id' => $orphan->id]);
    }

    /** A routable address is a person's, whatever it looks like. */
    public function test_an_account_on_a_routable_domain_is_never_a_fixture(): void
    {
        $real = $this->tenant('lookalike-agency', 'My Agency');
        // Reads like a fixture and is not one: `example.com.sa` is a domain somebody can hold, and a
        // suffix match on «example.com» would have taken it.
        $this->member($real, 'chromium-1785954737622@example.com.sa');

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(0);

        $this->assertDatabaseHas('tenants', ['id' => $real->id]);
        $this->assertDatabaseHas('users', ['email' => 'chromium-1785954737622@example.com.sa']);
    }

    /** The command is a development tool and says so by refusing to be anything else. */
    public function test_it_refuses_to_run_outside_local_testing_and_e2e(): void
    {
        $residue = $this->tenant('residue-agency');
        $this->member($residue, 'agency.chromium-1@example.com');

        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:purge-e2e-residue --force')->assertExitCode(1);

        $this->assertDatabaseHas('tenants', ['id' => $residue->id]);
    }
}
