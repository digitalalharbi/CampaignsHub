<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\XConnector;
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
 * OAUTH1-X-001 — the X Ads API is OAuth 1.0a, and the connection now speaks it.
 *
 * X's own Ads API documentation says every call must carry an OAuth 1.0a Authorization header. This
 * connection used the OAuth 2.0 PKCE flow of the general X API, which yields a credential the Ads API
 * is documented not to accept — so it would have connected, discovered nothing and read nothing. These
 * tests pin the three-legged flow (request token → authenticate → access token) end to end with X
 * faked, and that every later Ads call is signed. They do not prove the flow against X itself: that
 * needs a real app with Ads API access (BLOCKED_EXTERNAL_CREDENTIALS).
 */
final class XOAuth1FlowTest extends TestCase
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

        foreach (PlatformCredentials::for('x')->requires() as $key) {
            config()->set("ad_platforms.platforms.x.{$key}", "test-{$key}");
        }
    }

    private function fakeX(): void
    {
        Http::fake([
            'api.x.com/oauth/request_token' => Http::response('oauth_token=REQ1&oauth_token_secret=REQSECRET&oauth_callback_confirmed=true'),
            'api.x.com/oauth/access_token' => Http::response('oauth_token=ACC1&oauth_token_secret=ACCSECRET&user_id=42&screen_name=advertiser'),
            'ads-api.x.com/*' => Http::response(['data' => [['id' => 'abc12', 'name' => 'Main X account', 'timezone' => 'Asia/Riyadh', 'deleted' => false]]]),
        ]);
    }

    private function start(): string
    {
        return (string) $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/integrations/x/oauth/start')->assertOk()->json('data.authorization_url');
    }

    public function test_starting_asks_x_for_a_signed_request_token_and_sends_the_customer_to_authenticate(): void
    {
        $this->fakeX();

        $url = $this->start();

        $this->assertStringStartsWith('https://api.x.com/oauth/authenticate?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(['oauth_token' => 'REQ1'], $q, 'no PKCE challenge, no client_id, no scope — that is OAuth 2.0');

        Http::assertSent(function (Request $r): bool {
            if ($r->url() !== 'https://api.x.com/oauth/request_token') {
                return false;
            }
            $auth = $r->header('Authorization')[0] ?? '';

            return str_starts_with($auth, 'OAuth ')
                && str_contains($auth, 'oauth_consumer_key="test-client_id"')
                && str_contains($auth, 'oauth_signature_method="HMAC-SHA1"')
                && str_contains($auth, 'oauth_callback="'.rawurlencode(PlatformCredentials::for('x')->redirectUri()).'"')
                && ! str_contains($auth, 'oauth_token=');
        });
    }

    public function test_the_callback_trades_the_verifier_for_tokens_and_every_ads_call_is_signed(): void
    {
        $this->fakeX();
        $this->start();

        $res = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=REQ1&oauth_verifier=VER1');
        $res->assertRedirect();
        $this->assertStringContainsString('outcome=connected', (string) $res->headers->get('Location'));

        // The access-token call is signed with the REQUEST token and its secret, and names the verifier.
        Http::assertSent(function (Request $r): bool {
            if ($r->url() !== 'https://api.x.com/oauth/access_token') {
                return false;
            }
            $auth = $r->header('Authorization')[0] ?? '';

            return str_contains($auth, 'oauth_token="REQ1"') && str_contains($auth, 'oauth_verifier="VER1"');
        });

        // The tokens are stored with their secret, and never expire.
        $connection = ProviderConnection::withoutGlobalScopes()->where('provider', 'x')->firstOrFail();
        $tokens = app(TokenVault::class)->stored($connection);
        $this->assertSame('ACC1', $tokens->accessToken);
        $this->assertSame('ACCSECRET', $tokens->raw['token_secret']);
        $this->assertNull($tokens->expiresAt);
        $this->assertFalse($tokens->isExpired());

        // The discovery call that followed carried a signature made with the ACCESS token.
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), 'https://ads-api.x.com/12/accounts')
            && str_contains($r->header('Authorization')[0] ?? '', 'oauth_token="ACC1"')
            && str_contains($r->header('Authorization')[0] ?? '', 'oauth_signature='));
    }

    public function test_a_request_token_cannot_be_replayed(): void
    {
        $this->fakeX();
        $this->start();

        $this->get('/api/v1/oauth/ads/x/callback?oauth_token=REQ1&oauth_verifier=VER1');
        $again = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=REQ1&oauth_verifier=VER1');

        $this->assertStringContainsString('outcome=invalid_state', (string) $again->headers->get('Location'));
        $this->assertSame(1, ProviderConnection::withoutGlobalScopes()->where('provider', 'x')->count());
    }

    public function test_an_unknown_request_token_is_refused_before_anything_is_exchanged(): void
    {
        $this->fakeX();

        $res = $this->get('/api/v1/oauth/ads/x/callback?oauth_token=FORGED&oauth_verifier=VER1');

        $this->assertStringContainsString('outcome=invalid_state', (string) $res->headers->get('Location'));
        Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://api.x.com/oauth/access_token');
    }

    public function test_the_customer_refusing_ends_the_flow(): void
    {
        $this->fakeX();
        $this->start();

        $res = $this->get('/api/v1/oauth/ads/x/callback?denied=REQ1');

        $this->assertStringContainsString('outcome=denied', (string) $res->headers->get('Location'));
        Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://api.x.com/oauth/access_token');
    }

    public function test_a_refused_request_token_is_reported_not_papered_over(): void
    {
        Http::fake(['api.x.com/oauth/request_token' => Http::response('{"errors":[{"code":32}]}', 401)]);

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/integrations/x/oauth/start')->assertStatus(500)->getContent();
        $this->assertSame(0, ProviderConnection::withoutGlobalScopes()->where('provider', 'x')->count());
    }

    public function test_the_catalogue_describes_oauth_one_and_only_x_skips_pkce_and_refresh(): void
    {
        $x = ProviderCatalogue::get('x');

        $this->assertFalse($x->usesPkce);
        $this->assertFalse($x->supportsRefresh);
        $this->assertStringContainsString('OAuth 1.0a', $x->tokenNote);
        $this->assertTrue(app(PlatformOAuth::class)->usesOAuth1(PlatformCredentials::for('x')));
        foreach (['snapchat', 'tiktok', 'meta', 'google', 'linkedin', 'salla', 'zid'] as $other) {
            $this->assertFalse(app(PlatformOAuth::class)->usesOAuth1(PlatformCredentials::for($other)), $other);
        }
    }

    public function test_a_connection_made_without_a_token_secret_refuses_to_sign(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('predates OAuth 1.0a');

        $connector = app(XConnector::class);
        $method = new \ReflectionMethod($connector, 'api');
        $method->invoke($connector, new OAuthTokens('bearer-from-oauth2'));
    }
}
