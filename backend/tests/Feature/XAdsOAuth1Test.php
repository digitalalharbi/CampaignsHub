<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Catalogue\AuthScheme;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Configuration\ProviderConfigurationService;
use App\Domains\Integrations\Configuration\ProviderProbe;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConfiguration;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuth1Signer;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\GrantsMemberships;
use Tests\TestCase;

/**
 * X-OAUTH1-001 — X Ads authenticates with OAuth 1.0a signed requests, and nothing else.
 *
 * The owner tested the real X Developer Console against this product and found the configuration
 * wrong for X Ads: it asked for an OAuth 2.0 Client ID and Secret, used PKCE, and the connector sent
 * `Authorization: Bearer …` to `ads-api.x.com`, which accepts only OAuth 1.0a signed requests. No
 * approved app configured that way could ever have listed an ad account.
 *
 * None of this is proven against X itself — X has not approved Ads API access for the app, and no test
 * here may reach the network. So correctness is proven the two ways that do not need X:
 *
 * 1. **Known-answer signatures.** The signer is checked against the OAuth Core 1.0 / RFC 5849 worked
 *    example, with the nonce and timestamp fixed, down to the exact `Authorization` header. A signer
 *    that encodes one character wrongly fails here instead of failing at X as «Could not authenticate».
 * 2. **Request-level wiring.** The connector, the probe and the three legs of the flow are asserted to
 *    send exactly that header — consumer pair plus the right token pair — and never a bearer token.
 */
