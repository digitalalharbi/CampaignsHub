<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\SnapchatConnector;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CONTENT-COLLECTION-TILES-001 — a collection's tiles, from Snapchat's envelopes to the card a reader sees.
 *
 * ## The chain, and why each link is here
 *
 * A COLLECTION creative carries no tiles and no `top_snap_media_id`. A census of 625 live ones found
 * every single one carrying `collection_properties.interaction_zone_id`, and the zone is the only route
 * to the tiles:
 *
 *     creative.collection_properties.interaction_zone_id
 *       → GET interaction_zones/{id} → creative_element_ids
 *       → GET adaccounts/{id}/creative_elements
 *       → each element's media id → get_media_by_ids
 *       → cards → CreativePresenter
 *
 * ## The route cost three attempts, so it is pinned here
 *
 * `creativeelements` — no underscore — answered 404 three ways: singular, account-scoped collection,
 * and `get_creativeelements_by_ids`. Snapchat's published contract spells the entity
 * `creative_elements`. A test that accepted either spelling would let the bug back in, so the fake
 * below answers ONLY the underscored path and the unmatched fallback returns an error envelope.
 */
final class SnapchatCollectionTilesTest extends TestCase
{
    use RefreshDatabase;

    private function connector(): SnapchatConnector
    {
        // Without these the connector refuses before it asks anything — «awaiting credentials» — and a
        // test would be asserting the refusal rather than the chain.
        foreach (PlatformCredentials::for('snapchat')->requires() as $key) {
            config()->set("ad_platforms.platforms.snapchat.{$key}", "test-{$key}");
        }

        $tenant = Tenant::create(['name' => 'Tiles', 'slug' => 'tiles-'.uniqid(), 'status' => 'active']);

        $connection = app(TokenVault::class)->open(
            tenantId: (string) $tenant->getKey(),
            provider: 'snapchat',
            tokens: new OAuthTokens('AT', 'RT', now()->addDays(30)),
            connectionName: 'snapchat',
        );

        return (new SnapchatConnector)->withConnection($connection);
    }

    /** Snapchat's own envelopes, at every rung of the chain. */
    private function fakePlatform(array $overrides = []): void
    {
        Http::fake([...[
            '*/campaigns*' => Http::response(['campaigns' => [
                ['campaign' => ['id' => 'cmp-1', 'name' => 'Collection', 'status' => 'ACTIVE', 'objective' => 'WEB_CONVERSION']],
            ]], 200),
            '*/creatives*' => Http::response(['creatives' => [
                ['creative' => [
                    'id' => 'cr-col', 'name' => 'Ramadan collection', 'type' => 'COLLECTION',
                    // No top_snap_media_id — this is the shape production actually carries.
                    'collection_properties' => ['interaction_zone_id' => 'zone-1'],
                ]],
            ]], 200),
            '*interaction_zones/zone-1*' => Http::response(['interaction_zones' => [
                ['interaction_zone' => [
                    'id' => 'zone-1',
                    'ad_account_id' => 'act-1',
                    'creative_element_ids' => ['el-1', 'el-2'],
                ]],
            ]], 200),
            '*adaccounts/act-1/creative_elements*' => Http::response(['creative_elements' => [
                ['creative_element' => [
                    'id' => 'el-1', 'name' => 'Tile one', 'type' => 'BUTTON', 'interaction_type' => 'WEB_VIEW',
                    'title' => 'عباية سوداء', 'description' => 'قطن',
                    'button_properties' => ['button_overlay_media_id' => 'me-el-1'],
                    'web_view_properties' => ['url' => 'https://shop.example/abaya'],
                ]],
                ['creative_element' => [
                    'id' => 'el-2', 'name' => 'Tile two', 'type' => 'BUTTON', 'interaction_type' => 'DEEP_LINK',
                    'title' => 'Second tile',
                    'deep_link_properties' => ['icon_media_id' => 'me-el-2', 'deep_link_uri' => 'shop://p/2'],
                ]],
            ]], 200),
            '*get_media_by_ids*' => Http::response(['media' => [
                ['media' => ['id' => 'me-el-1', 'type' => 'IMAGE', 'download_link' => 'https://cf.snapchat.com/el-1.jpg']],
                ['media' => ['id' => 'me-el-2', 'type' => 'VIDEO', 'download_link' => 'https://cf.snapchat.com/el-2.mp4']],
            ]], 200),
            '*/ads*' => Http::response(['ads' => [
                ['ad' => ['id' => 'ad-col', 'ad_squad_id' => 'sq-1', 'name' => 'Collection ad', 'status' => 'ACTIVE', 'creative_id' => 'cr-col']],
            ]], 200),
            '*/adsquads*' => Http::response(['adsquads' => []], 200),
        ], ...$overrides]);
    }

