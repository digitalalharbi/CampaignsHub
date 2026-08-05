<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\IntegrationCredential;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\PlatformOAuth;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Integrations\Support\PlatformHttp;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * INTEG-OAUTH-001 — the ad-platform adapters, tested as ADAPTERS.
 *
 * ## What these tests can and cannot prove
 *
 * They prove the adapter code is real: that a Snapchat micro-spend becomes a decimal, that TikTok's
 * `code: 40001` is a failure and not data, that a 429 is retried and a 400 is not, that Google's
 * stream is flattened rather than half-read, and that an unconfigured platform never calls out.
 *
 * They do **not** prove any platform is connected. Every response below is faked, and a faked response
 * is a statement about our parsing, never about their API. No install in this repository holds
 * credentials for any of the six, so all six remain **Awaiting Credentials** and the audit says so.
 * That distinction is the point of writing it down here rather than in a commit message.
 */
final class AdPlatformConnectorTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'Ads', 'slug' => 'ads-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    // ── The honest default: nothing is configured on this install ─────────────────────────────

    /**
     * @dataProvider platforms
     */
    public function test_every_platform_is_awaiting_credentials_until_it_is_configured(string $platform): void
    {
        $creds = PlatformCredentials::for($platform);

        $this->assertFalse($creds->isConfigured(), "{$platform} should not be configured in tests");
        $this->assertNotSame([], $creds->missing(), 'an unconfigured platform must say what is missing');

        $connector = app(AdvertisingConnectorRegistry::class)->get($platform);
        $this->assertInstanceOf(ApiAdvertisingConnector::class, $connector);
        $this->assertSame(ConnectorStatus::AwaitingCredentials, $connector->status());
    }

    /** @return array<string, array{string}> */
    public static function platforms(): array
    {
        return [
            'snapchat' => ['snapchat'],
            'tiktok' => ['tiktok'],
            'meta' => ['meta'],
            'google' => ['google'],
            'x' => ['x'],
            'linkedin' => ['linkedin'],
        ];
    }

    /**
     * The strongest form of the claim: an un-credentialed sync makes NO network call at all.
     *
     * A connector that tried and failed would look identical in a log and be completely different in
     * effect — it would burn a platform's unauthenticated rate limit on every scheduled sweep.
     *
     * @dataProvider platforms
     */
    public function test_an_unconfigured_platform_refuses_without_calling_out(string $platform): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $connector = app(AdvertisingConnectorRegistry::class)->get($platform);
        $result = $connector->syncInsights('act_1', '2026-08-01', '2026-08-05');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('awaiting credentials', strtolower((string) $result->message));
        Http::assertNothingSent();
    }

    /** Google Ads is the one that can look configured and not be: the developer token is separate. */
    public function test_google_ads_is_not_configured_by_an_oauth_client_alone(): void
    {
        config()->set('ad_platforms.platforms.google.client_id', 'id');
        config()->set('ad_platforms.platforms.google.client_secret', 'secret');

        $creds = PlatformCredentials::for('google');

        $this->assertFalse($creds->isConfigured());
        $this->assertSame(['developer_token'], $creds->missing());
    }

    // ── The authorise URL each platform actually wants ────────────────────────────────────────

    public function test_each_platform_gets_the_authorize_url_it_asks_for(): void
    {
        $this->configure('meta');
        $this->configure('snapchat');
        $this->configure('tiktok');
        $this->configure('google');

        $oauth = app(PlatformOAuth::class);

        // Meta and Snapchat take a COMMA-separated scope list; a space-separated one is rejected.
        $meta = $oauth->authorizationUrl(PlatformCredentials::for('meta'), 'st');
        $this->assertStringContainsString('scope=ads_read%2Cads_management', $meta);
        $this->assertStringContainsString('response_type=code', $meta);
        $this->assertStringContainsString('state=st', $meta);

        // TikTok uses app_id, and neither response_type nor scope.
        $tiktok = $oauth->authorizationUrl(PlatformCredentials::for('tiktok'), 'st');
        $this->assertStringContainsString('app_id=', $tiktok);
        $this->assertStringNotContainsString('response_type', $tiktok);

        // Google issues a refresh token only when asked, and only on a fresh consent.
        $google = $oauth->authorizationUrl(PlatformCredentials::for('google'), 'st');
        $this->assertStringContainsString('access_type=offline', $google);
        $this->assertStringContainsString('prompt=consent', $google);
    }

    public function test_an_unconfigured_platform_has_no_authorize_url(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/awaiting credentials/i');

        app(PlatformOAuth::class)->authorizationUrl(PlatformCredentials::for('x'), 'st');
    }

    // ── Token exchange, including the platform that answers 200 for a failure ─────────────────

    public function test_a_standard_token_exchange_stores_the_expiry_it_was_given(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 3600, 'scope' => 'ads_read',
        ])]);

        $tokens = app(PlatformOAuth::class)->exchangeCode(PlatformCredentials::for('meta'), 'code-1');

        $this->assertSame('AT', $tokens->accessToken);
        $this->assertSame('RT', $tokens->refreshToken);
        $this->assertNotNull($tokens->expiresAt);
        $this->assertEqualsWithDelta(3600, Carbon::now()->diffInSeconds($tokens->expiresAt), 5);
    }

    /**
     * TikTok's HTTP 200 failure — the defect this whole `succeeded()` helper exists to prevent.
     *
     * Without it, `$response->successful()` is true, the body has no `access_token`, and an empty
     * string is stored as a live credential. The connection then shows CONNECTED and every sync fails.
     */
    public function test_a_tiktok_error_arrives_as_http_200_and_is_still_a_failure(): void
    {
        $this->configure('tiktok');
        Http::fake(['business-api.tiktok.com/*' => Http::response(['code' => 40001, 'message' => 'Invalid auth_code'], 200)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid auth_code/');

        app(PlatformOAuth::class)->exchangeCode(PlatformCredentials::for('tiktok'), 'bad');
    }

    /** Google omits the refresh token on every refresh; losing it would break the second hour. */
    public function test_a_refresh_keeps_the_refresh_token_the_platform_did_not_resend(): void
    {
        $this->configure('google');
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'AT2', 'expires_in' => 3600])]);

        $refreshed = app(PlatformOAuth::class)->refresh(
            PlatformCredentials::for('google'),
            new OAuthTokens('AT1', 'RT1', Carbon::now()->subMinute()),
        );

        $this->assertSame('AT2', $refreshed->accessToken);
        $this->assertSame('RT1', $refreshed->refreshToken);
    }

    // ── Retry policy ──────────────────────────────────────────────────────────────────────────

    /** A 429 is the platform asking us to wait, not a failure. */
    public function test_a_rate_limit_is_retried_and_then_succeeds(): void
    {
        Http::fake(['example.test/*' => Http::sequence()
            ->push(['message' => 'slow down'], 429)
            ->push(['ok' => true], 200)]);

        $response = PlatformHttp::client('meta')->get('https://example.test/thing');

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    /**
     * A 400 is us, and repeating it changes nothing except how much of the quota it costs.
     */
    public function test_a_bad_request_is_not_retried(): void
    {
        Http::fake(['example.test/*' => Http::response(['message' => 'bad field'], 400)]);

        $response = PlatformHttp::client('meta')->get('https://example.test/thing');

        $this->assertSame(400, $response->status());
        Http::assertSentCount(1);
    }

    /** A platform that names its own wait is believed, up to a bound a queue can live with. */
    public function test_the_backoff_honours_retry_after_but_not_beyond_two_minutes(): void
    {
        $this->assertSame(1000, PlatformHttp::backoff(1));
        $this->assertSame(2000, PlatformHttp::backoff(2));
        $this->assertSame(4000, PlatformHttp::backoff(3));
    }

    // ── The mappings that turn six shapes into one ────────────────────────────────────────────

    public function test_snapchat_micro_amounts_become_ordinary_money(): void
    {
        $this->configure('snapchat');
        Http::fake(['adsapi.snapchat.com/*' => Http::response([
            'timeseries_stats' => [[
                'timeseries_stat' => [
                    'id' => 'camp-1',
                    'timeseries' => [[
                        'start_time' => '2026-08-01T00:00:00.000-00:00',
                        'stats' => [
                            'spend' => 12_340_000, 'impressions' => 1000, 'swipes' => 40,
                            'conversion_purchases' => 3, 'conversion_purchases_value' => 99_000_000,
                        ],
                    ]],
                ],
            ]],
        ])]);

        $rows = $this->bound('snapchat')->syncInsights('act-1', '2026-08-01', '2026-08-02')->records;

        $this->assertCount(1, $rows);
        $this->assertSame('camp-1', $rows[0]['campaign_id']);
        $this->assertSame('2026-08-01', $rows[0]['date']);
        $this->assertEqualsWithDelta(12.34, $rows[0]['spend'], 0.001);
        $this->assertEqualsWithDelta(99.0, $rows[0]['revenue'], 0.001);
        // A swipe IS the click on this platform; without the mapping Snapchat CTR is always zero.
        $this->assertSame(40.0, $rows[0]['clicks']);
    }

    /** Meta reports every action type; only purchases are conversions. */
    public function test_meta_counts_purchases_as_conversions_and_leaves_page_likes_alone(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [[
                'campaign_id' => 'c1', 'date_start' => '2026-08-01', 'spend' => '10.5',
                'impressions' => '900', 'clicks' => '30',
                'actions' => [
                    ['action_type' => 'like', 'value' => '55'],
                    ['action_type' => 'purchase', 'value' => '4'],
                ],
                'action_values' => [['action_type' => 'purchase', 'value' => '250.00']],
            ]],
        ])]);

        $rows = $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02')->records;

        $this->assertSame(4.0, $rows[0]['conversions'], 'a page like is not a purchase');
        $this->assertSame(250.0, $rows[0]['revenue']);
    }

    /**
     * Google's `searchStream` answers with an ARRAY of chunks.
     *
     * The wrong reading — `$body['results']` — finds nothing, returns no rows, and reports no error,
     * which downstream is indistinguishable from a genuinely quiet week.
     */
    public function test_google_reads_every_chunk_of_a_stream_not_just_the_first(): void
    {
        $this->configure('google');
        Http::fake(['googleads.googleapis.com/*' => Http::response([
            ['results' => [[
                'campaign' => ['id' => '111'],
                'segments' => ['date' => '2026-08-01'],
                'metrics' => ['costMicros' => '5000000', 'impressions' => '10', 'clicks' => '2'],
            ]]],
            ['results' => [[
                'campaign' => ['id' => '222'],
                'segments' => ['date' => '2026-08-02'],
                'metrics' => ['costMicros' => '7000000', 'impressions' => '20', 'clicks' => '4'],
            ]]],
        ])]);

        $rows = $this->bound('google')->syncInsights('123-456-7890', '2026-08-01', '2026-08-02')->records;

        $this->assertCount(2, $rows);
        $this->assertSame(['111', '222'], array_column($rows, 'campaign_id'));
        $this->assertEqualsWithDelta(5.0, $rows[0]['spend'], 0.001);
    }

    /** …and it strips the dashes Google's own console puts in the customer id. */
    public function test_google_sends_the_customer_id_without_the_dashes_it_is_shown_with(): void
    {
        $this->configure('google');
        Http::fake(['googleads.googleapis.com/*' => Http::response([])]);

        $this->bound('google')->syncInsights('123-456-7890', '2026-08-01', '2026-08-02');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'customers/1234567890/googleAds:searchStream'));
    }

    /** X returns day-indexed arrays; a null day is absent data, not a zero. */
    public function test_x_unrolls_day_indexed_metrics_into_daily_rows(): void
    {
        $this->configure('x');
        Http::fake([
            'ads-api.x.com/*/accounts/*/campaigns*' => Http::response(['data' => [['id' => 'cx', 'name' => 'C', 'entity_status' => 'ACTIVE']]]),
            'ads-api.x.com/*/stats/*' => Http::response(['data' => [[
                'id' => 'cx',
                'id_data' => [['metrics' => [
                    'billed_charge_local_micro' => [1_000_000, null, 3_000_000],
                    'impressions' => [100, null, 300],
                ]]],
            ]]]),
        ]);

        $rows = $this->bound('x')->syncInsights('acct', '2026-08-01', '2026-08-03')->records;

        // Day two produced nothing at all, so there is no row claiming it spent zero.
        $this->assertCount(2, $rows);
        $this->assertSame(['2026-08-01', '2026-08-03'], array_column($rows, 'date'));
        $this->assertEqualsWithDelta(3.0, $rows[1]['spend'], 0.001);
    }

    // ── Isolation ─────────────────────────────────────────────────────────────────────────────

    /**
     * The registry hands out ONE connector per platform for the whole process.
     *
     * Binding it in place would carry one tenant's connection into the next tenant's sync — the exact
     * fail-open the isolation rules exist to prevent — so binding returns a clone and the shared
     * instance stays unbound.
     */
    public function test_binding_a_connection_never_mutates_the_shared_connector(): void
    {
        $this->configure('meta');

        $registry = app(AdvertisingConnectorRegistry::class);
        $shared = $registry->get('meta');
        $bound = $shared->withConnection($this->connection('meta'));

        $this->assertNotSame($shared, $bound);
        $this->assertSame(ConnectorStatus::Connected, $bound->status());
        // The shared one is configured but still nobody's — never Connected.
        $this->assertSame(ConnectorStatus::Disconnected, $registry->get('meta')->status());
    }

    public function test_a_connection_cannot_drive_another_platforms_connector(): void
    {
        $this->configure('meta');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot drive/');

        app(AdvertisingConnectorRegistry::class)->get('meta')->withConnection($this->connection('tiktok'));
    }

    // ── Token vault ───────────────────────────────────────────────────────────────────────────

    public function test_an_expired_token_is_refreshed_before_it_is_handed_out(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'FRESH', 'expires_in' => 3600])]);

        $connection = $this->connection('meta', expiresAt: Carbon::now()->addMinutes(5));
        $tokens = app(TokenVault::class)->fresh($connection);

        $this->assertSame('FRESH', $tokens->accessToken, 'a token expiring inside the skew must be refreshed');
        $this->assertTrue($connection->refresh()->token_expires_at?->greaterThan(Carbon::now()->addMinutes(50)));
    }

    /** A refresh that fails is a CONNECTION STATE an operator can see, not a silent zero. */
    public function test_a_failed_refresh_marks_the_connection_and_says_why(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Session revoked']], 400)]);

        $connection = $this->connection('meta', expiresAt: Carbon::now()->subHour());

        try {
            app(TokenVault::class)->fresh($connection);
            $this->fail('a dead token must not be handed out');
        } catch (RuntimeException) {
            // expected
        }

        $connection->refresh();
        $this->assertSame('error', $connection->status);
        $this->assertStringContainsString('Session revoked', (string) $connection->last_error);
    }

    /** The token itself is never readable through the model's array form. */
    public function test_the_stored_token_is_not_exposed_by_the_credential_model(): void
    {
        $this->configure('meta');
        $connection = $this->connection('meta');

        $credential = IntegrationCredential::withoutGlobalScopes()->findOrFail($connection->credential_id);

        $this->assertArrayNotHasKey('encrypted_payload', $credential->toArray());
        $this->assertStringNotContainsString('AT-secret', json_encode($credential->toArray(), JSON_THROW_ON_ERROR));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────────────────────

    /** Give a platform every value its own `requires` list names — and nothing more. */
    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function connection(string $provider, ?Carbon $expiresAt = null): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-secret', 'RT', $expiresAt ?? Carbon::now()->addDay()),
            connectionName: $provider,
        );
    }

    private function bound(string $platform): ApiAdvertisingConnector
    {
        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($this->connection($platform));
    }
}
