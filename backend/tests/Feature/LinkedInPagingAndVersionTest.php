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
 * LINKEDIN-PAGE-001 / LINKEDIN-VERSION-001 — audited against LinkedIn's *Pagination* concept page,
 * the *Marketing API Versioning* page and the *Ad Accounts* reference.
 *
 * ## LINKEDIN-PAGE-001 — ten. Every list this connector read stopped at ten
 *
 * `LinkedInConnector` made four calls — `adAccounts`, `adAccounts/{id}/adCampaigns`,
 * `adAccounts/{id}/creatives` and `adAnalytics` — and **not one of them passed `count` or `start`, or
 * looked at `paging`**. It read `$body['elements']` once and returned.
 *
 * LinkedIn's pagination page gives the default `count` as **10**.
 *
 * So an agency saw at most ten ad accounts. Each of those accounts showed at most ten campaigns, ten
 * creatives, and ten rows of analytics. Nothing errored, nothing was logged, and every total on every
 * surface — spend, impressions, the funnel, the client's own report — was short by whatever the
 * eleventh campaign onward did.
 *
 * This is the same defect the Meta connector already had and fixed (`MetaConnector::readAll`), with
 * one difference that matters: Meta's page size was 500, so it needed a genuinely large account to
 * bite. LinkedIn's is ten, which is a small account, and the truncation is silent at both.
 *
 * The end of the dataset is LinkedIn's own documented rule — «You have reached the end of the dataset
 * when your response contains fewer elements … than your count parameter request» — rather than a
 * guess about empty pages.
 *
 * ## LINKEDIN-VERSION-001 — pinned to a version LinkedIn retired before the one it has since retired
 *
 * `LINKEDIN_ADS_VERSION` defaulted to **202411** (November 2024). LinkedIn's versioning page states
 * the latest version is **202607** and that versions are supported for a **minimum of one year**; every
 * page of the marketing documentation currently carries the banner «The Marketing Version 202507
 * (Marketing July 2025) **has been sunset**».
 *
 * 202411 is eight months older than the version LinkedIn names as already sunset. And the version
 * header is not optional here — this connector's own comment says an unpinned call is rejected
 * outright — so every LinkedIn call was made against a retired version.
 *
 * Third provider in a row: `v18` for Google Ads, `v21.0` for Meta, `202411` here.
 */
final class LinkedInPagingAndVersionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    // ── The version ───────────────────────────────────────────────────────────────────────────

    /**
     * LINKEDIN-VERSION-001.
     *
     * Asserted as a NUMBER with a floor rather than a literal, so a later monthly bump passes and only
     * standing still fails. The floor is 202607, the latest version LinkedIn publishes.
     */
    public function test_linkedin_is_not_pinned_to_a_sunset_api_version(): void
    {
        $version = (string) config('ad_platforms.platforms.linkedin.version');

        $this->assertSame(1, preg_match('/^\d{6}$/', $version), 'the version must be a YYYYMM header value');

        $this->assertGreaterThanOrEqual(
            202607,
            (int) $version,
            "LinkedIn is pinned to {$version}. LinkedIn's own documentation names 202607 as the latest "
                .'and carries a banner that 202507 has already been SUNSET — so this version is eight '
                .'months older than one LinkedIn has retired, and the version header is mandatory.',
        );
    }

    /** And the pinned version is what actually goes out on the wire, on every call. */
    public function test_the_pinned_version_is_sent_as_the_linkedin_version_header(): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => []])]);

        $this->bound('linkedin')->listAdAccounts();

        Http::assertSent(fn (Request $r) => ($r->header('LinkedIn-Version')[0] ?? null)
            === (string) config('ad_platforms.platforms.linkedin.version'));
    }

    // ── Paging ────────────────────────────────────────────────────────────────────────────────

    /**
     * The heart of LINKEDIN-PAGE-001: an eleventh ad account is not invisible.
     *
     * The fixture answers with a full page and then a short one, which is exactly how LinkedIn signals
     * the end of a dataset.
     */
    public function test_every_ad_account_is_read_not_only_the_first_page(): void
    {
        $this->configure('linkedin');

        Http::fake([
            'api.linkedin.com/*start=100*' => Http::response(['elements' => $this->accounts(100, 20)]),
            'api.linkedin.com/*' => Http::response(['elements' => $this->accounts(0, 100)]),
        ]);

        $accounts = $this->bound('linkedin')->listAdAccounts();

        $this->assertCount(
            120,
            $accounts,
            'LINKEDIN-PAGE-001: only the first page was read, and LinkedIn\'s default page size is 10 — '
                .'every account past the first page was invisible on every surface.',
        );
    }

    /** A page size is asked for explicitly, rather than inheriting LinkedIn's default of ten. */
    public function test_the_request_asks_for_a_page_size_instead_of_taking_the_default_of_ten(): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => []])]);

        $this->bound('linkedin')->listAdAccounts();

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertArrayHasKey('count', $query, 'without an explicit count LinkedIn returns ten');
            $this->assertGreaterThan(10, (int) $query['count']);
            $this->assertLessThanOrEqual(1000, (int) $query['count'], 'LinkedIn caps a page at 1000');
            $this->assertSame('0', (string) ($query['start'] ?? null), 'the first page starts at zero');

            return true;
        });
    }

    /**
     * A page SHORTER than the one requested is the end, and no further call is made.
     *
     * LinkedIn's documented rule, and worth its own case: paging until an EMPTY page would spend one
     * unnecessary round trip on every sync of every account, which on a monthly-versioned API with
     * per-app throttling is not free.
     */
    public function test_a_short_page_ends_the_walk_without_another_request(): void
    {
        $this->configure('linkedin');
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => $this->accounts(0, 3)])]);

        $accounts = $this->bound('linkedin')->listAdAccounts();

        $this->assertCount(3, $accounts);
        Http::assertSentCount(1);
    }

    /** Campaigns page too — the level where truncation costs a client their spend. */
    public function test_every_campaign_is_read_not_only_the_first_page(): void
    {
        $this->configure('linkedin');

        Http::fake([
            'api.linkedin.com/*start=100*' => Http::response(['elements' => $this->campaigns(100, 5)]),
            'api.linkedin.com/*' => Http::response(['elements' => $this->campaigns(0, 100)]),
        ]);

        $campaigns = $this->bound('linkedin')->syncCampaigns('512')->records;

        $this->assertCount(105, $campaigns);
    }

    /** And analytics, which is where a missing page is a missing number rather than a missing row. */
    public function test_every_analytics_row_is_read_not_only_the_first_page(): void
    {
        $this->configure('linkedin');

        Http::fake([
            'api.linkedin.com/*start=100*' => Http::response(['elements' => $this->analytics(100, 4)]),
            'api.linkedin.com/*' => Http::response(['elements' => $this->analytics(0, 100)]),
        ]);

        $rows = $this->bound('linkedin')->syncInsights('512', '2026-08-01', '2026-08-02')->records;

        $this->assertCount(104, $rows);
    }

    /**
     * A ceiling, so a server that never shortens a page cannot become an unbounded loop.
     *
     * The same guard `MetaConnector::MAX_PAGES` already carries, for the same reason: a paging bug on
     * the other side must cost a bounded number of requests, not a worker.
     */
    public function test_paging_stops_at_a_ceiling_rather_than_looping_for_ever(): void
    {
        $this->configure('linkedin');

        // Always a FULL page: without a ceiling this never terminates.
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => $this->accounts(0, 100)])]);

        $this->bound('linkedin')->listAdAccounts();

        $this->assertLessThanOrEqual(50, Http::recorded()->count(), 'the walk must be bounded');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> */
    private function accounts(int $from, int $many): array
    {
        return array_map(static fn (int $i): array => [
            'id' => (string) (1000 + $i), 'name' => "Account {$i}", 'currency' => 'SAR', 'status' => 'ACTIVE',
        ], range($from, $from + $many - 1));
    }

    /** @return list<array<string,mixed>> */
    private function campaigns(int $from, int $many): array
    {
        return array_map(static fn (int $i): array => [
            'id' => (string) (2000 + $i), 'name' => "Campaign {$i}", 'status' => 'ACTIVE',
        ], range($from, $from + $many - 1));
    }

    /** @return list<array<string,mixed>> */
    private function analytics(int $from, int $many): array
    {
        return array_map(static fn (int $i): array => [
            'pivotValues' => ['urn:li:sponsoredCampaign:'.(3000 + $i)],
            'dateRange' => ['start' => ['year' => 2026, 'month' => 8, 'day' => 1]],
            'costInLocalCurrency' => '10.5',
            'impressions' => 100,
            'clicks' => 5,
        ], range($from, $from + $many - 1));
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
