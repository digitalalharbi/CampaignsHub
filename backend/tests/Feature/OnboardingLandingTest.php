<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\PortalResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AUTH-SESSION-RACE-OBS — the actual root cause, and it was never a race.
 *
 * ## What the gate's own instrumentation finally said
 *
 * «signed in and landed on /app/dashboard rather than the wizard; the account reports
 * {account: "present", onboarding_completed: false, tenant_ids: 1}».
 *
 * A brand-new paid account, a membership, and onboarding NOT finished — and the server sent it to a
 * dashboard for a workspace nobody had configured.
 *
 * ## Two deciders, and the server's was missing a condition
 *
 * `OnboardingGate` refuses `/app` until `account.onboarding.completed`. `landingPathFor()` sent
 * anyone to `/onboarding` only when they had NO MEMBERSHIP at all — a rule written when signing up
 * and being granted a workspace were the same event. Paid registration creates the membership
 * before onboarding is done, so the condition stopped covering the case it was written for.
 *
 * The gate is the second decider and would correct it, which is why this looked intermittent rather
 * than broken: the correction only lands if the gate re-renders before anything else settles, and
 * its own docblock records the case where it does not — «nothing re-decides once the navigation has
 * happened». So the failure moved between browsers and looked like a session race.
 *
 * One rule, on the server, and the gate becomes the confirmation rather than the only guard.
 *
 * ## The switcher still wins
 *
 * Somebody holding two memberships is asked WHICH workspace before being asked to finish setting one
 * up — the question «which of these?» has to be answered before «is that one ready?» means anything.
 */
final class OnboardingLandingTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $name, string $type, bool $onboarded): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug()->value().'-'.uniqid(),
            'status' => 'active',
            'account_type' => $type,
            'onboarding_step' => $onboarded ? 'done' : 'workspace',
            'onboarding_completed_at' => $onboarded ? now() : null,
        ]);
    }

    private function member(Tenant $tenant, Portal $portal, string $email): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => 'secret1234']);

        Membership::create([
            'user_id' => $user->id, 'tenant_id' => $tenant->id, 'portal' => $portal->value,
            'role' => 'member', 'status' => 'active', 'is_default' => true,
        ]);

        return $user;
    }

    /** The case the gate caught and the server did not. */
    public function test_an_account_that_has_not_finished_onboarding_lands_on_the_wizard(): void
    {
        $user = $this->member($this->tenant('Brand New', 'brand', onboarded: false), Portal::App, 'new@t.test');

        $this->assertSame('/onboarding', app(PortalResolver::class)->landingPathFor($user));
    }

    /** An agency is no different — the portal is not the question, the setup is. */
    public function test_an_unfinished_agency_lands_on_the_wizard_too(): void
    {
        $user = $this->member($this->tenant('Agency New', 'agency', onboarded: false), Portal::Agency, 'agency@t.test');

        $this->assertSame('/onboarding', app(PortalResolver::class)->landingPathFor($user));
    }

    /** A finished account is unaffected, which is the regression this must not cause. */
    public function test_a_finished_account_still_lands_in_its_portal(): void
    {
        $user = $this->member($this->tenant('Settled', 'brand', onboarded: true), Portal::App, 'settled@t.test');

        $this->assertSame('/app/dashboard', app(PortalResolver::class)->landingPathFor($user));
    }

    /**
     * «Which workspace?» is answered before «is it ready?».
     *
     * Sending somebody with two memberships to one tenant's wizard would pick a workspace on their
     * behalf, which is the decision the switcher exists to leave with them.
     */
    public function test_the_switcher_still_comes_first_for_a_multi_membership_user(): void
    {
        $a = $this->tenant('Multi A', 'agency', onboarded: false);
        $b = $this->tenant('Multi B', 'brand', onboarded: true);

        $user = User::create(['name' => 'U', 'email' => 'multi@t.test', 'password' => 'secret1234']);
        foreach ([[$a, Portal::Agency, true], [$b, Portal::App, false]] as [$t, $portal, $default]) {
            Membership::create([
                'user_id' => $user->id, 'tenant_id' => $t->id, 'portal' => $portal->value,
                'role' => 'member', 'status' => 'active', 'is_default' => $default,
            ]);
        }

        $this->assertSame('/switch', app(PortalResolver::class)->landingPathFor($user));
    }

    /** The platform owner has no workspace to onboard, and never had one. */
    public function test_the_platform_owner_is_never_sent_to_a_wizard(): void
    {
        $user = User::create(['name' => 'Root', 'email' => 'root@t.test', 'password' => 'secret1234']);
        // Not fillable — the flag that grants the whole platform is set deliberately, never by mass assignment.
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->assertSame('/admin', app(PortalResolver::class)->landingPathFor($user));
    }
}
