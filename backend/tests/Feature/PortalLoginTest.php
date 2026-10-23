<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Identity\Actions\OAuthOutcome;
use App\Domains\Identity\Actions\ResolveOAuthIdentity;
use App\Domains\Identity\Models\OAuthIdentity;
use App\Domains\Identity\Services\OAuthProviderRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing in is where the portal rule has to be enforced (LOGIN-003 / LOGIN-004).
 *
 * The check used to run AFTER the session existed: you were signed in, routed to a portal, and only
 * then shown a "not available" page. A wrong-portal choice is the same kind of mistake as a wrong
 * password and belongs in the same place — inside the sign-in, before anything is created.
 */
final class PortalLoginTest extends TestCase
{
    use RefreshDatabase;

    private array $spaHeaders = ['Origin' => 'http://localhost:5173'];

    private function member(string $slug, string $email, Portal $portal, ?string $accountType = null): User
    {
        $tenant = Tenant::create([
            'name' => ucfirst($slug), 'slug' => $slug.'-'.uniqid(), 'status' => 'active',
            'account_type' => $accountType, 'onboarding_step' => 'done', 'onboarding_completed_at' => now(),
        ]);
        app(TenantContext::class)->setTenantId($tenant->id);

        $user = User::create([
            'name' => 'U', 'email' => $email,
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $this->grantMembership($user, $tenant, $portal);

        return $user;
    }

    // ── The refusal happens inside the sign-in ────────────────────────────────────────────────

    public function test_choosing_a_portal_you_do_not_hold_refuses_the_sign_in(): void
    {
        $this->member('adv', 'adv@a.test', Portal::App, 'brand');

        $response = $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'adv@a.test', 'password' => 'secret1234', 'portal' => 'agency',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('meta.portal_mismatch', true)
            ->assertJsonPath('meta.requested_portal', 'agency')
            // …and tells the form where this account SHOULD go, so it can offer a way through.
            ->assertJsonPath('meta.destination', '/app/dashboard');

        // The important half: NO session was created. A refusal that signs you in anyway is not a
        // refusal, it is a redirect with a message.
        $this->assertGuest();
        $this->withHeaders($this->spaHeaders)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_choosing_a_portal_you_hold_signs_in_normally(): void
    {
        $this->member('agy', 'agy@a.test', Portal::Agency, 'agency');

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'agy@a.test', 'password' => 'secret1234', 'portal' => 'agency',
        ])->assertOk();

        $this->assertAuthenticated();
    }

    /** A plain sign-in claims no portal, so there is nothing to refuse. */
    public function test_no_portal_named_is_never_a_mismatch(): void
    {
        $this->member('plain', 'plain@a.test', Portal::App, 'brand');

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'plain@a.test', 'password' => 'secret1234',
        ])->assertOk();

