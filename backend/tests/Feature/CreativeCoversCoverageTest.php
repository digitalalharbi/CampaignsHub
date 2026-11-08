<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Models\UnifiedCampaign;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativeRows;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\TikTokConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BindsSeededAccounts;
use Tests\TestCase;

/**
 * CREATIVE-COVERS-COVERAGE-001 — Owner, 2026-11-07: «الغلاف + المحتوى: يجب أن يظهر لجميع المحتويات 100%».
 *
 * Production held creatives with no link at all (TikTok 410 of 1,648; Snapchat 3,047 of 5,489), Spark posts the
 * sweep never asked or asked under no identity, and a live client link from which every Meta creative was missing
 * because their row named a campaign without figures. Each fix is proven against a faked provider answer shaped
 * like the platform's own.
 */
final class CreativeCoversCoverageTest extends TestCase
{
    use BindsSeededAccounts;
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(CoverCache::DISK);
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
        foreach (['tiktok', 'snapchat'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
    }

    private function connection(string $provider): ProviderConnection
    {
        return app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay(), null, []),
            connectionName: $provider,
        );
    }

    /** @return array{0: ExternalAccount, 1: ExternalCampaign} */
    private function world(string $provider): array
    {
        $connection = $this->connection($provider);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'external_id' => 'adv-1', 'account_type' => 'ad_account', 'name' => 'A', 'status' => 'active', 'timezone' => 'Asia/Riyadh',
        ]);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'external_id' => 'cmp-1', 'name' => 'C', 'status' => 'active',
        ]);

        return [$account, $campaign];
    }

    private function creative(ExternalCampaign $campaign, array $over): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => $campaign->provider,
            'external_campaign_id' => $campaign->id, 'external_account_id' => $campaign->external_account_id,
            'name' => 'Ad', 'status' => 'active', 'source_type' => 'api',
        ], $over));
    }

    private function tiktok(): TikTokConnector
    {
        /** @var TikTokConnector $connector */
        $connector = app(AdvertisingConnectorRegistry::class)->get('tiktok')->withConnection($this->connection('tiktok'));

        return $connector;
    }

    /** TikTok's real `identity/video/info/` answer: `data.video_detail.video_info` (measured 2026-10-18). */
    private static function postAnswer(string $poster, string $file): array
    {
        return ['code' => 0, 'message' => 'OK', 'request_id' => 'r', 'data' => ['video_detail' => [
            'item_id' => '7300000000000000001', 'text' => 'post', 'status' => 'ITEM_STATUS_ONLY_AUTHOR_SEE',
            'video_info' => ['bit_rate' => 1, 'duration' => 12.4, 'format' => 'mp4', 'height' => 1024, 'width' => 576,
                'poster_url' => $poster, 'url' => $file, 'signature' => 's', 'size' => 1],
        ]]];
    }

    public function test_a_spark_post_whose_ad_names_no_identity_is_asked_under_the_advertisers_identities_and_keeps_the_one_that_answered(): void
    {
        $asked = [];
        Http::fake(function ($request) use (&$asked) {
            $url = urldecode($request->url());
            if (str_contains($url, 'identity/get/')) {
                return str_contains($url, 'identity_type=TT_USER')
                    ? Http::response(['code' => 0, 'data' => ['identity_list' => [
                        ['identity_id' => 'other-id', 'identity_type' => 'TT_USER', 'display_name' => 'Other'],
                        ['identity_id' => 'owner-id', 'identity_type' => 'TT_USER', 'display_name' => 'Owner'],
                    ], 'page_info' => ['page' => 1, 'total_page' => 1]]])
                    : Http::response(['code' => 0, 'data' => ['identity_list' => []]]);
            }
            if (str_contains($url, 'identity/video/info/')) {
                $asked[] = $url;

                return str_contains($url, 'identity_id=owner-id')
                    ? Http::response(self::postAnswer('https://p16-common-sign.tiktokcdn.com/spark.jpg?x-expires=1790000000', 'https://v16-tt4b.tiktokcdn.com/spark.mp4?x-expires=1790000000'))
                    : Http::response(['code' => 40000, 'message' => "Unable to fetch a valid item. Please check the 'identity_id'", 'request_id' => 'r']);
            }
            if (str_contains($url, 'advertiser/info/')) {
                return Http::response(['code' => 0, 'data' => ['list' => [['advertiser_id' => 'adv-1']]]]);
            }

            return Http::response(['code' => 0, 'data' => ['list' => [
                ['ad_id' => 's1', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'Spark', 'video_id' => null, 'tiktok_item_id' => '7300000000000000001'],
            ], 'page_info' => ['page' => 1, 'total_page' => 1]]]);
        });

        $ads = collect($this->tiktok()->syncAds('adv-1')->records)->keyBy('external_id');
        $creative = $ads['s1']['creative'];

        $this->assertSame('https://p16-common-sign.tiktokcdn.com/spark.jpg?x-expires=1790000000', $creative['thumbnail_url'], 'the post is live: its own poster');
        $this->assertSame('https://v16-tt4b.tiktokcdn.com/spark.mp4?x-expires=1790000000', $creative['video_url'], 'and its playable film');
        $this->assertSame(['TT_USER', 'owner-id'], [$creative['raw']['identity_type'], $creative['raw']['identity_id']], 'the identity that answered rides on the creative');
        $this->assertArrayNotHasKey('media_reason', $creative['raw']);
        $this->assertCount(2, $asked, 'the wrong identity is refused, the owner answers — bounded');
    }

    public function test_a_spark_photo_post_draws_its_first_picture(): void
    {
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, 'identity/video/info/')) {
                return Http::response(['code' => 0, 'data' => ['video_detail' => [
                    'item_id' => '7300000000000000002', 'video_info' => [],
                    'image_info' => [['image_url' => 'https://p16-common-sign.tiktokcdn.com/photo-1.jpg', 'width' => 1080], ['image_url' => 'https://p16-common-sign.tiktokcdn.com/photo-2.jpg']],
                ]]]);
            }

            return Http::response(['code' => 0, 'data' => ['list' => [
                ['ad_id' => 'p1', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'Photo', 'ad_format' => 'CAROUSEL_ADS', 'video_id' => null, 'tiktok_item_id' => '7300000000000000002', 'identity_type' => 'TT_USER', 'identity_id' => 'idn'],
            ], 'page_info' => ['page' => 1, 'total_page' => 1]]]);
        });

        $creative = collect($this->tiktok()->syncAds('adv-1')->records)->keyBy('external_id')['p1']['creative'];

        $this->assertSame('https://p16-common-sign.tiktokcdn.com/photo-1.jpg', $creative['thumbnail_url']);
        $this->assertSame('https://p16-common-sign.tiktokcdn.com/photo-1.jpg', $creative['asset_url'], 'a photo post\'s content is its picture');
        $this->assertArrayNotHasKey('video_url', $creative);
    }

    public function test_a_spark_creative_with_no_link_is_renewed_under_its_kept_identity_and_its_cover_is_copied(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->creative($campaign, [
            'external_creative_id' => '7300000000000000001', 'format' => 'video',
            'raw' => ['media' => 'spark', 'tiktok_item_id' => '7300000000000000001', 'identity_type' => 'BC_AUTH_TT', 'identity_id' => 'idn-1', 'identity_authorized_bc_id' => 'bc-1', 'media_reason' => 'spark_post_info_missing'],
        ]);
        $asked = [];
        Http::fake(function ($request) use (&$asked) {
            $url = urldecode($request->url());
            if (str_contains($url, 'identity/video/info/')) {
                $asked[] = $url;

                return Http::response(self::postAnswer('https://p16-common-sign.tiktokcdn.com/fresh.jpg?x-expires=4102444800', 'https://v16-tt4b.tiktokcdn.com/fresh.mp4?x-expires=4102444800'));
            }
            if (str_contains($url, 'p16-common-sign.tiktokcdn.com')) {
                return Http::response("\xFF\xD8\xFFJPEG", 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response(['code' => 40002, 'message' => 'unexpected'], 400);
        });

        $this->assertTrue(CoverCache::wanted($creative), 'no link yet, but TikTok can name one');
        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertNotNull($creative->cover_path);
        $this->assertSame('https://v16-tt4b.tiktokcdn.com/fresh.mp4?x-expires=4102444800', $creative->video_url, 'the playable film is renewed with it');
        $this->assertCount(1, $asked);
        $this->assertStringContainsString('identity_id=idn-1', $asked[0]);
        $this->assertStringContainsString('identity_authorized_bc_id=bc-1', $asked[0]);
        $this->assertStringContainsString('item_id=7300000000000000001', $asked[0]);
    }

    public function test_a_snapchat_film_with_no_still_gets_snapchats_own_thumbnail_and_a_copied_cover(): void
    {
        [, $campaign] = $this->world('snapchat');
        $creative = $this->creative($campaign, [
            'external_creative_id' => 'cr-1', 'format' => 'video',
            'video_url' => 'https://storage.googleapis.com/snap/film.mp4',
            'raw' => ['media_id' => 'media-1', 'media_type' => 'VIDEO'],
        ]);
        $asked = [];
        Http::fake(function ($request) use (&$asked) {
            $url = $request->url();
            if (str_contains($url, 'adsapi.snapchat.com')) {
                $asked[] = $url;

                return str_contains($url, 'media/media-1/thumbnail')
                    ? Http::response(['request_status' => 'SUCCESS', 'request_id' => 'r', 'expires_at' => '2030-01-01T00:00:00.000Z', 'link' => 'https://storage.googleapis.com/snap/thumb.jpg'])
                    : Http::response(['request_status' => 'ERROR'], 400);
            }

            return Http::response("\x89PNG....", 200, ['Content-Type' => 'image/png']);
        });

        $this->assertTrue(CoverCache::wanted($creative));
        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertSame('https://storage.googleapis.com/snap/thumb.jpg', $creative->thumbnail_url);
        $this->assertNotNull($creative->cover_path);
        $this->assertSame('https://storage.googleapis.com/snap/film.mp4', $creative->video_url, 'the film itself is untouched');
        $this->assertCount(1, $asked, 'one read: the media id was kept by the sweep');
    }

    public function test_a_snapchat_row_written_before_the_media_id_was_kept_finds_its_top_snap_through_the_creative(): void
    {
        [, $campaign] = $this->world('snapchat');
        $creative = $this->creative($campaign, ['external_creative_id' => 'comp-1', 'format' => 'composite']);
        Http::fake(function ($request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'creatives/comp-1') => Http::response(['request_status' => 'SUCCESS', 'creatives' => [['sub_request_status' => 'SUCCESS', 'creative' => ['id' => 'comp-1', 'type' => 'COMPOSITE', 'composite_properties' => ['creative_ids' => ['child-1', 'child-2']]]]]]),
                str_contains($url, 'creatives/child-1') => Http::response(['request_status' => 'SUCCESS', 'creatives' => [['sub_request_status' => 'SUCCESS', 'creative' => ['id' => 'child-1', 'type' => 'SNAP_AD', 'top_snap_media_id' => 'media-9']]]]),
                str_contains($url, 'media/media-9/thumbnail') => Http::response(['request_status' => 'SUCCESS', 'link' => 'https://storage.googleapis.com/snap/child-thumb.jpg']),
                str_contains($url, 'adsapi.snapchat.com') => Http::response(['request_status' => 'ERROR'], 400),
                default => Http::response("\xFF\xD8\xFFJPEG", 200, ['Content-Type' => 'image/jpeg']),
            };
        });

        $this->assertTrue(app(CoverCache::class)->capture($creative));
        $this->assertSame('https://storage.googleapis.com/snap/child-thumb.jpg', $creative->fresh()->thumbnail_url);
    }

    public function test_a_platform_that_gives_nothing_backs_off_and_a_dynamic_collection_is_never_asked(): void
    {
        [, $campaign] = $this->world('snapchat');
        $film = $this->creative($campaign, ['external_creative_id' => 'cr-2', 'format' => 'video', 'raw' => ['media_id' => 'media-2']]);
        $dynamic = $this->creative($campaign, ['external_creative_id' => 'dyn-1', 'format' => 'collection_dynamic']);
        Http::fake(['*' => Http::response(['request_status' => 'ERROR', 'debug_message' => 'not found'], 404)]);

        $this->assertFalse(app(CoverCache::class)->capture($film));
        $film->refresh();
        $this->assertSame('provider_gave_none', $film->cover_error);
        $this->assertTrue($film->cover_next_attempt_at->isFuture(), 'asked again later, not every pass');
        $this->assertFalse(CoverCache::wanted($film));

        $this->assertFalse(CoverCache::wanted($dynamic), 'composed per product at delivery: there is no file to ask for');
    }

    public function test_the_backfill_dry_run_counts_creatives_that_can_still_be_asked_for_a_link(): void
    {
        [, $campaign] = $this->world('tiktok');
        $this->creative($campaign, ['external_creative_id' => '1', 'format' => 'video', 'raw' => ['media' => 'spark']]);
        $this->creative($campaign, ['external_creative_id' => '2', 'format' => 'video', 'raw' => ['media' => 'video']]);
        $this->creative($campaign, ['external_creative_id' => '3', 'format' => 'video']);

        $this->artisan('creatives:backfill-covers', ['--provider' => 'tiktok'])
            ->expectsTable(
                ['provider', 'creatives', 'with_source', 'cached', 'due', 'backing_off', 'recoverable', 'provider_gives_none'],
                [['tiktok', 3, 0, 0, 2, 0, 2, 1]],
            )
            ->assertSuccessful();
    }

    public function test_a_creative_is_in_every_campaign_its_ads_ran_in_so_a_report_ceiling_does_not_drop_a_platform(): void
    {
        $accountId = $this->bindSeededAccount((string) $this->project->id, null, 'meta');
        $filed = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => '4Aug 2026- Sales', 'objective' => 'sales', 'status' => 'active']);
        $spent = UnifiedCampaign::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'name' => '4Aug 2026- Sales -KSA', 'objective' => 'sales', 'status' => 'active']);
        $external = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $accountId,
            'provider' => 'meta', 'external_id' => 'cmp-ksa', 'name' => 'KSA', 'status' => 'active', 'unified_campaign_id' => $spent->id,
        ]);
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta', 'external_creative_id' => 'cr-shared',
            'external_campaign_id' => $external->id, 'external_account_id' => $accountId, 'campaign_id' => $filed->id,
            'name' => 'Shared', 'format' => 'image', 'status' => 'active', 'source_type' => 'api',
        ]);
        \DB::table('external_ads')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id,
            'external_campaign_id' => $external->id, 'unified_campaign_id' => $spent->id, 'creative_id' => $creative->id,
            'provider' => 'meta', 'external_id' => 'ad-ksa', 'name' => 'Ad', 'status' => 'active', 'source_type' => 'api',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $query = ExternalCreative::query();
        app(CreativeRows::class)->applyFilters($query, ['project_ids' => [(string) $this->project->id], 'campaign_ids' => [(string) $spent->id]]);

        $this->assertSame([(string) $creative->id], $query->pluck('external_creatives.id')->map(fn ($id) => (string) $id)->all(), 'its ad ran — and spent — under the ceiling\'s campaign');

        $none = ExternalCreative::query();
        app(CreativeRows::class)->applyFilters($none, ['project_ids' => [(string) $this->project->id], 'campaign_ids' => [(string) Str::uuid()]]);
        $this->assertSame(0, $none->count(), 'a campaign it never ran in still excludes it');
    }
}
