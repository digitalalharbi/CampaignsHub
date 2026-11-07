<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Jobs\CacheCreativeCoverJob;
use App\Domains\Campaigns\Models\ExternalCampaign;
use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CoverCache;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\ClientWorkspaces\Models\ClientWorkspace;
use App\Domains\Integrations\Models\ExternalAccount;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Projects\Models\Project;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CREATIVE-PREVIEWS-001 — Owner, 2026-11-07: «حل مشكلة عدم ظهور المعاينة أو صورة الغلاف لمنصة تيك توك وميتا
 * وباقي المنصات! باستثناء سناب تظهر الصور والمعاينات.»
 *
 * One canonical path: the product shows OUR copy of a cover, served from our storage; a Meta or TikTok signed
 * link never reaches a browser; a lapsed link is renewed through the platform before it is copied; every
 * outcome is recorded per creative and a refusal backs off. Snapchat, which works, keeps its behaviour.
 */
final class CreativePreviewsTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0JFIF-cover-bytes";

    private Tenant $tenant;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake(CoverCache::DISK);
        foreach (['meta', 'tiktok'] as $platform) {
            foreach (PlatformCredentials::for($platform)->requires() as $key) {
                config()->set("ad_platforms.platforms.{$platform}.{$key}", "test-{$key}");
            }
        }
        $this->tenant = Tenant::create(['name' => 'T', 'slug' => 't-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
        $ws = ClientWorkspace::create(['tenant_id' => $this->tenant->id, 'name' => 'C', 'slug' => 'c-'.uniqid(), 'mode' => 'managed', 'status' => 'active']);
        $this->project = Project::create(['tenant_id' => $this->tenant->id, 'client_workspace_id' => $ws->id, 'name' => 'P', 'status' => 'active']);
    }

    // ── the browser is never handed a Meta or TikTok signed link ────────────────────────────────────────

    public function test_a_meta_still_is_our_cached_copy_never_the_signed_fbcdn_link(): void
    {
        $creative = $this->creative('meta', [
            'format' => 'image',
            'asset_url' => 'https://scontent-fra3-1.xx.fbcdn.net/v/t45/full.jpg?oe='.dechex(now()->addDays(2)->getTimestamp()),
            'thumbnail_url' => 'https://external-fra3-1.xx.fbcdn.net/emg1/thumb.jpg',
            'cover_path' => 'ab/abcdef.jpg', 'cover_cached_at' => now(),
        ]);

        $preview = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('available', $preview['state']);
        $this->assertSame('/media/covers/ab/abcdef.jpg', $preview['image_url'], 'our copy IS the still — it used to lose to the fbcdn asset_url');
        $this->assertSame('/media/covers/ab/abcdef.jpg', $preview['thumbnail_url']);
        $this->assertSame('cached', $preview['cover']['state']);
        $this->assertNoProviderHost($preview);
    }

    public function test_a_cached_cover_still_draws_after_the_provider_link_expired(): void
    {
        $creative = $this->creative('tiktok', [
            'format' => 'video',
            'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/obj/cover.jpg?x-expires=1762000000&x-signature=s',
            'video_url' => 'https://v16-ad.tiktokcdn.com/v.mp4?x-expires=1762000000',
            'asset_expires_at' => now()->subHour(),
            'cover_path' => 'cd/cdef.jpg', 'cover_cached_at' => now()->subDay(),
        ]);

        $preview = app(CreativePresenter::class)->preview($creative);

        $this->assertSame('available', $preview['state'], 'a lapsed provider link no longer empties a card we hold a copy for');
        $this->assertSame('/media/covers/cd/cdef.jpg', $preview['thumbnail_url']);
        $this->assertNull($preview['video_url'], 'the dead film is not offered');
        $this->assertNoProviderHost($preview);
    }

    public function test_without_a_copy_meta_and_tiktok_say_pending_or_failed_and_name_no_provider_host(): void
    {
        $meta = $this->creative('meta', ['format' => 'image', 'asset_url' => 'https://scontent-fra3-1.xx.fbcdn.net/v/full.jpg?oe=7FFFFFFF']);
        $tiktok = $this->creative('tiktok', ['format' => 'image', 'external_creative_id' => 'img-2', 'thumbnail_url' => 'https://p19-ad-site-sign-sg.ibyteimg.com/x.image?x-expires=4000000000', 'cover_state' => 'failed', 'cover_error' => 'http_403']);

        $m = app(CreativePresenter::class)->preview($meta);
        $t = app(CreativePresenter::class)->preview($tiktok);

        $this->assertSame('cover_pending', $m['state']);
        $this->assertSame('pending', $m['cover']['state']);
        $this->assertSame('الغلاف قيد التجهيز.', $m['note_ar']);
        $this->assertSame('cover_failed', $t['state']);
        $this->assertSame(['state' => 'failed', 'reason' => 'http_403'], $t['cover']);
        $this->assertNoProviderHost($m);
        $this->assertNoProviderHost($t);
    }

    public function test_snapchat_keeps_its_own_media_link_unchanged(): void
    {
        $link = 'https://adsapi-media.snapchat.com/media/abc/download?e=4000000000&sig=s';
        $snap = $this->creative('snapchat', ['format' => 'image', 'asset_url' => $link]);

        $preview = app(CreativePresenter::class)->preview($snap);

        $this->assertSame('available', $preview['state']);
        $this->assertSame($link, $preview['image_url'], 'Snapchat draws today; its link is still what the card reads until a copy exists');
    }

    // ── the cover endpoint ──────────────────────────────────────────────────────────────────────────────

    public function test_the_cover_endpoint_serves_cached_bytes_with_long_cache_headers_and_404s_a_missing_file(): void
    {
        Storage::disk(CoverCache::DISK)->put(CoverCache::ROOT.'/ab/abcdef.jpg', self::JPEG);

        $response = $this->get('/media/covers/ab/abcdef.jpg');
        $response->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame(self::JPEG, $response->streamedContent());
        $this->assertStringContainsString('max-age=604800', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Last-Modified'));

        $this->get('/media/covers/ab/missing.jpg')->assertNotFound();
    }

    public function test_covers_live_on_the_storage_volume_every_container_mounts(): void
    {
        $this->assertSame('local', CoverCache::DISK);
        $this->assertStringStartsWith(storage_path('app'), (string) config('filesystems.disks.local.root'), 'the covers root is under storage/app');

        $compose = (string) file_get_contents(dirname(base_path()).'/deploy/docker-compose.production.yml');
        foreach (['backend', 'queue', 'scheduler'] as $service) {
            $block = (string) preg_replace('~^.*\n  '.$service.":\n~s", '', $compose);
            $block = (string) preg_replace("~\n  [a-z]+:\n.*$~s", '', $block);
            $this->assertStringContainsString('campaignshub_storage:/var/www/html/storage/app', $block, "the {$service} container must mount the persistent storage volume");
        }
    }

    // ── the cache job: Meta and TikTok, images and videos ──────────────────────────────────────────────

    public function test_a_lapsed_meta_thumbnail_is_renewed_through_the_creative_node_then_copied(): void
    {
        $campaign = $this->campaign('meta', 'act_1');
        $creative = $this->creative('meta', [
            'external_campaign_id' => $campaign->id, 'external_creative_id' => 'k1', 'format' => 'image',
            'thumbnail_url' => 'https://scontent-fra3-1.xx.fbcdn.net/old.jpg?oe='.dechex(now()->subDay()->getTimestamp()),
        ]);
        $fresh = 'https://scontent-fra3-1.xx.fbcdn.net/new.jpg?oe='.dechex(now()->addDays(3)->getTimestamp());
        $asked = [];
        Http::fake(function (Request $request) use ($fresh, &$asked) {
            $asked[] = $url = $request->url();

            return match (true) {
                str_contains($url, 'graph.facebook.com') && str_contains($url, '/k1?') => Http::response(['id' => 'k1', 'thumbnail_url' => $fresh]),
                str_starts_with($url, 'https://scontent-fra3-1.xx.fbcdn.net/new.jpg') => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response('expired', 403),
            };
        });

        (new CacheCreativeCoverJob((string) $creative->getKey()))->handle(app(CoverCache::class));

        $creative->refresh();
        $this->assertSame('cached', $creative->cover_state);
        $this->assertSame($fresh, $creative->thumbnail_url, 'the fresh link is written back as the sweep would');
        Storage::disk(CoverCache::DISK)->assertExists(CoverCache::ROOT.'/'.$creative->cover_path);
        $this->assertSame(self::JPEG, Storage::disk(CoverCache::DISK)->get(CoverCache::ROOT.'/'.$creative->cover_path));
        $this->assertEmpty(array_filter($asked, static fn (string $u): bool => str_contains($u, '/old.jpg')), 'a link known to have lapsed is not fetched');
    }

    public function test_a_meta_video_poster_is_copied_from_its_live_link(): void
    {
        $creative = $this->creative('meta', [
            'format' => 'video',
            'video_url' => 'https://video-fra3-1.xx.fbcdn.net/v.mp4?oe='.dechex(now()->addDay()->getTimestamp()),
            'thumbnail_url' => 'https://scontent-fra3-1.xx.fbcdn.net/poster.jpg?oe='.dechex(now()->addDay()->getTimestamp()),
        ]);
        Http::fake(['scontent-fra3-1.xx.fbcdn.net/*' => Http::response(self::JPEG, 200, ['Content-Type' => 'application/octet-stream'])]);

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $preview = app(CreativePresenter::class)->preview($creative->fresh());
        $this->assertSame('/media/covers/'.$creative->fresh()?->cover_path, $preview['thumbnail_url'], 'a CDN that labels a JPEG octet-stream still sent a JPEG');
        $this->assertStringEndsWith('.jpg', (string) $creative->fresh()?->cover_path);
    }

    public function test_a_lapsed_tiktok_video_cover_is_renewed_through_file_video_ad_info_then_copied(): void
    {
        $campaign = $this->campaign('tiktok', '7000000000000000001');
        $creative = $this->creative('tiktok', [
            'external_campaign_id' => $campaign->id, 'external_creative_id' => 'v-1', 'format' => 'video', 'raw' => ['media' => 'video'],
            'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/old.jpg?x-expires='.now()->subHour()->getTimestamp().'&x-signature=s',
        ]);
        $cover = 'https://p16-common-sign.tiktokcdn.com/new.jpg?x-expires='.now()->addHours(6)->getTimestamp().'&x-signature=s';
        $asked = [];
        Http::fake(function (Request $request) use ($cover, &$asked) {
            $asked[] = $url = urldecode($request->url());

            return match (true) {
                str_contains($url, 'file/video/ad/info/') => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [[
                    'video_id' => 'v-1', 'video_cover_url' => $cover, 'preview_url' => 'https://v16m.tiktokcdn.com/v.mp4',
                    'preview_url_expire_time' => now()->addHours(6)->format('Y-m-d H:i:s'), 'width' => 1080, 'height' => 1920, 'duration' => 15.0,
                ]]]]),
                str_starts_with($url, 'https://p16-common-sign.tiktokcdn.com/new.jpg') => Http::response(self::JPEG, 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response('gone', 403),
            };
        });

        $this->assertTrue(app(CoverCache::class)->capture($creative));

        $video = collect($asked)->first(fn (string $u): bool => str_contains($u, 'file/video/ad/info/'));
        $this->assertNotNull($video);
        $this->assertStringContainsString('advertiser_id=7000000000000000001', (string) $video);
        $this->assertStringContainsString('video_ids=["v-1"]', (string) $video);
        $this->assertSame('cached', $creative->fresh()?->cover_state);
        $this->assertSame($cover, $creative->fresh()?->thumbnail_url);
    }

    public function test_a_tiktok_image_the_cdn_refuses_is_renewed_through_file_image_ad_info(): void
    {
        $campaign = $this->campaign('tiktok', '7000000000000000002');
        $creative = $this->creative('tiktok', [
            'external_campaign_id' => $campaign->id, 'external_creative_id' => 'img-1', 'format' => 'image', 'raw' => ['media' => 'image'],
            // No expiry stated in the URL — only the CDN's 403 says it lapsed.
            'thumbnail_url' => 'https://p19-ad-site-sign-sg.ibyteimg.com/old.image',
        ]);
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            return match (true) {
                str_contains($url, 'file/image/ad/info/') && str_contains($url, 'image_ids=["img-1"]') => Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => [['image_id' => 'img-1', 'image_url' => 'https://p19-ad-site-sign-sg.ibyteimg.com/new.image']]]]),
                str_starts_with($url, 'https://p19-ad-site-sign-sg.ibyteimg.com/new.image') => Http::response(self::JPEG, 200, ['Content-Type' => 'image/webp']),
                default => Http::response('forbidden', 403),
            };
        });

        $this->assertTrue(app(CoverCache::class)->capture($creative));
        $this->assertStringEndsWith('.webp', (string) $creative->fresh()?->cover_path);
    }

    public function test_a_provider_that_returns_nothing_records_the_state_and_backs_off_without_a_retry_storm(): void
    {
        $campaign = $this->campaign('tiktok', '7000000000000000003');
        $creative = $this->creative('tiktok', [
            'external_campaign_id' => $campaign->id, 'external_creative_id' => 'v-9', 'format' => 'video', 'raw' => ['media' => 'video'],
            'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/old.jpg?x-expires='.now()->subHour()->getTimestamp(),
        ]);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['code' => 0, 'message' => 'OK', 'data' => ['list' => []]]);
        });

        $this->assertFalse(app(CoverCache::class)->capture($creative));
        $creative->refresh();
        $this->assertSame('failed', $creative->cover_state);
        $this->assertSame('link_expired', $creative->cover_error);
        $this->assertSame(1, $creative->cover_attempts);
        $this->assertTrue($creative->cover_next_attempt_at?->isFuture());
        $this->assertSame('cover_failed', app(CreativePresenter::class)->preview($creative)['state']);

        // The next passes leave it alone until its time comes.
        $this->assertFalse(CoverCache::wanted($creative));
        (new CacheCreativeCoverJob((string) $creative->getKey()))->handle(app(CoverCache::class));
        $this->artisan('creatives:backfill-covers', ['--execute' => true])->expectsOutputToContain('0 cover capture(s) queued.')->assertSuccessful();
        $this->assertSame(1, $calls, 'one question to the platform, not one per pass');

        // And when it comes, the wait has grown.
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertTrue(CoverCache::wanted($creative->fresh()));
        app(CoverCache::class)->capture($creative->fresh());
        $this->assertSame(2, $creative->fresh()?->cover_attempts);
        $this->assertTrue($creative->fresh()?->cover_next_attempt_at?->greaterThan(now()->addMinutes(59)));
        Carbon::setTestNow();
    }

    public function test_a_creative_the_platform_gives_no_still_is_recorded_as_none_and_never_queued(): void
    {
        Queue::fake();
        $creative = $this->creative('x', ['format' => 'text']);

        $this->assertFalse(app(CoverCache::class)->capture($creative));
        $this->assertSame('none', $creative->fresh()?->cover_state);
        $this->assertSame('none', CoverCache::stateOf($creative->fresh()));
        $this->artisan('creatives:backfill-covers', ['--execute' => true])->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // ── the backfill ────────────────────────────────────────────────────────────────────────────────────

    public function test_the_backfill_is_a_dry_run_by_default_and_reports_each_platform(): void
    {
        Queue::fake();
        $this->creative('tiktok', ['external_creative_id' => 'a', 'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/a.jpg']);
        $this->creative('tiktok', ['external_creative_id' => 'b', 'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/b.jpg', 'cover_path' => 'ab/b.jpg', 'cover_cached_at' => now()]);
        $this->creative('meta', ['external_creative_id' => 'c', 'thumbnail_url' => 'https://scontent.xx.fbcdn.net/c.jpg', 'cover_state' => 'failed', 'cover_error' => 'http_403', 'cover_attempts' => 1, 'cover_next_attempt_at' => now()->addHour()]);
        $this->creative('x', ['external_creative_id' => 'd', 'format' => 'text']);

        $this->artisan('creatives:backfill-covers')
            ->expectsTable(
                ['provider', 'creatives', 'with_source', 'cached', 'due', 'backing_off', 'recoverable', 'provider_gives_none'],
                [['meta', 1, 1, 0, 0, 1, 0, 0], ['tiktok', 2, 2, 1, 1, 0, 0, 0], ['x', 1, 0, 0, 0, 0, 0, 1]],
            )
            ->expectsTable(['provider', 'last_failure', 'creatives'], [['meta', 'http_403', 1]])
            ->expectsOutputToContain('Dry run: 1 cover capture(s) would be queued')
            ->assertSuccessful();
        Queue::assertNothingPushed();

        $this->artisan('creatives:backfill-covers', ['--execute' => true, '--per-minute' => 1])->expectsOutputToContain('1 cover capture(s) queued.')->assertSuccessful();
        Queue::assertPushed(CacheCreativeCoverJob::class, 1);
    }

    public function test_the_limit_is_taken_after_choosing_what_is_due_not_before(): void
    {
        Queue::fake();
        // Ten newer creatives that already have a copy, and one older one that does not.
        for ($i = 0; $i < 10; $i++) {
            $this->creative('snapchat', ['external_creative_id' => "s{$i}", 'asset_url' => "https://adsapi-media.snapchat.com/{$i}.jpg", 'cover_path' => "ab/{$i}.jpg", 'cover_cached_at' => now(), 'last_active_at' => now()]);
        }
        $this->creative('tiktok', ['external_creative_id' => 'old', 'thumbnail_url' => 'https://p16-common-sign.tiktokcdn.com/old.jpg', 'last_active_at' => now()->subMonth()]);

        $this->artisan('creatives:refresh-covers', ['--limit' => 5])->expectsOutputToContain('1 cover capture(s) queued.')->assertSuccessful();
        Queue::assertPushed(CacheCreativeCoverJob::class, 1);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $preview */
    private function assertNoProviderHost(array $preview): void
    {
        $json = (string) json_encode($preview, JSON_UNESCAPED_SLASHES);
        foreach (['fbcdn.net', 'facebook.com', 'tiktokcdn.com', 'ibyteimg.com'] as $host) {
            $this->assertStringNotContainsString($host, $json, "a {$host} link reached the browser's payload");
        }
    }

    /** @param array<string, mixed> $over */
    private function creative(string $provider, array $over = []): ExternalCreative
    {
        return ExternalCreative::create(array_merge([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'provider' => $provider,
            'external_creative_id' => $provider.'-1', 'name' => 'Hero', 'format' => 'image', 'status' => 'active',
        ], $over));
    }

    private function campaign(string $provider, string $advertiser): ExternalCampaign
    {
        $connection = app(TokenVault::class)->open(tenantId: $this->tenant->id, provider: $provider, tokens: new OAuthTokens('AT', 'RT', Carbon::now()->addDays(30)), connectionName: $provider);
        $account = ExternalAccount::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'provider_connection_id' => $connection->getKey(), 'provider' => $provider,
            'account_type' => 'ad_account', 'external_id' => $advertiser, 'name' => $advertiser, 'status' => 'active', 'discovered_at' => Carbon::now(),
        ]);

        return ExternalCampaign::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenant->id, 'project_id' => $this->project->id, 'external_account_id' => $account->id,
            'provider' => $provider, 'external_id' => 'c-'.$advertiser, 'name' => 'C', 'status' => 'active',
        ]);
    }
}
