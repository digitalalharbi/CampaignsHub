<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Accounts\Models\RegistrationRequest;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AppliesToRegister;
use Tests\TestCase;

/**
 * New-user registration → email verification → onboarding (account type / service / workspace / first client
 * & project) → entitlement-driven navigation (personal full vs company simplified). One account model.
 */
final class RegistrationOnboardingTest extends TestCase
{
    use AppliesToRegister;
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class); // real registration grants the owner the full catalogue
    }

    /**
     * A registration walked all the way to a workspace (SIGNUP-002).
     *
     * Every test below this line is about ONBOARDING, which begins once an account exists. They used
     * to get one by submitting the form, because that is what submitting the form did; now they walk
     * the gated path to the same place. What each test asserts is unchanged.
     *
     * @return array{user: User}
     */
    private function register(string $email = 'new@owner.test'): array
    {
        ['user' => $user] = $this->applyAndVerify(['email' => $email]);

        return ['user' => $user];
    }

    /** Applying says what it did — and, honestly, what it could not deliver. */
    public function test_applying_records_an_application_and_an_undelivered_challenge(): void
    {
        $res = $this->apply([
            'tenant_name' => 'Acme WS', 'name' => 'Owner', 'email' => 'o@acme.test',
        ])->assertStatus(202);

        $res->assertJsonPath('data.registration.state', 'email_verification_required')
            ->assertJsonPath('data.registration.email_verified', false)
            ->assertJsonPath('data.registration.provisioned', false);

        // Honest delivery: no mail provider is wired, so the challenge was never sent. The dev link
        // exists ONLY outside production and is what keeps the journey walkable meanwhile.
        $res->assertJsonPath('data.verification.delivery_status', 'awaiting_provider_credentials');
        $this->assertNotNull($res->json('data.verification.dev_link'));

        /*
         * …and it tells the applicant what the policy will ask of them, before they wonder.
         *
         * Payment is asked for and approval is not, which is what the shipped policy says since
         * PLAN-PAID-001: there is no free plan left, so every application owes a first charge, and
         * nothing here is held for a human to read.
         */
        $res->assertJsonPath('data.policy.requires_approval', false)
            ->assertJsonPath('data.policy.requires_payment', true);

        // The application is NOT a workspace, and says so — before the money, it grants nothing.
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseMissing('users', ['email' => 'o@acme.test']);
    }

    /**
     * The completed journey produces a working owner — with the permissions to actually operate.
     *
     * The moment this happens moved with PLAN-PAID-001: verifying an email used to be the last gate
     * and therefore the point the owner appeared, and now it is the confirmed payment. The claim is
     * unchanged and is asserted where it now becomes true, on the account itself rather than on the
     * verify response — which today honestly reports an application that is still owed money.
     */
    public function test_the_completed_journey_creates_a_working_owner(): void
    {
        ['user' => $user, 'registration' => $registration] =
            $this->applyAndVerify(['tenant_name' => 'Acme WS', 'email' => 'o@acme.test']);

        $this->assertTrue($registration->isProvisioned());
        $this->assertNotNull($user->email_verified_at);

        $me = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();

        // Owner actually has permissions (previously the role was created empty).
        $me->assertJsonFragment(['role_slug' => 'tenant-owner']);
        $this->assertContains('clients.view', $me->json('data.user.permissions'));
        $me->assertJsonPath('data.user.email_verified', true);
    }

    /** Verifying an email clears ONE gate. It does not, on its own, produce an account. */
    public function test_verifying_the_email_alone_creates_nothing(): void
    {
        $applied = $this->apply(['tenant_name' => 'Unpaid WS', 'email' => 'unpaid@acme.test'])->assertStatus(202);

        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($applied),
        ])->assertOk()
            ->assertJsonPath('data.registration.provisioned', false)
            ->assertJsonPath('data.registration.state', 'approved_awaiting_payment');

        $this->assertDatabaseMissing('users', ['email' => 'unpaid@acme.test']);
        $this->assertDatabaseMissing('tenants', ['name' => 'Unpaid WS']);
    }

    /**
     * The email gate, seen from the other side.
     *
     * An unverified APPLICANT cannot reach onboarding because they have no account to reach it with
     * — that is SIGNUP-002 and is asserted above. This is the remaining case: a user who exists but
     * has not confirmed their address, which is how invited members arrive. The check in front of
     * onboarding must still hold for them.
     */
    public function test_onboarding_is_blocked_until_email_is_verified(): void
    {
        ['user' => $user] = $this->register('invited@owner.test');
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user->refresh(), 'sanctum')
            ->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])
            ->assertForbidden();
    }

    public function test_full_personal_onboarding_flow_and_full_menu(): void
    {
        ['user' => $user] = $this->register();

        // A registration that answered no questions on the public site resumes at the first one.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.account.onboarding.step', 'account_type')
            ->assertJsonPath('data.user.email_verified', true);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])
            ->assertOk()->assertJsonPath('data.account.workspace_kind', 'personal')
            ->assertJsonPath('data.account.onboarding.step', 'service');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/service', ['service' => 'combined'])
            ->assertOk()->assertJsonPath('data.account.module_switcher', true)
            ->assertJsonPath('data.account.onboarding.step', 'workspace');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/workspace', ['name' => 'Acme Agency', 'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'language' => 'ar'])
            ->assertOk()->assertJsonPath('data.account.onboarding.step', 'first_client');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/first-client', ['name' => 'First Client'])
            ->assertOk()->assertJsonPath('data.account.onboarding.step', 'first_project');
        $this->assertDatabaseHas('client_workspaces', [
            'tenant_id' => $user->currentTenant()?->id, 'name' => 'First Client',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/first-project', ['name' => 'First Project'])
            ->assertOk()->assertJsonPath('data.account.onboarding.step', 'data_source');
        $this->assertDatabaseHas('projects', [
            'tenant_id' => $user->currentTenant()?->id, 'name' => 'First Project',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/complete')
            ->assertOk()->assertJsonPath('data.account.onboarding.completed', true);

        /*
         * /auth/me reflects the AGENCY portal's menu — because answering "agency" at the account-type
         * step moved the founding membership there (REG-001), not because the account type is read
         * as a menu persona. `portal` is asserted alongside `nav` so the two cannot drift: a nav
         * that still listed clients while the membership said `app` is exactly the state this
         * regression consisted of.
         */
        $account = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json('data.user.account');
        $this->assertSame('agency', $account['portal']);
        foreach (['dashboard', 'clients', 'projects', 'requests', 'campaigns', 'reports', 'team', 'settings'] as $k) {
            $this->assertContains($k, $account['nav']);
        }
    }

    public function test_company_account_gets_the_simplified_menu_and_skips_client_step(): void
    {
        ['user' => $user] = $this->register('brand@owner.test');

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'brand'])
            ->assertOk()->assertJsonPath('data.account.workspace_kind', 'company');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/service', ['service' => 'paid_media'])
            ->assertOk()->assertJsonPath('data.account.module_switcher', false); // single module → no switcher

        // Company workspace skips the client step → straight to first_project.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/workspace', ['name' => 'BrandCo'])
            ->assertOk()->assertJsonPath('data.account.onboarding.step', 'first_project');

        // SaaS Workspace (subscriber) menu: its own projects/team/subscription, but NO agency tools
        // (other clients, the public-requests inbox) and no Ops-only surfaces (billing/messaging).
        $nav = $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->json('data.user.account.nav');
        foreach (['dashboard', 'campaigns', 'projects', 'team', 'subscriptions'] as $shown) {
            $this->assertContains($shown, $nav);
        }
        foreach (['clients', 'requests', 'billing', 'messaging'] as $hidden) {
            $this->assertNotContains($hidden, $nav);
        }
    }

    public function test_company_workspace_is_denied_agency_endpoints_at_the_api(): void
    {
        ['user' => $user] = $this->register('co@owner.test');
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'brand'])->assertOk();

        // The simplified company menu is enforced at the API, not just hidden — clients + requests are 403.
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/requests')->assertForbidden();
    }

    /**
     * Choosing "agency" during onboarding MOVES the founding membership into the agency portal
     * (REG-001) — it does not merely relabel the tenant.
     *
     * This registration names no journey, so it is seeded into the advertiser portal, which is the
     * case that was broken: the tenant became an agency, the membership stayed `app`, and the
     * agency's own endpoints refused its owner forever. Both halves are asserted — where the
     * membership now points, and that the endpoint follows.
     */
    public function test_choosing_agency_during_onboarding_moves_the_membership_to_the_agency_portal(): void
    {
        ['user' => $user] = $this->register('pers@owner.test');

        $this->assertSame(
            Portal::App,
            $user->memberships()->firstOrFail()->portal,
            'a registration with no journey starts in the advertiser portal',
        );

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])
            ->assertOk()->assertJsonPath('data.account.portal', 'agency');

        $this->assertSame(Portal::Agency, $user->memberships()->firstOrFail()->refresh()->portal);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/app/clients')->assertOk();
    }

    /**
     * …and the move stops at the founder. A workspace that already has a second member is one whose
     * portals were granted deliberately, so reclassifying the company must not relocate anyone.
     */
    public function test_the_portal_move_does_not_touch_a_workspace_that_already_has_a_team(): void
    {
        ['user' => $user] = $this->register('team@owner.test');

        $tenant = $user->currentTenant();
        $colleague = $this->userWithMembership($tenant, 'colleague@owner.test', Portal::App);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])->assertOk();

        $this->assertSame(Portal::App, $user->memberships()->firstOrFail()->refresh()->portal);
        $this->assertSame(Portal::App, $colleague->memberships()->firstOrFail()->portal);
    }

    /** An address that already has an ACCOUNT cannot be registered a second time. */
    public function test_duplicate_email_is_rejected(): void
    {
        $this->register('dup@owner.test');

        $this->apply(['tenant_name' => 'Another', 'name' => 'Dup', 'email' => 'dup@owner.test'])
            ->assertStatus(422);
    }

    /**
     * An address with a PENDING application, however, may apply again.
     *
     * Someone who closed the tab before verifying is not an error, and refusing them would lock a
     * person out of their own address over an attempt they cannot see or cancel. The live-email
     * unique index means there is exactly one such row, so the second attempt updates it rather than
     * opening a rival application.
     */
    public function test_re_applying_before_verifying_reuses_the_pending_application(): void
    {
        $first = $this->apply(['email' => 'again@owner.test'])->assertStatus(202);
        $second = $this->apply(['email' => 'again@owner.test', 'tenant_name' => 'Corrected Name'])
            ->assertStatus(202);

        $this->assertSame($first->json('data.registration.id'), $second->json('data.registration.id'));
        $this->assertSame(1, RegistrationRequest::query()->count());

        // The corrected detail is what gets provisioned — and only the newest challenge works.
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($first),
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($second),
        ])->assertOk();

        // …and it is the corrected detail that becomes the workspace, once it is paid for.
        $this->payForRegistration(RegistrationRequest::query()->firstOrFail());
        $this->assertDatabaseHas('tenants', ['name' => 'Corrected Name']);
    }

    public function test_invalid_or_reused_verification_token_is_rejected(): void
    {
        $token = $this->verificationTokenFrom($this->apply(['email' => 'once@owner.test']));

        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $token])->assertOk();
        // Reusing a consumed token fails.
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => $token])->assertStatus(422);
        // A bogus token fails.
        $this->postJson('/api/v1/auth/registration/verify-email', ['token' => 'nope'])->assertStatus(422);
    }

    /**
     * AUTH-002: the path chosen on the public site must survive registration. It is stored on the tenant,
     * so verification lands on the workspace step instead of asking the visitor to pick it a second time.
     */
    public function test_journey_chosen_on_the_public_site_is_preserved_through_registration(): void
    {
        $res = $this->apply([
            'tenant_name' => 'Journey WS', 'name' => 'Journey Owner', 'email' => 'journey@owner.test',
            'account_type' => 'agency', 'service' => 'paid_media',
        ])->assertStatus(202);

        // Recorded on the APPLICATION — not held in browser state that a refresh would lose, and not
        // on a workspace that does not exist yet.
        $this->assertSame('agency', RegistrationRequest::query()->firstOrFail()->account_type);

        // Both questions were already answered, so onboarding resumes at the workspace step — read
        // from the account, which exists once the first payment is confirmed.
        $this->postJson('/api/v1/auth/registration/verify-email', [
            'token' => $this->verificationTokenFrom($res),
        ])->assertOk();
        $this->payForRegistration(RegistrationRequest::query()->firstOrFail());

        $owner = User::where('email', 'journey@owner.test')->firstOrFail();
        $this->actingAs($owner, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.account.onboarding.step', 'workspace')
            ->assertJsonPath('data.user.account.account_type', 'agency');

        $this->assertSame(
            ['paid_media'],
            Tenant::where('name', 'Journey WS')->value('enabled_modules'),
        );
    }

    /** Without a journey nothing is presumed — the wizard still asks for the account type. */
    public function test_registration_without_a_journey_still_starts_at_account_type(): void
    {
        ['user' => $user] = $this->applyAndVerify(['email' => 'nojourney@owner.test']);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('data.user.account.onboarding.step', 'account_type');
    }

    /** An account type outside the enum is rejected rather than silently stored. */
    public function test_registration_rejects_an_unknown_account_type(): void
    {
        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Bad WS', 'name' => 'Bad', 'email' => 'bad@owner.test',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'account_type' => 'not_a_type',
        ])->assertStatus(422);
    }
}
