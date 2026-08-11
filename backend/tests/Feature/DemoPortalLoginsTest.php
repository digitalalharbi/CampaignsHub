<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoPortalLoginsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One demo account per portal, and none that spans two (SIGNUP-006).
 *
 * The defect these tests close was in the sign-in page rather than the data: every portal tab offered
 * the same agency login, so "try the influencer portal" meant signing in as an agency operator and
 * being shown the agency console. A demo set that cannot demonstrate the difference between the
 * portals makes them look identical when they are not — which was exactly the impression REG-001 had
 * to be undone to correct.
 *
 * So the claim is not "five accounts exist". It is that each one reaches its own portal and is refused
 * everywhere else, proven through the real sign-in and the real gates.
 */
final class DemoPortalLoginsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const ACCOUNTS = [
        'admin@campaignshub.io' => 'admin',
        'advertiser@campaignshub.io' => 'app',
        'agency@campaignshub.io' => 'agency',
        'layla@creators.demo' => 'influencers',
        'client@campaignshub.io' => 'portal',
    ];

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        /*
         * Seeded AS A LOCAL INSTALL, which is the thing under test.
         *
         * `DatabaseSeeder` gates the demo chain on `local|demo`, so seeding from `testing` would
         * assert against a world that contains none of these accounts — and pass for the wrong
         * reason. Listing the demo seeders by hand instead would drift from the real order the
         * moment one is added, so the environment is what changes, once, here.
         */
        /*
         * Seeded with the influencers sub-system ON (INFL-OFF-001).
         *
         * This class's claim is that the demo set can demonstrate every portal the product HAS, and
         * each one only its own. That claim does not change when a portal is temporarily withdrawn —
         * what changes is which of them are advertised, and `InfluencersRetiredTest` is where that is
         * asserted, on a seed run with the flag off.
         */
        $this->withInfluencersEnabled();

        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();
    }

    public function test_a_fresh_install_ships_one_demo_account_for_each_of_the_five_portals(): void
    {
        foreach (self::ACCOUNTS as $email => $portal) {
            $user = User::where('email', $email)->first();

            $this->assertNotNull($user, "the demo account for /{$portal} is missing: {$email}");

            if ($portal === 'admin') {
                // The console is reached by the flag, never by a membership: giving the platform owner
                // one would place them inside a workspace they administer.
                $this->assertTrue($user->is_platform_admin, 'the admin demo account must be a platform admin');
                $this->assertSame(0, $user->memberships()->count(), 'the platform owner belongs to no tenant');

                continue;
            }

            $held = $user->memberships()->get()->map(fn ($m) => $m->portal->value)->all();

            $this->assertSame([$portal], $held, "{$email} must hold exactly /{$portal}");
            $this->assertFalse($user->is_platform_admin, "{$email} must not be a platform administrator");
        }
    }

    /**
     * No demo account is a skeleton key.
     *
     * The original defect was one agency login standing in for all five; this asserts the opposite
     * directly — no account holds a second portal, and none of the four customers can administer.
     */
    public function test_no_demo_account_spans_two_portals(): void
    {
        foreach (array_keys(self::ACCOUNTS) as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->assertLessThanOrEqual(
                1,
                $user->memberships()->count(),
                "{$email} holds more than one portal — a demo account that spans two cannot demonstrate either",
            );
        }
    }

    /**
     * Signing in through a portal the account does not hold is refused IN THE FORM (LOGIN-003).
     *
     * No session is created and nothing is navigated — the same shape of answer as a wrong password,
     * because it is the same kind of mistake.
     */
    public function test_each_account_is_refused_at_every_portal_but_its_own(): void
    {
        // The portals a sign-in may name. `/portal` is excluded: it is still served by its own OTP
        // engine, so a password sign-in for it is not a thing that exists to refuse.
        // The portals a sign-in may name. `/portal` is excluded: it is still served by its own OTP
        // engine, so a password sign-in for it is not a thing that exists to refuse.
        $portals = [Portal::App->value, Portal::Agency->value, Portal::Influencers->value];
        $accounts = [
            'advertiser@campaignshub.io' => 'app',
            'agency@campaignshub.io' => 'agency',
            'layla@creators.demo' => 'influencers',
        ];

        /*
         * Refusals first, then the successes.
         *
         * Interleaving them would leave a session from the previous account's successful sign-in in
         * the client's cookie jar, and `assertGuest` would then be failing on that rather than on the
         * refusal under test.
         */
        foreach ($accounts as $email => $own) {
            foreach ($portals as $portal) {
                if ($portal === $own) {
                    continue;
                }

                $response = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
                    'email' => $email, 'password' => 'password', 'portal' => $portal,
                ]);

                $response->assertForbidden()
                    ->assertJsonPath('meta.portal_mismatch', true)
                    ->assertJsonPath('meta.requested_portal', $portal);

                // …and told where they SHOULD go, so the form can offer a way through. The
                // destination is a landing PATH, which may be deeper than the portal root.
                $this->assertStringStartsWith(
                    '/'.$own,
                    (string) $response->json('meta.destination'),
                    'the refusal must name the portal this account actually holds',
                );

                // No session was created: a wrong portal is refused before `Auth::login`, exactly
                // like a wrong password.
                $this->assertGuest();
            }
        }

        // …and each one does reach its own.
        foreach ($accounts as $email => $own) {
            $this->flushSession();
            $this->app['auth']->forgetGuards();

            $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
                'email' => $email, 'password' => 'password', 'portal' => $own,
            ])->assertOk();
        }
    }

    /**
     * The client portal's demo customer is confined to ONE client space.
     *
     * A client-portal membership with no scope would show this customer the agency's whole book of
     * business — which is the failure `MembershipGrant::forAgencyClient()` is named after.
     */
    public function test_the_portal_customer_is_confined_to_their_own_client_space(): void
    {
        $membership = User::where('email', 'client@campaignshub.io')->firstOrFail()
            ->memberships()->firstOrFail();

        $this->assertSame(Portal::ClientPortal, $membership->portal);
        $this->assertCount(1, $membership->clientScopeIds(), 'exactly one client space, never all of them');
    }

    /**
     * The influencers portal ships BOTH of its sides (REVIEW-001).
     *
     * `layla@creators.demo` is a creator and is correctly refused the agency surfaces, which meant
     * the operational half of that portal — roster, collaborations, nominations, tracking — had no
     * demo login at all and could not be demonstrated by signing in.
     *
     * Two accounts in one portal is not what `test_no_demo_account_spans_two_portals` forbids. That
     * rule is about an account holding two PORTALS; these two are opposite sides of one agreement,
     * and a portal that ships only one of them cannot show what it is for.
     */
    public function test_the_influencers_portal_ships_an_agency_side_login_as_well_as_a_creator(): void
    {
        $operator = User::where('email', 'talent@demo-agency.local')->first();

        $this->assertNotNull($operator, 'the agency side of /influencers has no demo login');
        $this->assertSame(
            ['influencers'],
            $operator->memberships()->get()->map(fn ($m) => $m->portal->value)->all(),
        );

        // The two sides differ where it matters: one may answer a nomination, the other may not.
        $this->assertTrue($operator->hasPermission('influencers.approve'));
        $this->assertTrue($operator->hasPermission('influencers.view_costs'));

        $creator = User::where('email', 'layla@creators.demo')->firstOrFail();
        $this->assertFalse(
            $creator->hasPermission('influencers.view'),
            'the creator must not hold the agency surfaces — that is what makes the two sides distinct',
        );
    }

    /**
     * Demo accounts are development-only.
     *
     * A deployed install must not carry an account whose password is published in a seeder, and the
     * seeder refuses to run rather than relying on nobody calling it.
     */
    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $before = User::where('email', 'admin@campaignshub.io')->firstOrFail()->updated_at;

        // Called directly rather than through `$this->seed()`, which asks for confirmation before
        // running anything in production — the very guard that would hide whether the SEEDER refuses.
        app()->detectEnvironment(fn () => 'production');
        (new DemoPortalLoginsSeeder)->run();
        app()->detectEnvironment(fn () => 'testing');

        $this->assertEquals(
            $before,
            User::where('email', 'admin@campaignshub.io')->firstOrFail()->updated_at,
            'the seeder touched an account in production',
        );
    }
}
