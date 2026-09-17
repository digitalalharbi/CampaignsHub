<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Access\Models\Permission;
use App\Domains\Access\Models\Role;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Support\ProviderErrorText;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * A request receipt may name the request; it may never carry the credential that was sent with it.
 *
 * `ApiAdvertisingConnector::read()` records every call's URL for `integrations:probe`, which prints
 * it into a workflow log, and its docblock said «the URL carries no secret — every platform here
 * authenticates in a header». TikTok does not: `/oauth2/advertiser/get/` takes `app_id` and `secret`
 * in the QUERY STRING, and that is the documented call, not a mistake in ours. So the one discovery
 * call every TikTok connection makes wrote the app secret into the receipt, and a probe on TikTok
 * would have printed it.
 *
 * The second path is the OAuth callback. Guzzle appends « for {uri}» to a connection failure's
 * message, and the callback sends that message back to the browser as `?reason=` — with the query
 * string, secret included, in the address bar and the browser history.
 *
 * Both are pinned here rather than in the TikTok suite because the rule is about the receipt and
 * the redirect, and the next platform to put a credential in a query string must hit the same guard.
 */
final class SecretNeverInLoggedUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'tt-app-secret-3f9c1e-NEVER-ON-A-SCREEN';

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);

        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);

        $role = Role::create(['tenant_id' => $this->tenant->id, 'name' => 'Owner', 'slug' => 'owner']);
        $role->givePermissionTo(...Permission::pluck('key')->all());

        $this->owner = User::create(['name' => 'O', 'email' => 'o@t.test', 'password' => 'secret123']);
        $this->grantMembership($this->owner, $this->tenant);
        $this->owner->assignRole($role);

        foreach (PlatformCredentials::for('tiktok')->requires() as $key) {
            config()->set("ad_platforms.platforms.tiktok.{$key}", "test-{$key}");
        }
        config()->set('ad_platforms.platforms.tiktok.client_secret', self::SECRET);
    }

    /**
     * **The defect, pinned.** The receipt of the one call that sends the secret in the query.
     */
    public function test_the_call_receipt_of_tiktok_discovery_carries_no_app_secret(): void
    {
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => Http::response([
                'code' => 0,
                'data' => ['list' => [['advertiser_id' => '777', 'advertiser_name' => 'A', 'currency' => 'SAR']]],
            ]),
        ]);

        $connector = new TikTokConnector;
        $accounts = $connector->discoverAdAccounts(new OAuthTokens('AT'));
        $log = $connector->takeCallLog();

        $this->assertCount(1, $accounts, 'the discovery itself still works');
        $this->assertCount(1, $log);

        $url = $log[0]['url'];

        $this->assertStringNotContainsString(self::SECRET, $url, 'the receipt carries the app secret');
        $this->assertStringNotContainsString(rawurlencode(self::SECRET), $url);
        // The receipt still says WHAT was asked: the endpoint, and that a secret was sent (not which).
        $this->assertStringContainsString('oauth2/advertiser/get', $url);
        $this->assertStringContainsString('app_id=test-client_id', $url);
        $this->assertMatchesRegularExpression('/secret=(\[redacted\]|%5Bredacted%5D)/', $url);

        // And the request TikTok received is untouched — the redaction is of the record, not the call.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'secret='.self::SECRET));
    }

    /**
     * A credential is redacted by its VALUE as well as by its name.
     *
     * The name list catches `secret=`; it cannot catch the next platform that calls the same thing
     * `sig`, `key` or `pass`. Every configured secret value the connector holds is removed wherever it
     * appears in a receipt, whatever the parameter is called.
     */
    public function test_a_configured_secret_is_redacted_from_a_receipt_whatever_the_parameter_is_called(): void
    {
        $url = 'https://business-api.tiktok.com/x/?app_id=test-client_id&sig='.self::SECRET.'&page=2';

        $redacted = ProviderErrorText::forReceipt($url, [self::SECRET]);

        $this->assertStringNotContainsString(self::SECRET, $redacted);
        $this->assertStringContainsString('app_id=test-client_id', $redacted);
        $this->assertStringContainsString('page=2', $redacted);
        $this->assertStringContainsString('sig=[redacted]', $redacted);
    }

    /**
     * **The second path.** A connection failure during discovery is sent back to the browser as the
     * reason, and Guzzle's message names the URL that failed — query string and all.
     */
    public function test_a_failed_discovery_does_not_send_the_secret_back_in_the_redirect(): void
    {
        $failing = 'https://business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/?app_id=test-client_id&secret='.self::SECRET;

        Sleep::fake(); // the retry loop backs off between attempts; the wait is not what is under test
        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/access_token*' => Http::response([
                'code' => 0,
                'data' => ['access_token' => 'AT', 'advertiser_ids' => ['777'], 'scope' => [4]],
            ]),
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => static function (): never {
                // The shape Guzzle actually produces: the curl error, then « for {uri}».
                throw new ConnectionException('cURL error 28: Operation timed out after 60000 milliseconds for '.self::failingUrl());
            },
        ]);

        $start = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/v1/integrations/tiktok/oauth/start')
            ->assertOk()
            ->json('data.authorization_url');
        parse_str((string) parse_url((string) $start, PHP_URL_QUERY), $query);

        $response = $this->get('/api/v1/oauth/ads/tiktok/callback?'.http_build_query([
            'code' => 'c', 'auth_code' => 'ac', 'id' => '1', 'state' => (string) $query['state'],
        ]));

        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('outcome=failed', $location);
        $this->assertStringNotContainsString(self::SECRET, urldecode($location), 'the app secret went back to the browser');
        $this->assertStringContainsString('timed out', urldecode($location), 'the reason itself still reaches the reader');
        $this->assertSame($failing, self::failingUrl());
    }

    /**
     * **Found by the case above, and pinned on its own.** A connection that never landed is the one
     * failure `PlatformHttp::isWorthRetrying()` always says yes to — and `backoff()` was typed to
     * accept only a `RequestException`, which a connection failure is not. So the retry loop's first
     * real timeout, on any platform, threw a TypeError out of the sleep callback instead of waiting
     * and trying again, and the reader was shown «Argument #2 must be of type ?RequestException»
     * where the platform's own words belonged.
     */
    public function test_a_connection_failure_is_retried_rather_than_crashing_the_retry_loop(): void
    {
        Sleep::fake();
        $attempts = 0;

        Http::fake([
            'business-api.tiktok.com/open_api/*/oauth2/advertiser/get*' => static function () use (&$attempts) {
                if (++$attempts === 1) {
                    throw new ConnectionException('cURL error 28: Operation timed out for '.self::failingUrl());
                }

                return Http::response([
                    'code' => 0,
                    'data' => ['list' => [['advertiser_id' => '777', 'advertiser_name' => 'A', 'currency' => 'SAR']]],
                ]);
            },
        ]);

        $accounts = (new TikTokConnector)->discoverAdAccounts(new OAuthTokens('AT'));

        $this->assertSame(2, $attempts, 'the second attempt is the retry');
        $this->assertCount(1, $accounts, 'the retry answered, and the answer was read');
    }

    private static function failingUrl(): string
    {
        return 'https://business-api.tiktok.com/open_api/v1.3/oauth2/advertiser/get/?app_id=test-client_id&secret='.self::SECRET;
    }
}
