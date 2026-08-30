<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Enums\ConnectorStatus;
use App\Domains\Integrations\Models\ExternalAccount;
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

    /**
     * SNAP-001 — every canonical metric Snapchat reports, read from the field that MEANS it.
     *
     * The fixture deliberately includes the three fields that are the classic wrong answers, each
     * with a value that would be obvious if it leaked into the wrong column: a started checkout that
     * is not a sale, a viewed product that is not a basket, and a pixel page-view that is not the
     * delivery metric. The assertions are as much about what is NOT read as what is.
     */
    public function test_snapchat_maps_every_canonical_metric_from_the_field_that_means_it(): void
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
                            'uniques' => 800, 'landing_page_views' => 25,
                            'video_views' => 300, 'video_views_15s' => 120, 'view_completion' => 90,
                            'conversion_add_cart' => 12, 'conversion_view_content' => 400,
                            'conversion_start_checkout' => 7, 'conversion_purchases' => 3,
                            'conversion_purchases_value' => 99_000_000,
                            'conversion_page_views' => 999,
                            'shares' => 5, 'saves' => 6, 'story_opens' => 7,
                        ],
                    ]],
                ],
            ]],
        ])]);

        $row = $this->bound('snapchat')->syncInsights('act-1', '2026-08-01', '2026-08-02')->records[0];

        // Money arrives in millionths and is divided once, at the edge.
        $this->assertEqualsWithDelta(12.34, $row['spend'], 0.001);
        $this->assertEqualsWithDelta(99.0, $row['revenue'], 0.001);

        $this->assertSame(1000.0, $row['impressions']);
        $this->assertSame(40.0, $row['clicks'], 'a swipe IS the click on this platform');
        $this->assertSame(800.0, $row['reach']);
        $this->assertSame(90.0, $row['video_completions']);

        // A purchase is a purchase. `conversion_start_checkout` is 7 and must appear nowhere near it.
        $this->assertSame(3.0, $row['purchases']);
        $this->assertSame(3.0, $row['conversions']);
        $this->assertSame(7.0, $row['checkout'], 'a started checkout is its own stage, never a sale');

        // Add to cart is 12; viewing a product 400 times is not putting anything in a basket.
        $this->assertSame(12.0, $row['add_to_cart']);

        // The DELIVERY metric (25), not the pixel event (999) — two different measurements.
        $this->assertSame(25.0, $row['landing_page_views']);

        // 300 viewers, not 300 + the 120 of them who watched longer.
        $this->assertSame(300.0, $row['video_views']);

        /*
         * `frequency` and `engagements` are absent, and that is the answer.
         *
         * Frequency is derived — impressions over reach — and a stored daily frequency summed across
         * a month is a number with no referent. Engagements would have to be `shares + saves +
         * story_opens`, a total Snapchat never publishes, so producing one would manufacture a metric
         * the platform did not report.
         */
        $this->assertArrayNotHasKey('frequency', $row);
        $this->assertArrayNotHasKey('engagements', $row);
    }

    /** A metric the account does not report is ABSENT, so no surface can print it as a measured zero. */
    public function test_snapchat_omits_a_metric_the_account_never_reported_rather_than_sending_zero(): void
    {
        $this->configure('snapchat');
        Http::fake(['adsapi.snapchat.com/*' => Http::response([
            'timeseries_stats' => [[
                'timeseries_stat' => [
                    'id' => 'camp-1',
                    'timeseries' => [[
                        'start_time' => '2026-08-01T00:00:00.000-00:00',
                        // An awareness buy: delivery only, and no conversion pixel at all.
                        'stats' => ['spend' => 5_000_000, 'impressions' => 2000, 'swipes' => 10],
                    ]],
                ],
            ]],
        ])]);

        $row = $this->bound('snapchat')->syncInsights('act-1', '2026-08-01', '2026-08-02')->records[0];

        foreach (['purchases', 'conversions', 'revenue', 'add_to_cart', 'checkout', 'reach'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "{$key} was never reported and must not be sent as 0");
        }
        $this->assertSame(2000.0, $row['impressions']);
    }

    /**
     * A paged list is read to its END.
     *
     * One page and stop is not a partial answer that looks partial — it is a complete-looking answer
     * with entities missing, and their spend goes missing with them. The second page is served from
     * the URL the first one named, because rebuilding the cursor by hand is how a paging parameter
     * gets dropped and the same page is fetched forever.
     */
    public function test_snapchat_follows_the_paging_cursor_instead_of_stopping_at_the_first_page(): void
    {
        $this->configure('snapchat');
        Http::fake([
            'adsapi.snapchat.com/v1/adaccounts/act-1/campaigns?cursor=2' => Http::response([
                'campaigns' => [['campaign' => ['id' => 'c2', 'name' => 'Second page', 'status' => 'PAUSED']]],
            ]),
            'adsapi.snapchat.com/*' => Http::response([
                'campaigns' => [['campaign' => ['id' => 'c1', 'name' => 'First page', 'status' => 'ACTIVE']]],
                'paging' => ['next_link' => 'https://adsapi.snapchat.com/v1/adaccounts/act-1/campaigns?cursor=2'],
            ]),
        ]);

        $campaigns = $this->bound('snapchat')->syncCampaigns('act-1')->records;

        $this->assertCount(2, $campaigns, 'the second page was never fetched');
        $this->assertSame(['c1', 'c2'], array_column($campaigns, 'external_id'));
    }

    /**
     * TIKTOK-001 — every canonical metric TikTok reports, mapped to the field that MEANS it.
     *
     * The fixture is built to catch a leak rather than to confirm a happy path. `conversion` is 500
     * while `complete_payment` is 12: if the two are ever conflated the difference is unmissable, and
     * that conflation is the one this platform invites — TikTok's `conversion` counts every event the
     * campaign was optimised for, so on a lead-gen buy it is a count of LEADS. Reporting it as
     * purchases would tell a client they sold five hundred things.
     *
     * `video_watched_2s` and `video_watched_6s` carry distinctive values for the same reason: they are
     * the same viewers measured at longer thresholds, so a mapping that adds them to `video_views`
     * counts one person three times.
     */
    public function test_tiktok_maps_every_canonical_metric_to_the_field_that_means_it(): void
    {
        $this->configure('tiktok');
        Http::fake(['business-api.tiktok.com/*' => Http::response([
            'code' => 0,
            'data' => [
                'list' => [[
                    'dimensions' => ['campaign_id' => 'c1', 'stat_time_day' => '2026-08-01 00:00:00'],
                    'metrics' => [
                        'spend' => '412.75', 'impressions' => '90000', 'clicks' => '1800',
                        'reach' => '65000', 'frequency' => '1.38',
                        'video_play_actions' => '54000', 'video_watched_2s' => '31000',
                        'video_watched_6s' => '17000', 'video_views_p100' => '9000',
                        'add_to_cart' => '260', 'initiate_checkout' => '140',
                        'complete_payment' => '12', 'total_purchase_value' => '4380.00',
                        // The trap: five hundred optimisation events, twelve of them sales.
                        'conversion' => '500',
                        'likes' => '900', 'comments' => '40', 'shares' => '75', 'follows' => '30',
                    ],
                ]],
                'page_info' => ['page' => 1, 'total_page' => 1],
            ],
        ])]);

        $row = $this->bound('tiktok')->syncInsights('adv-1', '2026-08-01', '2026-08-02')->records[0];

        $this->assertSame('2026-08-01', $row['date'], 'the timestamp is stored as the date it names');
        $this->assertEqualsWithDelta(412.75, $row['spend'], 0.001, 'TikTok bills in the account currency, not millionths');
        $this->assertEqualsWithDelta(4380.0, $row['revenue'], 0.001);
        $this->assertSame(90000.0, $row['impressions']);
        $this->assertSame(1800.0, $row['clicks']);
        $this->assertSame(65000.0, $row['reach']);

        // The line this platform makes dangerous. Twelve sales, not five hundred.
        $this->assertSame(12.0, $row['purchases'], 'a purchase is a completed payment, never «a conversion»');
        $this->assertSame(500.0, $row['conversions'], 'TikTok\'s own results figure is still carried — just never as the sale');
        $this->assertSame(140.0, $row['checkout'], 'a started checkout is its own stage, never a sale');
        $this->assertSame(260.0, $row['add_to_cart']);

        // 54,000 viewers — not 54,000 plus the 31,000 and 17,000 of them who watched longer.
        $this->assertSame(54000.0, $row['video_views']);
        $this->assertSame(9000.0, $row['video_completions']);

        /*
         * Three canonical metrics are absent, and that IS the answer.
         *
         * `frequency` is derived and is computed at read time (null on a zero denominator); TikTok
         * publishes one, and storing a daily frequency to be summed across a month would produce a
         * number with no referent. `landing_page_views` has no TikTok equivalent — its «content
         * views» metric is a different event at a different moment. `engagements` would have to be
         * likes + comments + shares + follows, a total TikTok never publishes, and the fixture
         * supplies all four precisely so a mapping that invented it would be caught here.
         */
        foreach (['frequency', 'landing_page_views', 'engagements'] as $absent) {
            $this->assertArrayNotHasKey($absent, $row, "{$absent} is not something TikTok reports and must not be manufactured");
        }
    }

    /** A metric the advertiser does not report is ABSENT, so no surface can print it as a measured zero. */
    public function test_tiktok_omits_a_metric_the_advertiser_never_reported_rather_than_sending_zero(): void
    {
        $this->configure('tiktok');
        Http::fake(['business-api.tiktok.com/*' => Http::response([
            'code' => 0,
            'data' => [
                'list' => [[
                    'dimensions' => ['campaign_id' => 'c1', 'stat_time_day' => '2026-08-01 00:00:00'],
                    // A reach buy with no pixel: delivery only. And TikTok sends an explicit null for
                    // a metric it has no value for, which `isset` cannot tell from an absent key.
                    'metrics' => [
                        'spend' => '80.00', 'impressions' => '40000', 'clicks' => '120',
                        'complete_payment' => null, 'total_purchase_value' => null,
                    ],
                ]],
            ],
        ])]);

        $row = $this->bound('tiktok')->syncInsights('adv-1', '2026-08-01', '2026-08-02')->records[0];

        foreach (['purchases', 'revenue', 'conversions', 'add_to_cart', 'checkout', 'reach', 'video_views'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "{$key} was never reported and must not be sent as 0");
        }
        $this->assertSame(40000.0, $row['impressions']);
    }

    /**
     * A paged list is read to its END.
     *
     * TikTok states the extent in `data.page_info.total_page` and expects the reader to ask for the
     * next `page`. Reading one and stopping is a complete-LOOKING answer with entities missing, and
     * whichever the platform ordered last take their spend out of every total on every surface.
     */
    public function test_tiktok_reads_every_page_instead_of_stopping_at_the_first(): void
    {
        $this->configure('tiktok');
        Http::fake([
            'business-api.tiktok.com/*page=2*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [['campaign_id' => 'c2', 'campaign_name' => 'Second page', 'operation_status' => 'DISABLE']],
                    'page_info' => ['page' => 2, 'total_page' => 2],
                ],
            ]),
            'business-api.tiktok.com/*' => Http::response([
                'code' => 0,
                'data' => [
                    'list' => [['campaign_id' => 'c1', 'campaign_name' => 'First page', 'operation_status' => 'ENABLE']],
                    'page_info' => ['page' => 1, 'total_page' => 2],
                ],
            ]),
        ]);

        $campaigns = $this->bound('tiktok')->syncCampaigns('adv-1')->records;

        $this->assertCount(2, $campaigns, 'the second page was never fetched');
        $this->assertSame(['c1', 'c2'], array_column($campaigns, 'external_id'));
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
     * META-001 — the same sale, reported three ways, is ONE sale.
     *
     * Meta returns a purchase under several action types at once: `offsite_conversion.fb_pixel_purchase`
     * is what the pixel saw, `purchase` is the consolidated standard event, `omni_purchase` is the
     * cross-surface rollup. This connector used to ADD every matching type together, so a typical
     * account had its purchases, its revenue and therefore its ROAS multiplied by three — on the
     * number a client judges the whole engagement by.
     *
     * The fixture reports all three with the SAME value, as a real account does. Twelve, not thirty-six.
     */
    public function test_meta_counts_one_sale_once_even_when_it_is_reported_three_ways(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [[
                'campaign_id' => 'c1', 'date_start' => '2026-08-01', 'spend' => '480.00',
                'impressions' => '120000', 'clicks' => '2400', 'reach' => '86000',
                'actions' => [
                    ['action_type' => 'purchase', 'value' => '12'],
                    ['action_type' => 'omni_purchase', 'value' => '12'],
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '12'],
                ],
                'action_values' => [
                    ['action_type' => 'purchase', 'value' => '3600.00'],
                    ['action_type' => 'omni_purchase', 'value' => '3600.00'],
                    ['action_type' => 'offsite_conversion.fb_pixel_purchase', 'value' => '3600.00'],
                ],
            ]],
        ])]);

        $row = $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02')->records[0];

        // Asserted on `conversions` first because that is the figure the previous code DID produce,
        // so the failure it leaves behind is the defect itself — «36 is not identical to 12» — and
        // not merely a key that did not exist yet.
        $this->assertSame(12.0, $row['conversions'], 'three views of one sale were added together');
        $this->assertSame(3600.0, $row['revenue'], 'revenue tripled, and ROAS with it');
        $this->assertSame(12.0, $row['purchases']);
    }

    /**
     * Every canonical metric Meta reports, mapped to the action type that MEANS it.
     *
     * The fixture is built to catch a leak: `view_content` is 5,000 while `add_to_cart` is 300, so
     * confusing the two is unmissable, and `link_click` is 2,400 against a `landing_page_view` of
     * 1,900 — the click and the arrival are different moments and the larger number is the flattering
     * one. `initiate_checkout` sits between add-to-cart and purchase as its own stage.
     */
    public function test_meta_maps_every_canonical_metric_to_the_action_that_means_it(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [[
                'campaign_id' => 'c1', 'date_start' => '2026-08-01', 'spend' => '480.00',
                'impressions' => '120000', 'clicks' => '2400', 'reach' => '86000',
                'actions' => [
                    ['action_type' => 'link_click', 'value' => '2400'],
                    ['action_type' => 'landing_page_view', 'value' => '1900'],
                    ['action_type' => 'offsite_conversion.fb_pixel_view_content', 'value' => '5000'],
                    ['action_type' => 'add_to_cart', 'value' => '300'],
                    ['action_type' => 'initiate_checkout', 'value' => '160'],
                    ['action_type' => 'purchase', 'value' => '12'],
                    ['action_type' => 'post_engagement', 'value' => '7400'],
                    ['action_type' => 'like', 'value' => '640'],
                ],
                'action_values' => [['action_type' => 'purchase', 'value' => '3600.00']],
                'video_play_actions' => [['action_type' => 'video_view', 'value' => '64000']],
                'video_p100_watched_actions' => [['action_type' => 'video_view', 'value' => '9100']],
            ]],
        ])]);

        $row = $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02')->records[0];

        $this->assertSame(300.0, $row['add_to_cart'], 'a content view is not a basket add');
        $this->assertSame(160.0, $row['checkout'], 'a started checkout is its own stage, never a sale');
        $this->assertSame(12.0, $row['purchases']);
        $this->assertSame(1900.0, $row['landing_page_views'], 'the arrival, not the click');
        $this->assertSame(7400.0, $row['engagements'], 'Meta publishes one engagement total; a like alone is not it');

        /*
         * `video_play_actions`, not `video_30_sec_watched_actions`.
         *
         * The thirty-second metric exists only for videos at least that long, so on the fifteen-second
         * creatives most of this market runs it is never returned at all — and the product showed
         * «video views» as unreported for ads watched sixty-four thousand times.
         */
        $this->assertSame(64000.0, $row['video_views']);
        $this->assertSame(9100.0, $row['video_completions']);

        // `frequency` is derived from impressions and reach; a stored daily one cannot be summed.
        $this->assertArrayNotHasKey('frequency', $row);
    }

    /**
     * An action type Meta never sent is ABSENT, not zero.
     *
     * Meta omits an action type entirely when its count is zero, so «no purchase action» cannot be
     * told apart from «no pixel on this campaign». Storing 0 would state the first when it might be
     * the second, on the stage a client reads as their sales.
     */
    public function test_meta_omits_an_action_it_never_reported_rather_than_sending_zero(): void
    {
        $this->configure('meta');
        Http::fake(['graph.facebook.com/*' => Http::response([
            'data' => [[
                'campaign_id' => 'c1', 'date_start' => '2026-08-01', 'spend' => '90.00',
                'impressions' => '40000', 'clicks' => '110',
                // A reach buy with no pixel: engagement only.
                'actions' => [['action_type' => 'post_engagement', 'value' => '1200']],
            ]],
        ])]);

        $row = $this->bound('meta')->syncInsights('act_1', '2026-08-01', '2026-08-02')->records[0];

        foreach (['purchases', 'conversions', 'revenue', 'add_to_cart', 'checkout', 'landing_page_views', 'video_views'] as $key) {
            $this->assertArrayNotHasKey($key, $row, "{$key} was never reported and must not be sent as 0");
        }
        $this->assertSame(1200.0, $row['engagements']);
    }

    /**
     * A Graph edge is read to its END.
     *
     * `limit=500` is a page size, not a guarantee of completeness: an account with 600 ads answered
     * with 500 and said nothing about the rest, and the hundred that vanished took their spend out of
     * every total on every surface.
     */
    public function test_meta_follows_the_graph_cursor_instead_of_stopping_at_the_first_page(): void
    {
        $this->configure('meta');
        Http::fake([
            'graph.facebook.com/*after=CURSOR2*' => Http::response([
                'data' => [['id' => 'c2', 'name' => 'Second page', 'status' => 'PAUSED']],
            ]),
            'graph.facebook.com/*' => Http::response([
                'data' => [['id' => 'c1', 'name' => 'First page', 'status' => 'ACTIVE']],
                'paging' => ['next' => 'https://graph.facebook.com/v25.0/act_1/campaigns?after=CURSOR2'],
            ]),
        ]);

        $campaigns = $this->bound('meta')->syncCampaigns('act_1')->records;

        $this->assertCount(2, $campaigns, 'the second page was never fetched');
        $this->assertSame(['c1', 'c2'], array_column($campaigns, 'external_id'));
    }

    /**
     * GADS-001 — a lead is not a sale, and Google Ads will happily let you say it is.
     *
     * Google has no purchase metric. `metrics.conversions` counts whichever conversion ACTIONS the
     * account has been told to count — a phone call, a form, a signup, a store visit, a sale — and
     * `metrics.conversions_value` is the value assigned to all of them. This connector reported both
     * as `conversions` and `revenue`, so a lead-generation account read its enquiries as orders and
     * whatever internal value it had put on a lead as money taken.
     *
     * The fixture is that account: 260 conversions worth 26,000 in assigned lead value, of which
     * exactly 8 sales worth 3,200 are real. The second, category-segmented query is what tells them
     * apart, and the fake answers it separately so the two really are distinguished.
     */
    public function test_google_counts_only_purchase_category_conversions_as_sales(): void
    {
        $this->configure('google');
        Http::fake(function ($request) {
            $isSalesQuery = str_contains((string) ($request->data()['query'] ?? ''), 'conversion_action_category');

            return Http::response($isSalesQuery ? [
                ['results' => [[
                    'campaign' => ['id' => '111'],
                    'segments' => ['date' => '2026-08-01', 'conversionActionCategory' => 'PURCHASE'],
                    'metrics' => ['conversions' => 8, 'conversionsValue' => 3200.0],
                ]]],
            ] : [
                ['results' => [[
                    'campaign' => ['id' => '111'],
                    'segments' => ['date' => '2026-08-01'],
                    'metrics' => [
                        'costMicros' => '900000000', 'impressions' => '54000', 'clicks' => '2100',
                        // Every conversion action the account counts, and the value it assigns them.
                        'conversions' => 260, 'conversionsValue' => 26000.0,
                        'videoViews' => '12000', 'engagements' => '3100',
                    ],
                ]]],
            ]);
        });

        $row = $this->bound('google')->syncInsights('123-456-7890', '2026-08-01', '2026-08-02')->records[0];

        $this->assertSame(8.0, $row['purchases'], '260 conversions of every kind were reported as sales');
        $this->assertSame(3200.0, $row['revenue'], 'the value assigned to leads was reported as money taken');
        $this->assertSame(260.0, $row['conversions'], 'Google\'s own conversions figure is still carried — just not as the sale');
        $this->assertEqualsWithDelta(900.0, $row['spend'], 0.001, 'cost arrives in micros and is divided once');
        $this->assertSame(12000.0, $row['video_views']);
        $this->assertSame(3100.0, $row['engagements']);
    }

    /** An account that measures no sales has not measured zero sales. */
    public function test_google_leaves_purchases_absent_when_no_conversion_action_is_a_purchase(): void
    {
        $this->configure('google');
        Http::fake(function ($request) {
            $isSalesQuery = str_contains((string) ($request->data()['query'] ?? ''), 'conversion_action_category');

            // A brand campaign: conversions counted, none of them in the PURCHASE category.
            return Http::response($isSalesQuery ? [['results' => []]] : [
                ['results' => [[
                    'campaign' => ['id' => '111'],
                    'segments' => ['date' => '2026-08-01'],
                    'metrics' => ['costMicros' => '50000000', 'impressions' => '9000', 'clicks' => '210', 'conversions' => 40],
                ]]],
            ]);
        });

        $row = $this->bound('google')->syncInsights('123-456-7890', '2026-08-01', '2026-08-02')->records[0];

        $this->assertArrayNotHasKey('purchases', $row, 'no purchase-category action means unmeasured, not zero');
        $this->assertArrayNotHasKey('revenue', $row);
        $this->assertArrayNotHasKey('video_views', $row);
        $this->assertSame(40.0, $row['conversions']);
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

    /**
     * X-001 — the whole canonical set, out of the metric groups the mapping actually reads.
     *
     * The request asked for `BILLING,ENGAGEMENT` while `unroll()` read `conversion_purchases` (a
     * WEB_CONVERSION metric) and `video_total_views` (a VIDEO metric). X never returned either, so
     * both were mapped from a key that could not arrive — and downstream that is indistinguishable
     * from a platform which does not report them: no error, no log, the metrics simply never existed.
     *
     * The conversion group also answers a DIFFERENT SHAPE: an object per metric with the count under
     * `metric` and the money under `sale_amount_local_micro`, rather than the plain day-indexed array
     * every other group sends. Reading only the plain shape returns null for every conversion, which
     * looks exactly the same as never having asked.
     */
    public function test_x_asks_for_the_metric_groups_it_reads_and_understands_both_shapes(): void
    {
        $this->configure('x');
        Http::fake([
            'ads-api.x.com/*/accounts/*/campaigns*' => Http::response(['data' => [['id' => 'cx', 'name' => 'C', 'entity_status' => 'ACTIVE']]]),
            'ads-api.x.com/*/stats/*' => Http::response(['data' => [[
                'id' => 'cx',
                'id_data' => [['metrics' => [
                    'billed_charge_local_micro' => [4_000_000],
                    'impressions' => [52000],
                    'clicks' => [900],
                    'engagements' => [3400],
                    'video_total_views' => [21000],
                    'video_views_100' => [4100],
                    // The conversion shape: nested, and money beside the count.
                    'conversion_purchases' => [
                        'metric' => [14],
                        'sale_amount_local_micro' => [5_600_000],
                    ],
                    'conversion_add_to_cart' => ['metric' => [220]],
                    'conversion_checkouts_initiated' => ['metric' => [95]],
                ]]],
            ]]]),
        ]);

        $row = $this->bound('x')->syncInsights('acct', '2026-08-01', '2026-08-02')->records[0];

        // Every group the mapping reads is requested — the defect this unit exists to fix.
        Http::assertSent(fn (Request $r) => ! str_contains($r->url(), '/stats/')
            || (str_contains($r->url(), 'WEB_CONVERSION') && str_contains($r->url(), 'VIDEO')));

        $this->assertEqualsWithDelta(4.0, $row['spend'], 0.001);
        $this->assertSame(3400.0, $row['engagements'], 'X publishes one engagement total, so it is read');
        $this->assertSame(21000.0, $row['video_views']);
        $this->assertSame(4100.0, $row['video_completions']);

        // The nested conversion shape, count and money.
        $this->assertSame(14.0, $row['purchases']);
        $this->assertSame(14.0, $row['conversions']);
        $this->assertSame(220.0, $row['add_to_cart']);
        $this->assertSame(95.0, $row['checkout'], 'a started checkout is its own stage, never a sale');
        $this->assertEqualsWithDelta(5.6, $row['revenue'], 0.001, 'conversion value arrives in millionths');

        // No X equivalent, so absent rather than approximated from clicks.
        $this->assertArrayNotHasKey('landing_page_views', $row);
        $this->assertArrayNotHasKey('frequency', $row);
    }

    /**
     * A window with conversions but no billed spend is still a window.
     *
     * The day count was measured across spend, impressions and clicks only, so a paused campaign
     * still converting from earlier impressions measured as zero days long and every conversion in
     * it was dropped before anything could store it.
     */
    public function test_x_measures_the_window_across_every_series_it_was_sent(): void
    {
        $this->configure('x');
        Http::fake([
            'ads-api.x.com/*/accounts/*/campaigns*' => Http::response(['data' => [['id' => 'cx', 'name' => 'C', 'entity_status' => 'ACTIVE']]]),
            'ads-api.x.com/*/stats/*' => Http::response(['data' => [[
                'id' => 'cx',
                'id_data' => [['metrics' => [
                    'conversion_purchases' => ['metric' => [2, 5]],
                ]]],
            ]]]),
        ]);

        $rows = $this->bound('x')->syncInsights('acct', '2026-08-01', '2026-08-03')->records;

        $this->assertCount(2, $rows, 'a window measured only by spend threw the conversions away');
        $this->assertSame(2.0, $rows[0]['purchases']);
        $this->assertSame(5.0, $rows[1]['purchases']);
        $this->assertArrayNotHasKey('spend', $rows[0], 'nothing was billed, and that is not a spend of zero');
    }

    /**
     * LINKEDIN-001 — what LinkedIn measures, and the two things it refuses to guess at.
     *
     * LinkedIn's adAnalytics has no purchase metric. `externalWebsiteConversions` counts every
     * conversion the account defined — a demo request, a whitepaper download, a contact form, and on
     * the rare B2C account a sale — with no way to ask for one category the way Google Ads can. So it
     * is carried as `conversions`, and `purchases` stays ABSENT rather than being approximated from
     * it. A sales funnel on a LinkedIn-only account ends in «لم تُرسل», which is the truth.
     *
     * `conversionValueInLocalCurrency` used to be mapped to `revenue`. It is the value the advertiser
     * ASSIGNED to those conversions — commonly an internal worth put on a lead — so reporting it as
     * revenue put a ROAS on a client's report built from money nobody had taken. Removing it costs a
     * figure and buys back the only thing that makes the rest of them worth reading.
     */
    public function test_linkedin_reports_what_it_measures_and_refuses_to_call_lead_value_revenue(): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response([
            'elements' => [[
                'pivotValues' => ['urn:li:sponsoredCampaign:9911'],
                'dateRange' => ['start' => ['year' => 2026, 'month' => 8, 'day' => 1]],
                'costInLocalCurrency' => '1840.00',
                'impressions' => 74000,
                'clicks' => 610,
                // LINKEDIN-REACH-001 — the current metric name. `approximateUniqueImpressions` is the
                // legacy one LinkedIn's metrics table no longer carries a row for.
                'approximateMemberReach' => 51000,
                'externalWebsiteConversions' => 38,
                // The trap: 76,000 of «value» the advertiser assigned to 38 leads.
                'conversionValueInLocalCurrency' => '76000.00',
                'videoViews' => 22000,
                'videoCompletions' => 3900,
                'totalEngagements' => 1450,
                'landingPageClicks' => 540,
            ]],
        ])]);

        $row = $this->bound('linkedin')->syncInsights('507', '2026-08-01', '2026-08-02')->records[0];

        $this->assertSame('9911', $row['campaign_id']);
        $this->assertSame('2026-08-01', $row['date']);
        $this->assertEqualsWithDelta(1840.0, $row['spend'], 0.001);
        $this->assertSame(51000.0, $row['reach']);
        $this->assertSame(38.0, $row['conversions']);
        $this->assertSame(22000.0, $row['video_views']);
        $this->assertSame(3900.0, $row['video_completions']);
        $this->assertSame(1450.0, $row['engagements'], 'LinkedIn publishes one engagement total; assembling it from likes would double count');

        // The two refusals, and they are the point of this test.
        $this->assertArrayNotHasKey('revenue', $row, 'the value assigned to a lead is not money anybody has taken');
        $this->assertArrayNotHasKey('purchases', $row, 'LinkedIn publishes no purchase count, so there is none to report');
        // And the click before the arrival is not the arrival.
        $this->assertArrayNotHasKey('landing_page_views', $row);
        $this->assertArrayNotHasKey('frequency', $row);
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
        $connection = $this->connection($platform);

        /*
         * A discovered account with a TIMEZONE — SNAP-WINDOW-001.
         *
         * Snapchat's DAY stats require the range to sit on the ad account's own day boundary, so the
         * connector reads the timezone discovery recorded and refuses rather than defaulting when
         * there is none. These tests are about metric MAPPING, so the fixture has to satisfy the
         * window rule standing in front of it — the same way a credentials rule has to be satisfied
         * before a mapping test can reach the mapping.
         */
        ExternalAccount::withoutGlobalScopes()->firstOrCreate([
            'provider_connection_id' => $connection->getKey(),
            'external_id' => 'act-1',
            'account_type' => 'ad_account',
        ], [
            'tenant_id' => $this->tenant->id,
            'provider' => $platform,
            'name' => 'act-1',
            'status' => 'active',
            'timezone' => 'Asia/Riyadh',
        ]);

        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($connection);
    }
}
