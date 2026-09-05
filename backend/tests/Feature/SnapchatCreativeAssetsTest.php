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

    /**
     * SNAP-MEDIA-URL-001 — the ad account id belongs IN the media path.
     *
     * The call went to `adaccounts/get_media_by_ids` with no account, and Snapchat answered
     * «Request URL can not be correctly processed» for every chunk on every sweep. Production
     * measured it exactly: 1,038 creatives carried a media id, the call was made, 0 resolved — so
     * all 1,456 Content cards were blank while the structure run reported success.
     *
     * Asserting the URL rather than only the outcome, because a fake that answers any URL would let
     * this pass while production kept failing. That gap is what made the bug survive a deploy.
     */
    public function test_the_media_request_names_the_ad_account(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'WEB_VIEW'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/media/me-1.jpg'],
        ]);

        $this->creatives();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'adaccounts/act-1/get_media_by_ids'));

        // ...and never the account-less form the platform refuses.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'adaccounts/get_media_by_ids'));
    }

    /**
     * SNAP-MEDIA-BODY-001 — the body is `entity_ids`, and each entry is an OBJECT.
     *
     * This sent `{"media_ids": ["a"]}`; the documented shape is
     * `{"entity_ids": [{"id": "a"}]}` — a different key AND a different element shape, wrong twice.
     *
     * Production named it: with the URL corrected, the refusal changed from «Request URL can not be
     * correctly processed» to «Request BODY can not be correctly processed». Asserting the payload
     * rather than the outcome, for the same reason the URL is asserted — a fake accepts anything, so
     * only the shape we SEND can catch this before a deploy does.
     */
    public function test_the_media_request_sends_the_documented_body(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'WEB_VIEW'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/media/me-1.jpg'],
        ]);

        $this->creatives();

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'get_media_by_ids')) {
                return false;
            }

            $body = $request->data();

            return array_key_exists('entity_ids', $body)
                && $body['entity_ids'] === [['id' => 'me-1']];
        });

        // ...and never the flat array of bare ids the platform refuses.
        Http::assertNotSent(fn ($request): bool => array_key_exists('media_ids', (array) $request->data()));
    }

    /**
     * CONTENT-PREVIEW-SHAPES-001 — a COLLECTION is a collection, not a carousel.
     *
     * The map said `carousel`, and `CreativePresenter::kind()` has had a separate `collection` case
     * since it was written: a hero asset over a grid of tiles, which is not a swipeable strip of
     * equals. Snapchat's collections went down the wrong branch on every surface that reads a kind.
     */
    public function test_a_collection_is_reported_as_a_collection(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'COLLECTION'],
        ], [
            'me-1' => ['type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/hero.jpg'],
        ]);

        $this->assertSame('collection', $this->creatives()['cr-1']['format']);
    }

    /**
     * A type this `match` does not know is KEPT, not discarded.
     *
     * `default => null` threw the platform's answer away, and `ImportExternalStructure` then wrote
     * `'image'` for the blank — so a Snapchat COMPOSITE was stored as a still, and every surface
     * downstream behaved correctly on a claim we had invented. Keeping the word makes the gap
     * visible in the data instead of silent.
     */
    public function test_an_unmapped_creative_type_is_kept_rather_than_discarded(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'COMPOSITE'],
        ], [
            'me-1' => ['type' => 'VIDEO', 'download_link' => 'https://cf.snapchat.com/story.mp4'],
        ]);

        $creative = $this->creatives()['cr-1'];

        $this->assertSame('composite', $creative['format'], 'The platform said COMPOSITE; nothing else may be recorded.');
        $this->assertSame('https://cf.snapchat.com/story.mp4', $creative['video_url']);
    }

    /**
     * A creative that states NO type takes the one its media reports.
     *
     * The platform's own answer, one call later — not a guess. Filled only into a blank: a creative
     * that named its shape keeps it, because a COMPOSITE story whose first snap is a film is a
     * story, not a video.
     */
    public function test_an_unstated_format_is_answered_by_the_resolved_media(): void
    {
        Http::fake([
            '*get_media_by_ids*' => Http::response(['media' => [
                ['media' => ['id' => 'me-1', 'type' => 'VIDEO', 'download_link' => 'https://cf.snapchat.com/x.mp4']],
            ]], 200),
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => ['id' => 'cr-1', 'name' => 'No type', 'top_snap_media_id' => 'me-1']],
            ]], 200),
            '*' => Http::response([], 200),
        ]);

        $this->assertSame('video', $this->creatives()['cr-1']['format']);
    }

    /** ...and a stated shape is never overwritten by one of its parts. */
    public function test_a_stated_shape_survives_its_medias_type(): void
    {
        $this->fakeApi([
            'cr-1' => ['media' => 'me-1', 'type' => 'COLLECTION'],
        ], [
            'me-1' => ['type' => 'VIDEO', 'download_link' => 'https://cf.snapchat.com/hero.mp4'],
        ]);

        $this->assertSame('collection', $this->creatives()['cr-1']['format'], 'A collection whose hero is a film is still a collection.');
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
