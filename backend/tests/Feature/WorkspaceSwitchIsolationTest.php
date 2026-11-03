<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Accounts\Services\OwnPendingRegistration;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\MembershipScope;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * WORKSPACE-SWITCH-001 — one person, several workspaces, and each answer confined to what that person holds.
 *
 * `PortalAccessTest` covers the portal gate. This covers the chooser: what the list may name, what a switch may
 * refuse and how, and that a workspace the person has lost neither stays reachable nor takes the others with it.
 */
final class WorkspaceSwitchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('/__probe/scope', fn () => response()->json(['tenant' => app(TenantContext::class)->tenantId()]));
    }

    /** @param array<string, mixed> $extra */
    private function tenant(string $name, array $extra = []): Tenant
    {
        return Tenant::create(array_merge([
            'name' => $name, 'slug' => str($name)->slug()->value(),
            'status' => 'active', 'account_type' => 'brand',
            'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ], $extra));
    }

    private function user(string $email): User
    {
        $user = User::create(['name' => 'U', 'email' => $email, 'password' => 'secret123']);
        // Not mass-assignable, and whether the address is proved decides whose application this person may reach.
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function grant(User $user, Tenant $tenant, Portal $portal = Portal::App): Membership
    {
        return app(MembershipProvisioner::class)->ensure($user, $tenant, $portal);
    }

    /** @return array<string, array<string, string>> */
    private function endedReview(): array
    {
        return ['settings' => ['review' => ['active' => true, 'expires_at' => now()->subDay()->toIso8601String()]]];
    }

    public function test_the_list_names_only_the_callers_workspaces_and_nothing_about_anyone_elses(): void
    {
        $mine = $this->tenant('Mine One');
        $mineToo = $this->tenant('Mine Two');
        $theirs = $this->tenant('Secret Neighbour');
        $me = $this->user('me@test.dev');
        $other = $this->user('other@test.dev');
        $this->grant($me, $mine);
        $this->grant($me, $mineToo, Portal::Agency);
        $this->grant($other, $theirs);
        $this->grant($other, $mine); // somebody else in MY workspace is not listed either.

        $res = $this->actingAs($me, 'sanctum')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertOk();

        $this->assertCount(2, $res->json('data.memberships'));
        $this->assertEqualsCanonicalizing(
            [(string) $mine->id, (string) $mineToo->id],
            array_column(array_column($res->json('data.memberships'), 'tenant'), 'id'),
        );
        $body = $res->getContent();
        $this->assertStringNotContainsString('Secret Neighbour', $body);
        $this->assertStringNotContainsString('secret-neighbour', $body);
        $this->assertStringNotContainsString((string) $theirs->id, $body);
        $this->assertStringNotContainsString('other@test.dev', $body);
    }

    /** Validated only as a string, a non-uuid reached a uuid column: Postgres raised 22P02, and a refusal became a 500. */
    public function test_a_membership_id_that_is_not_even_a_uuid_is_refused_like_any_other_it_does_not_break(): void
    {
        $tenant = $this->tenant('Malformed Co');
        $user = $this->user('malformed@test.dev');
        $this->grant($user, $tenant);

        foreach (['not-a-uuid', "1' OR '1'='1", str_repeat('a', 300)] as $probe) {
            $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
                ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => $probe])
                ->assertForbidden();
        }
    }

    /** ACCESS: suspension belongs to a workspace, so the person loses THAT workspace — not all of them, and not none of them. */
    public function test_a_suspended_workspace_is_neither_listed_nor_switchable_and_the_other_one_keeps_working(): void
    {
        $working = $this->tenant('Working Co');
        $suspended = $this->tenant('Suspended Co');
        $user = $this->user('suspended@test.dev');
        $this->grant($user, $working);
        $lost = $this->grant($user, $suspended, Portal::Agency);
        $suspended->forceFill(['status' => 'suspended'])->save();

        $res = $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertOk();
        $this->assertSame([(string) $working->id], array_column(array_column($res->json('data.memberships'), 'tenant'), 'id'));

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $lost->getKey()])
            ->assertForbidden();
    }

    /** Even as the DEFAULT membership: the request must not run inside a workspace that was suspended. */
    public function test_a_default_membership_in_a_suspended_workspace_does_not_scope_the_request_to_it(): void
    {
        $suspended = $this->tenant('Default Suspended');
        $working = $this->tenant('Fallback Working');
        $user = $this->user('default-suspended@test.dev');
        $default = $this->grant($user, $suspended);
        $other = $this->grant($user, $working, Portal::Agency);
        $default->forceFill(['is_default' => true])->save();
        $other->forceFill(['is_default' => false])->save();
        $suspended->forceFill(['status' => 'suspended'])->save();

        $this->actingAs($user, 'sanctum')->getJson('/__probe/scope')
            ->assertOk()->assertJsonPath('tenant', (string) $working->id);
    }

    /**
     * A person holding a live workspace and an ended review workspace was refused EVERYWHERE, the chooser included,
     * whenever the ended one was their default: the one door out answered 403 `review_ended`.
     */
    public function test_an_ended_review_workspace_does_not_lock_the_person_out_of_the_chooser_or_their_other_workspace(): void
    {
        $review = $this->tenant('Ended Review', $this->endedReview());
        $working = $this->tenant('Real Work');
        $user = $this->user('reviewer-and-member@test.dev');
        $default = $this->grant($user, $review);
        $other = $this->grant($user, $working, Portal::Agency);
        $default->forceFill(['is_default' => true])->save();
        $other->forceFill(['is_default' => false])->save();

        $res = $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertOk();
        $this->assertSame([(string) $working->id], array_column(array_column($res->json('data.memberships'), 'tenant'), 'id'));

        $this->actingAs($user, 'sanctum')->getJson('/__probe/scope')
            ->assertOk()->assertJsonPath('tenant', (string) $working->id);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $default->getKey()])
            ->assertForbidden();
    }

    /** The lock-out stays exactly as strict when the ended review workspace is all the person has. */
    public function test_a_person_holding_only_an_ended_review_workspace_is_still_refused(): void
    {
        $review = $this->tenant('Only Review', $this->endedReview());
        $user = $this->user('only-reviewer@test.dev');
        $this->grant($user, $review);

        $this->actingAs($user, 'sanctum')->getJson('/__probe/scope')
            ->assertForbidden()->assertJsonPath('code', 'review_ended');
    }

    /** A removed workspace left a nameless row in the chooser, and a switch into it was accepted. */
    public function test_a_deleted_workspace_is_neither_listed_nor_switchable(): void
    {
        $working = $this->tenant('Still Here');
        $deleted = $this->tenant('Gone Co');
        $user = $this->user('deleted@test.dev');
        $this->grant($user, $working);
        $lost = $this->grant($user, $deleted, Portal::Agency);
        $deleted->delete();

        $res = $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertOk();
        $this->assertSame([(string) $working->id], array_column(array_column($res->json('data.memberships'), 'tenant'), 'id'));

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $lost->getKey()])
            ->assertForbidden();
    }

    /** A withdrawn portal is not offered by the list (INFL-OFF-001), so a switch must not reach it around the list either. */
    public function test_a_membership_in_a_withdrawn_portal_cannot_be_switched_into(): void
    {
        config(['brand.features.influencers_ugc_enabled' => false]);
        $tenant = $this->tenant('Withdrawn Co');
        $other = $this->tenant('Offered Co');
        $user = $this->user('withdrawn@test.dev');
        $this->grant($user, $other);
        $influencers = $this->grant($user, $tenant, Portal::Influencers);

        $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $influencers->getKey()])
            ->assertForbidden();
    }

    public function test_switching_scopes_the_next_request_to_the_chosen_workspace_only(): void
    {
        $first = $this->tenant('First Co');
        $second = $this->tenant('Second Co');
        $user = $this->user('moves@test.dev');
        $this->grant($user, $first);
        $target = $this->grant($user, $second, Portal::Agency);

        $this->actingAs($user, 'web')->withHeaders($this->spaHeaders)
            ->postJson('/api/v1/auth/memberships/switch', ['membership_id' => (string) $target->getKey()])
            ->assertOk()->assertJsonPath('data.current.tenant.id', (string) $second->id);

        $this->actingAs($user, 'web')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')
            ->assertOk()->assertJsonPath('data.current.tenant.id', (string) $second->id);
    }

    /** @param array<string, mixed> $overrides */
    private function application(string $email, AccountState $state, array $overrides = []): RegistrationRequest
    {
        $application = new RegistrationRequest;
        $application->forceFill(array_merge([
            'email' => $email, 'name' => 'A', 'tenant_name' => 'Applied Co', 'account_type' => 'brand',
            'requested_portal' => 'app', 'plan_code' => 'growth', 'password' => Hash::make('secret1234'),
            'state' => $state->value, 'email_verified_at' => now(),
        ], $overrides))->save();

        return $application;
    }

    private function membershipsAs(User $user): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/memberships')->assertOk();
    }

    /** Zero memberships is an ANSWER, not a failure: this is a 200, so it can never be what renders a load error. */
    public function test_approved_awaiting_payment_with_no_workspace_is_sent_to_their_own_status_and_granted_nothing(): void
    {
        $user = $this->user('applicant@test.dev');
        $application = $this->application('Applicant@test.dev', AccountState::ApprovedAwaitingPayment);

        $res = $this->membershipsAs($user);

        $res->assertJsonPath('data.memberships', [])
            ->assertJsonPath('data.current', null)
            ->assertJsonPath('data.destination', '/signup/status?request='.$application->getKey())
            ->assertJsonPath('data.pending_registration.state', 'approved_awaiting_payment');

        $this->actingAs($user, 'sanctum')->getJson('/__probe/scope')->assertOk()->assertJsonPath('tenant', null);
    }

    public function test_pending_approval_also_goes_to_its_own_status(): void
    {
        $user = $this->user('waiting@test.dev');
        $application = $this->application('waiting@test.dev', AccountState::PendingApproval);

        $this->membershipsAs($user)->assertJsonPath('data.pending_registration.id', (string) $application->getKey());
    }

    public function test_somebody_elses_application_is_never_offered(): void
    {
        $user = $this->user('nobody@test.dev');
        $this->application('someone-else@test.dev', AccountState::ApprovedAwaitingPayment);

        $res = $this->membershipsAs($user);

        $res->assertJsonPath('data.pending_registration', null)->assertJsonPath('data.destination', '/onboarding');
        $this->assertStringNotContainsString('someone-else', $res->getContent());
    }

    /** An account that only TYPED the address has proved nothing, and an application nobody verified proves nothing either. */
    public function test_an_unverified_address_on_either_side_opens_nothing(): void
    {
        $unverified = User::create(['name' => 'U', 'email' => 'claims@test.dev', 'password' => 'secret123']);
        $this->application('claims@test.dev', AccountState::ApprovedAwaitingPayment);
        $this->assertNull($unverified->email_verified_at);
        $this->assertNull(app(OwnPendingRegistration::class)->for($unverified));

        $verified = $this->user('unproved-application@test.dev');
        $this->application('unproved-application@test.dev', AccountState::PendingApproval, ['email_verified_at' => null]);
        $this->membershipsAs($verified)->assertJsonPath('data.pending_registration', null);
    }

    /** A closed application is not a next step, and there is no assuming every empty account has one. */
    public function test_a_rejected_application_and_no_application_at_all_answer_onboarding(): void
    {
        $rejected = $this->user('rejected@test.dev');
        $this->application('rejected@test.dev', AccountState::Rejected);
        $this->membershipsAs($rejected)->assertJsonPath('data.pending_registration', null)->assertJsonPath('data.destination', '/onboarding');

        $this->membershipsAs($this->user('invited-then-removed@test.dev'))
            ->assertJsonPath('data.pending_registration', null)->assertJsonPath('data.destination', '/onboarding');
    }

    /**
     * Routing state only: a provisioned application, however it was paid for or waived, is a membership like any other.
     * The waiver itself is #940's; nothing here reads it.
     */
    public function test_explicitly_waived_and_active_routes_like_any_member(): void
    {
        $tenant = $this->tenant('Waived Co');
        $user = $this->user('waived@test.dev');
        $this->grant($user, $tenant);
        $this->application('waived@test.dev', AccountState::Active, [
            'tenant_id' => $tenant->id, 'provisioned_at' => now(), 'review_concessions' => ['fee_waived' => true],
        ]);

        $this->membershipsAs($user)
            ->assertJsonPath('data.pending_registration', null)
            ->assertJsonPath('data.destination', '/app/dashboard')
            ->assertJsonPath('data.memberships.0.tenant.id', (string) $tenant->id);
    }

    /** A member who also applied for a second workspace is still a member: the application does not hijack the route. */
    public function test_holding_a_membership_outranks_a_second_application(): void
    {
        $tenant = $this->tenant('Already Member');
        $user = $this->user('both@test.dev');
        $this->grant($user, $tenant);
        $this->application('both@test.dev', AccountState::ApprovedAwaitingPayment);

        $this->membershipsAs($user)->assertJsonPath('data.pending_registration', null)->assertJsonPath('data.destination', '/app/dashboard');
    }

    public function test_a_restricted_membership_reports_its_own_ceiling_and_routes_normally(): void
    {
        $tenant = $this->tenant('Restricted Co');
        $user = $this->user('restricted@test.dev');
        $membership = $this->grant($user, $tenant, Portal::Agency);
        $clientId = (string) Str::uuid();
        $membership->scopes()->create(['scope_type' => MembershipScope::TYPE_CLIENT, 'scope_id' => $clientId]);

        $this->membershipsAs($user)
            ->assertJsonPath('data.memberships.0.client_scope_ids', [$clientId])
            ->assertJsonPath('data.needs_switcher', false);
    }
}
