<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Models\ProviderConnection;
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
 * CREATIVE-SYNC-MEDIA-001 — what X and LinkedIn creatives show, within the access each connector holds.
 *
 * X: the Ads API's own `accounts/:id/tweets` read (same host, same signing). LinkedIn: only a Text Ad states
 * its words in the creatives response; a sponsored post's picture needs `r_organization_social`, which this
 * connector does not hold, so those stay null. IMPLEMENTED_NOT_VERIFIED — no live account in CI.
 */
final class XAndLinkedInCreativeFaceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'x-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /** @return list<array<string,mixed>> */
    private function xAds(array $tweets, bool $refuse = false): array
    {
        Http::fake(function (Request $r) use ($tweets, $refuse) {
            $u = $r->url();

            if (str_contains($u, '/promoted_tweets')) {
                return Http::response(['data' => [['id' => 'pt1', 'tweet_id' => '900', 'line_item_id' => 'li1', 'entity_status' => 'ACTIVE']]]);
            }
            if (str_contains($u, '/tweets')) {
                return $refuse ? Http::response(['errors' => []], 403) : Http::response(['data' => $tweets]);
            }

            return Http::response(['data' => []]);
        });

        $c = $this->bound('x');
        $m = new \ReflectionMethod($c, 'fetchAds');

        return $m->invoke($c, app(TokenVault::class)->stored($this->connection), 'acc1');
    }

    public function test_a_promoted_tweet_with_a_photo_gets_its_text_and_picture(): void
    {
        $ads = $this->xAds([['id_str' => '900', 'full_text' => 'Summer drop is here', 'entities' => ['media' => [['type' => 'photo', 'media_url_https' => 'https://pbs.twimg.com/media/abc.jpg']]]]]);

        $c = $ads[0]['creative'];
        $this->assertSame('image', $c['format']);
        $this->assertSame('https://pbs.twimg.com/media/abc.jpg', $c['thumbnail_url']);
        $this->assertSame('https://pbs.twimg.com/media/abc.jpg', $c['asset_url']);
        $this->assertSame('Summer drop is here', $c['body']);
        $this->assertSame('900', $c['external_id']);
    }

    public function test_a_tweet_film_is_a_poster_frame_never_a_file(): void
    {
        $ads = $this->xAds([['id_str' => '900', 'full_text' => 'Watch', 'extended_entities' => ['media' => [['type' => 'video', 'media_url_https' => 'https://pbs.twimg.com/ext_tw_video_thumb/1.jpg']]]]]);

        $c = $ads[0]['creative'];
        $this->assertSame('video', $c['format']);
        $this->assertSame('https://pbs.twimg.com/ext_tw_video_thumb/1.jpg', $c['thumbnail_url']);
        $this->assertArrayNotHasKey('asset_url', $c);
    }

    public function test_a_text_only_tweet_is_a_text_card(): void
    {
        $ads = $this->xAds([['id_str' => '900', 'full_text' => 'Just words']]);

        $this->assertSame('text_card', $ads[0]['creative']['format']);
        $this->assertSame('Just words', $ads[0]['creative']['body']);
        $this->assertArrayNotHasKey('thumbnail_url', $ads[0]['creative']);
    }

    public function test_a_refused_tweet_read_keeps_the_ad_with_its_old_minimal_creative(): void
    {
        $ads = $this->xAds([], true);

        $this->assertSame('text', $ads[0]['creative']['format']);
        $this->assertSame('900', $ads[0]['creative']['external_id']);
    }

    public function test_a_linkedin_text_ad_states_its_words_and_a_post_creative_states_nothing_invented(): void
    {
        Http::fake(['api.linkedin.com/*' => Http::response(['elements' => [
            ['id' => 'urn:li:sponsoredCreative:1', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                'content' => ['textAd' => ['headline' => 'Hire fast', 'description' => 'Post a job today', 'landingPage' => 'https://jobs.example']]],
            ['id' => 'urn:li:sponsoredCreative:2', 'campaign' => 'urn:li:sponsoredCampaign:5', 'intendedStatus' => 'ACTIVE',
                'content' => ['reference' => 'urn:li:share:77']],
        ]])]);

        $c = $this->bound('linkedin');
        $ads = (new \ReflectionMethod($c, 'fetchAds'))->invoke($c, app(TokenVault::class)->stored($this->connection), '123');

        $this->assertSame('text_card', $ads[0]['creative']['format']);
        $this->assertSame('Hire fast', $ads[0]['creative']['headline']);
        $this->assertSame('Post a job today', $ads[0]['creative']['body']);
        $this->assertSame('https://jobs.example', $ads[0]['destination_url']);
        $this->assertArrayNotHasKey('creative', $ads[1], 'a sponsored post has no readable picture under r_ads');
    }

    private ?ProviderConnection $connection = null;

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