        $this->assertAuthenticated();
    }

    /** An unknown portal value behaves exactly like naming none — never a validation error. */
    public function test_an_unknown_portal_value_is_ignored(): void
    {
        $this->member('junk', 'junk@a.test', Portal::App, 'brand');

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'junk@a.test', 'password' => 'secret1234', 'portal' => 'not-a-portal',
        ])->assertOk();
    }

    /**
     * Wrong password beats wrong portal, and the message must not distinguish them.
     *
     * Answering "wrong portal" to a bad password would confirm that the account exists and which
     * portal it belongs to, to someone who has just proved they do not have its password.
     */
    public function test_a_bad_password_is_not_told_about_the_portal(): void
    {
        $this->member('quiet', 'quiet@a.test', Portal::App, 'brand');

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'quiet@a.test', 'password' => 'wrong-password', 'portal' => 'agency',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    /** The platform owner holds `admin` through the flag, so the admin portal is not a mismatch. */
    public function test_the_platform_owner_may_sign_in_through_the_admin_portal(): void
    {
        $admin = User::create([
            'name' => 'Owner', 'email' => 'owner@platform.test',
            'password' => Hash::make('secret1234'), 'email_verified_at' => now(),
        ]);
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->withHeaders($this->spaHeaders)->postJson('/api/v1/auth/login', [
            'email' => 'owner@platform.test', 'password' => 'secret1234', 'portal' => 'admin',
        ])->assertOk();
    }

    // ── Social sign-in ────────────────────────────────────────────────────────────────────────

    /**
     * With no credentials configured, the providers are listed as Awaiting Credentials and starting
     * one is refused. The page still shows them — a capability that is not set up is stated, not
     * hidden, and never rendered as a working button.
     */
    public function test_social_providers_report_awaiting_credentials_when_unconfigured(): void
    {
        config(['services.google.client_id' => null, 'services.google.client_secret' => null]);

        $providers = $this->getJson('/api/v1/auth/oauth/providers')->assertOk()->json('data.providers');

        $google = collect($providers)->firstWhere('provider', 'google');
        $this->assertSame(OAuthProviderRegistry::AWAITING_CREDENTIALS, $google['status']);
        $this->assertFalse($google['available']);

        $this->postJson('/api/v1/auth/oauth/google/start')
            ->assertStatus(503)
            ->assertJsonPath('meta.status', OAuthProviderRegistry::AWAITING_CREDENTIALS);
    }

    /** Half-configured is not configured: a client id with no secret fails at the token exchange. */
    public function test_a_client_id_without_a_secret_is_not_configured(): void
    {
        config(['services.google.client_id' => 'id-only', 'services.google.client_secret' => null]);

        $this->assertFalse(app(OAuthProviderRegistry::class)->isConfigured('google'));
    }

    /** Configured: the authorize URL carries PKCE, state and nonce — never a bare client id. */
    public function test_starting_a_configured_provider_returns_a_pkce_authorize_url(): void
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $url = $this->postJson('/api/v1/auth/oauth/google/start', ['portal' => 'app'])
            ->assertOk()->json('data.authorize_url');

        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('state=', $url);
        $this->assertStringContainsString('nonce=', $url);
        $this->assertStringContainsString('response_type=code', $url);
        // The secret never leaves the server.
        $this->assertStringNotContainsString('test-secret', $url);
    }

    /** A callback whose `state` does not match a flow this browser started is refused. */
    public function test_a_callback_with_a_forged_state_is_refused(): void
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $this->get('/api/v1/auth/oauth/google/callback?code=abc&state=forged')
            ->assertRedirect(Frontend::url('/login?oauth=failed'));

        $this->assertGuest();
    }

    /** The Google verifier must not accidentally remove replay protection from another provider. */
    public function test_a_non_google_identity_token_still_has_to_match_the_flow_nonce(): void
    {
        config([
            'services.apple.client_id' => 'apple-client',
            'services.apple.client_secret' => 'apple-secret',
        ]);

        $url = $this->postJson('/api/v1/auth/oauth/apple/start')->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $encode = static fn (array $value): string => rtrim(strtr(base64_encode(json_encode($value, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $idToken = $encode(['alg' => 'none']).'.'.$encode([
            'sub' => 'apple-user',
            'nonce' => 'nonce-from-another-flow',
            'email' => 'person@example.test',
        ]).'.signature';

        Http::fake(['https://appleid.apple.com/auth/token' => Http::response(['id_token' => $idToken])]);

        $this->post('/api/v1/auth/oauth/apple/callback', [
            'code' => 'apple-code',
            'state' => $query['state'],
        ])->assertRedirect(Frontend::url('/login?oauth=failed'));

        $this->assertGuest();
        $this->assertDatabaseCount('oauth_identities', 0);
    }

    public function test_google_linking_is_bound_to_the_authenticated_user_and_single_use(): void
    {
        $user = $this->member('link-owner', 'owner@a.test', Portal::App, 'brand');
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $url = $this->actingAs($user)->postJson('/api/v1/auth/oauth/google/link')
            ->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->fakeGoogleIdentityToken((string) $query['nonce'], 'google-link-sub', 'owner@a.test');

        $callback = '/api/v1/auth/oauth/google/callback?'.http_build_query([
            'code' => 'google-code',
            'state' => $query['state'],
        ]);

        $this->get($callback)->assertRedirect(Frontend::url('/account/security?oauth_link=connected&provider=google'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'google-link-sub',
        ]);

        $this->get($callback)->assertRedirect(Frontend::url('/login?oauth=failed'));
        $this->assertDatabaseCount('oauth_identities', 1);
    }

    public function test_google_linking_fails_if_the_authenticated_user_changes_during_the_round_trip(): void
    {
        $starter = $this->member('link-starter', 'starter@a.test', Portal::App, 'brand');
        $other = $this->member('link-other', 'other@a.test', Portal::App, 'brand');
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $url = $this->actingAs($starter)->postJson('/api/v1/auth/oauth/google/link')
            ->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->fakeGoogleIdentityToken((string) $query['nonce'], 'google-cross-user-sub', 'starter@a.test');

        $guard = Auth::guard('web');
        $guard->forgetUser();

        $this->withSession([$guard->getName() => $other->getAuthIdentifier()])
            ->get('/api/v1/auth/oauth/google/callback?'.http_build_query([
                'code' => 'google-code',
                'state' => $query['state'],
            ]))->assertRedirect(Frontend::url('/account/security?oauth_link=failed&provider=google'));

        $this->assertDatabaseCount('oauth_identities', 0);
    }

    // ── Account linking ───────────────────────────────────────────────────────────────────────

    /** An existing link signs that user in — the only path that authenticates. */
    public function test_an_existing_link_resolves_to_its_user(): void
    {
        $user = $this->member('linked', 'linked@a.test', Portal::App, 'brand');
        OAuthIdentity::create([
            'user_id' => $user->getKey(), 'provider' => 'google',
            'provider_user_id' => 'google-sub-1', 'email' => 'linked@a.test',
            'email_verified' => true, 'linked_at' => now(),
        ]);

        ['user' => $resolved] = app(ResolveOAuthIdentity::class)->execute('google', [
            'sub' => 'google-sub-1', 'email' => 'linked@a.test',
            'email_verified' => true, 'name' => 'U', 'avatar' => null,
        ]);

        $this->assertTrue($resolved->is($user));
    }

    /**
     * A MATCHING EMAIL IS NOT A LINK.
     *
     * The case this codifies: someone signs in with a Google account whose address happens to equal
     * an existing user's. Treating that as proof of ownership hands over the local account to
     * whoever can make a provider assert that address — including a provider that never verified it.
     * Linking is done from inside an authenticated session instead.
     */
    public function test_a_matching_email_does_not_silently_adopt_an_account(): void
    {
        $this->member('victim', 'victim@a.test', Portal::App, 'brand');

        try {
            app(ResolveOAuthIdentity::class)->execute('google', [
                'sub' => 'attacker-sub', 'email' => 'victim@a.test',
                'email_verified' => true, 'name' => 'Someone', 'avatar' => null,
            ]);
            $this->fail('a matching email must not resolve to the existing account');
        } catch (OAuthOutcome $outcome) {
            $this->assertSame(ResolveOAuthIdentity::LINK_REQUIRED, $outcome->reason);
        }

        $this->assertDatabaseCount('oauth_identities', 0);
    }

    /** Signing in is not a way to register — new accounts go through the gated path. */
    public function test_an_unknown_provider_account_does_not_create_a_user(): void
    {
        try {
            app(ResolveOAuthIdentity::class)->execute('google', [
                'sub' => 'brand-new', 'email' => 'nobody@a.test',
                'email_verified' => true, 'name' => 'New', 'avatar' => null,
            ]);
            $this->fail('an unknown provider account must not mint a user');
        } catch (OAuthOutcome $outcome) {
            $this->assertSame(ResolveOAuthIdentity::REGISTRATION_REQUIRED, $outcome->reason);
        }

        $this->assertDatabaseMissing('users', ['email' => 'nobody@a.test']);
    }

    /** Linking from an authenticated session is the safe half, and it refuses a taken identity. */
    public function test_a_provider_account_cannot_be_linked_to_two_users(): void
    {
        $first = $this->member('one', 'one@a.test', Portal::App, 'brand');
        $second = $this->member('two', 'two@a.test', Portal::App, 'brand');

        $profile = ['sub' => 'shared-sub', 'email' => 'one@a.test', 'email_verified' => true, 'name' => null, 'avatar' => null];
        app(ResolveOAuthIdentity::class)->link($first, 'google', $profile);

        $this->expectExceptionMessage('already connected to another user');
        app(ResolveOAuthIdentity::class)->link($second, 'google', $profile);
    }

    private function fakeGoogleIdentityToken(string $nonce, string $subject, string $email): void
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $windowsConfig = 'C:\\Program Files\\Git\\mingw64\\etc\\ssl\\openssl.cnf';
        if (PHP_OS_FAMILY === 'Windows' && is_file($windowsConfig)) {
            $options['config'] = $windowsConfig;
        }

        $privateKey = openssl_pkey_new($options);
        $details = openssl_pkey_get_details($privateKey);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'kid' => 'feature-key'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-id',
            'sub' => $subject,
            'nonce' => $nonce,
            'iat' => time(),
            'exp' => time() + 300,
            'email' => $email,
            'email_verified' => true,
        ], JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'id_token' => $header.'.'.$payload.'.'.$encode($signature),
            ]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [[
                'kid' => 'feature-key',
                'kty' => 'RSA',
                'n' => $encode($details['rsa']['n']),
                'e' => $encode($details['rsa']['e']),
            ]]]),
        ]);
    }
}
