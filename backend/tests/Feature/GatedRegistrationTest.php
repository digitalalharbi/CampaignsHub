<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Actions\ProvisionWorkspace;
use App\Domains\Accounts\Enums\AccountState;
use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Filling in a registration form opens NOTHING (SIGNUP-002).
 *
 * Registration used to create a tenant, a workspace and a membership in one transaction, so an
 * application that had not been verified, approved or paid for was indistinguishable from a paying
 * customer. These tests hold the gate: a request exists, it grants nothing, and a workspace appears
 * only at the crossing.
 */
final class GatedRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
    }

    /**
     * Build a pending application in ONE write, exactly as the real action must.
     *
     * `password` and `state` are not fillable — deliberately, since an applicant must not be able to
     * set either — so they cannot be passed to `create()`. Writing the row in two steps would fail
     * the NOT NULL on `password`, which is the correct constraint: a registration always has a
     * credential, even before it has a workspace.
     */
    private function request(array $overrides = []): RegistrationRequest
    {
        $request = new RegistrationRequest;
        $request->forceFill(array_merge([
            'email' => 'applicant@a.test',
            'name' => 'Applicant',
            'tenant_name' => 'Applicant Co',
            'account_type' => 'brand',
            'requested_portal' => 'app',
            'plan_code' => 'growth',
            'password' => Hash::make('secret1234'),
            'state' => AccountState::EmailVerificationRequired->value,
        ], $overrides))->save();

        return $request->refresh();
    }

    // ── The application grants nothing ────────────────────────────────────────────────────────

    public function test_a_registration_request_creates_no_tenant_workspace_or_membership(): void
    {
        $this->request();

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('memberships', 0);
        // Nor a user: an unverified applicant has no account to sign in with.
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_unverified_request_cannot_be_provisioned(): void
    {
        $request = $this->request();

        $this->expectExceptionMessage('before the email address is verified');
        app(ProvisionWorkspace::class)->execute($request);
    }

    /**
     * Verified but NOT approved is still nothing.
     *
     * The interesting refusal: someone who has done everything asked of them so far, and whose
     * application is waiting on a human. Provisioning here would hand a workspace to an account the
     * platform has not agreed to serve.
     */
    public function test_a_verified_but_unapproved_request_cannot_be_provisioned(): void
    {
        $request = $this->request();
        $request->forceFill([
            'email_verified_at' => now(),
            'state' => AccountState::PendingApproval->value,
        ])->save();

        $this->expectExceptionMessage('has not been approved');
        app(ProvisionWorkspace::class)->execute($request->refresh());

        $this->assertDatabaseCount('tenants', 0);
    }

    // ── The crossing ──────────────────────────────────────────────────────────────────────────

    public function test_an_approved_request_becomes_a_working_workspace(): void
    {
        $request = $this->request();
        $request->forceFill([
            'email_verified_at' => now(),
            'state' => AccountState::ApprovedAwaitingPayment->value,
        ])->save();

        ['tenant' => $tenant, 'user' => $user] = app(ProvisionWorkspace::class)->execute($request->refresh());

        // The workspace exists and is operational.
        $this->assertSame(AccountState::Active, $tenant->account_state);
        $this->assertTrue($tenant->isOperational());
        $this->assertNotNull($tenant->activated_at);

        // …with exactly one membership, in the portal that was ASKED for and then approved.
        $membership = Membership::query()->forUser($user->id)->firstOrFail();
        $this->assertSame('app', $membership->portal->value);
        $this->assertSame((string) $tenant->id, (string) $membership->tenant_id);

        // …and the request now records the crossing.
        $request->refresh();
        $this->assertTrue($request->isProvisioned());
        $this->assertNotNull($request->provisioned_at);
        $this->assertSame(AccountState::Active, $request->state);
    }

    /** The applicant signs in with the password they chose when they applied, not a new one. */
    public function test_the_applicants_own_password_carries_through(): void
    {
        $request = $this->request(['password' => Hash::make('chosen-at-signup')]);
        $request->forceFill([
            'email_verified_at' => now(),
            'state' => AccountState::ApprovedAwaitingPayment->value,
        ])->save();

        ['user' => $user] = app(ProvisionWorkspace::class)->execute($request->refresh());

        $this->assertTrue(Hash::check('chosen-at-signup', $user->password));
        $this->assertNotNull($user->email_verified_at, 'verification done during the path must carry over');
    }

    /** A webhook delivered twice must not mint a second workspace. */
    public function test_provisioning_is_idempotent(): void
    {
        $request = $this->request();
        $request->forceFill([
            'email_verified_at' => now(),
            'state' => AccountState::ApprovedAwaitingPayment->value,
        ])->save();

        $action = app(ProvisionWorkspace::class);
        $first = $action->execute($request->refresh());
        $second = $action->execute($request->refresh());

        $this->assertSame((string) $first['tenant']->id, (string) $second['tenant']->id);
        $this->assertSame(1, Tenant::count());
        $this->assertSame(1, User::count());
        $this->assertSame(1, Membership::count());
    }

    // ── The request is not a way to grant yourself things ─────────────────────────────────────

    /**
     * `state` and `tenant_id` are not form fields.
     *
     * An applicant who could set either would grant themselves exactly what the gate withholds:
     * `state=active` skips the whole path, and `tenant_id` claims a workspace that already exists.
     */
    public function test_the_state_and_tenant_cannot_be_mass_assigned(): void
    {
        $tenant = Tenant::create(['name' => 'Someone Else', 'slug' => 'someone-else', 'status' => 'active']);

        $request = $this->request();
        $request->fill([
            'state' => AccountState::Active->value,
            'tenant_id' => $tenant->id,
            'tenant_name' => 'Renamed Co',
        ])->save();

        $request->refresh();
        $this->assertSame(AccountState::EmailVerificationRequired, $request->state);
        $this->assertNull($request->tenant_id);
        $this->assertSame('Renamed Co', $request->tenant_name, 'ordinary fields must still be assignable');
    }

    /** The password never travels back out — a pending registration is still a credential. */
    public function test_the_password_is_hidden_from_serialisation(): void
    {
        $request = $this->request();

        $this->assertArrayNotHasKey('password', $request->toArray());
        $this->assertArrayNotHasKey('password', $request->statusPayload());
    }

    // ── What the applicant is told ────────────────────────────────────────────────────────────

    /**
     * Every pre-activation state has something to show, because "nothing happened when I signed up"
     * is the failure this path exists to avoid.
     */
    public function test_each_waiting_state_reports_a_label_and_the_right_next_step(): void
    {
        $request = $this->request();

        // A list of pairs rather than a map: an enum instance cannot be an array key in PHP.
        foreach ([
            [AccountState::EmailVerificationRequired, true],
            [AccountState::MobileVerificationRequired, true],
            [AccountState::ApprovedAwaitingPayment, true],
            // Waiting on US — telling the applicant to do something would be misleading.
            [AccountState::PendingApproval, false],
            [AccountState::PaymentPending, false],
        ] as [$state, $expectsNextStep]) {
            $request->forceFill(['state' => $state->value])->save();
            $payload = $request->refresh()->statusPayload();

            $this->assertSame($state->value, $payload['state']);
            $this->assertNotSame('', $payload['label'], "{$state->value} must have a human label");
            $this->assertSame(
                $expectsNextStep,
                $payload['next_step'] !== null,
                "{$state->value} next-step expectation"
            );
        }
    }

    /** A rejected application says WHY, in words the applicant can act on. */
    public function test_a_rejection_carries_its_reason(): void
    {
        $request = $this->request();
        $request->forceFill([
            'state' => AccountState::Rejected->value,
            'state_reason' => 'The company name did not match the documents provided.',
        ])->save();

        $payload = $request->refresh()->statusPayload();

        $this->assertSame('rejected', $payload['state']);
        $this->assertSame('The company name did not match the documents provided.', $payload['reason']);
        $this->assertFalse($payload['provisioned']);
    }

    // ── One live application per address ──────────────────────────────────────────────────────

    public function test_a_second_live_request_for_the_same_email_is_refused(): void
    {
        $this->request();

        $this->expectException(QueryException::class);
        $this->request();
    }

    /** …but a REJECTED application must not block someone from applying again. */
    public function test_a_rejected_applicant_may_apply_again(): void
    {
        $first = $this->request();
        $first->forceFill(['state' => AccountState::Rejected->value])->save();

        $second = $this->request();

        $this->assertNotSame((string) $first->getKey(), (string) $second->getKey());
    }
}