    /**
     * The one collection creative the connector produced.
     *
     * Through `syncAds`, because that is the real path: Snapchat's ads name a creative by id and the
     * creative list is fetched and joined inside `fetchAds`. Driving a private method instead would
     * prove a shape nothing in production reaches.
     *
     * @return array<string,mixed>
     */
    private function collection(): array
    {
        $result = $this->connector()->syncAds('act-1');
        $rows = $result->records;

        foreach ($rows as $row) {
            $creative = $row['creative'] ?? null;

            if (is_array($creative) && ($creative['external_id'] ?? null) === 'cr-col') {
                return $creative;
            }
        }

        self::fail('no collection creative reached an ad row; success='.var_export($result->success, true)
            .' message='.($result->message ?? '—').' rows='.json_encode($rows));
    }

    public function test_the_tiles_arrive_as_cards_with_their_copy_and_media(): void
    {
        $this->fakePlatform();

        $cards = $this->collection()['cards'] ?? null;

        self::assertIsArray($cards, 'a collection creative reached the importer with no cards at all');
        self::assertCount(2, $cards);

        self::assertSame('عباية سوداء', $cards[0]['headline']);
        self::assertSame('قطن', $cards[0]['body']);
        self::assertSame('https://cf.snapchat.com/el-1.jpg', $cards[0]['image_url']);
        self::assertSame('https://shop.example/abaya', $cards[0]['destination_url']);

        // A video tile's file goes in the video column, never the image one.
        self::assertSame('https://cf.snapchat.com/el-2.mp4', $cards[1]['video_url']);
        self::assertArrayNotHasKey('image_url', $cards[1]);
        self::assertSame('shop://p/2', $cards[1]['destination_url']);
    }

    /**
     * The underscore is the whole finding, so the wrong spelling must not work.
     *
     * Three production attempts spelled it `creativeelements` and all three 404'd. A fake that matched
     * either spelling would make this test pass against the bug.
     */
    public function test_the_unpunctuated_spelling_is_not_accepted(): void
    {
        $this->fakePlatform([
            // Only the WRONG path answers; the right one refuses, as production did.
            '*adaccounts/act-1/creativeelements*' => Http::response(['creativeelements' => [
                ['creative_element' => ['id' => 'el-1', 'title' => 'Tile one']],
            ]], 200),
            '*adaccounts/act-1/creative_elements*' => Http::response([
                'request_status' => 'ERROR', 'debug_message' => 'Resource can not be found',
            ], 404),
        ]);

        $row = $this->collection();

        self::assertArrayNotHasKey('cards', $row, 'the old spelling produced cards, so the route is not pinned');
    }

    /** A zone that cannot be read leaves the creative exactly as it was — never a fabricated tile. */
    public function test_a_refused_zone_reports_nothing_rather_than_an_empty_collection(): void
    {
        $this->fakePlatform([
            '*interaction_zones/zone-1*' => Http::response(['debug_message' => 'Resource can not be found'], 404),
        ]);

        $row = $this->collection();

        self::assertArrayNotHasKey('cards', $row);
    }

    /**
     * `cards` absent and `cards` empty are different sentences, and the presenter says them apart.
     *
     * Absent is «the platform reported nothing»; an empty list would be «it has none». A collection
     * whose zone refused must produce the first, or the card claims knowledge the sync never had.
     */
    public function test_the_presenter_tells_reported_nothing_from_has_none(): void
    {
        $unknown = new ExternalCreative;
        $unknown->cards = null;

        $known = new ExternalCreative;
        $known->cards = [];

        $presenter = app(CreativePresenter::class);
        $read = function (ExternalCreative $c) use ($presenter): array {
            $method = new \ReflectionMethod($presenter, 'cards');

            return $method->invoke($presenter, $c, 'available');
        };

        self::assertFalse($read($unknown)['cards_reported'], 'a creative nobody could read must not claim to have none');
        self::assertTrue($read($known)['cards_reported']);
    }

    /** Bounded: a zone is one request each, and an account can hold hundreds of collections. */
    public function test_the_zone_reads_are_bounded(): void
    {
        $bound = new \ReflectionClassConstant(SnapchatConnector::class, 'ZONES_PER_SYNC');

        self::assertLessThanOrEqual(100, $bound->getValue(), 'an unbounded zone sweep turns a working sync into a throttled one');
        self::assertGreaterThan(0, $bound->getValue());
    }
}
