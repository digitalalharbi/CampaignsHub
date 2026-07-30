<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Role;
use App\Domains\Influencers\Actions\LinkCreatorAccount;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Tenancy\Actions\GrantMembership;
use App\Domains\Tenancy\DTOs\MembershipGrant;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The creator's own side of the influencers portal (INFL-002).
 *
 * The portal holds two populations that are NOT two permission levels of one thing — they are
 * opposite parties to an agreement, and almost every rule below exists because treating them as one
 * surface with a "hide costs" flag leaks something:
 *
 *   - the money INVERTS. The agency sees what the client is billed; the creator sees what they are
 *     paid. Show a creator `agreed_fee` and you have disclosed the agency's markup on their own work
 *     to the one person who must not be told it.
 *   - a creator sees nothing until terms were actually SENT. Before that the fee is still being
 *     argued about internally.
 *   - a creator submits; only the agency approves. Otherwise they sign off their own work.
 *   - no roster link means NO collaborations, never all of them.
 */
final class CreatorPortalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->agency = Tenant::create([
            'name' => 'Creator Agency', 'slug' => 'creator-agency-'.uniqid(),
            'status' => 'active', 'account_type' => 'agency',
        ]);
        $this->holdingTenant((string) $this->agency->id);
    }

    private function creator(string $name, ?string $handle = null): Influencer
    {
        return Influencer::create([
            'tenant_id' => $this->agency->id, 'name' => $name, 'handle' => $handle,
            'primary_platform' => 'instagram', 'followers' => 120_000, 'engagement_rate' => 4.25,
            'status' => 'active', 'internal_notes' => 'Asked for double last time.',
        ]);
    }

    /** A creator WITH portal access, and the login that reaches it. */
    private function signedInCreator(string $name, string $email): array
    {
        $influencer = $this->creator($name, str($name)->slug()->value());
        $result = app(LinkCreatorAccount::class)->execute($influencer, $email);

        return [$influencer->fresh(), $result['user']];
    }

    private function collaboration(Influencer $creator, array $over = []): InfluencerCollaboration
    {
        return InfluencerCollaboration::create(array_merge([
            'tenant_id' => $this->agency->id,
            'influencer_id' => $creator->id,
            'title' => 'Launch push',
            'status' => 'draft',
            'currency' => 'SAR',
            'agreed_fee' => 25000,
            'influencer_fee' => 18000,
        ], $over));
    }

    /** Puts a collaboration into the state a creator can actually see: terms sent. */
    private function offered(Influencer $creator, array $over = []): InfluencerCollaboration
    {
        $c = $this->collaboration($creator, $over);
        $c->forceFill(['terms_sent_at' => now(), 'status' => 'awaiting_creator'])->save();

        return $c->fresh();
    }

    /** @param  list<string>  $permissions */
    private function operator(string $email, array $permissions): User
    {
        $user = User::create([
            'tenant_id' => $this->agency->id, 'name' => 'Op', 'email' => $email,
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);

        $role = Role::create(['tenant_id' => $this->agency->id, 'name' => 'R', 'slug' => 'r-'.uniqid()]);
        $role->givePermissionTo(...$permissions);
        $user->assignRole($role);

        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $user, tenant: $this->agency, portal: Portal::Influencers, role: 'member',
        ));

        return $user;
    }

    // ---------------------------------------------------------------- the money inversion

    /**
     * THE central rule of this surface. The creator is told their own fee and never the client's
     * price, so the agency's margin on their work stays the agency's business.
     */
    public function test_a_creator_sees_their_own_fee_and_never_the_clients_price(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla@creators.test');
        $this->offered($influencer);

        $payload = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/influencers/me/collaborations')->assertOk()->json('data.collaborations.0');

        $this->assertSame('18000.00', $payload['fee']);
        $this->assertArrayNotHasKey('agreed_fee', $payload);
        $this->assertArrayNotHasKey('margin', $payload);
        $this->assertArrayNotHasKey('influencer_fee', $payload);
        // Belt and braces: the agency's price must not appear anywhere in the response body, under
        // any key. A nested presenter added later could reintroduce it without failing the above.
        $this->assertStringNotContainsString('25000', json_encode($payload));
    }

    /** The notes the agency keeps ABOUT a creator are never part of the creator's own view. */
    public function test_internal_notes_never_reach_the_creator(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla2@creators.test');
        $this->offered($influencer, ['internal_notes' => 'Chased twice for the last deliverable.']);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/influencers/me/collaborations')->assertOk()->getContent();

        $this->assertStringNotContainsString('Chased twice', $body);
        $this->assertStringNotContainsString('Asked for double', $body);
    }

    // ---------------------------------------------------------------- who reaches what

    /** One creator never sees another's agreement, fee or brief. */
    public function test_a_creator_sees_only_their_own_collaborations(): void
    {
        [$mine, $user] = $this->signedInCreator('Layla', 'layla3@creators.test');
        $theirs = $this->creator('Omar', 'omar-other');

        $this->offered($mine, ['title' => 'Mine']);
        $this->offered($theirs, ['title' => 'Theirs']);

        $titles = array_column(
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/me/collaborations')
                ->assertOk()->json('data.collaborations'),
            'title',
        );

        $this->assertSame(['Mine'], $titles);
    }

    /** Reaching for another creator's collaboration by id is a 404, not a redacted 200. */
    public function test_another_creators_collaboration_is_not_reachable_by_id(): void
    {
        [, $user] = $this->signedInCreator('Layla', 'layla4@creators.test');
        $theirs = $this->offered($this->creator('Omar', 'omar-id'));

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/influencers/me/collaborations/{$theirs->id}")->assertNotFound();
    }

    /**
     * Fail-closed, in the same shape as ClientScopeResolver: a login with no roster entry reaches
     * NOTHING. Read as "unrestricted", the absence of a link would hand them the whole roster.
     */
    public function test_a_login_with_no_roster_entry_reaches_nothing(): void
    {
        $this->offered($this->creator('Layla', 'layla-unlinked'));

        $stranger = User::create([
            'tenant_id' => $this->agency->id, 'name' => 'Nobody', 'email' => 'nobody@creators.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $stranger, tenant: $this->agency, portal: Portal::Influencers, role: 'creator',
        ));

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/v1/influencers/me/collaborations')
            ->assertOk()->assertJsonCount(0, 'data.collaborations');
    }

    /**
     * …and /me says so plainly rather than rendering an empty portal. "You are not a creator here"
     * and "you are a creator with nothing on" are different answers, and a blank screen meaning the
     * first gets filed as a bug for weeks.
     */
    public function test_me_refuses_a_login_that_is_not_a_creator_here(): void
    {
        $stranger = User::create([
            'tenant_id' => $this->agency->id, 'name' => 'Nobody', 'email' => 'nobody2@creators.test',
            'password' => 'secret123', 'email_verified_at' => now(),
        ]);
        app(GrantMembership::class)->execute(new MembershipGrant(
            user: $stranger, tenant: $this->agency, portal: Portal::Influencers, role: 'creator',
        ));

        $this->actingAs($stranger, 'sanctum')->getJson('/api/v1/influencers/me')->assertForbidden();
    }

    /**
     * A creator holds no `influencers.*` permission, so the agency's own endpoints refuse them.
     * This is the default rather than a special case — and it is asserted rather than trusted.
     */
    public function test_a_creator_cannot_reach_the_agency_surface(): void
    {
        [, $user] = $this->signedInCreator('Layla', 'layla5@creators.test');

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/roster')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/collaborations')->assertForbidden();
    }

    // ---------------------------------------------------------------- the offer gate

    /** A draft nobody has offered yet is invisible to the creator — the fee is still being argued. */
    public function test_a_collaboration_with_no_terms_sent_is_invisible_to_the_creator(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla6@creators.test');
        $draft = $this->collaboration($influencer, ['title' => 'Still negotiating']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/me/collaborations')
            ->assertOk()->assertJsonCount(0, 'data.collaborations');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/influencers/me/collaborations/{$draft->id}")->assertNotFound();
    }

    /** Sending terms is what makes it visible, and it is an operator's act. */
    public function test_sending_terms_makes_the_offer_visible(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla7@creators.test');
        $draft = $this->collaboration($influencer);
        $manager = $this->operator('mgr1@infl.test', ['influencers.view', 'influencers.manage', 'clients.view_all']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$draft->id}/send-terms")->assertOk();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/me/collaborations')
            ->assertOk()->assertJsonCount(1, 'data.collaborations');
    }

    /** Terms cannot be sent to somebody who has no way to read them. */
    public function test_terms_cannot_be_sent_to_a_creator_with_no_portal_access(): void
    {
        $offline = $this->creator('Omar', 'omar-offline');
        $draft = $this->collaboration($offline);
        $manager = $this->operator('mgr2@infl.test', ['influencers.view', 'influencers.manage', 'clients.view_all']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$draft->id}/send-terms")
            ->assertStatus(422);
    }

    /** Nor with a blank where the creator's pay should be. */
    public function test_terms_cannot_be_sent_without_a_creator_fee(): void
    {
        [$influencer] = $this->signedInCreator('Layla', 'layla8@creators.test');
        $draft = $this->collaboration($influencer, ['influencer_fee' => null]);
        $manager = $this->operator('mgr3@infl.test', ['influencers.view', 'influencers.manage', 'clients.view_all']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$draft->id}/send-terms")
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------- the agreement

    public function test_a_creator_accepts_terms_and_the_agreement_becomes_active(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla9@creators.test');
        $offer = $this->offered($influencer);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", ['decision' => 'accepted'])
            ->assertOk()
            ->assertJsonPath('data.collaboration.decision', 'accepted')
            ->assertJsonPath('data.collaboration.can_submit', true);

        $this->assertSame('active', $offer->fresh()->status);
    }

    public function test_a_declined_offer_records_the_reason(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla10@creators.test');
        $offer = $this->offered($influencer);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", [
                'decision' => 'declined', 'reason' => 'Clashes with an exclusivity I already signed.',
            ])->assertOk();

        $fresh = $offer->fresh();
        $this->assertSame('declined', $fresh->creator_decision);
        $this->assertSame('declined', $fresh->status);
        $this->assertStringContainsString('exclusivity', (string) $fresh->creator_decline_reason);
    }

    /**
     * Answerable once. Flipping an answer after work began leaves the agency unable to say whether
     * the piece they are holding was ever agreed to.
     */
    public function test_an_answered_offer_cannot_be_answered_again(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla11@creators.test');
        $offer = $this->offered($influencer);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", ['decision' => 'accepted'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", ['decision' => 'declined'])
            ->assertStatus(422);

        $this->assertSame('accepted', $offer->fresh()->creator_decision);
    }

    /** Re-sending revised terms clears the old answer, so a stale "declined" cannot hide a new offer. */
    public function test_resending_terms_reopens_the_decision(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla12@creators.test');
        $offer = $this->offered($influencer);
        $manager = $this->operator('mgr4@infl.test', ['influencers.view', 'influencers.manage', 'clients.view_all']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", ['decision' => 'declined'])
            ->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$offer->id}/send-terms")->assertOk();

        $this->assertNull($offer->fresh()->creator_decision);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/respond", ['decision' => 'accepted'])
            ->assertOk();
    }

    // ---------------------------------------------------------------- submitting work

    public function test_a_creator_submits_a_deliverable_for_review(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla13@creators.test');
        $offer = $this->offered($influencer);
        $offer->forceFill(['creator_decision' => 'accepted', 'creator_responded_at' => now()])->save();
        $item = $offer->deliverables()->create([
            'tenant_id' => $this->agency->id, 'type' => 'reel', 'platform' => 'instagram', 'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/deliverables/{$item->id}/submit", [
                'submitted_url' => 'https://instagram.com/p/abc123',
            ])->assertOk()
            ->assertJsonPath('data.collaboration.deliverables.0.status', 'submitted');

        $this->assertNotNull($item->fresh()->submitted_at);
    }

    /** Work cannot be submitted against terms nobody has agreed to. */
    public function test_work_cannot_be_submitted_before_the_terms_are_accepted(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla14@creators.test');
        $offer = $this->offered($influencer);
        $item = $offer->deliverables()->create([
            'tenant_id' => $this->agency->id, 'type' => 'reel', 'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/deliverables/{$item->id}/submit", [
                'submitted_url' => 'https://instagram.com/p/abc123',
            ])->assertStatus(422);
    }

    /**
     * The separation of powers in this domain. A creator has no route that sets `approved` — they
     * submit, and the agency decides. Asserted on the agency endpoint too, because that is the one
     * a creator might reach for.
     */
    public function test_a_creator_cannot_approve_their_own_work(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla15@creators.test');
        $offer = $this->offered($influencer);
        $offer->forceFill(['creator_decision' => 'accepted'])->save();
        $item = $offer->deliverables()->create([
            'tenant_id' => $this->agency->id, 'type' => 'reel', 'status' => 'submitted',
        ]);

        // The agency's update route is permission-gated, and a creator holds none.
        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/v1/influencers/collaborations/{$offer->id}/deliverables/{$item->id}", [
                'status' => 'approved',
            ])->assertForbidden();

        // And their own route cannot be talked into it: an approved piece is not re-submittable.
        $item->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/deliverables/{$item->id}/submit", [
                'submitted_url' => 'https://instagram.com/p/zzz',
            ])->assertStatus(422);

        $this->assertSame('approved', $item->fresh()->status);
    }

    /** A rejection's feedback is cleared on resubmission — it described work already replaced. */
    public function test_resubmitting_after_a_rejection_clears_the_stale_feedback(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla16@creators.test');
        $offer = $this->offered($influencer);
        $offer->forceFill(['creator_decision' => 'accepted'])->save();
        $item = $offer->deliverables()->create([
            'tenant_id' => $this->agency->id, 'type' => 'reel', 'status' => 'rejected',
            'feedback' => 'The logo is cropped.',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$offer->id}/deliverables/{$item->id}/submit", [
                'submitted_url' => 'https://instagram.com/p/take-two',
            ])->assertOk();

        $this->assertNull($item->fresh()->feedback);
    }

    /** A deliverable id from someone else's collaboration is a 404, not a write. */
    public function test_a_deliverable_from_another_collaboration_cannot_be_submitted_against(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla17@creators.test');
        $mine = $this->offered($influencer);
        $mine->forceFill(['creator_decision' => 'accepted'])->save();

        $theirs = $this->offered($this->creator('Omar', 'omar-deliv'));
        $stolen = $theirs->deliverables()->create([
            'tenant_id' => $this->agency->id, 'type' => 'reel', 'status' => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/influencers/me/collaborations/{$mine->id}/deliverables/{$stolen->id}/submit", [
                'submitted_url' => 'https://instagram.com/p/nope',
            ])->assertNotFound();

        $this->assertSame('pending', $stolen->fresh()->status);
    }

    // ---------------------------------------------------------------- granting access

    public function test_granting_access_creates_a_login_and_a_creator_membership(): void
    {
        $influencer = $this->creator('Layla', 'layla-grant');
        $manager = $this->operator('mgr5@infl.test', ['influencers.view', 'influencers.manage']);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/roster/{$influencer->id}/access", ['email' => 'Layla@Creators.test'])
            ->assertOk()
            ->assertJsonPath('data.created_account', true)
            // Honest about what did NOT happen: no invitation is emailed.
            ->assertJsonPath('data.delivery', 'not_sent');

        $user = User::where('email', 'layla@creators.test')->firstOrFail();
        $this->assertSame((int) $user->id, (int) $influencer->fresh()->user_id);
        $this->assertTrue($response->json('data.influencer.has_portal_access'));

        $this->assertTrue(Membership::query()
            ->where('user_id', $user->id)->where('tenant_id', $this->agency->id)
            ->where('portal', Portal::Influencers->value)->where('role', 'creator')->exists());
    }

    /** A staff login is never turned into a creator — that would read colleagues' costs unchecked. */
    public function test_a_staff_account_cannot_be_linked_as_a_creator(): void
    {
        $staff = $this->operator('staff@infl.test', ['influencers.view']);
        $influencer = $this->creator('Layla', 'layla-staff');
        $manager = $this->operator('mgr6@infl.test', ['influencers.view', 'influencers.manage']);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/v1/influencers/roster/{$influencer->id}/access", ['email' => $staff->email])
            ->assertStatus(422);

        $this->assertNull($influencer->fresh()->user_id);
    }

    /** Nor is an account stolen from the creator it already belongs to. */
    public function test_an_account_already_linked_elsewhere_is_not_moved(): void
    {
        [$first] = $this->signedInCreator('Layla', 'shared@creators.test');
        $second = $this->creator('Omar', 'omar-steal');

        $this->expectException(RuntimeException::class);
        app(LinkCreatorAccount::class)->execute($second, 'shared@creators.test');

        $this->assertNotNull($first->fresh()->user_id);
    }

    /** `user_id` is not a form field: a roster PATCH carrying it changes nothing. */
    public function test_a_roster_update_cannot_set_the_linked_account(): void
    {
        [, $victim] = $this->signedInCreator('Layla', 'victim@creators.test');
        $other = $this->creator('Omar', 'omar-patch');
        $manager = $this->operator('mgr7@infl.test', ['influencers.view', 'influencers.manage']);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/v1/influencers/roster/{$other->id}", [
                'name' => 'Omar', 'user_id' => $victim->id,
            ])->assertOk();

        $this->assertNull($other->fresh()->user_id);
    }

    /** Withdrawing access ends it immediately and keeps the roster history. */
    public function test_revoking_access_ends_the_creators_session_reach(): void
    {
        [$influencer, $user] = $this->signedInCreator('Layla', 'layla18@creators.test');
        $this->offered($influencer);
        $manager = $this->operator('mgr8@infl.test', ['influencers.view', 'influencers.manage']);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/v1/influencers/roster/{$influencer->id}/access")->assertOk();

        $this->assertNull($influencer->fresh()->user_id);
        // The roster entry itself survives — who the agency worked with is part of the record.
        $this->assertNotNull(Influencer::query()->whereKey($influencer->id)->first());

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/influencers/me')->assertForbidden();
    }

    /** A creator in one agency is not a creator in another. */
    public function test_creator_access_does_not_cross_tenants(): void
    {
        [, $user] = $this->signedInCreator('Layla', 'layla19@creators.test');

        $other = Tenant::create([
            'name' => 'Other Agency', 'slug' => 'other-'.uniqid(),
            'status' => 'active', 'account_type' => 'agency',
        ]);

        // Build the rival agency's data while the context genuinely points at it — a closure that
        // only *claims* to switch tenants writes the rows under the original scope and the
        // assertion then passes for the wrong reason.
        $this->holdingTenant((string) $other->id);
        $rival = Influencer::create([
            'tenant_id' => $other->id, 'name' => 'Rival', 'handle' => 'rival',
            'primary_platform' => 'tiktok', 'status' => 'active',
        ]);
        $rivalWork = InfluencerCollaboration::create([
            'tenant_id' => $other->id, 'influencer_id' => $rival->id,
            'title' => 'Other agency work', 'status' => 'active', 'currency' => 'SAR',
            'influencer_fee' => 9000,
        ]);
        $rivalWork->forceFill(['terms_sent_at' => now()])->save();

        // Without this the assertion below passes whether or not the row was ever written, and a
        // silently-failing setup would read as proof of isolation.
        $this->assertNotNull($rivalWork->fresh()->terms_sent_at);

        // Link the rival roster entry to the SAME login, so the only thing keeping the two apart is
        // the tenant boundary rather than the roster link.
        $rival->forceFill(['user_id' => $user->getKey()])->save();

        $this->holdingTenant((string) $this->agency->id);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/influencers/me/collaborations')->assertOk()->getContent();

        $this->assertStringNotContainsString('Other agency work', $body);
    }
}
