<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Requests\Models\RequestType;
use App\Domains\Tenancy\Enums\Portal;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Influencers & UGC is withdrawn, and nothing was lost doing it (INFL-OFF-001).
 *
 * Two claims, and they pull in opposite directions, which is why both are here:
 *
 *   **Closed.** The portal admits nobody — not a member, not an operator, not a hand-written
 *   payload — and the product stops advertising it: no door, no registration option, no demo login,
 *   no new requests for the module.
 *
 *   **Intact.** Every table, model, service, controller, permission and test is still present, the
 *   service type still exists with its requests attached, and the sub-system's own suite still runs
 *   green with the flag on. Turning it back on is a decision, not a rebuild.
 *
 * A withdrawal that only did the first half would be indistinguishable, six months from now, from
 * having deleted the thing — and the deletion would only be discovered when somebody tried to
 * restore it.
 */
final class InfluencersRetiredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seeded as a real local install, with the flag at its shipped value.
        app()->detectEnvironment(fn () => 'local');
        $this->seed(DatabaseSeeder::class);
        app()->detectEnvironment(fn () => 'testing');
        $this->assertingAcrossTenants();
    }

    public function test_the_sub_system_ships_switched_off(): void
    {
        $this->assertFalse(Portal::Influencers->isEnabled());
        $this->assertFalse(config('brand.features.influencers_ugc_enabled'));
    }

    /** The portal is not among the ones a visitor may be offered. */
    public function test_the_portal_is_not_offered(): void
    {
        $offered = array_map(fn (Portal $p) => $p->value, Portal::offeredMembershipPortals());

        $this->assertNotContains('influencers', $offered);
        $this->assertContains('app', $offered);
        $this->assertContains('agency', $offered);
        $this->assertContains('portal', $offered);
    }

    /**
     * …and the enum still knows about it, because every stored row does.
     *
     * Removing the case is the other way to express "withdrawn", and it would orphan every
     * membership, collaboration and nomination that names the portal — including the ones that have
     * to survive to be handed back.
     */
    public function test_the_portal_still_exists_as_a_concept(): void
    {
        $this->assertNotNull(Portal::tryFrom('influencers'));
        $this->assertContains('influencers', Portal::values());
    }

    /** No demo login is advertised for a portal nobody can open. */
    public function test_no_influencer_demo_login_is_seeded(): void
    {
        $this->assertNull(
            User::query()->where('email', 'talent@demo-agency.local')->first(),
            'a demo login was seeded for a portal that refuses it',
        );
    }

    /** A registration cannot ask for the withdrawn portal, however the payload is written. */
    public function test_registration_refuses_the_withdrawn_portal(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Talent Co',
            'name' => 'Applicant',
            'email' => 'applicant@example.test',
            'password' => 'secret-passw0rd',
            'password_confirmation' => 'secret-passw0rd',
            'requested_portal' => 'influencers',
        ], ['Origin' => 'http://localhost:5173'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('requested_portal');
    }

    /** …and cannot ask for its module either. */
    public function test_registration_refuses_the_withdrawn_service_module(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Talent Co',
            'name' => 'Applicant',
            'email' => 'applicant2@example.test',
            'password' => 'secret-passw0rd',
            'password_confirmation' => 'secret-passw0rd',
            'service' => 'influencer_marketing',
        ], ['Origin' => 'http://localhost:5173'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service');
    }

    /**
     * The DATA is untouched — the part that would be impossible to notice going wrong.
     *
     * Every table the sub-system owns is still present. A withdrawal that quietly dropped one of
     * them would look identical from the outside until the day somebody tried to switch it back on.
     */
    public function test_every_table_the_sub_system_owns_still_exists(): void
    {
        foreach ([
            'influencers',
            'influencer_collaborations',
            'influencer_deliverables',
            'influencer_deliverable_results',
            'influencer_nominations',
            'influencer_tracking_assets',
        ] as $table) {
            $this->assertTrue(
                \Schema::hasTable($table),
                "the {$table} table was dropped rather than left alone",
            );
        }
    }

    /** Its permissions are still seeded, so restoring it does not mean re-granting anything. */
    public function test_the_permissions_survive(): void
    {
        foreach (['influencers.view', 'influencers.manage', 'influencers.approve', 'influencers.view_costs'] as $key) {
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
    }

    /** The service type is preserved inside Requests rather than deactivated or deleted. */
    public function test_the_service_type_is_preserved_inside_requests(): void
    {
        $type = RequestType::query()->where('key', 'influencer_ugc')->first();

        $this->assertNotNull($type);
        $this->assertTrue($type->is_active);
        $this->assertSame('influencer_marketing', $type->module);

        // …and it is not on offer.
        $this->assertFalse(
            RequestType::query()->offered()->where('key', 'influencer_ugc')->exists(),
            'a withdrawn service type is still being offered for new requests',
        );
    }
}
