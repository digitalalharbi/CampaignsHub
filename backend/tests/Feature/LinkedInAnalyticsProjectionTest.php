<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LINKEDIN-PROJECTION-001 — the field list went out as ONE field, and LinkedIn said so.
 *
 * ## The production failure, exactly
 *
 * A real LinkedIn account was connected: OAuth completed, the ad account was discovered, campaigns
 * were discovered. Only Daily Ad Analytics failed, with:
 *
 *     Projected field 'pivotValues%2CdateRange%2CcostInLocalCurrency%2Cimpressions%2Cclicks%2C…'
 *     not present in schema 'com.linkedin.ads.externalapi.reportingapi.v9.AdAnalyticsV9'
 *
 * Read it as LinkedIn read it: the whole string, commas and all, is the name of ONE projected field.
 * `PendingRequest::get($url, $query)` builds the query with `http_build_query`, which percent-encodes
 * every comma — and a Rest.li projection is a comma-separated list whose commas are structure, not
 * data. LinkedIn's own reference sends them literally:
 *
 *     …&fields=externalWebsiteConversions,dateRange,impressions,landingPageClicks,…
 *
 * while the URNs inside `List(…)` ARE percent-encoded in the same sample URL. So this is not «encode
 * nothing» — it is «encode the values, leave the Rest.li structure alone».
 *
 * The same encoding hits `accounts=List(urn:li:sponsoredAccount:…)` and the `dateRange` tuple, which
 * are structure for the same reason.
 *
 * ## And one metric that no longer exists
 *
 * `approximateUniqueImpressions` is not in the metrics table for any version this pin can reach. The
 * table lists `approximateMemberReach` and describes it as «an updated and more accurate version of
 * legacy metric approximateUniqueImpressions … fully launched in Jan 2024». It also states two
 * conditions this connector must respect: non-demographic pivots only (CAMPAIGN qualifies), and a
 * date range of at most 92 days.
 *
 * Audited against LinkedIn's Reporting page and its Metrics Available table for version 202607.
 */
