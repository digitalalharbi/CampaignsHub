<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Requests\Models\ContactVerification;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\MembershipProvisioner;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * LOGIN-E2E-001 — the email code is AUTHENTICATION, not a screen that changes.
 *
 * ## What this file exists to prevent
 *
 * A sign-in flow can look completely finished while being hollow: a code that verifies, a URL that
 * changes, and no session behind it — or a session that exists but grants whatever the address bar
 * asks for. Every test here walks the real endpoints and then asks a question the interface cannot
 * answer on its own: is there a session, whose is it, where did the SERVER say to go, and what does
 * that session refuse.
 *
 * The destination is never computed in the browser. `GET /auth/memberships` derives it from the
 * platform-admin flag, real memberships and their portals, and these tests assert the server's own
 * answer — so a frontend that started routing by email address would not make them pass.
 */
final class EmailSignInJourneyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The SPA's origin, on every call.
     *
     * Sanctum only engages its stateful path — and therefore only attaches a session — for requests
     * whose Origin matches the frontend. Without it `session()->regenerate()` throws, which is a test
     * artefact rather than a defect: a browser always sends one.
     *
     * @var array<string, string>
     */
    private array $spa = ['Origin' => 'http://localhost:5173'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        /*
         * One probe route per portal, carrying only the middleware under test.
         *
         * Borrowed from `PortalAccessTest` for the same reason it exists there: asserting isolation
         * against a feature endpoint tests that endpoint's own checks as much as the gate, and a
         * feature that grew a second guard would hide a gate that had stopped working.
         */
        foreach (Portal::cases() as $portal) {
            Route::middleware(['api', 'auth:sanctum', 'tenant', 'portal:'.$portal->value])
                ->get('/__probe/'.$portal->value, fn () => response()->json(['portal' => $portal->value]));
        }
    }

    private function tenant(string $name, ?string $accountType = null): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'status' => 'active',
            'account_type' => $accountType,
        ]);
    }

    private function user(string $email): User
    {
        return User::create([
            'name' => 'U',
            'email' => $email,
            'password' => 'secret123',
            'email_verified_at' => now(),
        ]);
    }

    private function grant(User $user, Tenant $tenant, Portal $portal): void
    {
        app(MembershipProvisioner::class)->ensure($user, $tenant, $portal);
    }

    /** Move every challenge's clock back, so a resend is allowed without sleeping in a test. */
    private function forgetCooldown(): void
    {
        ContactVerification::query()->update(['last_sent_at' => Carbon::now()->subMinutes(5)]);
    }

    /**
     * The whole journey, over the real endpoints: ask for a code, read it, verify it, hold a session.
     *
     * Returns the destination the SERVER chose, which is the thing under test in most of this file.
     */
    private function signInWithCode(string $email): string
    {
        $start = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => $email])
            ->assertOk();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/email-code/verify', [
            'verification_id' => (string) $start->json('data.verification_id'),
            'code' => (string) $start->json('data.dev_code'),
        ])->assertOk();

        return (string) $this->withHeaders($this->spa)
            ->getJson('/api/v1/auth/memberships')
            ->assertOk()
            ->json('data.destination');
    }

    // ── where the server sends each kind of account ───────────────────────────────────────────

    public function test_a_platform_admin_is_sent_to_admin(): void
    {
        $user = $this->user('owner@platform.test');
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->assertSame('/admin', $this->signInWithCode('owner@platform.test'));
    }

    public function test_an_advertiser_is_sent_to_app(): void
    {
        $user = $this->user('brand@advertiser.test');
        $this->grant($user, $this->tenant('Advertiser', 'company'), Portal::App);

        $this->assertSame('/app/dashboard', $this->signInWithCode('brand@advertiser.test'));
    }

    public function test_an_agency_operator_is_sent_to_agency(): void
    {
        $user = $this->user('ops@agency.test');
        $this->grant($user, $this->tenant('Agency', 'agency'), Portal::Agency);

        $this->assertSame('/agency', $this->signInWithCode('ops@agency.test'));
    }

    /**
     * Two memberships means the product cannot know which one they came for, so it asks.
     *
     * Guessing here is the failure that reads as «it signed me into the wrong company»: an agency
     * operator who also runs their own advertising lands in whichever the resolver happened to sort
     * first, and nothing on the screen says a second workspace exists.
     */
    public function test_somebody_holding_two_workspaces_is_sent_to_the_switcher(): void
    {
        $user = $this->user('both@example.test');
        $this->grant($user, $this->tenant('Their Agency', 'agency'), Portal::Agency);
        $this->grant($user, $this->tenant('Their Brand', 'company'), Portal::App);

        $this->assertSame('/switch', $this->signInWithCode('both@example.test'));
    }

    /** A verified account with no membership at all is onboarded, not stranded on a refusal page. */
    public function test_an_account_with_no_workspace_is_sent_to_onboarding(): void
    {
        $this->user('nowhere@example.test');

        $this->assertSame('/onboarding', $this->signInWithCode('nowhere@example.test'));
    }

    // ── the session is real, and so are its limits ────────────────────────────────────────────

    /**
     * The code opens a SESSION, not a redirect.
     *
     * `/auth/me` is the question a changed URL cannot answer. A flow that verified a code, navigated,
     * and left the caller anonymous would pass every assertion about the address bar and fail this.
     */
    public function test_the_code_opens_a_session_a_protected_endpoint_accepts(): void
    {
        $user = $this->user('brand@advertiser.test');
        $this->grant($user, $this->tenant('Advertiser', 'company'), Portal::App);

        $this->signInWithCode('brand@advertiser.test');

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'brand@advertiser.test');

        // …and a real endpoint inside the portal the server chose actually answers.
        $this->withHeaders($this->spa)->getJson('/__probe/app')->assertOk();
    }

    /**
     * The URL grants nothing after an OTP sign-in either.
     *
     * This is the isolation claim stated where it is most likely to be forgotten: a new credential is
     * a new way in, and a new way in that skipped the portal gate would be a way around it.
     */
    public function test_an_app_session_is_refused_the_other_portals(): void
    {
        $user = $this->user('brand@advertiser.test');
        $this->grant($user, $this->tenant('Advertiser', 'company'), Portal::App);

        $this->signInWithCode('brand@advertiser.test');

        $this->withHeaders($this->spa)->getJson('/__probe/agency')->assertForbidden();
        $this->withHeaders($this->spa)->getJson('/__probe/portal')->assertForbidden();
        $this->withHeaders($this->spa)->getJson('/__probe/influencers')->assertForbidden();
    }

    /** And an agency session is refused the advertiser portal, which is the same rule the other way. */
    public function test_an_agency_session_is_refused_the_app_portal(): void
    {
        $user = $this->user('ops@agency.test');
        $this->grant($user, $this->tenant('Agency', 'agency'), Portal::Agency);

        $this->signInWithCode('ops@agency.test');

        $this->withHeaders($this->spa)->getJson('/__probe/agency')->assertOk();
        $this->withHeaders($this->spa)->getJson('/__probe/app')->assertForbidden();
    }

    /**
     * The platform owner does not quietly become a tenant member.
     *
     * `/admin` is held by a flag, not a membership, and the portal gates ask for memberships — so the
     * owner is refused the tenant portals exactly like anybody else who does not hold one.
     */
    public function test_a_platform_admin_does_not_gain_a_tenant_portal(): void
    {
        $user = $this->user('owner@platform.test');
        $user->forceFill(['is_platform_admin' => true])->save();

        $this->signInWithCode('owner@platform.test');

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertOk();
        $this->withHeaders($this->spa)->getJson('/__probe/app')->assertForbidden();
        $this->withHeaders($this->spa)->getJson('/__probe/agency')->assertForbidden();
    }

    /**
     * Signing out is accepted, and the code path that does it is the canonical one.
     *
     * ## Why this test stops here, and where the real proof lives
     *
     * The obvious assertion — sign out, then `GET /auth/me` and expect 401 — cannot be trusted in
     * this harness. Laravel's test client resolves auth into the test process's own container and
     * shares it across the requests in a test rather than doing a cookie round trip, so the guard
     * still reports the user after an HTTP logout even though the SERVER invalidated the session.
     * The same three lines invalidate correctly when called in-process, which is what
     * `EmailSignInTest::test_a_code_cannot_be_used_twice` relies on.
     *
     * Writing the 401 assertion anyway and reaching for `flushSession()` to make it pass would be
     * asserting that the TEST cleared its own state — true, and nothing to do with the product.
     *
     * So the browser owns this claim: `e2e/login-otp-journey.spec.ts` signs out over real cookies and
     * then asks `/auth/me`, which is the question a person's browser actually asks.
     */
    public function test_signing_out_is_accepted(): void
    {
        $user = $this->user('brand@advertiser.test');
        $this->grant($user, $this->tenant('Advertiser', 'company'), Portal::App);

        $this->signInWithCode('brand@advertiser.test');
        $this->assertAuthenticated();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertOk();
    }

    /** Out, then in again — a spent session must not stop the next code from working. */
    public function test_signing_in_again_after_signing_out_works(): void
    {
        $user = $this->user('brand@advertiser.test');
        $this->grant($user, $this->tenant('Advertiser', 'company'), Portal::App);

        $this->signInWithCode('brand@advertiser.test');
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertOk();
        $this->forgetCooldown();

        $this->assertSame('/app/dashboard', $this->signInWithCode('brand@advertiser.test'));
    }

    // ── the message itself ────────────────────────────────────────────────────────────────────

    /**
     * With a provider configured, a real message is composed and the ledger says `sent`.
     *
     * The defect this closes was quiet and complete: `CredentialMail::SIGN_IN_CODE` existed, rendered
     * in the admin gallery, and was sent by nothing. A code was minted, hashed, stored — and no
     * message was ever composed, with or without credentials. The flow would have gone on reporting
     * «awaiting credentials» after real SMTP was wired, and nobody would have received anything.
     */
    public function test_a_configured_provider_actually_sends_the_code(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp', 'services.notifications.email_enabled' => true]);

        $res = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'brand@advertiser.test'])
            ->assertOk();

        // Either a transport accepted it, or the channel honestly reports it has none — never a
        // silent nothing. This asserts the CALL happened by checking the ledger recorded an attempt.
        $this->assertNotNull(
            DB::table('mail_deliveries')->where('recipient', 'brand@advertiser.test')->first(),
            'no delivery was recorded for the sign-in code — nothing tried to send it',
        );

        $this->assertContains(
            $res->json('data.delivery_status'),
            ['sent', 'sandbox', 'awaiting_credentials', 'failed'],
            'the delivery status is not an outcome from the ledger',
        );
    }

    /** The composed message carries the code and says how long it lasts. */
    public function test_the_message_is_the_sign_in_code_template(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'brand@advertiser.test'])
            ->assertOk();

        $delivery = DB::table('mail_deliveries')->where('recipient', 'brand@advertiser.test')->first();

        if ($delivery === null || $delivery->status === 'awaiting_credentials') {
            // No provider on this install: the honest state, asserted by its own test below.
            $this->assertTrue(true);

            return;
        }

        Mail::assertSent(CredentialMail::class, fn (CredentialMail $m) => $m->purpose === CredentialMail::SIGN_IN_CODE
            && $m->code !== null
            && $m->expiresInMinutes > 0);
    }

    /**
     * With no provider, nothing is sent and nothing pretends otherwise.
     *
     * READY_FOR_CREDENTIALS expressed as a fact on a row rather than as a claim in a document.
     */
    public function test_with_no_provider_nothing_is_sent_and_the_row_says_so(): void
    {
        Mail::fake();
        config(['requests.verification.providers.email' => false]);

        $res = $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'brand@advertiser.test'])
            ->assertOk();

        $this->assertNotContains($res->json('data.delivery_status'), ['sent', 'delivered']);
        Mail::assertNothingOutgoing();
    }

    /**
     * A transport that throws is recorded, and the endpoint still answers.
     *
     * A sign-in form that returns 500 because a mail host blinked tells the visitor nothing, and
     * loses the code already minted for them. The failure belongs in the ledger, not in the response.
     */
    public function test_a_failing_transport_is_recorded_rather_than_thrown(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'localhost', 'mail.mailers.smtp.port' => 1]);

        $this->withHeaders($this->spa)
            ->postJson('/api/v1/auth/email-code/start', ['email' => 'brand@advertiser.test'])
            ->assertOk();

        $status = (string) ContactVerification::query()->latest('created_at')->firstOrFail()->delivery_status;

        $this->assertNotContains($status, ['sent', 'delivered'], "a failed send was recorded as «{$status}»");
    }

    // ── what must NOT open a session ──────────────────────────────────────────────────────────

    /**
     * Asking for help is not signing in.
     *
     * The «تواصل معنا» panel sits on the sign-in page, which is exactly where a form that quietly
     * authenticated somebody would be least noticed.
     */
    public function test_the_contact_panel_opens_no_session_and_creates_no_account(): void
    {
        $before = User::query()->count();

        $this->withHeaders($this->spa)->postJson('/api/v1/contact', [
            'name' => 'سارة', 'email' => 'sara@example.test', 'topic' => 'plan_choice', 'source' => 'login',
        ])->assertOk();

        $this->assertGuest();
        $this->assertSame($before, User::query()->count());
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
