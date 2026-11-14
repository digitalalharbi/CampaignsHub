<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalAd;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\Models\ProjectIntegrationBinding;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Sync\AccountStructureSyncer;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CONTENT-TEXT-CTA-001 — MEDIA-ALL-001 sub-rows B1/B2/B3/B5/B7: the ad's own words, its call to action and its
 * destination reach the creative row on Meta, TikTok and Snapchat, and a TikTok film whose link lapsed is never
 * handed to ffmpeg.
 *
 * Production 2026-11-13 (read-only inventory): Meta 0/959 creatives with text or CTA, TikTok `raw.ad_text` on 129/284
 * and `body` on 0, Snapchat 0/6,201 with text or destination, the `cta` column empty for every platform, and all 152
 * TikTok `frame_ffmpeg_failed` covers on films whose stored expiry had passed. Every response below is faked in the
 * shape each platform's reference documents; no token, account or person is real.
 */
final class ContentTextCtaParityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake(CoverCache::DISK);
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId((string) $this->tenant->id);
        $ws = ClientWorkspace::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active', 'client_status' => 'active']);
        $this->project = Project::withoutGlobalScopes()->create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    // ── B1 Meta ──────────────────────────────────────────────────────────────────────────────

    public function test_meta_reads_the_copy_and_call_to_action_from_every_place_meta_states_them(): void
    {
        $account = $this->account('meta');
        Http::fake(function ($request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/campaigns') => Http::response(['data' => [['id' => '120', 'name' => 'C', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']]]),
                str_contains($url, '/adsets') => Http::response(['data' => [['id' => '220', 'campaign_id' => '120', 'name' => 'S', 'status' => 'ACTIVE']]]),
                str_contains($url, '/ads?') || str_contains($url, '/ads&') => Http::response(['data' => self::metaAds()]),
                default => Http::response(['id' => '0']),
            };
        });

        $this->assertSame('success', app(AccountStructureSyncer::class)->sync($account)->status);

        Http::assertSent(static function ($request): bool {
            $url = urldecode($request->url());

            return (str_contains($url, '/ads?') || str_contains($url, '/ads&'))
                && str_contains($url, 'object_story_spec') && str_contains($url, ',body,') && str_contains($url, ',title,')
                && str_contains($url, 'call_to_action_type') && str_contains($url, 'asset_feed_spec');
        });

        // AdCreativeLinkData: message / name / description / call_to_action.type
        $link = $this->creativeRow('401');
        $this->assertSame('Free delivery on every abaya', $link->body);
        $this->assertSame('Ramadan collection', $link->headline);
        $this->assertSame('Ends Friday', $link->description);
        $this->assertSame('SHOP_NOW', $link->cta);
        // Regression: the media and destination this creative already rendered are unchanged.
        $this->assertSame('https://scontent.example/full.jpg', $link->asset_url);
        $this->assertSame('https://scontent.example/small.jpg', $link->thumbnail_url);
        $this->assertSame('https://shop.example/ramadan', $link->destination_url);
        $this->assertSame('image', $link->format);

        // AdCreativeVideoData: message / title / link_description / call_to_action.type
        $video = $this->creativeRow('402');
        $this->assertSame('Watch how it is made', $video->body);
        $this->assertSame('Behind the seams', $video->headline);
        $this->assertSame('Two minutes', $video->description);
        $this->assertSame('LEARN_MORE', $video->cta);
        $this->assertSame('https://scontent.example/poster.jpg', $video->thumbnail_url);
        $this->assertSame('https://shop.example/film', $video->destination_url);

        // A creative built from an existing post: no story spec, the copy on the AdCreative itself.
        $post = $this->creativeRow('403');
        $this->assertSame('Our new branch is open', $post->body);
        $this->assertSame('Visit us', $post->headline);
        $this->assertSame('GET_DIRECTIONS', $post->cta);

        // AdAssetFeedSpec (dynamic creative): the first of each list, and the feed's landing page.
        $feed = $this->creativeRow('404');
        $this->assertSame('Body one', $feed->body);
        $this->assertSame('Title one', $feed->headline);
        $this->assertSame('Desc one', $feed->description);
        $this->assertSame('ORDER_NOW', $feed->cta);
        $this->assertSame('https://shop.example/feed', $feed->destination_url);

        // A catalogue template's `{{product.…}}` text is rendered per product by Meta: never stored as copy.
        $catalog = $this->creativeRow('405');
        $this->assertSame('catalog', $catalog->format);
        $this->assertNull($catalog->headline);
        $this->assertSame('Everything in stock', $catalog->body);
        $this->assertSame('SHOP_NOW', $catalog->cta);

        // A creative that states no copy keeps none — nothing is composed from its name.
        $bare = $this->creativeRow('406');
        $this->assertNull($bare->body);
        $this->assertNull($bare->headline);
        $this->assertNull($bare->cta);
    }

    // ── B2 TikTok ────────────────────────────────────────────────────────────────────────────

    public function test_tiktok_writes_the_ad_text_as_the_body_and_its_call_to_action(): void
    {
        $account = $this->account('tiktok');
        Http::fake([
            'business-api.tiktok.com/*/file/video/ad/info/*' => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => []]]),
            'business-api.tiktok.com/*/campaign/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                ['campaign_id' => 'c1', 'campaign_name' => 'TT', 'operation_status' => 'ENABLE'],
            ]]]),
            'business-api.tiktok.com/*/adgroup/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                ['adgroup_id' => 'g1', 'campaign_id' => 'c1', 'adgroup_name' => 'G', 'operation_status' => 'ENABLE'],
            ]]]),
            // `GET /ad/get/` — `ad_text`, `call_to_action` and `landing_page_url` as TikTok's v1.3 reference lists them.
            'business-api.tiktok.com/*/ad/get/*' => Http::response(['code' => 0, 'data' => ['list' => [
                ['ad_id' => 'a1', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'With text', 'operation_status' => 'ENABLE',
                    'video_id' => 'v-1', 'ad_text' => 'Order before noon, delivered today', 'call_to_action' => 'ORDER_NOW',
                    'landing_page_url' => 'https://shop.example/tt'],
                ['ad_id' => 'a2', 'adgroup_id' => 'g1', 'campaign_id' => 'c1', 'ad_name' => 'No text', 'operation_status' => 'ENABLE',
                    'video_id' => 'v-2'],
            ]]]),
        ]);

        $this->assertSame('success', app(AccountStructureSyncer::class)->sync($account)->status);

        $withText = $this->creativeRow('v-1');
        $this->assertSame('Order before noon, delivered today', $withText->body);
        $this->assertSame('ORDER_NOW', $withText->cta);
        // Regression: shape, destination and the kept payload are as before.
        $this->assertSame('video', $withText->format);
        $this->assertSame('https://shop.example/tt', $withText->destination_url);
        $this->assertSame('Order before noon, delivered today', $withText->raw['ad_text']);

        $noText = $this->creativeRow('v-2');
        $this->assertNull($noText->body);
        $this->assertNull($noText->cta);
    }

    public function test_a_tiktok_row_synced_before_the_fix_shows_its_kept_ad_text_as_the_body(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'tiktok',
            'external_creative_id' => 'v-old', 'name' => 'Old', 'format' => 'video', 'status' => 'active',
            'raw' => ['media' => 'video', 'ad_text' => 'Kept but never written'],
        ]);

        $detail = app(CreativePresenter::class)->detail($creative, null);

        $this->assertSame('Kept but never written', $detail['copy']['body']);
        // Only TikTok's own key, only TikTok: another platform's raw payload is not read as copy.
        $meta = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'meta',
            'external_creative_id' => 'm-old', 'name' => 'Old', 'format' => 'image', 'status' => 'active',
            'raw' => ['ad_text' => 'not meta copy'],
        ]);
        $this->assertNull(app(CreativePresenter::class)->detail($meta, null)['copy']['body']);
    }

    // ── B3 Snapchat ──────────────────────────────────────────────────────────────────────────

    public function test_snapchat_reads_the_headline_call_to_action_and_destination_from_the_creative(): void
    {
        $account = $this->account('snapchat');
        Http::fake([
            'adsapi.snapchat.com/*/campaigns' => Http::response(['campaigns' => [
                ['campaign' => ['id' => 'cmp-1', 'name' => 'Snap', 'status' => 'ACTIVE']],
            ]]),
            'adsapi.snapchat.com/*/adsquads' => Http::response(['adsquads' => [
                ['adsquad' => ['id' => 'sq-1', 'campaign_id' => 'cmp-1', 'name' => 'Squad', 'status' => 'ACTIVE']],
            ]]),
            // Snap Marketing API «Creatives»: headline, brand_name, call_to_action and the attachment properties.
            'adsapi.snapchat.com/*/creatives' => Http::response(['creatives' => [
                ['creative' => ['id' => 'crv-web', 'name' => 'Web view', 'type' => 'WEB_VIEW', 'headline' => 'New season drop',
                    'brand_name' => 'Example Brand', 'call_to_action' => 'SHOP_NOW', 'shareable' => true,
                    'web_view_properties' => ['url' => 'https://shop.example/snap', 'allow_snap_javascript_sdk' => false]]],
                ['creative' => ['id' => 'crv-deep', 'name' => 'Deep link', 'type' => 'DEEP_LINK', 'headline' => 'Open the app',
                    'call_to_action' => 'OPEN_APP',
                    'deep_link_properties' => ['deep_link_uri' => 'exampleapp://offers', 'app_name' => 'Example',
                        'web_view_fallback_url' => 'https://shop.example/fallback']]],
                ['creative' => ['id' => 'crv-plain', 'name' => 'Plain snap', 'type' => 'SNAP_AD']],
            ]]),
            'adsapi.snapchat.com/*/ads' => Http::response(['ads' => [
                ['ad' => ['id' => 'ad-web', 'ad_squad_id' => 'sq-1', 'creative_id' => 'crv-web', 'name' => 'A', 'status' => 'ACTIVE']],
                ['ad' => ['id' => 'ad-deep', 'ad_squad_id' => 'sq-1', 'creative_id' => 'crv-deep', 'name' => 'B', 'status' => 'ACTIVE']],
                ['ad' => ['id' => 'ad-plain', 'ad_squad_id' => 'sq-1', 'creative_id' => 'crv-plain', 'name' => 'C', 'status' => 'ACTIVE']],
            ]]),
        ]);

        $run = app(AccountStructureSyncer::class)->sync($account);
        $this->assertSame('success', $run->status, (string) $run->error);

        $web = $this->creativeRow('crv-web');
        $this->assertSame('New season drop', $web->headline);
        $this->assertSame('SHOP_NOW', $web->cta);
        $this->assertSame('https://shop.example/snap', $web->destination_url);
        // The brand name is not the ad's copy: kept with the payload, never written as the body.
        $this->assertNull($web->body);
        $this->assertSame('Example Brand', $web->raw['brand_name']);
        $this->assertSame('image', $web->format);
        $this->assertSame('https://shop.example/snap', ExternalAd::withoutGlobalScopes()->where('external_id', 'ad-web')->value('destination_url'));

        $deep = $this->creativeRow('crv-deep');
        $this->assertSame('Open the app', $deep->headline);
        $this->assertSame('OPEN_APP', $deep->cta);
        // The https landing page, not the app scheme.
        $this->assertSame('https://shop.example/fallback', $deep->destination_url);

        $plain = $this->creativeRow('crv-plain');
        $this->assertNull($plain->headline);
        $this->assertNull($plain->destination_url);
        $this->assertSame('video', $plain->format);
        $this->assertNull(ExternalAd::withoutGlobalScopes()->where('external_id', 'ad-plain')->value('destination_url'));
    }

    // ── B5 the importer ──────────────────────────────────────────────────────────────────────

    public function test_an_over_long_call_to_action_or_headline_is_cut_to_its_column_instead_of_failing_the_sync(): void
    {
        $account = $this->account('meta');
        $long = str_repeat('ع', 700);
        Http::fake(function ($request) use ($long) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, '/campaigns') => Http::response(['data' => [['id' => '120', 'name' => 'C', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_SALES']]]),
                str_contains($url, '/adsets') => Http::response(['data' => [['id' => '220', 'campaign_id' => '120', 'name' => 'S', 'status' => 'ACTIVE']]]),
                str_contains($url, '/ads?') || str_contains($url, '/ads&') => Http::response(['data' => [[
                    'id' => '391', 'adset_id' => '220', 'campaign_id' => '120', 'name' => 'Long', 'status' => 'ACTIVE',
                    'creative' => ['id' => '491', 'object_type' => 'SHARE', 'object_story_spec' => ['link_data' => [
                        'name' => $long, 'description' => $long.$long, 'call_to_action' => ['type' => str_repeat('X', 90)],
                        'link' => 'https://shop.example/?utm='.str_repeat('a', 2100),
                    ]]],
                ]]]),
                default => Http::response(['id' => '0']),
            };
        });

        $this->assertSame('success', app(AccountStructureSyncer::class)->sync($account)->status);

        $row = $this->creativeRow('491');
        $this->assertSame(500, mb_strlen((string) $row->headline));
        $this->assertSame(1000, mb_strlen((string) $row->description));
        $this->assertSame(64, strlen((string) $row->cta));
        // A URL is never cut (a cut URL is a broken link): one over the column is left out, and the sync still succeeds.
        $this->assertNull($row->destination_url);
        $this->assertNull(ExternalAd::withoutGlobalScopes()->where('external_id', '391')->value('destination_url'));
    }

    // ── B7 TikTok cover frames ───────────────────────────────────────────────────────────────

    public function test_a_tiktok_film_whose_link_lapsed_is_renewed_and_the_platforms_still_is_copied_without_ffmpeg(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->tiktokFilm($campaign);
        Process::fake();
        $asked = 0;
        Http::fake(function ($request) use (&$asked) {
            $url = urldecode($request->url());
            if (str_contains($url, 'file/video/ad/info/')) {
                $asked++;

                // `GET /file/video/ad/info/`: video_cover_url, preview_url and preview_url_expire_time (UTC).
                return Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [[
                    'video_id' => 'v-film', 'video_cover_url' => 'https://p16-sign-va.tiktokcdn.com/fresh-cover.jpg?x-expires=4102444800&x-signature=s',
                    'preview_url' => 'https://v16-tt4b.tiktokcdn.com/abc/fresh/video/tos/film.mp4',
                    'preview_url_expire_time' => '2099-01-01 00:00:00', 'width' => 720, 'height' => 1280,
                ]]]]);
            }
            if (str_contains($url, 'fresh-cover.jpg')) {
                return Http::response("\xFF\xD8\xFF\xE0JFIF", 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('gone', 403);
        });

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        $this->assertSame(CoverCache::SOURCE_PROVIDER, $creative->cover_source, 'the platform’s own still is the cover, not a frame');
        $this->assertNull($creative->cover_error);
        $this->assertGreaterThanOrEqual(1, $asked);
        $this->assertTrue($creative->asset_expires_at->isFuture());
        Process::assertNothingRan();
    }

    /** The still link is not lapsed but its CDN fails (500): the film's own lapse is what triggers the renewal. */
    public function test_a_tiktok_film_found_lapsed_in_the_frame_step_is_renewed_and_its_fresh_still_copied_without_ffmpeg(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->tiktokFilm($campaign, ['thumbnail_url' => 'https://p16-sign-va.tiktokcdn.com/old-cover.jpg?x-signature=s']);
        Process::fake();
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, 'file/video/ad/info/')) {
                return Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [[
                    'video_id' => 'v-film', 'video_cover_url' => 'https://p16-sign-va.tiktokcdn.com/fresh-cover.jpg?x-expires=4102444800&x-signature=s',
                    'preview_url' => 'https://v16-tt4b.tiktokcdn.com/abc/fresh/video/tos/film.mp4',
                    'preview_url_expire_time' => '2099-01-01 00:00:00',
                ]]]]);
            }
            if (str_contains($url, 'fresh-cover.jpg')) {
                return Http::response("\xFF\xD8\xFF\xE0JFIF", 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response('cdn error', 500);
        });

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $this->assertSame(CoverCache::SOURCE_PROVIDER, $creative->refresh()->cover_source);
        Process::assertNothingRan();
    }

    public function test_a_tiktok_spark_film_whose_own_link_is_live_is_framed_though_its_poster_expired(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->tiktokFilm($campaign, [
            'thumbnail_url' => null,
            'video_url' => 'https://v16-tt4b.tiktokcdn.com/spark.mp4?x-expires=4102444800&x-signature=s',
            'raw' => ['media' => 'spark'],
        ]);
        Process::fake(['*' => Process::result(output: "\xFF\xD8\xFF\xE0FRAME")]);
        Http::fake(fn () => Http::response(['code' => 40001, 'message' => 'refused', 'request_id' => 'r']));

        $this->assertTrue(app(CoverCache::class)->capture($creative));
        $this->assertSame(CoverCache::SOURCE_VIDEO_FRAME, $creative->refresh()->cover_source);
    }

    public function test_a_tiktok_film_that_cannot_be_renewed_is_never_handed_to_ffmpeg_and_says_its_link_expired(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->tiktokFilm($campaign);
        Process::fake();
        Http::fake(function ($request) {
            $url = urldecode($request->url());
            if (str_contains($url, 'file/video/ad/info/')) {
                return Http::response(['code' => 40001, 'message' => 'Insufficient permissions for some videos', 'request_id' => 'r']);
            }

            return Http::response('gone', 403);
        });

        $this->assertFalse(app(CoverCache::class)->capture($creative));

        $creative->refresh();
        Process::assertNothingRan();
        $this->assertNotSame('frame_ffmpeg_failed', $creative->cover_error);
        $this->assertSame('video_expired', $creative->cover_error);
        $this->assertNotNull($creative->cover_next_attempt_at, 'it backs off like any other failure');
    }

    public function test_a_tiktok_film_whose_stored_expiry_is_still_ahead_is_framed_exactly_as_before(): void
    {
        [, $campaign] = $this->world('tiktok');
        $creative = $this->tiktokFilm($campaign, [
            'thumbnail_url' => null,
            'asset_expires_at' => Carbon::now()->addHours(3),
        ]);
        Process::fake(['*' => Process::result(output: "\xFF\xD8\xFF\xE0FRAME")]);
        // No still: the renewal is asked first and TikTok refuses it, so the frame comes from the live film.
        Http::fake(fn () => Http::response(['code' => 40001, 'message' => 'Insufficient permissions for some videos', 'request_id' => 'r']));

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $this->assertSame(CoverCache::SOURCE_VIDEO_FRAME, $creative->refresh()->cover_source);
        Process::assertRan(static fn ($process): bool => in_array('ffmpeg', (array) $process->command, true));
    }

    public function test_a_snapchat_film_is_framed_from_its_link_whatever_its_stored_expiry_says(): void
    {
        $creative = ExternalCreative::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'snapchat',
            'external_creative_id' => 's-film', 'name' => 'Snap film', 'format' => 'video', 'status' => 'active',
            'video_url' => 'https://cf-st.sc-cdn.net/d/film.mp4?e=4102444800',
            // The stored expiry may be a still's; for Snapchat the link's own `e=` decides, as before.
            'asset_expires_at' => Carbon::now()->subDay(),
        ]);
        Process::fake(['*' => Process::result(output: "\xFF\xD8\xFF\xE0FRAME")]);

        $this->assertTrue(app(CoverCache::class)->capture($creative));
        $this->assertSame(CoverCache::SOURCE_VIDEO_FRAME, $creative->refresh()->cover_source);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** @return list<array<string,mixed>> Meta `/{account}/ads` rows in the Marketing API's own shape */
    private static function metaAds(): array
    {
        $ad = static fn (string $id, array $creative): array => [
            'id' => '3'.substr($id, 1), 'adset_id' => '220', 'campaign_id' => '120', 'name' => 'Ad '.$id, 'status' => 'ACTIVE',
            'creative' => ['id' => $id] + $creative,
        ];

        return [
            $ad('401', ['name' => 'Link', 'object_type' => 'SHARE', 'image_url' => 'https://scontent.example/full.jpg',
                'thumbnail_url' => 'https://scontent.example/small.jpg',
                'object_story_spec' => ['page_id' => 'p', 'link_data' => [
                    'link' => 'https://shop.example/ramadan', 'message' => 'Free delivery on every abaya', 'name' => 'Ramadan collection',
                    'description' => 'Ends Friday', 'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://shop.example/ramadan']],
                ]]]),
            $ad('402', ['name' => 'Video', 'object_type' => 'VIDEO',
                'object_story_spec' => ['page_id' => 'p', 'video_data' => [
                    'image_url' => 'https://scontent.example/poster.jpg', 'message' => 'Watch how it is made', 'title' => 'Behind the seams',
                    'link_description' => 'Two minutes', 'call_to_action' => ['type' => 'LEARN_MORE', 'value' => ['link' => 'https://shop.example/film']],
                ]]]),
            $ad('403', ['name' => 'Post', 'object_type' => 'STATUS', 'body' => 'Our new branch is open', 'title' => 'Visit us',
                'call_to_action_type' => 'GET_DIRECTIONS']),
            $ad('404', ['name' => 'Dynamic', 'object_type' => 'SHARE', 'asset_feed_spec' => [
                'bodies' => [['text' => 'Body one'], ['text' => 'Body two']],
                'titles' => [['text' => 'Title one'], ['text' => 'Title two']],
                'descriptions' => [['text' => 'Desc one']],
                'call_to_action_types' => ['ORDER_NOW', 'SHOP_NOW'],
                'link_urls' => [['website_url' => 'https://shop.example/feed']],
            ]]),
            $ad('405', ['name' => '{{product.name}}', 'object_type' => 'SHARE',
                'object_story_spec' => ['page_id' => 'p', 'template_data' => [
                    'link' => 'https://shop.example/p/{{product.id}}', 'name' => '{{product.name}}', 'message' => 'Everything in stock',
                    'call_to_action' => ['type' => 'SHOP_NOW'],
                ]]]),
            $ad('406', ['name' => 'Bare', 'object_type' => 'PHOTO', 'thumbnail_url' => 'https://scontent.example/bare.jpg']),
        ];
    }

    private function creativeRow(string $externalId): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->where('external_creative_id', $externalId)->firstOrFail();
    }

    private function configure(string $platform): void
    {
        foreach (PlatformCredentials::for($platform)->requires() as $key) {
            config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
        }
    }

    private function account(string $provider): ExternalAccount
    {
        $this->configure($provider);
        $connection = app(TokenVault::class)->open(
            tenantId: (string) $this->tenant->id,
            provider: $provider,
            tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30), null, []),
            connectionName: $provider,
        );
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => "act_{$provider}", 'name' => ucfirst($provider), 'status' => 'active',
            'discovered_at' => Carbon::now(),
        ]);
        ProjectIntegrationBinding::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'client_workspace_id' => $this->project->client_workspace_id,
            'project_id' => $this->project->id, 'external_account_id' => $account->id, 'provider' => $provider,
            'purpose' => 'advertising', 'is_active' => true, 'campaign_management_enabled' => true,
        ]);

        return $account;
    }

    /** @return array{0: ExternalAccount, 1: ExternalCampaign} */
    private function world(string $provider): array
    {
        $account = $this->account($provider);
        $campaign = ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'external_id' => 'cmp-1', 'name' => 'C', 'status' => 'active',
        ]);

        return [$account, $campaign];
    }

    /**
     * The Production shape of all 152: a TikTok film on `v16-tt4b.tiktokcdn.com` whose link carries no `x-expires`,
     * a stored `asset_expires_at` (TikTok's `preview_url_expire_time`) already past, and a cover link that lapsed.
     */
    private function tiktokFilm(ExternalCampaign $campaign, array $over = []): ExternalCreative
    {
        return ExternalCreative::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => 'tiktok',
            'external_campaign_id' => $campaign->id, 'external_account_id' => $campaign->external_account_id,
            'external_creative_id' => 'v-film', 'name' => 'Film', 'format' => 'video', 'status' => 'active', 'source_type' => 'api',
            'video_url' => 'https://v16-tt4b.tiktokcdn.com/abc/old/video/tos/film.mp4',
            'thumbnail_url' => 'https://p16-sign-va.tiktokcdn.com/old-cover.jpg?x-expires=1700000000&x-signature=s',
            'asset_expires_at' => Carbon::now()->subDays(2),
            'raw' => ['media' => 'video'],
        ], $over));
    }
}