final class LinkedInAnalyticsProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * The exact defect: the outgoing URL must carry literal commas between the projected fields.
     *
     * Asserted on the RAW url rather than on a parsed query, because parsing is what hides this —
     * `parse_str` decodes `%2C` back into a comma and the assertion passes over the bug.
     */
    public function test_the_field_projection_goes_out_with_literal_commas(): void
    {
        $this->analyticsCall();

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();
            if (! str_contains($url, 'adAnalytics')) {
                return false;
            }

            $this->assertStringNotContainsString(
                '%2C',
                $url,
                'LINKEDIN-PROJECTION-001: the commas are encoded, so LinkedIn reads the whole list as one '
                    ."projected field and answers «not present in schema». URL: {$url}",
            );

            $this->assertMatchesRegularExpression(
                '/fields=[A-Za-z]+(,[A-Za-z]+)+/',
                $url,
                'the projection must be a comma-separated list of field names',
            );

            return true;
        });
    }

    /** The Rest.li structures travel the same way: `List(…)` and the date tuple are syntax. */
    public function test_the_account_list_and_date_range_keep_their_restli_syntax(): void
    {
        $this->analyticsCall();

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());
            if (! str_contains($url, 'adAnalytics')) {
                return false;
            }

            $raw = $request->url();
            $this->assertStringContainsString('accounts=List(', $raw, 'List(…) is Rest.li syntax, not a value');
            $this->assertStringContainsString('dateRange=(start:(year:', $raw, 'the date tuple is syntax too');

            return true;
        });
    }

    /**
     * The legacy metric is gone from the request.
     *
     * Not «renamed because a newer name looked right»: the metrics table for this version has no row
     * for `approximateUniqueImpressions` at all, and names `approximateMemberReach` as what replaced
     * it. Asking for a field the schema does not define is what produced the production error in the
     * first place, one field at a time.
     */
    public function test_it_asks_for_the_reach_metric_this_version_defines(): void
    {
        $this->analyticsCall();

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();
            if (! str_contains($url, 'adAnalytics')) {
                return false;
            }

            $this->assertStringNotContainsString('approximateUniqueImpressions', $url);
            $this->assertStringContainsString('approximateMemberReach', $url);

            return true;
        });
    }

    /**
     * Over 92 days the metric is not asked for at all.
     *
     * LinkedIn: «This metric is only available when the number of days in the date range is less than
     * or equal to 92 days». A backfill asks for a longer window, and asking for a field outside the
     * conditions the schema states is the class of request that failed in production. The rest of the
     * projection is unchanged, so a long window still returns spend, impressions and clicks.
     */
    public function test_a_window_longer_than_ninety_two_days_omits_the_reach_metric(): void
    {
        $this->analyticsCall('2026-01-01', '2026-08-01');

        Http::assertSent(function (Request $request): bool {
            $url = $request->url();
            if (! str_contains($url, 'adAnalytics')) {
                return false;
            }

            $this->assertStringNotContainsString('approximateMemberReach', $url);
            $this->assertStringContainsString('impressions', $url);

            return true;
        });
    }

    /** The reach LinkedIn sends under the current name reaches the canonical row. */
    public function test_the_reach_it_returns_is_carried_into_the_row(): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => [[
            'pivotValues' => ['urn:li:sponsoredCampaign:77'],
            'dateRange' => ['start' => ['year' => 2026, 'month' => 8, 'day' => 1]],
            'impressions' => 1200,
            'clicks' => 40,
            'costInLocalCurrency' => '310.50',
            'approximateMemberReach' => 900,
        ]]])]);

        $rows = $this->bound('linkedin')->syncInsights('512', '2026-08-01', '2026-08-01')->records;

        $this->assertCount(1, $rows);
        $this->assertSame(900.0, $rows[0]['reach']);
    }

    /**
     * LINKEDIN-RESULTS-001 — Results follow each campaign's objective, as Campaign Manager reports them.
     *
     * Production (Saudi Surfing Federation, Sep 15 – Oct 14): spend matched to the cent, but «Results»
     * read 0 beside an Engagement campaign's 1,067 engagements and a Website Visits campaign's 960
     * landing-page clicks — because results were always `externalWebsiteConversions`.
     */
    public function test_results_are_the_key_result_of_each_campaigns_objective(): void
    {
        $this->configure('linkedin');
        $row = static fn (string $campaign) => [
            'pivotValues' => ["urn:li:sponsoredCampaign:{$campaign}"],
            'dateRange' => ['start' => ['year' => 2026, 'month' => 10, 'day' => 1]],
            'impressions' => 5000, 'clicks' => 300, 'costInLocalCurrency' => '72.32',
            'totalEngagements' => 1067, 'landingPageClicks' => 960, 'externalWebsiteConversions' => 0,
            'oneClickLeads' => 12, 'videoViews' => 400,
        ];
        Http::fake([
            'api.linkedin.com/*adCampaigns*' => Http::response(['elements' => [
                ['id' => 1, 'name' => 'Engage', 'objectiveType' => 'ENGAGEMENT', 'status' => 'ACTIVE'],
                ['id' => 2, 'name' => 'Visits', 'objectiveType' => 'WEBSITE_VISIT', 'status' => 'ACTIVE'],
                ['id' => 3, 'name' => 'Leads', 'objectiveType' => 'LEAD_GENERATION', 'status' => 'ACTIVE'],
                ['id' => 4, 'name' => 'Sales', 'objectiveType' => 'WEBSITE_CONVERSION', 'status' => 'ACTIVE'],
            ]]),
            'api.linkedin.com/*adAnalytics*' => Http::response(['elements' => [$row('1'), $row('2'), $row('3'), $row('4')]]),
        ]);

        $rows = collect($this->bound('linkedin')->syncInsights('558609374', '2026-10-01', '2026-10-01')->records)->keyBy('campaign_id');

        $this->assertSame(1067.0, $rows['1']['conversions'], 'an Engagement campaign\'s results are its engagements');
        $this->assertSame(960.0, $rows['2']['conversions'], 'a Website Visits campaign\'s results are its landing-page clicks');
        $this->assertSame(12.0, $rows['3']['conversions']);
        $this->assertSame(0.0, $rows['4']['conversions'], 'a conversion campaign keeps the conversions it reported, zero included');
        $this->assertSame(12.0, $rows['1']['leads']);
        $this->assertSame(1067.0, $rows['1']['engagements']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'adAnalytics') && str_contains(urldecode($r->url()), 'oneClickLeads'));
    }

    private function analyticsCall(string $from = '2026-08-01', string $to = '2026-08-02'): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => []])]);

        $this->bound('linkedin')->syncInsights('512', $from, $to);
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function bound(string $platform): ApiAdvertisingConnector
    {
        $connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $platform,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: $platform,
        );

        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($connection);
    }
}
