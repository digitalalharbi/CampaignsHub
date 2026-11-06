<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Audit\Models\AuditLog;
use App\Domains\Identity\Models\OAuthIdentity;
use App\Domains\Identity\Services\PendingOAuthLink;
use App\Domains\Notifications\Mail\CredentialMail;
use App\Domains\Notifications\Providers\MessageProvider;
use App\Domains\Tenancy\Enums\Portal;
use App\Domains\Tenancy\Models\Membership;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LOGIN-004 — a Google account whose verified address matches a local account is linked IN PLACE, after proof.
 *
 * Production, 2026-11-06: signing in with Google ended on «لم يُربط حساب Google هذا بعد…» for an owner whose password worked. The
 * refusal is right (a matching address is not proof of ownership) and the dead end was not: the proof can be taken on the spot.
 * These cases pin both halves — nothing is linked and nobody is signed in without proof, and with proof there is no dead end.
 */
final class GoogleLinkInPlaceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private ?\OpenSSLAsymmetricKey $key = null;

    /** @var array<string, string> */
    private array $spa = ['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/'];

    private function tenant(string $status = 'active'): Tenant
    {
        $tenant = Tenant::create(['name' => 'T '.Str::random(5), 'slug' => 't-'.Str::lower(Str::random(8)), 'status' => $status]);
        $tenant->forceFill(['onboarding_completed_at' => now()])->save();

        return $tenant;
    }

    private function person(string $email = 'owner@link.test', array $attributes = []): User
    {
        $user = User::factory()->create(['email' => $email, 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now(), ...$attributes]);
        Membership::query()->create([
            'tenant_id' => $this->tenant()->id, 'user_id' => $user->id, 'portal' => Portal::App->value,
            'status' => 'active', 'is_default' => true, 'role' => 'owner',
        ]);

        return $user;
    }

    /** A real callback: PKCE start, a signed ID token, the same exchange the browser's return trip makes. */
    private function googleCallback(string $email, bool $verified = true, string $sub = 'sub-google-1', ?string $redirect = null): TestResponse
    {
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $url = (string) $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/google/start', ['redirect' => $redirect])->assertOk()->json('data.authorize_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        // One key per test: the verifier caches the provider's certificates, so a second callback must be signed by the same key.
        $this->key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $key = $this->key;
        $details = openssl_pkey_get_details($key);
        $encode = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $header = $encode(json_encode(['alg' => 'RS256', 'kid' => 'link-key'], JSON_THROW_ON_ERROR));
        $payload = $encode(json_encode([
            'iss' => 'https://accounts.google.com', 'aud' => 'test-id', 'sub' => $sub, 'nonce' => $query['nonce'],
            'iat' => time(), 'exp' => time() + 300, 'email' => $email, 'email_verified' => $verified,
            'name' => 'Mohammed G', 'picture' => 'https://lh3.googleusercontent.com/a/example',
        ], JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        // The first matching stub wins, so each callback starts from a fresh fake carrying ITS token.
        app()->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['id_token' => $header.'.'.$payload.'.'.$encode($signature)]),
            'https://www.googleapis.com/oauth2/v3/certs' => Http::response(['keys' => [['kid' => 'link-key', 'kty' => 'RSA', 'n' => $encode($details['rsa']['n']), 'e' => $encode($details['rsa']['e'])]]]),
        ]);

        return $this->get('/api/v1/auth/oauth/google/callback?'.http_build_query(['code' => 'c', 'state' => $query['state']]));
    }

    private function offered(User $user, ?string $redirect = null): void
    {
        $this->googleCallback((string) $user->email, redirect: $redirect)->assertRedirect(Frontend::url('/login?oauth=link'));
    }

    private function pendingPassword(string $password): TestResponse
    {
        return $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/password', ['password' => $password]);
    }

    private function configuredMail(): void
    {
        config(['providers.channels.email' => LinkInPlaceMailProvider::class]);
        Mail::fake();
    }

    /** The one-time secret, read out of the message a person would receive. */
    private function mailedSecret(): string
    {
        $secret = null;
        Mail::assertSent(CredentialMail::class, function (CredentialMail $mail) use (&$secret): bool {
            parse_str((string) parse_url((string) $mail->url, PHP_URL_QUERY), $query);
            $secret = $query['confirm'] ?? null;

            return $mail->purpose === CredentialMail::OAUTH_LINK;
        });
        $this->assertIsString($secret);

        return $secret;
    }

    public function test_a_verified_matching_address_offers_a_pending_link_instead_of_a_dead_end(): void
    {
        $user = $this->person();

        $response = $this->googleCallback('owner@link.test');

        $response->assertRedirect(Frontend::url('/login?oauth=link'));
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count(), 'nothing is linked before proof');

        $card = $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertOk();
        $card->assertJsonPath('data.provider', 'google')
            ->assertJsonPath('data.name', 'Mohammed G')
            ->assertJsonPath('data.local_email', 'ow•••@link.test')
            ->assertJsonPath('data.email_sent', false);
        $this->assertLessThanOrEqual(PendingOAuthLink::TTL_SECONDS, (int) $card->json('data.expires_in'));
        $this->assertStringNotContainsString('correct-horse', $card->getContent());

        $this->assertSame(1, AuditLog::query()->where('action', 'auth.oauth_link_offered')->where('user_id', $user->id)->count());
    }

    public function test_the_redirect_carries_no_identity_or_profile_data_and_nothing_is_logged(): void
    {
        $this->person();
        Log::spy();

        $location = (string) $this->googleCallback('owner@link.test', sub: 'sub-secret-9')->headers->get('Location');

        $this->assertSame(Frontend::url('/login?oauth=link'), $location);
        foreach (['sub-secret-9', 'owner', 'Mohammed', 'googleusercontent', 'message='] as $leak) {
            $this->assertStringNotContainsString($leak, $location);
        }
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
    }

    public function test_the_correct_password_links_signs_in_and_audits_and_google_then_signs_in_directly(): void
    {
        $user = $this->person();
        $this->offered($user, '/app/campaigns');

        $response = $this->pendingPassword(self::PASSWORD)->assertOk();

        $response->assertJsonPath('data.destination', '/app/campaigns')->assertJsonPath('data.user.email', 'owner@link.test');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('oauth_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'sub-google-1']);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.oauth_link_confirmed_by_password')->where('user_id', $user->id)->count());
        $this->assertStringNotContainsString(self::PASSWORD, AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString('sub-google-1', AuditLog::query()->get()->toJson());

        // Case 1 from here on: a link exists, so Google alone signs in.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertGuest('web');
        $this->googleCallback('owner@link.test')->assertRedirect(Frontend::url(Portal::App->landingPath()));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_wrong_password_links_nothing_signs_nobody_in_and_spends_the_forms_allowance(): void
    {
        $user = $this->person();
        $this->offered($user);
        config(['auth.login_throttle_local' => 2]);

        $this->pendingPassword('not-the-password')->assertStatus(422)->assertJsonPath('errors.password.0', __('auth.failed'));
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.oauth_link_failed')->count());

        // The same bucket the sign-in form spends: one more here, then the FORM is throttled too.
        $this->pendingPassword('still-wrong')->assertStatus(422);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'owner@link.test', 'password' => self::PASSWORD])->assertStatus(429);
        $this->pendingPassword(self::PASSWORD)->assertStatus(429);
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_five_wrong_passwords_discard_the_pending_link(): void
    {
        $user = $this->person();
        $this->offered($user);

        for ($i = 0; $i < PendingOAuthLink::MAX_WRONG_PASSWORDS - 1; $i++) {
            $this->pendingPassword('nope-'.$i)->assertStatus(422);
        }
        $this->pendingPassword('nope-last')->assertStatus(410)->assertJsonPath('meta.state', 'expired');
        $this->pendingPassword(self::PASSWORD)->assertStatus(410);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_a_pending_link_is_single_use(): void
    {
        $user = $this->person();
        $this->offered($user);
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $pending = app(PendingOAuthLink::class)->peek($request);
        $this->assertNotNull($pending);

        $this->assertTrue(app(PendingOAuthLink::class)->claim($request, $pending));
        $this->assertFalse(app(PendingOAuthLink::class)->claim($request, $pending), 'a replay finds it already taken');
    }

    public function test_it_expires_after_ten_minutes(): void
    {
        $user = $this->person();
        $this->offered($user);

        $this->travel(11)->minutes();

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertStatus(410);
        $this->pendingPassword(self::PASSWORD)->assertStatus(410);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_another_browser_session_holds_nothing_to_confirm(): void
    {
        $user = $this->person();
        $this->offered($user);

        // A different browser: no session of the one that completed the Google flow.
        app('session.store')->flush();

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertStatus(410);
        $this->pendingPassword(self::PASSWORD)->assertStatus(410);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_somebody_elses_password_cannot_confirm_the_pending_link(): void
    {
        $owner = $this->person('owner@link.test');
        $other = $this->person('other@link.test', ['password' => Hash::make('another-password-1')]);
        $this->offered($owner);

        // The proof is for the account the address matched — never for whichever account the password fits.
        $this->pendingPassword('another-password-1')->assertStatus(422);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->where('user_id', $other->id)->count());
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_an_account_with_two_factor_is_treated_exactly_as_the_password_form_treats_it(): void
    {
        // There is no second-factor step at sign-in anywhere in this product yet (password or Google), so parity is the
        // property: linking in place may open no door the password form keeps shut, and none that it holds open.
        $user = $this->person('twofa@link.test', ['two_factor_enabled' => true, 'two_factor_secret' => 'JBSWY3DPEHPK3PXP']);

        $form = $this->withHeaders($this->spa)->postJson('/api/v1/auth/login', ['email' => 'twofa@link.test', 'password' => self::PASSWORD]);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout');
        $this->offered($user);
        $linked = $this->pendingPassword(self::PASSWORD);

        $this->assertSame($form->getStatusCode(), $linked->getStatusCode());
        $this->assertSame($form->json('data.user.two_factor_enabled'), $linked->json('data.user.two_factor_enabled'));
    }

    public function test_a_suspended_account_is_not_signed_in_and_nothing_is_linked(): void
    {
        $user = $this->person('gone@link.test', ['disabled_at' => now()]);
        $this->offered($user);

        $this->pendingPassword(self::PASSWORD)->assertStatus(403);

        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.oauth_link_failed')->count());
    }

    public function test_an_account_whose_workspace_is_suspended_is_not_signed_in_and_nothing_is_linked(): void
    {
        $user = User::factory()->create(['email' => 'susp@link.test', 'password' => Hash::make(self::PASSWORD), 'email_verified_at' => now()]);
        Membership::query()->create([
            'tenant_id' => $this->tenant('suspended')->id, 'user_id' => $user->id, 'portal' => Portal::App->value,
            'status' => 'active', 'is_default' => true, 'role' => 'owner',
        ]);
        $this->offered($user);

        $this->pendingPassword(self::PASSWORD)->assertStatus(403);

        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_cancel_discards_the_pending_link(): void
    {
        $user = $this->person();
        $this->offered($user);

        $this->withHeaders($this->spa)->deleteJson('/api/v1/auth/oauth/pending-link')->assertOk();

        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertStatus(410);
        $this->pendingPassword(self::PASSWORD)->assertStatus(410);
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_the_mailed_link_links_and_signs_in_once_and_only_in_the_same_session(): void
    {
        $this->configuredMail();
        $user = $this->person();
        $this->offered($user, '/app/campaigns');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/email')->assertOk()->assertJsonStructure(['data' => ['delivery', 'resend_after']]);
        $secret = $this->mailedSecret();
        Mail::assertSent(CredentialMail::class, fn (CredentialMail $mail): bool => $mail->hasTo('owner@link.test'));

        // Opened elsewhere (no session of the browser that asked): fails closed and consumes nothing.
        $session = app('session.store')->all();
        app('session.store')->flush();
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/confirm-email', ['token' => $secret])->assertStatus(410);
        $this->assertGuest('web');
        app('session.store')->replace($session);

        // A wrong secret in the right browser: refused.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/confirm-email', ['token' => 'x'.$secret])->assertStatus(422);
        $this->assertGuest('web');

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/confirm-email', ['token' => $secret])
            ->assertOk()->assertJsonPath('data.destination', '/app/campaigns');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('oauth_identities', ['user_id' => $user->id, 'provider' => 'google']);
        $this->assertSame(1, AuditLog::query()->where('action', 'auth.oauth_link_confirmed_by_email')->count());

        // Replayed: nothing left to take.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/logout');
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/confirm-email', ['token' => $secret])->assertStatus(410);
        $this->assertGuest('web');
    }

    public function test_an_expired_mailed_link_fails(): void
    {
        $this->configuredMail();
        $user = $this->person();
        $this->offered($user);
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/email')->assertOk();
        $secret = $this->mailedSecret();

        $this->travel(11)->minutes();

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/confirm-email', ['token' => $secret])->assertStatus(410);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_the_mailed_link_goes_to_the_accounts_own_address_and_is_rate_limited(): void
    {
        $this->configuredMail();
        $user = $this->person();
        $this->offered($user);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/email')->assertOk();
        // The cooldown: a second request straight away is refused rather than sending another message.
        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/email')->assertStatus(429)->assertJsonPath('meta.state', 'wait');
        Mail::assertSentCount(1);
    }

    public function test_an_address_nobody_proved_cannot_receive_the_link(): void
    {
        $this->configuredMail();
        $user = $this->person('unproven@link.test', ['email_verified_at' => null, 'email_unproven_by_policy_at' => now()]);
        $this->offered($user);

        $this->withHeaders($this->spa)->postJson('/api/v1/auth/oauth/pending-link/email')->assertStatus(422)->assertJsonPath('meta.state', 'email_unavailable');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertOk()->assertJsonPath('data.can_email', false);
        Mail::assertNothingSent();
    }

    public function test_an_unverified_provider_address_is_still_refused_with_no_pending_link(): void
    {
        $this->person();

        $this->googleCallback('owner@link.test', verified: false)
            ->assertRedirect(Frontend::url('/login?'.http_build_query([
                'oauth' => 'link_required',
                'message' => 'An account already uses this email address. Sign in with your password first, then connect Google from your account settings.',
            ])));

        $this->assertGuest('web');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertStatus(410);
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_an_unknown_address_is_still_registration_required_with_no_pending_link(): void
    {
        $this->googleCallback('nobody@link.test')
            ->assertRedirect(Frontend::url('/login?'.http_build_query([
                'oauth' => 'registration_required',
                'message' => 'No CampaignsHub account is connected to this Google account. Create an account first.',
            ])));

        $this->assertGuest('web');
        $this->withHeaders($this->spa)->getJson('/api/v1/auth/oauth/pending-link')->assertStatus(410);
        $this->assertSame(0, User::query()->where('email', 'nobody@link.test')->count());
    }

    public function test_a_google_account_already_linked_to_another_user_is_refused(): void
    {
        $user = $this->person('owner@link.test');
        $holder = $this->person('holder@link.test');
        OAuthIdentity::create([
            'user_id' => $holder->getKey(), 'provider' => 'google', 'provider_user_id' => 'sub-google-1',
            'email' => 'holder@link.test', 'email_verified' => true, 'linked_at' => now(),
        ]);

        // The sub is already linked, so Google signs that other user in; it is never offered against the matching address.
        $this->googleCallback('owner@link.test')->assertRedirect(Frontend::url(Portal::App->landingPath()));
        $this->assertAuthenticatedAs($holder, 'web');
        $this->assertSame(0, OAuthIdentity::query()->where('user_id', $user->id)->count());
    }

    public function test_a_provider_account_taken_after_the_offer_is_refused_at_confirmation(): void
    {
        $user = $this->person('owner@link.test');
        $holder = $this->person('holder@link.test');
        $this->offered($user);
        // Somebody else links that Google account between the offer and the proof.
        OAuthIdentity::create([
            'user_id' => $holder->getKey(), 'provider' => 'google', 'provider_user_id' => 'sub-google-1',
            'email' => 'holder@link.test', 'email_verified' => true, 'linked_at' => now(),
        ]);

        $this->pendingPassword(self::PASSWORD)->assertStatus(409)->assertJsonPath('meta.state', 'link_failed');

        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->where('user_id', $user->id)->count());
    }

    public function test_an_address_changed_since_the_offer_discards_it(): void
    {
        $user = $this->person('owner@link.test');
        $this->offered($user);
        $user->forceFill(['email' => 'moved@link.test'])->save();

        $this->pendingPassword(self::PASSWORD)->assertStatus(410);
        $this->assertGuest('web');
        $this->assertSame(0, OAuthIdentity::query()->count());
    }

    public function test_linking_from_account_settings_is_unchanged(): void
    {
        $user = $this->person();
        config(['services.google.client_id' => 'test-id', 'services.google.client_secret' => 'test-secret']);

        $this->actingAs($user)->postJson('/api/v1/auth/oauth/google/link')->assertOk()->assertJsonStructure(['data' => ['authorize_url']]);
    }
}

/** A stand-in for a channel that has credentials. The transport itself is faked. */
final class LinkInPlaceMailProvider implements MessageProvider
{
    public function channel(): string
    {
        return 'email';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @param  array<string,mixed>  $payload */
    public function send(string $destination, array $payload): array
    {
        return ['status' => 'sent', 'provider_message_id' => 'test', 'error' => null];
    }
}
