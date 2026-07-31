<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * New-user registration → email verification → onboarding (account type / service / workspace / first client
 * & project) → entitlement-driven navigation (personal full vs company simplified). One account model.
 */
final class RegistrationOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class); // real registration grants the owner the full catalogue
    }

    /** @return array{user: User, token: string} */
    private function register(string $email = 'new@owner.test'): array
    {
        $res = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'New Workspace', 'name' => 'New Owner',
            'email' => $email, 'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ])->assertCreated();

        $link = $res->json('data.email_verification.dev_link');
        $token = $link ? explode('token=', $link)[1] : '';
        $user = User::where('email', $email)->firstOrFail();

        return ['user' => $user, 'token' => $token];
    }

    public function test_registration_creates_a_working_owner_and_queues_verification(): void
    {
        $res = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Acme WS', 'name' => 'Owner', 'email' => 'o@acme.test',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ])->assertCreated();

        // Owner actually has permissions (previously the role was created empty).
        $res->assertJsonFragment(['role_slug' => 'tenant-owner']);
        $this->assertContains('clients.view', $res->json('data.user.permissions'));
        // Honest verification delivery + non-prod dev link.
        $res->assertJsonPath('data.email_verification.delivery_status', 'awaiting_provider_credentials');
        $this->assertNotNull($res->json('data.email_verification.dev_link'));
        // Fresh account starts unverified, at the email step.
        $res->assertJsonPath('data.user.account.onboarding.step', 'verify_email')
            ->assertJsonPath('data.user.email_verified', false);
    }

    public function test_onboarding_is_blocked_until_email_is_verified(): void
    {
        ['user' => $user] = $this->register();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])
            ->assertForbidden();
    }

    public function test_full_personal_onboarding_flow_and_full_menu(): void
    {
        ['user' => $user, 'token' => $token] = $this->register();

        // Verify email → advances to account_type.
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk()
            ->assertJsonPath('data.user.account.onboarding.step', 'account_type')
            ->assertJsonPath('data.user.email_verified', true);
        $user->refresh();

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
        ['user' => $user, 'token' => $token] = $this->register('brand@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        $user->refresh();

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
        ['user' => $user, 'token' => $token] = $this->register('co@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        $user->refresh();
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
        ['user' => $user, 'token' => $token] = $this->register('pers@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        $user->refresh();

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
        ['user' => $user, 'token' => $token] = $this->register('team@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        $user->refresh();

        $tenant = $user->currentTenant();
        $colleague = $this->userWithMembership($tenant, 'colleague@owner.test', Portal::App);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/onboarding/account-type', ['account_type' => 'agency'])->assertOk();

        $this->assertSame(Portal::App, $user->memberships()->firstOrFail()->refresh()->portal);
        $this->assertSame(Portal::App, $colleague->memberships()->firstOrFail()->portal);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->register('dup@owner.test');
        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Another', 'name' => 'Dup', 'email' => 'dup@owner.test',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
        ])->assertStatus(422);
    }

    public function test_invalid_or_reused_verification_token_is_rejected(): void
    {
        ['token' => $token] = $this->register('once@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk();
        // Reusing a consumed token fails.
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertStatus(422);
        // A bogus token fails.
        $this->postJson('/api/v1/auth/email/verify', ['token' => 'nope'])->assertStatus(422);
    }

    /**
     * AUTH-002: the path chosen on the public site must survive registration. It is stored on the tenant,
     * so verification lands on the workspace step instead of asking the visitor to pick it a second time.
     */
    public function test_journey_chosen_on_the_public_site_is_preserved_through_registration(): void
    {
        $res = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/register', [
            'tenant_name' => 'Journey WS', 'name' => 'Journey Owner', 'email' => 'journey@owner.test',
            'password' => 'secret1234', 'password_confirmation' => 'secret1234',
            'account_type' => 'agency', 'service' => 'paid_media',
        ])->assertCreated();

        // Recorded immediately — not held in browser state that a refresh would lose.
        $res->assertJsonPath('data.user.account.account_type', 'agency')
            ->assertJsonPath('data.user.account.onboarding.step', 'verify_email');

        $token = explode('token=', (string) $res->json('data.email_verification.dev_link'))[1];

        // Both questions were already answered, so onboarding resumes at the workspace step.
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk()
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
        ['token' => $token] = $this->register('nojourney@owner.test');
        $this->postJson('/api/v1/auth/email/verify', ['token' => $token])->assertOk()
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
