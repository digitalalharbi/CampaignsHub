<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProviderConnection;
use App\Domains\Integrations\OAuth\OAuthTokens;
use App\Domains\Integrations\OAuth\PlatformCredentials;
use App\Domains\Integrations\OAuth\TokenVault;
use App\Domains\Integrations\Providers\GoogleAdsConnector;
use App\Domains\Integrations\Registry\AdvertisingConnectorRegistry;
use App\Domains\Tenancy\Context\TenantContext;
use App\Domains\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * CREATIVE-SYNC-MEDIA-001 — Google stored `creative => null` for every ad, so no Google ad had a cover.
 *
 * The bodies below are `searchStream` answers in Google's documented REST shape (camelCase fields,
 * asset references by resource name). No Google account is reachable from CI: IMPLEMENTED_NOT_VERIFIED.
 */
final class GoogleAdsCreativeMediaTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $adFace  the `ad` object of the creative query, beside the id */
    private function ads(array $adFace, array $assets = [], bool $mediaQueryRefused = false): array
    {
        Http::fake(function (Request $request) use ($adFace, $assets, $mediaQueryRefused) {
            $q = (string) ($request->data()['query'] ?? '');

            if (str_contains($q, 'FROM asset')) {
                return Http::response([['results' => $assets]]);
            }

            if (str_contains($q, 'responsive_display_ad')) {
                return $mediaQueryRefused
                    ? Http::response([['error' => ['code' => 400, 'message' => 'Unrecognized field in the query']]], 400)
                    : Http::response([['results' => [['adGroupAd' => ['ad' => ['id' => '555'] + $adFace]]]]]);
            }

            return Http::response([['results' => [[
                'adGroupAd' => ['ad' => ['id' => '555', 'type' => 'RESPONSIVE_DISPLAY_AD', 'finalUrls' => ['https://shop.example']], 'status' => 'ENABLED'],
                'adGroup' => ['id' => '222'], 'campaign' => ['id' => '111'],
            ]]]]);
        });

        $method = new \ReflectionMethod(GoogleAdsConnector::class, 'fetchAds');

        return $method->invoke($this->google(), app(TokenVault::class)->stored($this->connection), '1111111111');
    }

    public function test_a_responsive_display_ad_takes_its_marketing_image_from_the_asset(): void
    {
        $ads = $this->ads(
            ['responsiveDisplayAd' => [
                'headlines' => [['text' => 'Big sale']], 'descriptions' => [['text' => 'Today only']],
                'marketingImages' => [['asset' => 'customers/1/assets/9']],
            ]],
            [['asset' => ['resourceName' => 'customers/1/assets/9', 'imageAsset' => ['fullSize' => ['url' => 'https://tpc.googlesyndication.com/simgad/1', 'widthPixels' => '1200']]]]],
        );

        $creative = $ads[0]['creative'];
        $this->assertSame('image', $creative['format']);
        $this->assertSame('https://tpc.googlesyndication.com/simgad/1', $creative['asset_url']);
        $this->assertSame('https://tpc.googlesyndication.com/simgad/1', $creative['thumbnail_url']);
        $this->assertSame('Big sale', $creative['headline']);
        $this->assertSame('Today only', $creative['body']);
        $this->assertSame('555', $creative['external_id']);
        $this->assertSame('https://shop.example', $ads[0]['destination_url'], 'the ad list is untouched');
    }

    public function test_a_video_ad_gets_its_youtube_cover_and_watch_page(): void
    {
        $ads = $this->ads(
            ['videoAd' => ['video' => ['asset' => 'customers/1/assets/7']]],
            [['asset' => ['resourceName' => 'customers/1/assets/7', 'youtubeVideoAsset' => ['youtubeVideoId' => 'dQw4w9WgXcQ']]]],
        );

        $creative = $ads[0]['creative'];
        $this->assertSame('video', $creative['format']);
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $creative['thumbnail_url']);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $creative['preview_url']);
        $this->assertArrayNotHasKey('asset_url', $creative, 'a YouTube page is not a file');
        $this->assertSame('dQw4w9WgXcQ', $creative['raw']['youtube_video_id']);
    }

    public function test_an_image_ad_uses_its_own_links(): void
    {
        $ads = $this->ads(['imageAd' => ['imageUrl' => 'https://tpc.googlesyndication.com/simgad/2', 'previewImageUrl' => 'https://tpc.googlesyndication.com/simgad/2p']]);

        $this->assertSame('https://tpc.googlesyndication.com/simgad/2', $ads[0]['creative']['asset_url']);
        $this->assertSame('https://tpc.googlesyndication.com/simgad/2p', $ads[0]['creative']['thumbnail_url']);
    }

    public function test_a_responsive_search_ad_is_a_text_card_with_no_invented_picture(): void
    {
        $ads = $this->ads(['responsiveSearchAd' => [
            'headlines' => [['text' => 'Buy shoes'], ['text' => 'Free delivery']],
            'descriptions' => [['text' => 'Best prices in Riyadh']],
        ]]);

        $creative = $ads[0]['creative'];
        $this->assertSame('text_card', $creative['format']);
        $this->assertSame('Buy shoes', $creative['headline']);
        $this->assertSame('Best prices in Riyadh', $creative['body']);
        $this->assertArrayNotHasKey('thumbnail_url', $creative);
        $this->assertArrayNotHasKey('asset_url', $creative);
    }

    public function test_an_unresolved_asset_leaves_no_picture(): void
    {
        $ads = $this->ads(['responsiveDisplayAd' => ['marketingImages' => [['asset' => 'customers/1/assets/404']]]]);
        $this->assertArrayNotHasKey('creative', $ads[0]);
    }

    public function test_a_refused_creative_query_costs_the_pictures_never_the_ads(): void
    {
        $ads = $this->ads([], [], true);

        $this->assertCount(1, $ads);
        $this->assertSame('555', $ads[0]['external_id']);
        $this->assertArrayNotHasKey('creative', $ads[0]);
    }

    private ?ProviderConnection $connection = null;

    private function google(): GoogleAdsConnector
    {
        $tenant = Tenant::create(['name' => 'G', 'slug' => 'g-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($tenant->id);

        foreach (PlatformCredentials::for('google')->requires() as $key) {
            config()->set("ad_platforms.platforms.google.{$key}", "test-{$key}");
        }

        $this->connection = app(TokenVault::class)->open(
            tenantId: $tenant->id,
            provider: 'google',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay()),
            connectionName: 'google',
        );

        /** @var GoogleAdsConnector $connector */
        $connector = app(AdvertisingConnectorRegistry::class)->get('google')->withConnection($this->connection);

        return $connector;
    }
}
