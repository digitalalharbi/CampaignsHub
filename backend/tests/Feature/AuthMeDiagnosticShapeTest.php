<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AUTH-SESSION-RACE-OBS — the shape the registration walk's diagnostic reads, held here.
 *
 * ## Why a test exists for a test's instrument
 *
 * `registration-onboarding.spec.ts` reports the account's own state when the walk lands somewhere
 * other than the wizard. Its first version read `data.onboarding_completed_at`, `data.tenant.id` and
 * `data.memberships` — none of which `/auth/me` has ever carried at that depth, because the endpoint
 * answers `{ data: { user } }`.
 *
 * So every occurrence printed three nulls, and three nulls read as «this account has no tenant and
 * was never onboarded»: a confident diagnosis the evidence did not support, and the opposite of what
 * the URL implied. An instrument that reports the same value whatever happened is worse than no
 * instrument, and the failure it describes was left open on the strength of it.
 *
 * The fields below are the ones `OnboardingGate` actually branches on. If the payload is reshaped,
 * the diagnostic goes blind again in exactly the same way — silently, and only visible the next time
 * something fails. This is what makes that impossible rather than unlikely.
 */
final class AuthMeDiagnosticShapeTest extends TestCase
{
    use RefreshDatabase;

    private function signedIn(?Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'A', 'email' => 'a-'.uniqid().'@t.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);

        if ($tenant !== null) {
            app(TenantContext::class)->setTenantId($tenant->id);
            $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'O', 'slug' => 'o-'.uniqid()]);
            $role->givePermissionTo(...Permission::pluck('key')->all());
            $this->grantMembership($user, $tenant);
            $user->assignRole($role);
        }

        return $user;
    }

    /** @return array<string, mixed> */
    private function me(User $user): array
    {
        return (array) $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->json('data.user');
    }

    /**
     * The four inputs the gate reads, at the depth the diagnostic reads them.
     *
     * `email_verified`, `is_platform_admin`, `account` and `account.onboarding.completed` decide, in
     * that order, whether a signed-in reader is sent to `/verify-email`, through, to `/switch`, or to
     * `/onboarding`. A failure that names them names the branch.
     */
    public function test_the_gate_inputs_are_where_the_diagnostic_looks_for_them(): void
    {
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'],
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);

        $user = $this->me($this->signedIn($tenant));

        $this->assertArrayHasKey('email_verified', $user);
        $this->assertArrayHasKey('is_platform_admin', $user);
        $this->assertArrayHasKey('tenant_ids', $user);
        $this->assertArrayHasKey('account', $user);
        $this->assertIsArray($user['account'], 'a member’s account block is what the gate needs to read onboarding from');
        $this->assertArrayHasKey('onboarding', $user['account']);
        $this->assertArrayHasKey('completed', $user['account']['onboarding']);
        $this->assertTrue($user['account']['onboarding']['completed']);
    }

    /**
     * An account that has NOT finished onboarding says so, rather than omitting the fact.
     *
     * This is the state the walk is asserting about, and the one whose absence the broken probe could
     * not distinguish from anything else.
     */
    public function test_an_unfinished_onboarding_is_reported_as_incomplete(): void
    {
        $this->seed(PermissionSeeder::class);

        $tenant = Tenant::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'],
            'onboarding_step' => 'workspace', 'onboarding_completed_at' => null,
        ]);

        $user = $this->me($this->signedIn($tenant));

        $this->assertFalse($user['account']['onboarding']['completed']);
    }

    /**
     * The payload is nested under `user`, which is the assumption the probe was wrong about.
     *
     * Asserted directly rather than implied by the keys above: a future `me()` that returned the
     * resource unwrapped would satisfy every other assertion here through `json('data.user')`
     * returning null and `assertArrayHasKey` never running.
     */
    public function test_the_payload_is_nested_under_user(): void
    {
        $this->seed(PermissionSeeder::class);
        $tenant = Tenant::create([
            'name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active',
            'account_type' => 'agency', 'enabled_modules' => ['paid_media'],
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);

        $body = (array) $this->actingAs($this->signedIn($tenant), 'sanctum')
            ->getJson('/api/v1/auth/me')->assertOk()->json();

        $this->assertArrayHasKey('user', (array) $body['data'], '/auth/me answers { data: { user } } — the probe reads that depth');
        $this->assertArrayNotHasKey('email_verified', (array) $body['data'], 'the resource is not spread onto `data` itself');
    }
}
