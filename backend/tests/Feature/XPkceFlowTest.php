<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\OAuth\AuthorizationState;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * X-PKCE-001 — PKCE was declared mandatory in three places and implemented in none.
 *
 * ## The defect
 *
 * `ProviderCatalogue` says so plainly. The class header: «X — PKCE is mandatory, so `code_verifier`
 * must survive the whole round trip». The X definition: `usesPkce: true`, and a `tokenNote` telling
 * the reader that «the authorise call carries a `code_challenge` and the token call must present the
 * matching `code_verifier`».
 *
 * `grep -rn "code_challenge\|code_verifier" app/Domains/Integrations/` returned **only those three
 * comments**. Not one line of code. The only PKCE in this repository was in
 * `Identity/Services/OAuthFlow` — the unrelated staff sign-in flow.
 *
 * And X is not a platform where PKCE is a hardening option. Its own documentation: «We only provide
 * authorization code with PKCE and refresh token as the supported grant types.» It is the *only*
 * authorization-code flow X offers. So **every X connection attempt would have been refused at the
 * authorise step**, for every customer, always — while the console described the integration as ready
 * and the catalogue described the mechanism in detail.
 *
 * That combination is the reason this is worth a defect id of its own. Documentation that describes a
 * mechanism nobody wrote is worse than no documentation: it is the thing a reviewer checks INSTEAD of
 * the code.
 *
 * ## Where the verifier lives
 *
 * Not the session. The integrations callback is a PUBLIC route — the platform redirects a browser to
 * it and nothing of the session survives that hop, which is the whole reason `AuthorizationState`
 * exists. So the verifier rides in that record's `extra`, and inherits its properties exactly:
 * single-use (`Cache::pull`), short-lived, and bound to the tenant, user and provider that minted it.
 *
 * ## And the catalogue is now the thing that decides
 *
 * `usesPkce` used to be a field nothing read. It now drives the behaviour, so the declaration and the
 * implementation cannot drift apart again — which is precisely how they came to differ.
 */
final class XPkceFlowTest extends TestCase
{
    use GrantsMemberships;
    use RefreshDatabase;