final class XAdsOAuth1Test extends TestCase
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

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Known answers ─────────────────────────────────────────────────────────────────────────

    /**
     * The worked example from OAuth Core 1.0 §A.5 (the basis of RFC 5849), reproduced to the byte:
     * GET http://photos.example.net/photos?file=vacation.jpg&size=original, signed HMAC-SHA1.
     */
    public function test_the_signer_reproduces_the_oauth_core_worked_example_exactly(): void
    {
        $this->fixNonceAndClock('kllo9940pd9333jh', 1191242096);

        $signer = new OAuth1Signer(
            consumerKey: 'dpf43f3p2l4k3l03',
            consumerSecret: 'kd94hf93k423kf44',
            token: 'nnch734d00sl2jdk',
            tokenSecret: 'pfkkdhi9sl3r4s00',
        );

        $header = $signer->authorizationHeader(
            new PsrRequest('GET', 'http://photos.example.net/photos?file=vacation.jpg&size=original'),
        );

        $this->assertSame(
            'OAuth oauth_consumer_key="dpf43f3p2l4k3l03", oauth_nonce="kllo9940pd9333jh", '
                .'oauth_signature="tR3%2BTy81lMeYAr%2FFid0kMTYa%2FWM%3D", oauth_signature_method="HMAC-SHA1", '
                .'oauth_timestamp="1191242096", oauth_token="nnch734d00sl2jdk", oauth_version="1.0"',
            $header,
        );
    }

    /**
     * X's own «Creating a signature» example: a POST whose parameters are split between the query
     * string and a form body, with characters that must be percent-encoded. Both halves must enter
     * the base string, and a signer that ignores the body passes the GET example above and fails here.
     */
    public function test_the_signer_reproduces_xs_documented_signature_for_a_form_post(): void
    {
        $this->fixNonceAndClock('kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg', 1318622958);

        $signer = new OAuth1Signer(
            consumerKey: 'xvz1evFS4wEEPTGEFPHBog',
            consumerSecret: 'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw',
            token: '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb',
            tokenSecret: 'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE',
        );

        $header = $signer->authorizationHeader(new PsrRequest(
            'POST',
            'https://api.twitter.com/1.1/statuses/update.json?include_entities=true',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            'status=Hello%20Ladies%20%2B%20Gentlemen%2C%20a%20signed%20OAuth%20request%21',
        ));

        $this->assertStringContainsString('oauth_signature="hCtSmYh%2BiHYCEqBWrE7C7hYmtUk%3D"', $header);
    }

    // ── The connector ─────────────────────────────────────────────────────────────────────────

    /**
     * The defect, pinned at the wire: listing ad accounts is a signed OAuth 1.0a GET, never a bearer call.
     *
     * The expected header is the signer's answer for exactly these inputs, and the signer is held to
     * the published examples above — so a connector that signed with the wrong secret, the owner's
     * token, or no token at all produces a different header and fails here.
     */
    public function test_the_connector_signs_get_accounts_with_all_four_credentials_and_sends_no_bearer(): void
    {
        $this->configure();
        $this->fixNonceAndClock('fixednonce0000000000000000000000', 1789000000);

        Http::preventStrayRequests();
        Http::fake(['https://ads-api.x.com/12/accounts' => Http::response(['data' => [
            ['id' => '18ce54d4x5t', 'name' => 'Launch', 'timezone' => 'Asia/Riyadh'],
        ]])]);

        $connection = $this->connection(new OAuthTokens('workspace-token', tokenSecret: 'workspace-token-secret'));

        $accounts = app(AdvertisingConnectorRegistry::class)->get('x')->withConnection($connection)->listAdAccounts();

        $this->assertSame('18ce54d4x5t', $accounts[0]['external_id']);

        $expected = (new OAuth1Signer(
            consumerKey: 'test-consumer_key',
            consumerSecret: 'test-consumer_secret',
            token: 'workspace-token',
            tokenSecret: 'workspace-token-secret',
        ))->authorizationHeader(new PsrRequest('GET', 'https://ads-api.x.com/12/accounts'));

        Http::assertSent(function (Request $request) use ($expected): bool {
            $authorization = $request->header('Authorization');

            $this->assertSame('GET', $request->method());
            $this->assertSame('https://ads-api.x.com/12/accounts', $request->url());
            $this->assertCount(1, $authorization, 'exactly one Authorization header');
            $this->assertStringStartsNotWith('Bearer', $authorization[0], 'X-OAUTH1-001: a bearer token was sent to the X Ads API');
            $this->assertSame($expected, $authorization[0]);

            return true;
        });
        Http::assertSentCount(1);
    }

    /** The WORKSPACE's token signs, never the app owner's — that is what keeps one tenant out of another's accounts. */
    public function test_the_connector_never_signs_with_the_app_owners_token(): void
    {
        $this->configure();
        Http::fake(['https://ads-api.x.com/12/accounts' => Http::response(['data' => []])]);

        $connection = $this->connection(new OAuthTokens('workspace-token', tokenSecret: 'workspace-token-secret'));
        app(AdvertisingConnectorRegistry::class)->get('x')->withConnection($connection)->listAdAccounts();

        Http::assertSent(fn (Request $request): bool => str_contains($request->header('Authorization')[0], 'oauth_token="workspace-token"')
            && ! str_contains($request->header('Authorization')[0], 'test-access_token'));
    }

    /** A connection authorised under the old OAuth 2.0 model has no token secret: refused, and nothing sent. */
    public function test_a_connection_from_the_oauth2_model_is_refused_without_calling_x(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake();

        $connection = $this->connection(new OAuthTokens('an-oauth2-bearer-token', 'RT', Carbon::now()->addDay()));

        $result = app(AdvertisingConnectorRegistry::class)->get('x')->withConnection($connection)->syncCampaigns('acct');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Connect X again', (string) $result->message);
        Http::assertNothingSent();
    }

    // ── The three legs ────────────────────────────────────────────────────────────────────────

    /** Leg one: a signed request token bound to our callback, and the customer sent to X with it — no PKCE, no state. */
    public function test_starting_asks_x_for_a_request_token_and_sends_the_customer_to_authorise_it(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake(['https://api.x.com/oauth/request_token' => Http::response(
            'oauth_token=req-token&oauth_token_secret=req-secret&oauth_callback_confirmed=true',
        )]);

        $url = $this->start();

        $this->assertSame('https://api.x.com/oauth/authorize?oauth_token=req-token', $url);

        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            $this->assertSame('POST', $request->method());
            $this->assertStringStartsWith('OAuth ', $authorization);
            $this->assertStringContainsString('oauth_consumer_key="test-consumer_key"', $authorization);
            $this->assertStringContainsString(
                'oauth_callback="'.rawurlencode(ProviderCatalogue::get('x')->redirectUri()).'"',
                $authorization,
            );
            // No user token exists at leg one — and above all, not the app owner's.
            $this->assertStringNotContainsString('oauth_token=', $authorization);

            return true;
        });
    }

    /** A request token X issued without confirming our callback would lead the customer nowhere. */
    public function test_a_request_token_without_a_confirmed_callback_is_refused(): void
    {
        $this->configure();
        Http::fake(['https://api.x.com/oauth/request_token' => Http::response('oauth_token=t&oauth_token_secret=s')]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/x/oauth/start')
            ->assertStatus(502)
            ->assertJsonPath('meta.status', 'provider_refused');
    }

    /** Legs two and three: the verifier is exchanged with the request token's secret, and the pair is stored encrypted. */
    public function test_the_callback_exchanges_the_verifier_and_stores_the_token_pair_encrypted(): void
    {
        $this->configure();
        Http::fake([
            'https://api.x.com/oauth/request_token' => Http::response(
                'oauth_token=req-token&oauth_token_secret=req-secret&oauth_callback_confirmed=true',
            ),
            'https://api.x.com/oauth/access_token' => Http::response(
                'oauth_token=user-token&oauth_token_secret=user-token-secret&user_id=6253282&screen_name=campaignshub',
            ),
            'https://ads-api.x.com/12/accounts' => Http::response(['data' => [['id' => 'acct-1', 'name' => 'Launch']]]),
        ]);

        $this->start();
        $this->fixNonceAndClock('callbacknonce000000000000000000', 1789000100);

        $response = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=req-token&oauth_verifier=the-verifier');

        $this->assertStringContainsString('outcome=connected', (string) $response->headers->get('Location'));

        $expected = (new OAuth1Signer(
            consumerKey: 'test-consumer_key',
            consumerSecret: 'test-consumer_secret',
            token: 'req-token',
            tokenSecret: 'req-secret',
            verifier: 'the-verifier',
        ))->authorizationHeader(new PsrRequest('POST', 'https://api.x.com/oauth/access_token'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/oauth/access_token'
            && $request->header('Authorization')[0] === $expected);

        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'x')->sole();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('6253282', $connection->external_owner_id);
        $this->assertSame(1, ExternalAccount::withoutGlobalScopes()->where('provider_connection_id', $connection->id)->count());

        $stored = app(TokenVault::class)->stored($connection);
        $this->assertSame('user-token', $stored->accessToken);
        $this->assertSame('user-token-secret', $stored->tokenSecret);

        $credential = IntegrationCredential::withoutGlobalScopes()->findOrFail($connection->credential_id);
        $this->assertSame('oauth1', $credential->credential_type);
        $raw = (string) $credential->getRawOriginal('encrypted_payload');
        $this->assertStringNotContainsString('user-token-secret', $raw, 'the token secret must be ciphertext at rest');
        $this->assertStringNotContainsString('user-token-secret', json_encode($connection->toArray(), JSON_THROW_ON_ERROR));
    }

    /** An `oauth_token` we never recorded — or one already used — opens nothing and exchanges nothing. */
    public function test_a_callback_for_a_request_token_we_did_not_issue_exchanges_nothing(): void
    {
        $this->configure();
        Http::preventStrayRequests();
        Http::fake();

        $response = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=forged&oauth_verifier=v');

        $this->assertStringContainsString('outcome=invalid_state', (string) $response->headers->get('Location'));
        Http::assertNothingSent();
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->count());
    }

    /** Cancelling on X consumes the pending authorisation, so it cannot be completed later. */
    public function test_a_denied_authorisation_cannot_be_completed_afterwards(): void
    {
        $this->configure();
        Http::fake(['https://api.x.com/oauth/request_token' => Http::response(
            'oauth_token=req-token&oauth_token_secret=req-secret&oauth_callback_confirmed=true',
        )]);

        $this->start();

        $denied = $this->get('/api/v1/oauth/ads/x/callback?denied=req-token');
        $this->assertStringContainsString('outcome=denied', (string) $denied->headers->get('Location'));

        $later = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=req-token&oauth_verifier=v');
        $this->assertStringContainsString('outcome=invalid_state', (string) $later->headers->get('Location'));
    }

    /** The OAuth 2.0 entry points refuse X rather than build a URL or a grant X does not accept. */
    public function test_the_oauth2_flow_refuses_x(): void
    {
        $this->configure();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/OAuth 1\.0a/');

        app(PlatformOAuth::class)->authorizationUrl(PlatformCredentials::for('x'), 'state');
    }

    // ── Test configuration ────────────────────────────────────────────────────────────────────

    /** The probe is a real signed GET /12/accounts with the four admin values, and its pass claims nothing more. */
    public function test_the_probe_signs_get_accounts_with_the_four_admin_credentials(): void
    {
        $this->saveAdminCredentials();
        Http::preventStrayRequests();
        Http::fake(['https://ads-api.x.com/12/accounts' => Http::response(['data' => [['id' => 'owner-acct', 'name' => 'Owner Secret Account']]])]);

        $result = app(ProviderProbe::class)->run('x');

        $this->assertTrue($result['passed']);
        $this->assertStringContainsString('no workspace is connected', $result['message']);
        $this->assertStringNotContainsString('Owner Secret Account', $result['message'], 'the owner\'s accounts are not echoed');

        Http::assertSent(function (Request $request): bool {
            $authorization = $request->header('Authorization')[0] ?? '';

            $this->assertStringStartsWith('OAuth ', $authorization);
            $this->assertStringContainsString('oauth_consumer_key="ck-1234"', $authorization);
            $this->assertStringContainsString('oauth_token="owner-token-5678"', $authorization);
            $this->assertStringNotContainsString('Bearer', $authorization);

            return $request->method() === 'GET' && $request->url() === 'https://ads-api.x.com/12/accounts';
        });
    }

    /** An app X has not approved for the Ads API is a failure that says so — the owner's real state today. */
    public function test_the_probe_names_an_app_without_ads_api_access(): void
    {
        $this->saveAdminCredentials();
        Http::fake(['https://ads-api.x.com/12/accounts' => Http::response(
            ['errors' => [['code' => 'UNAUTHORIZED_CLIENT_APPLICATION', 'message' => 'The client application making this request does not have access to Twitter Ads API']]],
            403,
        )]);

        $result = app(ProviderProbe::class)->run('x');

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('refused the Ads API to this app', $result['message']);
    }

    public function test_the_probe_names_a_signature_x_could_not_authenticate(): void
    {
        $this->saveAdminCredentials();
        Http::fake(['https://ads-api.x.com/12/accounts' => Http::response(
            ['errors' => [['code' => 32, 'message' => 'Could not authenticate you.']]],
            401,
        )]);

        $result = app(ProviderProbe::class)->run('x');

        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('could not authenticate', $result['message']);
    }

    // ── What the operator is asked for ────────────────────────────────────────────────────────

    public function test_x_asks_for_exactly_the_four_oauth1a_credentials_and_no_bearer_token(): void
    {
        $x = ProviderCatalogue::get('x');

        $this->assertSame(AuthScheme::OAuth1a, $x->authScheme);
        $this->assertSame(['consumer_key', 'consumer_secret', 'access_token', 'access_token_secret'], $x->requiredKeys());
        $this->assertSame($x->requiredKeys(), $x->secretKeys(), 'every one of the four is write-only');
        $this->assertSame([], $x->scopes, 'OAuth 1.0a has no scopes');
        $this->assertFalse($x->supportsRefresh);

        foreach ($x->fields as $field) {
            $this->assertStringNotContainsStringIgnoringCase('bearer', $field->label.$field->where);
            $this->assertStringNotContainsStringIgnoringCase('oauth 2.0', $field->label.$field->labelAr);
            $this->assertNotSame('', $field->labelAr);
            $this->assertNotSame('', $field->whereAr);
        }

        foreach (ProviderCatalogue::all() as $definition) {
            $this->assertSame(
                $definition->key === 'x' ? AuthScheme::OAuth1a : AuthScheme::OAuth2,
                $definition->authScheme,
                "{$definition->key}: only X Ads uses OAuth 1.0a",
            );
        }
    }

    /** Saved credentials are never returned — not by the admin API, not as a value, only as a four-character hint. */
    public function test_the_admin_api_never_returns_a_saved_x_credential(): void
    {
        $admin = User::create(['name' => 'P', 'email' => 'p@x.test', 'password' => 'secret123']);
        $admin->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/integrations/providers/x', [
                'consumer_key' => 'ck-value-aaaa', 'consumer_secret' => 'cs-value-bbbb',
                'access_token' => 'at-value-cccc', 'access_token_secret' => 'ats-value-dddd',
            ])
            ->assertOk();

        $body = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/settings/integrations/providers')->assertOk()->getContent();

        foreach (['ck-value-aaaa', 'cs-value-bbbb', 'at-value-cccc', 'ats-value-dddd'] as $value) {
            $this->assertStringNotContainsString($value, (string) $body);
        }

        $raw = (string) ProviderConfiguration::query()->where('provider', 'x')->sole()->getRawOriginal('credentials');
        $this->assertStringNotContainsString('ats-value-dddd', $raw, 'stored encrypted');
        $this->assertTrue(app(ProviderConfigurationService::class)->isConfigured('x'));
    }

    // ── What happens to rows from the OAuth 2.0 model ─────────────────────────────────────────

    public function test_the_migration_takes_oauth2_x_connections_out_of_connected_and_drops_the_obsolete_keys(): void
    {
        $legacy = $this->connection(new OAuthTokens('bearer', 'RT', Carbon::now()->addDay()));
        $current = ProviderConnection::withoutGlobalScopes()->create([
            ...collect($legacy->getAttributes())->except(['id', 'credential_id', 'client_workspace_id', 'created_at', 'updated_at'])->all(),
            'credential_id' => IntegrationCredential::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'provider' => 'x', 'credential_scope' => 'tenant_shared',
                'credential_type' => 'oauth1', 'encrypted_payload' => '{"access_token":"t","token_secret":"s"}', 'status' => 'active',
            ])->id,
            'client_workspace_id' => null,
        ]);

        ProviderConfiguration::query()->create([
            'provider' => 'x', 'environment' => 'sandbox', 'enabled' => true,
            'credentials' => ['client_id' => 'old-oauth2-id', 'client_secret' => 'old-oauth2-secret', 'consumer_key' => 'kept'],
            'scopes' => ['tweet.read'], 'last_test_status' => 'passed',
        ]);

        (require database_path('migrations/2026_09_16_090000_x_ads_authenticates_with_oauth_one_a.php'))->up();

        $this->assertSame('error', $legacy->fresh()->status);
        $this->assertStringContainsString('Connect X again', (string) $legacy->fresh()->last_error);
        $this->assertSame('connected', $current->fresh()->status, 'an OAuth 1.0a connection is left alone');

        $row = ProviderConfiguration::query()->where('provider', 'x')->sole();
        $this->assertSame(['consumer_key' => 'kept'], $row->credentials);
        $this->assertNull($row->scopes);
        $this->assertNull($row->last_test_status);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    private function fixNonceAndClock(string $nonce, int $timestamp): void
    {
        Str::createRandomStringsUsing(static fn (): string => $nonce);
        Carbon::setTestNow(Carbon::createFromTimestamp($timestamp));
    }

    private function configure(): void
    {
        foreach (PlatformCredentials::for('x')->requires() as $key) {
            config()->set("ad_platforms.platforms.x.{$key}", "test-{$key}");
        }
    }

    private function saveAdminCredentials(): void
    {
        app(ProviderConfigurationService::class)->save('x', [
            'consumer_key' => 'ck-1234', 'consumer_secret' => 'cs-1234',
            'access_token' => 'owner-token-5678', 'access_token_secret' => 'owner-secret-5678',
        ]);
    }

    private function connection(OAuthTokens $tokens): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'x',
            tokens: $tokens,
            connectionName: 'X Ads API',
        );
    }

    private function start(): string
    {
        return (string) $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/x/oauth/start')
            ->assertOk()
            ->json('data.authorization_url');
    }
}
