<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\Providers\SnapchatConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SNAP-CREATIVE-ASSETS-001 — Snapchat does expose the asset; we were not asking.
 *
 * Every Snapchat creative rendered «لا تتيح هذه المنصة أصل المحتوى» — «this platform does not expose
 * the creative's asset». That is a claim ABOUT SNAPCHAT, and it was false. The creative body carries
 * `top_snap_media_id` and never the file, and nothing followed the id.
 *
 * The current API (verified against developers.snap.com, August 2026):
 *   - `GET /v1/media/{id}` returns `download_link`, `type`, `media_status`
 *   - `POST /adaccounts/get_media_by_ids` takes up to 2,000 ids — 1,451 creatives in one call
 *     rather than 1,451 round trips against a rate-limited API.
 */
final class SnapchatCreativeAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_creatives_media_is_resolved_into_an_asset_url(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'WEB_VIEW'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/media/me-1.jpg'],
        ]);

        $creative = $this->creatives()['cr-1'];

        $this->assertSame('https://cf.snapchat.com/media/me-1.jpg', $creative['asset_url']);
        $this->assertArrayNotHasKey('video_url', $creative, 'An image is not a video.');
    }

    /** A video's file belongs in the video column — an MP4 in the image slot renders as a broken picture. */
    public function test_a_video_media_lands_in_the_video_column(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'SNAP_AD'],
        ], [
            'me-1' => ['type' => 'VIDEO', 'download_link' => 'https://cf.snapchat.com/media/me-1.mp4'],
        ]);

        $creative = $this->creatives()['cr-1'];

        $this->assertSame('https://cf.snapchat.com/media/me-1.mp4', $creative['video_url']);
        $this->assertArrayNotHasKey('asset_url', $creative);
    }

    /**
     * A URL carrying a credential is NOT stored.
     *
     * `CreativePresenter` has a `withheld` state for exactly this, and the standing rule is that a
     * provider token must never reach the browser or a log line. Snapchat signs with an opaque
     * signature rather than an access token, so this is a guard rather than an expectation — which
     * is precisely what makes it safe to be wrong about.
     */
    public function test_a_credential_bearing_url_is_refused_rather_than_stored(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'WEB_VIEW'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/m.jpg?access_token=SECRET'],
        ]);

        $creative = $this->creatives()['cr-1'];

        $this->assertArrayNotHasKey('asset_url', $creative);
        $this->assertArrayNotHasKey('video_url', $creative);
    }

    /** One batch call for the whole account, not one per creative. */
    public function test_every_media_is_fetched_in_a_single_batch(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'WEB_VIEW'],
            'cr-2' => ['media' => 'me-2', 'type' => 'WEB_VIEW'],
            'cr-3' => ['media' => 'me-3', 'type' => 'WEB_VIEW'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/1.jpg'],
            'me-2' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/2.jpg'],
            'me-3' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/3.jpg'],
        ]);

        $this->creatives();

        $batches = 0;
        Http::recorded(function ($request) use (&$batches) {
            if (str_contains($request->url(), 'get_media_by_ids')) {
                $batches++;
            }

            return true;
        });

        $this->assertSame(1, $batches, 'Three creatives must not cost three media round trips.');
    }

    /**
     * A media lookup that fails costs the picture and nothing else.
     *
     * The asset is an enrichment. Failing the structure sweep over it would lose the campaigns, ad
     * squads and ads that came back in the same run.
     */
    public function test_a_failed_media_lookup_leaves_the_creative_intact(): void
    {
        Http::fake([
            '*get_media_by_ids*' => Http::response(['error' => 'rate limited'], 429),
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-1', 'name' => 'Summer hero', 'type' => 'SNAP_AD', 'top_snap_media_id' => 'me-1']],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $creative = $this->creatives()['cr-1'];

        $this->assertSame('Summer hero', $creative['name']);
        $this->assertArrayNotHasKey('asset_url', $creative);
    }

    /** A creative with no media id is not a failure and costs no call. */
    public function test_a_creative_without_media_asks_for_nothing(): void
    {
        Http::fake([
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-1', 'name' => 'No asset', 'type' => 'SNAP_AD']],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->creatives();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'get_media_by_ids'));
    }

    /** @return array<string, array<string, mixed>> */
    private function creatives(): array
    {
        $connector = app(SnapchatConnector::class);

        $method = new \ReflectionMethod($connector, 'creativesById');
        $method->setAccessible(true);

        return $method->invoke($connector, new OAuthTokens('AT', 'RT', now()->addDay()), 'act-1');
    }

    /**
     * @param  array<string, array{media:string, type:string}>  $creatives
     * @param  array<string, array{type:string, download_link:string}>  $media
     */
    private function fakeApi(array $creatives, array $media): void
    {
        Http::fake([
            '*get_media_by_ids*' => Http::response(['media' => array_map(
                static fn (string $id, array $m): array => ['media' => ['id' => $id, ...$m]],
                array_keys($media),
                array_values($media),
            )], 200),
            '*/creatives*' => Http::response(['creatives' => array_map(
                static fn (string $id, array $c): array => ['creative' => [
                    'id' => $id, 'name' => "Creative {$id}", 'type' => $c['type'], 'top_snap_media_id' => $c['media'],
                ]],
                array_keys($creatives),
                array_values($creatives),
            )], 200),
            '*' => Http::response([], 200),
        ]);
    }
}
