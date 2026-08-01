<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Influencers\Models\Influencer;
use App\Domains\Influencers\Models\InfluencerCollaboration;
use App\Domains\Influencers\Models\InfluencerDeliverable;
use App\Domains\Influencers\Models\InfluencerNomination;
use App\Domains\Influencers\Models\InfluencerTrackingAsset;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The second half of the influencers contract (INFL-003).
 *
 * Three claims, and they are all about not overstating what is known:
 *
 *  1. **A decision is kept whichever way it went.** A rejected creator leaves a reason, so nobody
 *     proposes them again next quarter having missed the conversation.
 *  2. **A click is measured; a redemption is reported.** The platform serves the redirect, so it
 *     counts clicks itself. A discount code is redeemed in the brand's own store, which it has never
 *     seen — and the response says so on every row rather than showing two zeroes that mean
 *     opposite things.
 *  3. **Results belong to the post.** «Which one worked» is the question that changes what you
 *     commission next, and a campaign total cannot answer it.
 */
final class InfluencerNominationAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $manager;

    private Influencer $creator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'Agency', 'slug' => 'agency-infl3', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $this->holdingTenant($this->tenant->id);

        $this->manager = $this->staff('manager@infl3.test', Permission::pluck('key')->all(), 'all-infl3');

        $this->creator = Influencer::create([
            'name' => 'Layla', 'handle' => '@layla', 'primary_platform' => 'instagram',
            'followers' => 120000, 'tier' => 'mid', 'status' => 'active',
        ]);
    }

    /** @param list<string> $permissions */
    private function staff(string $email, array $permissions, string $roleSlug): User
    {
        $user = User::create([
            'name' => 'Staff', 'email' => $email,
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $this->tenant, Portal::Influencers, 'member');

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => $roleSlug, 'slug' => $roleSlug]);
        if ($permissions !== []) {
            $role->givePermissionTo(...$permissions);
        }
        $user->assignRole($role);

        return $user->refresh();
    }

    private function nominate(array $over = []): array
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/influencers/nominations', array_merge([
                'influencer_id' => (string) $this->creator->getKey(),
                'proposed_fee' => '5000.00', 'currency' => 'SAR',
                'rationale' => 'Her audience is the one this campaign is for.',
            ], $over))
            ->assertCreated()->json('data');
    }

    // ── Nominations ───────────────────────────────────────────────────────────────────────────

    public function test_a_creator_can_be_put_forward_with_the_reasoning_kept(): void
    {
        $nomination = $this->nominate();

        $this->assertSame('proposed', $nomination['status']);
        $this->assertSame('Layla', $nomination['influencer']['name']);
        $this->assertSame('Her audience is the one this campaign is for.', $nomination['rationale']);
        // Not yet work — a proposal grants nothing.
        $this->assertFalse($nomination['is_convertible']);
        $this->assertNull($nomination['collaboration_id']);
    }

    /**
     * Two people shortlisting the same creator is a duplicate, not two opinions.
     *
     * Stored twice, they would be decided separately — and the trail would then say yes and no about
     * the same person for the same campaign.
     */
    public function test_proposing_the_same_creator_twice_collapses_into_one(): void
    {
        $first = $this->nominate();
        $second = $this->nominate(['rationale' => 'Someone else had the same idea.']);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, InfluencerNomination::query()->count());
    }

    /**
     * Deciding is a DIFFERENT permission from proposing.
     *
     * Anyone who may add a creator to the roster may suggest one; committing the agency to them is
     * somebody else's call. Collapsing the two makes the shortlist a rubber stamp its own author
     * holds.
     */
    public function test_proposing_does_not_carry_the_right_to_decide(): void
    {
        $proposer = $this->staff('proposer@infl3.test', ['influencers.view', 'influencers.manage'], 'proposer-infl3');
        $nomination = $this->nominate();

        $this->actingAs($proposer, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertSame('proposed', InfluencerNomination::query()->findOrFail($nomination['id'])->status);
    }

    /** A «no» without a reason is the one that gets the same creator proposed again next quarter. */
    public function test_a_rejection_needs_a_reason(): void
    {
        $nomination = $this->nominate();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", ['decision' => 'rejected'])
            ->assertStatus(422);

        $answered = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", [
                'decision' => 'rejected', 'note' => 'Her audience skews outside the target market.',
            ])->assertOk()->json('data');

        $this->assertSame('rejected', $answered['status']);
        $this->assertSame('Her audience skews outside the target market.', $answered['decision_note']);
    }

    public function test_an_answered_nomination_cannot_be_answered_again(): void
    {
        $nomination = $this->nominate();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", ['decision' => 'approved'])
            ->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", [
                'decision' => 'rejected', 'note' => 'changed my mind',
            ])->assertStatus(422);
    }

    /** Only a yes becomes work, and the trail runs from the idea to the contract. */
    public function test_an_approved_nomination_becomes_a_collaboration_once(): void
    {
        $nomination = $this->nominate();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/collaboration", ['title' => 'Summer launch'])
            ->assertStatus(422); // not approved yet

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", ['decision' => 'approved'])
            ->assertOk();

        $first = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/collaboration", ['title' => 'Summer launch'])
            ->assertCreated()->json('data');

        // Asking twice hands back the same contract rather than signing a second one.
        $second = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/collaboration", ['title' => 'Summer launch'])
            ->assertCreated()->json('data');

        $this->assertSame($first['collaboration_id'], $second['collaboration_id']);
        $this->assertSame(1, InfluencerCollaboration::query()->count());
        // The fee proposed carries into what was agreed, rather than being retyped.
        $this->assertSame('5000.00', (string) InfluencerCollaboration::query()->firstOrFail()->agreed_fee);
    }

    // ── Attribution ───────────────────────────────────────────────────────────────────────────

    private function collaboration(): InfluencerCollaboration
    {
        return InfluencerCollaboration::create([
            'influencer_id' => $this->creator->getKey(),
            'title' => 'Summer launch', 'status' => 'active', 'currency' => 'SAR', 'agreed_fee' => '5000.00',
        ]);
    }

    /**
     * A link's clicks are MEASURED; a code's redemptions are not, until somebody says otherwise.
     *
     * This is the whole point of the surface. Two zeroes side by side mean different things, and the
     * response has to be able to say which is which.
     */
    public function test_a_link_is_measured_and_a_discount_code_is_not(): void
    {
        $collaboration = $this->collaboration();

        $link = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'link', 'destination_url' => 'https://brand.example/summer',
            ])->assertCreated()->json('data');

        $code = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'discount_code', 'discount_type' => 'percent', 'discount_value' => '15',
            ])->assertCreated()->json('data');

        $this->assertTrue($link['count_is_measured'], 'this platform serves the redirect, so it knows');
        $this->assertStringContainsString('/t/'.$link['code'], (string) $link['share_url']);

        $this->assertFalse($code['count_is_measured'], 'the store was never connected');
        $this->assertSame('awaiting_credentials', $code['redemptions_source']);
        $this->assertSame(0, $code['redemptions']);
        $this->assertNull($code['share_url'], 'a discount code is not a URL this platform serves');
    }

    /** A link with nowhere to go is a 404 for whoever the creator sends it to. */
    public function test_a_tracking_link_must_have_a_destination(): void
    {
        $collaboration = $this->collaboration();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", ['kind' => 'link'])
            ->assertStatus(422);
    }

    /**
     * The public redirect counts the click and sends the visitor on — with no session and no tenant.
     *
     * The person following it is a stranger. That the platform can answer at all without a tenant in
     * context is exactly why the code is globally unique.
     */
    public function test_the_public_redirect_counts_a_real_click(): void
    {
        $collaboration = $this->collaboration();
        $link = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'link', 'destination_url' => 'https://brand.example/summer',
            ])->assertCreated()->json('data');

        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->get('/t/'.$link['code'])->assertRedirect('https://brand.example/summer');
        $this->get('/t/'.$link['code'])->assertRedirect('https://brand.example/summer');

        $asset = InfluencerTrackingAsset::query()->findOrFail($link['id']);
        $this->assertSame(2, $asset->clicks);
        $this->assertNotNull($asset->last_clicked_at);
    }

    /**
     * An unknown code is not an error page for a stranger who did nothing wrong.
     *
     * They followed a link a creator posted. A 404 tells them only that the brand looks broken, so
     * they go to the site instead — and nothing is created or leaked on the way.
     */
    public function test_an_unknown_code_sends_the_visitor_somewhere_neutral(): void
    {
        $this->get('/t/NOPE-123456')->assertRedirectContains('/');
        $this->assertSame(0, InfluencerTrackingAsset::query()->count());
    }

    /** A retired link stops counting rather than continuing to accrue somebody's numbers. */
    public function test_a_retired_link_no_longer_counts(): void
    {
        $collaboration = $this->collaboration();
        $link = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'link', 'destination_url' => 'https://brand.example/summer',
            ])->assertCreated()->json('data');

        InfluencerTrackingAsset::query()->whereKey($link['id'])->update(['is_active' => false]);

        $this->get('/t/'.$link['code']);

        $this->assertSame(0, InfluencerTrackingAsset::query()->findOrFail($link['id'])->clicks);
    }

    /** A reported redemption is stored as reported, never as measured by this platform. */
    public function test_a_recorded_redemption_says_a_person_typed_it(): void
    {
        $collaboration = $this->collaboration();
        $code = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'discount_code', 'discount_type' => 'percent', 'discount_value' => '15',
            ])->assertCreated()->json('data');

        $updated = $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/influencers/tracking/{$code['id']}/redemptions", ['redemptions' => 42])
            ->assertOk()->json('data');

        $this->assertSame(42, $updated['redemptions']);
        $this->assertSame('manual', $updated['redemptions_source']);
        $this->assertTrue($updated['count_is_measured'], 'somebody supplied it, so the number now means something');
    }

    /** Clicks are counted, not typed — the endpoint refuses to be used that way. */
    public function test_a_link_cannot_have_its_numbers_typed_in(): void
    {
        $collaboration = $this->collaboration();
        $link = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/collaborations/{$collaboration->getKey()}/tracking", [
                'kind' => 'link', 'destination_url' => 'https://brand.example/summer',
            ])->assertCreated()->json('data');

        $this->actingAs($this->manager, 'sanctum')
            ->patchJson("/api/v1/influencers/tracking/{$link['id']}/redemptions", ['redemptions' => 9999])
            ->assertStatus(422);
    }

    // ── Results per deliverable ───────────────────────────────────────────────────────────────

    public function test_results_belong_to_the_post_and_correct_rather_than_stack(): void
    {
        $collaboration = $this->collaboration();
        $deliverable = InfluencerDeliverable::create([
            'collaboration_id' => $collaboration->getKey(),
            'type' => 'reel', 'platform' => 'instagram', 'status' => 'published',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/deliverables/{$deliverable->getKey()}/results", [
                'reach' => 80000, 'engagements' => 4000, 'clicks' => 900,
            ])->assertCreated();

        // Re-entered, because the first figure was read too early.
        $corrected = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/deliverables/{$deliverable->getKey()}/results", [
                'reach' => 100000, 'engagements' => 6000, 'clicks' => 1200,
            ])->assertCreated()->json('data');

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/influencers/deliverables/{$deliverable->getKey()}/results")
            ->assertOk()->json('data');

        $this->assertCount(1, $rows, 'a correction must not become a second reading');
        $this->assertSame(100000, $rows[0]['reach']);
        $this->assertSame('manual', $rows[0]['source']);
        // 6000 of 100000 reached. `round()` hands back a whole number here, so compare the value.
        $this->assertEquals(6.0, $corrected['engagement_rate']);
    }

    /**
     * «We do not know the reach» must never render as «nobody engaged».
     *
     * A rate of 0% is a real, bad result. A null is an absence of information, and a chart that
     * cannot tell them apart will report the second as the first.
     */
    public function test_an_unknown_reach_yields_no_rate_rather_than_zero(): void
    {
        $collaboration = $this->collaboration();
        $deliverable = InfluencerDeliverable::create([
            'collaboration_id' => $collaboration->getKey(),
            'type' => 'story', 'platform' => 'instagram', 'status' => 'published',
        ]);

        $result = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/deliverables/{$deliverable->getKey()}/results", ['engagements' => 500])
            ->assertCreated()->json('data');

        $this->assertNull($result['engagement_rate']);
        $this->assertNull($result['reach']);
    }

    /**
     * A hand-typed number can never claim to have come from a platform.
     *
     * `source` is set by the server, not read from the request — otherwise the single honesty claim
     * this surface makes could be overridden by whoever is posting.
     */
    public function test_the_source_cannot_be_supplied_by_the_caller(): void
    {
        $collaboration = $this->collaboration();
        $deliverable = InfluencerDeliverable::create([
            'collaboration_id' => $collaboration->getKey(),
            'type' => 'post', 'platform' => 'instagram', 'status' => 'published',
        ]);

        $result = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/influencers/deliverables/{$deliverable->getKey()}/results", [
                'reach' => 1000, 'engagements' => 100, 'source' => 'platform',
            ])->assertCreated()->json('data');

        $this->assertSame('manual', $result['source']);
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────

    /** Another tenant's nomination is not found, not forbidden — it does not exist to this caller. */
    public function test_a_nomination_belongs_to_one_tenant_only(): void
    {
        $nomination = $this->nominate();

        $other = Tenant::create(['name' => 'Other', 'slug' => 'other-infl3', 'status' => 'active']);
        app(TenantContext::class)->setTenantId($other->id);
        $outsider = $this->outsiderFor($other);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/influencers/nominations/{$nomination['id']}/decide", ['decision' => 'approved'])
            ->assertNotFound();
    }

    private function outsiderFor(Tenant $tenant): User
    {
        $user = User::create([
            'name' => 'Outsider', 'email' => 'outsider@infl3.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant, Portal::Influencers, 'member');

        $role = Role::create(['tenant_id' => $tenant->id, 'name' => 'outsider', 'slug' => 'outsider-infl3']);
        $role->givePermissionTo(...Permission::pluck('key')->all());
        $user->assignRole($role);

        return $user->refresh();
    }
}
