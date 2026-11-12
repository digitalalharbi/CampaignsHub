<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Integrations\Catalogue\ProviderCapability;
use App\Domains\Integrations\Catalogue\ProviderCatalogue;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\ApiAdvertisingConnector;
use App\Domains\Integrations\Providers\ReportsCreativeInsights;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * X-LINKEDIN-CONTENT-001 — master task 11: «محتويات X ولينكدإن لا تظهر».
 *
 * Root cause, proved in code before anything was changed:
 *
 *  1. Both connectors reported at the CAMPAIGN grain only (X `entity=CAMPAIGN`, LinkedIn `pivot=CAMPAIGN`) and
 *     neither implemented `ReportsCreativeInsights`, so no X or LinkedIn creative ever received a
 *     `creative_daily_metrics` row or a `last_active_at`. `ReportAds::for()` and `CreativeRows::applyPeriodActivity()`
 *     keep only creatives with evidence of activity in the period, so EVERY X and LinkedIn creative was dropped from
 *     the report, the live link and the content lists — whatever media it had.
 *  2. LinkedIn sponsored-post creatives (Sponsored Content — the common LinkedIn ad) carried `creative => null`, so
 *     the importer never wrote a creative row for them at all.
 *  3. LinkedIn post copy and pictures need `r_organization_social` (Posts API) and `rw_ads` (Images API GET), which
 *     the app does not hold — BLOCKED_EXTERNAL_CREDENTIALS, stated per creative, never a made-up picture.
 */
final class XAndLinkedInContentReachesReportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ?ProviderConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'XL', 'slug' => 'xl-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    public function test_x_and_linkedin_declare_and_implement_creative_level_stats(): void
    {
        foreach (['x', 'linkedin'] as $key) {
            $this->assertTrue(ProviderCatalogue::get($key)->supports(ProviderCapability::CreativeInsights), "{$key} declares creative stats");
            $this->assertInstanceOf(ReportsCreativeInsights::class, $this->bound($key), "{$key} implements creative stats");
        }
    }

    public function test_x_reports_promoted_tweets_per_tweet_with_the_campaign_currency(): void
    {
        $statsQueries = [];
        Http::fake(function (Request $r) use (&$statsQueries) {
            $u = $r->url();

            if (str_contains($u, '/stats/accounts/')) {
                parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                $statsQueries[] = $q;

                return Http::response(['data' => [
                    ['id' => 'pt1', 'id_data' => [['metrics' => ['billed_charge_local_micro' => [2_000_000], 'impressions' => [100], 'clicks' => [4], 'engagements' => [9]]]]],
                    ['id' => 'pt2', 'id_data' => [['metrics' => ['billed_charge_local_micro' => [1_000_000], 'impressions' => [50], 'clicks' => [1]]]]],
                    ['id' => 'pt3', 'id_data' => [['metrics' => ['billed_charge_local_micro' => [5_000_000], 'impressions' => [10]]]]],
                ]]);
            }
            if (str_contains($u, '/promoted_tweets')) {
                return Http::response(['data' => [
                    ['id' => 'pt1', 'tweet_id' => '900', 'line_item_id' => 'li1'],
                    ['id' => 'pt2', 'tweet_id' => '900', 'line_item_id' => 'li1'],
                    ['id' => 'pt3', 'tweet_id' => '901', 'line_item_id' => 'li1'],
                ]]);
            }
            if (str_contains($u, '/line_items')) {
                return Http::response(['data' => [['id' => 'li1', 'campaign_id' => 'c1']]]);
            }
            if (str_contains($u, '/campaigns')) {
                return Http::response(['data' => [['id' => 'c1', 'name' => 'C', 'currency' => 'SAR']]]);
            }
            if (str_contains($u, '/accounts/acc1') && ! str_contains($u, '/accounts/acc1/')) {
                return Http::response(['data' => ['id' => 'acc1', 'timezone' => 'Asia/Riyadh']]);
            }

            return Http::response(['data' => []]);
        });

        $connector = $this->bound('x');
        $this->recordAccount('x', 'acc1', 'Asia/Riyadh');

        $result = $connector->syncCreativeInsights('acc1', '2026-11-01', '2026-11-01');
        $this->assertTrue($result->success, (string) $result->message);

        $this->assertNotSame([], $statsQueries);
        $this->assertSame('PROMOTED_TWEET', $statsQueries[0]['entity']);
        $this->assertStringContainsString('pt1', $statsQueries[0]['entity_ids']);

        $byTweet = [];
        foreach ($result->records as $row) {
            $byTweet[$row['campaign_id'].'|'.$row['date']] = $row;
        }

        $this->assertCount(2, $byTweet, 'one row per TWEET (the creative), not per promoted tweet');
        $this->assertSame(3.0, $byTweet['900|2026-11-01']['spend'], 'two promotions of one tweet are summed');
        $this->assertSame(150.0, $byTweet['900|2026-11-01']['impressions']);
        $this->assertSame('SAR', $byTweet['900|2026-11-01']['currency']);
        $this->assertSame(5.0, $byTweet['901|2026-11-01']['spend']);
    }

    public function test_linkedin_reports_per_creative_with_the_result_its_campaign_objective_names(): void
    {
        $pivots = [];
        Http::fake(function (Request $r) use (&$pivots) {
            $u = $r->url();

            if (str_contains($u, '/adAnalytics')) {
                parse_str((string) parse_url($u, PHP_URL_QUERY), $q);
                $pivots[] = $q['pivot'] ?? null;

                return Http::response(['elements' => [
                    ['pivotValues' => ['urn:li:sponsoredCreative:11'], 'dateRange' => ['start' => ['year' => 2026, 'month' => 11, 'day' => 1]],
                        'costInLocalCurrency' => '12.5', 'impressions' => 400, 'clicks' => 8, 'landingPageClicks' => 6, 'externalWebsiteConversions' => 1],
                    ['pivotValues' => ['urn:li:sponsoredCreative:99'], 'dateRange' => ['start' => ['year' => 2026, 'month' => 11, 'day' => 1]],
                        'costInLocalCurrency' => '1', 'impressions' => 3],
                ]]);
            }
            if (str_contains($u, '/creatives')) {
                return Http::response(['elements' => [
                    ['id' => 'urn:li:sponsoredCreative:11', 'campaign' => 'urn:li:sponsoredCampaign:5', 'content' => ['reference' => 'urn:li:share:77']],
                ]]);
            }
            if (str_contains($u, '/adCampaigns')) {
                return Http::response(['elements' => [['id' => 5, 'name' => 'Visits', 'objectiveType' => 'WEBSITE_VISIT']]]);
            }

            return Http::response(['elements' => []]);
        });

        $result = $this->bound('linkedin')->syncCreativeInsights('123', '2026-11-01', '2026-11-01');
        $this->assertTrue($result->success, (string) $result->message);
        $this->assertSame(['CREATIVE'], array_values(array_unique($pivots)));

        $rows = [];
        foreach ($result->records as $row) {
            $rows[$row['campaign_id']] = $row;
        }

        $this->assertSame(12.5, $rows['11']['spend']);
        $this->assertSame('2026-11-01', $rows['11']['date']);
        $this->assertSame(6.0, $rows['11']['conversions'], 'a website-visit campaign reads its landing-page clicks as its result');
        // A creative whose campaign is not known keeps its figures; its result falls back to conversions, absent here.
        $this->assertSame(1.0, $rows['99']['spend']);
        $this->assertArrayNotHasKey('conversions', $rows['99']);
    }

    public function test_a_linkedin_sponsored_post_is_imported_as_content_and_names_the_missing_permission(): void
    {
        Http::fake(function (Request $r) {
            $u = $r->url();

            if (str_contains($u, '/posts')) {
                return Http::response(['message' => 'Not enough permissions to access: posts', 'status' => 403], 403);
            }

            return Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                    'content' => ['reference' => 'urn:li:share:77']],
            ]]);
        });

        $ads = $this->linkedinAds();

        $this->assertArrayHasKey('creative', $ads[0], 'a sponsored post is content: it must have a creative row');
        $creative = $ads[0]['creative'];
        $this->assertSame('2', $creative['external_id']);
        $this->assertArrayNotHasKey('thumbnail_url', $creative, 'no picture is invented');
        $this->assertArrayNotHasKey('asset_url', $creative);
        $this->assertSame('linkedin_post_scope_missing', $creative['raw']['media_reason']);
        $this->assertSame('urn:li:share:77', $creative['raw']['reference']);
    }

    public function test_a_linkedin_sponsored_post_carries_its_words_and_picture_once_the_scopes_are_granted(): void
    {
        $expires = Carbon::parse('2026-12-01 00:00:00', 'UTC');

        Http::fake(function (Request $r) use ($expires) {
            $u = $r->url();

            if (str_contains($u, '/posts')) {
                return Http::response(['results' => [
                    'urn:li:share:77' => ['id' => 'urn:li:share:77', 'commentary' => 'Join our webinar', 'content' => ['media' => ['id' => 'urn:li:image:IMG1', 'title' => 'Webinar']]],
                ]]);
            }
            if (str_contains($u, '/images')) {
                return Http::response(['results' => [
                    'urn:li:image:IMG1' => ['id' => 'urn:li:image:IMG1', 'status' => 'AVAILABLE',
                        'downloadUrl' => 'https://media.licdn.com/dms/image/IMG1/x.jpg?e=1&t=sig', 'downloadUrlExpiresAt' => $expires->getTimestampMs()],
                ]]);
            }

            return Http::response(['elements' => [
                ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                    'content' => ['reference' => 'urn:li:share:77']],
            ]]);
        });

        $creative = $this->linkedinAds()[0]['creative'];

        $this->assertSame('image', $creative['format']);
        $this->assertSame('Join our webinar', $creative['body']);
        $this->assertSame('Webinar', $creative['headline']);
        $this->assertSame('https://media.licdn.com/dms/image/IMG1/x.jpg?e=1&t=sig', $creative['thumbnail_url']);
        $this->assertSame($expires->toIso8601String(), Carbon::parse($creative['asset_expires_at'])->utc()->toIso8601String());
        $this->assertArrayNotHasKey('media_reason', $creative['raw'] ?? []);
    }

    public function test_linkedin_signed_media_is_copied_to_our_storage_never_hotlinked(): void
    {
        $this->assertTrue(CoverCache::isCopyOnlyHost('https://media.licdn.com/dms/image/IMG1/x.jpg?e=1&t=sig'));
        $this->assertFalse(CoverCache::hotlinkable('https://media.licdn.com/dms/image/IMG1/x.jpg?e=1&t=sig'));
    }

    public function test_the_card_says_which_linkedin_permission_is_missing(): void
    {
        $creative = new ExternalCreative([
            'provider' => 'linkedin', 'name' => 'Creative 2', 'format' => null,
            'raw' => ['media_reason' => 'linkedin_post_scope_missing', 'reference' => 'urn:li:share:77'],
        ]);

        $preview = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('unavailable', $preview['state']);
        $this->assertStringContainsString('r_organization_social', (string) $preview['note_en']);
        $this->assertStringContainsString('لينكدإن', (string) $preview['note_ar']);
    }

    /**
     * The acceptance in the Owner's words: «عدد الإعلانات في التقرير = عددها في مدير الإعلانات لنفس الفترة».
     *
     * Two X tweets and two LinkedIn creatives ran in the period. Before the fix nothing wrote a creative figure for
     * either platform and the report listed none of them; now each one's figures land on its creative and the report
     * section lists all four, each with its picture or — for the LinkedIn post whose file needs a permission — the
     * reason, never an invented picture.
     */
    public function test_every_x_and_linkedin_ad_that_ran_is_listed_in_the_report(): void
    {
        $ws = \App\Domains\ClientWorkspaces\Models\ClientWorkspace::create([
            'tenant_id' => $this->tenant->id, 'name' => 'W', 'slug' => 'w-'.uniqid(),
            'mode' => 'managed', 'status' => 'active', 'client_status' => 'active',
        ]);
        $project = \App\Domains\Projects\Models\Project::create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active',
        ]);

        $creativeOf = function (string $provider, string $accountExternal, string $creativeExternal, array $face) use ($project): array {
            $account = \App\Domains\Integrations\Models\ExternalAccount::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'provider' => $provider, 'external_id' => $accountExternal],
                ['provider_connection_id' => $this->connection?->getKey(), 'account_type' => 'ad_account', 'name' => $provider,
                    'currency' => 'SAR', 'timezone' => 'Asia/Riyadh', 'status' => 'active', 'discovered_at' => Carbon::now()],
            );
            $campaign = \App\Domains\Campaigns\Models\ExternalCampaign::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $this->tenant->id, 'external_account_id' => $account->id, 'external_id' => 'cmp-'.$provider],
                ['project_id' => $project->id, 'provider' => $provider, 'name' => 'Campaign', 'status' => 'active'],
            );
            ExternalCreative::withoutGlobalScopes()->create([
                'tenant_id' => $this->tenant->id, 'project_id' => $project->id, 'external_campaign_id' => $campaign->id,
                'external_account_id' => $account->id, 'provider' => $provider, 'external_creative_id' => $creativeExternal,
                'name' => "Creative {$creativeExternal}", ...$face,
            ]);

            return [$account, $campaign];
        };

        $section = fn (): array => app(\App\Domains\Reports\Services\ReportAds::class)->for(
            'traffic', Carbon::parse('2026-11-01'), Carbon::parse('2026-11-01'), ['project_ids' => [(string) $project->id]],
        );

        // X: the tweets, as the import writes them from the Ads API's own tweets read.
        $this->bound('x');
        [$xAccount] = $creativeOf('x', 'acc1', '900', ['format' => 'image', 'thumbnail_url' => 'https://pbs.twimg.com/media/a.jpg', 'asset_url' => 'https://pbs.twimg.com/media/a.jpg']);
        $creativeOf('x', 'acc1', '901', ['format' => 'text_card', 'body' => 'Just words']);
        // LinkedIn: a Text Ad and a sponsored post whose picture needs a permission the app does not hold.
        $this->bound('linkedin');
        [$liAccount] = $creativeOf('linkedin', '123', '11', ['format' => 'text_card', 'headline' => 'Hire fast', 'body' => 'Post a job']);
        $creativeOf('linkedin', '123', '12', ['format' => null, 'raw' => ['media_reason' => 'linkedin_post_scope_missing', 'reference' => 'urn:li:share:77']]);

        $this->assertSame(0, $section()['creatives_in_scope'] - $section()['creatives_unmeasured'], 'before the creative stats, no X or LinkedIn ad is measured in the period');

        $upsert = app(\App\Domains\Campaigns\Actions\UpsertCreativeDailyMetrics::class);
        $upsert->execute($xAccount, [
            ['currency' => 'SAR', 'campaign_id' => '900', 'date' => '2026-11-01', 'spend' => 3.0, 'impressions' => 150.0, 'clicks' => 5.0],
            ['currency' => 'SAR', 'campaign_id' => '901', 'date' => '2026-11-01', 'spend' => 5.0, 'impressions' => 10.0],
        ]);
        $upsert->execute($liAccount, [
            ['campaign_id' => '11', 'date' => '2026-11-01', 'spend' => 12.5, 'impressions' => 400.0, 'clicks' => 8.0],
            ['campaign_id' => '12', 'date' => '2026-11-01', 'spend' => 1.0, 'impressions' => 3.0],
        ]);

        $after = $section();
        $listed = array_map(static fn (array $r): string => (string) ($r['provider'] ?? ''), $after['roster']);
        sort($listed);

        $this->assertSame(['linkedin', 'linkedin', 'x', 'x'], $listed, 'every X and LinkedIn ad that ran is in the report');
        $this->assertSame(0, $after['creatives_unmeasured']);

        $states = [];
        foreach ($after['ads'] as $ad) {
            $states[$ad['name']] = $ad['preview']['state'] ?? null;
        }
        $this->assertSame('available', $states['Creative 900'] ?? null, 'the X picture is shown');
        $post = ExternalCreative::withoutGlobalScopes()->where('provider', 'linkedin')->where('external_creative_id', '12')->firstOrFail();
        $preview = app(CreativePresenter::class)->preview($post);
        $this->assertSame('unavailable', $preview['state'], 'the LinkedIn post says why, never an invented picture');
        $this->assertNull($preview['image_url']);
        $this->assertStringContainsString('r_organization_social', (string) $preview['note_en']);
    }

    /** @return list<array<string,mixed>> */
    private function linkedinAds(): array
    {
        $c = $this->bound('linkedin');

        return (new \ReflectionMethod($c, 'fetchAds'))->invoke($c, app(TokenVault::class)->stored($this->connection), '123');
    }

    private function recordAccount(string $provider, string $externalId, string $timezone): void
    {
        \App\Domains\Integrations\Models\ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id,
            'provider_connection_id' => $this->connection?->getKey(),
            'provider' => $provider,
            'external_id' => $externalId,
            'account_type' => 'ad_account',
            'name' => 'Acc',
            'timezone' => $timezone,
        ]);
    }

    private function bound(string $platform): ApiAdvertisingConnector
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $platform,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay(), null, ['token_secret' => 'ts-secret']),
            connectionName: $platform,
        );

        return app(AdvertisingConnectorRegistry::class)->get($platform)->withConnection($this->connection);
    }
}
