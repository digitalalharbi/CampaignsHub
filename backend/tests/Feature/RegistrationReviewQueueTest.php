<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * The registration review queue (SIGNUP-003).
 *
 * Two things are being proven here. First, that an approval gate is now actually operable — before
 * this queue existed, turning it on meant applications could only be approved by editing the
 * database. Second, and more important, that a reviewer's approval clears the APPROVAL gate and
 * nothing else: an application that also owes money must not become a workspace because a person
 * clicked approve.
 */
final class RegistrationReviewQueueTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $this->assertingAcrossTenants();
    }

    private function reviewer(): User
    {
        $user = User::create(['name' => 'Owner', 'email' => 'platform@review.test', 'password' => 'secret1234']);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        return $user->refresh();
    }

    /** An application held at the approval gate, ready for a decision. */
    private function pending(string $email = 'pending@a.test'): RegistrationRequest
    {
        $res = $this->apply(['email' => $email]);
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();

        return RegistrationRequest::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
    }

    private function assertNothingGranted(): void
    {
        $this->assertSame(0, Tenant::count(), 'no workspace may exist yet');
        $this->assertSame(0, Membership::count(), 'no membership may exist yet');
    }

    // ── Reach ─────────────────────────────────────────────────────────────────────────────────

    /** The queue is the platform owner's, and a customer cannot see other people's applications. */
    public function test_the_queue_is_not_reachable_by_a_customer(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $this->pending();

        $customer = User::create(['name' => 'Customer', 'email' => 'customer@a.test', 'password' => 'secret1234']);

        $this->getJson('/api/v1/admin/registrations')->assertUnauthorized();
        $this->actingAs($customer, 'sanctum')->getJson('/api/v1/admin/registrations')->assertForbidden();
    }

    // ── The queue ─────────────────────────────────────────────────────────────────────────────

    public function test_the_queue_lists_what_is_waiting_and_where_each_one_is_held(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $this->pending('one@a.test');
        $this->apply(['email' => 'two@a.test']); // still unverified

        $res = $this->actingAs($this->reviewer(), 'sanctum')
            ->getJson('/api/v1/admin/registrations')->assertOk();

        $this->assertSame(2, $res->json('data.meta.total'));
        $this->assertSame(1, $res->json('data.counts.pending_approval'));
        $this->assertSame(1, $res->json('data.counts.email_verification_required'));
    }

    /** A reviewer sees what an applicant is not shown — and still never the password. */
    public function test_the_detail_view_carries_the_decision_history_and_no_credential(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();

        $res = $this->actingAs($this->reviewer(), 'sanctum')
            ->getJson("/api/v1/admin/registrations/{$request->getKey()}")->assertOk();

        $res->assertJsonPath('data.registration.state', 'pending_approval')
            ->assertJsonPath('data.policy.requires_approval', true);

        // The transitions are the audit trail, not a second log written for the screen.
        $actions = array_column((array) $res->json('data.transitions'), 'action');
        $this->assertContains('registration.started', $actions);

        $this->assertStringNotContainsString('password', (string) $res->getContent());
    }

    // ── Deciding ──────────────────────────────────────────────────────────────────────────────

    public function test_approving_creates_the_workspace_when_nothing_else_is_owed(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve", ['note' => 'Company checks out.'])
            ->assertOk()->assertJsonPath('data.registration.state', 'active');

        $this->assertSame(1, Membership::count());
    }

    /**
     * The claim this whole unit turns on: approval is not activation.
     *
     * A reviewer pressing approve on an application that also owes money must leave it owing money.
     * If this ever passes with `state: active`, the console has become a way to hand out unpaid
     * access one click at a time.
     */
    public function test_approving_an_application_that_owes_money_does_not_activate_it(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true, 'requires_payment' => true]]);
        $request = $this->pending();

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve")
            ->assertOk()->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->assertNothingGranted();
        $this->assertSame(0, User::where('email', 'pending@a.test')->count());
    }

    public function test_rejecting_requires_a_reason_and_the_applicant_is_shown_it(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/reject")
            ->assertStatus(422);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/reject", [
                'reason' => 'We could not verify the commercial registration.',
            ])->assertOk()->assertJsonPath('data.registration.state', 'rejected');

        // The applicant's own status screen carries the reason, not a bare "rejected".
        $this->getJson("/api/v1/auth/registration/{$request->getKey()}")->assertOk()
            ->assertJsonPath('data.registration.reason', 'We could not verify the commercial registration.');

        $this->assertNothingGranted();
    }

    /**
     * Asking for more does not decide the application — it moves who is waiting.
     *
     * The state is untouched, and the visible change is that the applicant's screen now has a next
     * step where it previously said there was nothing for them to do.
     */
    public function test_requesting_information_hands_the_application_back_without_deciding_it(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();

        $this->getJson("/api/v1/auth/registration/{$request->getKey()}")
            ->assertJsonPath('data.registration.next_step', null);

        $this->actingAs($this->reviewer(), 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/request-info", [
                'note' => 'Please send the commercial registration certificate.',
            ])->assertOk()->assertJsonPath('data.registration.state', 'pending_approval');

        $this->getJson("/api/v1/auth/registration/{$request->getKey()}")
            ->assertJsonPath('data.registration.next_step', 'Please send the commercial registration certificate.');

        $this->assertNothingGranted();
    }

    // ── Terms ─────────────────────────────────────────────────────────────────────────────────

    /** A reviewer's decision about one application outranks the plan it was made against. */
    public function test_a_reviewer_can_waive_a_gate_for_one_application(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true, 'requires_payment' => true]]);
        $request = $this->pending();
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/v1/admin/registrations/{$request->getKey()}", [
                'requires_payment' => false,
                'reason' => 'Migrating from an annual contract already paid off-platform.',
            ])->assertOk()->assertJsonPath('data.policy.requires_payment', false);

        // …and only now does approving produce a workspace.
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve")
            ->assertOk()->assertJsonPath('data.registration.state', 'active');

        $this->assertSame(1, Membership::count());
    }

    /** A concession is a record with an author and a justification, not a silent flag. */
    public function test_changing_the_terms_requires_a_reason_and_keeps_it(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/v1/admin/registrations/{$request->getKey()}", ['requires_payment' => false])
            ->assertStatus(422);

        $this->actingAs($reviewer, 'sanctum')
            ->patchJson("/api/v1/admin/registrations/{$request->getKey()}", [
                'plan_code' => 'enterprise', 'discount_percent' => 25, 'trial_days' => 30,
                'reason' => 'Agreed with the sales team.',
            ])->assertOk();

        $concessions = RegistrationRequest::findOrFail($request->getKey())->review_concessions;
        $this->assertSame('Agreed with the sales team.', $concessions['reason']);
        $this->assertSame($reviewer->id, $concessions['decided_by']);
        $this->assertSame(25, $concessions['discount_percent']);
        $this->assertSame('enterprise', RegistrationRequest::findOrFail($request->getKey())->plan_code);
    }

    // ── Applications that are already settled ─────────────────────────────────────────────────

    public function test_an_application_that_already_became_a_workspace_cannot_be_reviewed_again(): void
    {
        ['registration' => $request] = $this->applyAndVerify(['email' => 'done@a.test']);

        /*
         * Provisioning signs the applicant in, and that session travels with the test client. Drop it
         * before reviewing: two identities in one request is not a state the console ever sees, and
         * leaving it in place makes the second admin call fail for a reason that has nothing to do
         * with what is being asserted.
         */
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $reviewer = $this->reviewer();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve")->assertStatus(422);
        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/reject", ['reason' => 'no'])
            ->assertStatus(422);

        // Still exactly one workspace — the second approval did not run the path again.
        $this->assertSame(1, Tenant::count());
    }

    public function test_a_rejected_application_cannot_be_approved_afterwards(): void
    {
        config(['accounts.registration.default' => ['requires_approval' => true]]);
        $request = $this->pending();
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/reject", ['reason' => 'Declined.'])
            ->assertOk();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson("/api/v1/admin/registrations/{$request->getKey()}/approve")->assertStatus(422);

        $this->assertSame(AccountState::Rejected, RegistrationRequest::findOrFail($request->getKey())->state);
        $this->assertNothingGranted();
    }
}