    private Tenant $tenant;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->operator = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'secret123']);
        $this->grantMembership($this->operator, $this->tenant);
        $this->operator->assignRole($role);
    }

    // ── The authorise call ────────────────────────────────────────────────────────────────────

    /** The defect, pinned: X's authorise URL carried no challenge, so X would refuse it. */
    public function test_the_x_authorise_url_carries_a_challenge_and_names_s256(): void
    {
        $this->configure('x');
        Http::preventStrayRequests();

        $url = $this->start('x');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey(
            'code_challenge',
            $query,
            'X-PKCE-001: the authorise URL had no code_challenge, and PKCE is the only '
                .'authorization-code grant X supports — every connection would have been refused.',
        );

        $this->assertSame('S256', $query['code_challenge_method'] ?? null, 'plain is not acceptable to X');
    }

    /**
     * The challenge is the SHA-256 of the verifier we kept — checked by recomputing it, not by
     * trusting that two values were produced together.
     */
    public function test_the_challenge_is_the_s256_of_the_verifier_that_was_stored(): void
    {
        $this->configure('x');
        Http::preventStrayRequests();

        $url = $this->start('x');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $record = AuthorizationState::claim((string) $query['state'], 'x');

        $this->assertIsArray($record);
        $this->assertArrayHasKey('code_verifier', $record, 'the verifier must survive the round trip');

        $verifier = (string) $record['code_verifier'];

        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'],
            'the challenge must be the base64url-encoded SHA-256 of the verifier, per RFC 7636',
        );
    }

    /** RFC 7636: 43–128 unreserved characters. A verifier outside that range is refused by the server. */
    public function test_the_verifier_is_within_the_length_the_specification_allows(): void
    {
        $this->configure('x');
        Http::preventStrayRequests();

        parse_str((string) parse_url($this->start('x'), PHP_URL_QUERY), $query);
        $record = AuthorizationState::claim((string) $query['state'], 'x');

        $verifier = (string) ($record['code_verifier'] ?? '');

        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
        $this->assertSame(1, preg_match('/^[A-Za-z0-9\-._~]+$/', $verifier), 'unreserved characters only');
    }

    /** A verifier that repeated would defeat the point of having one. */
    public function test_two_flows_do_not_share_a_verifier(): void
    {
        $this->configure('x');
        Http::preventStrayRequests();

        parse_str((string) parse_url($this->start('x'), PHP_URL_QUERY), $first);
        parse_str((string) parse_url($this->start('x'), PHP_URL_QUERY), $second);

        $this->assertNotSame($first['code_challenge'], $second['code_challenge']);
    }

    /**
     * And nobody else gets one.
     *
     * The set of providers that send a challenge is asserted to be exactly the set declaring
     * `usesPkce`, walked from the catalogue rather than named here — so a provider that adopts PKCE
     * later cannot be declared without being implemented, which is the defect itself.
     */
    public function test_only_the_providers_that_declare_pkce_send_a_challenge(): void
    {
        Http::preventStrayRequests();
        $oauth = app(PlatformOAuth::class);

        foreach (['snapchat', 'tiktok', 'meta', 'google', 'x', 'linkedin', 'salla', 'zid'] as $platform) {
            $this->configure($platform);

            $definition = ProviderCatalogue::get($platform);
            $creds = PlatformCredentials::for($platform);
            $url = $oauth->authorizationUrl($creds, 'st', $oauth->codeVerifier($creds));

            $this->assertSame(
                $definition->usesPkce,
                str_contains($url, 'code_challenge='),
                "{$platform}: the catalogue's usesPkce and the authorise URL disagree",
            );
        }
    }

    // ── The exchange ──────────────────────────────────────────────────────────────────────────

    /** The other half: the token call presents the verifier the challenge was made from. */
    public function test_the_token_exchange_presents_the_matching_verifier(): void
    {
        $this->configure('x');

        $url = $this->start('x');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $challenge = (string) $query['code_challenge'];

        Http::fake([
            'api.x.com/*' => Http::response(['access_token' => 'AT', 'expires_in' => 7200, 'refresh_token' => 'RT']),
            'ads-api.x.com/*' => Http::response(['data' => []]),
        ]);

        $this->get('/api/v1/oauth/ads/x/callback?code=the-code&state='.$query['state']);

        Http::assertSent(function (Request $request) use ($challenge): bool {
            // The token host specifically. `ads-api.x.com` also contains «api.x.com», and matching
            // loosely would inspect the account listing — which carries no verifier and never should.
            if (! str_starts_with($request->url(), 'https://api.x.com/2/oauth2/token')) {
                return false;
            }

            $verifier = $request->data()['code_verifier'] ?? null;

            $this->assertIsString($verifier, 'X-PKCE-001: the token call presented no code_verifier');
            $this->assertSame(
                $challenge,
                rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
                'the verifier presented must be the one the challenge was derived from',
            );

            return true;
        });
    }

    /**
     * Fail closed: a PKCE flow with no verifier is REFUSED, not attempted without one.
     *
     * This is the branch that decides what a future bug looks like. Exchanging anyway would send X a
     * request it must reject, and the customer would be shown X's refusal as though the platform were
     * at fault — the failure mode this whole audit exists to remove. Refusing names the real cause.
     */
    public function test_a_pkce_flow_whose_verifier_was_lost_is_refused_rather_than_attempted(): void
    {
        $this->configure('x');
        Http::preventStrayRequests();

        // A state minted WITHOUT a verifier — what a half-applied deployment would leave behind.
        $state = AuthorizationState::issue(
            tenantId: $this->tenant->id,
            provider: 'x',
            userId: $this->operator->id,
        );

        $response = $this->get('/api/v1/oauth/ads/x/callback?code=the-code&state='.$state);

        $response->assertRedirect();
        $this->assertStringContainsString('outcome=failed', (string) $response->headers->get('Location'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** Start a real flow through the controller and return the authorisation URL it issued. */
    private function start(string $platform): string
    {
        $response = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/integrations/{$platform}/oauth/start")
            ->assertOk();

        return (string) $response->json('data.authorization_url');
    }

    private function configure(string $platform): void
    {
        $root = in_array($platform, ['salla', 'zid'], true) ? 'commerce_platforms' : 'ad_platforms';

        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("{$root}.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }
}
