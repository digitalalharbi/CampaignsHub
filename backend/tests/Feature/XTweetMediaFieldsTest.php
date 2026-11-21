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
 * X-MEDIA-FIELDS-002 — Owner, 2026-11-21: on the Ettifaq live report every X content item showed its words and
 * «الصورة غير متاحة». The stored creatives were `text_card` with no still: the connector read a tweet's picture only
 * from `media_url_https`, while X documents a tweet's single image at `entities.media[0].media_url`
 * (docs.x.com «Identifying media»), and read a card's picture only from a few top-level names.
 */
final class XTweetMediaFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ?ProviderConnection $connection = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'xm-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * @param  list<array<string, mixed>>  $tweets
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>|null  $library
     * @param  (callable(string): array<string, mixed>)|null  $embed  the public embed read's answer; null = 404
     * @return array<string, array<string, mixed>>
     */
    private function creatives(array $tweets, array $cards = [], ?array $library = null, ?callable $embed = null): array
    {
        Http::fake(function (Request $r) use ($tweets, $cards, $library, $embed) {
            $u = $r->url();
            if (str_contains($u, 'cdn.syndication.twimg.com')) {
                return $embed === null ? Http::response('', 404) : Http::response($embed($u));
            }
            if (str_contains($u, '/promoted_tweets')) {
                return Http::response(['data' => array_map(static fn (array $t) => ['id' => 'pt'.$t['id_str'], 'tweet_id' => $t['id_str'], 'line_item_id' => 'li1', 'entity_status' => 'ACTIVE'], $tweets)]);
            }
            if (str_contains($u, '/cards/all')) {
                parse_str((string) parse_url($u, PHP_URL_QUERY), $q);

                // As X answers: legacy cards (the composer's website cards) only with include_legacy_cards=true.
                return Http::response(['data' => array_values(array_filter(
                    $cards,
                    static fn (array $c) => ! ($c['_legacy'] ?? false) || ($q['include_legacy_cards'] ?? 'false') === 'true',
                ))]);
            }
            if (str_contains($u, '/media_library/')) {
                return $library === null ? Http::response(['errors' => [['code' => 'NOT_FOUND']]], 404) : Http::response(['data' => $library]);
            }
            if (str_contains($u, '/accounts/acc1/tweets')) {
                return Http::response(['next_cursor' => null, 'data' => $tweets]);
            }

            return Http::response(['data' => []]);
        });

        $c = $this->bound();
        $ads = (new \ReflectionMethod($c, 'fetchAds'))->invoke($c, app(TokenVault::class)->stored($this->connection), 'acc1');
        $out = [];
        foreach ($ads as $ad) {
            $out[$ad['creative']['external_id']] = $ad['creative'];
        }

        return $out;
    }

    public function test_a_tweet_image_given_only_as_media_url_is_read_on_https(): void
    {
        $c = $this->creatives([[
            'id_str' => '2116860102603088099', 'full_text' => 'المواجهة أقوى بحضورك!',
            'entities' => ['media' => [['type' => 'photo', 'media_url' => 'http://pbs.twimg.com/media/ettifaq.jpg']]],
        ]]);

        $this->assertSame('image', $c['2116860102603088099']['format']);
        $this->assertSame('https://pbs.twimg.com/media/ettifaq.jpg', $c['2116860102603088099']['thumbnail_url'] ?? null);
    }

    public function test_a_component_card_takes_its_picture_inline_and_its_words_from_details(): void
    {
        $c = $this->creatives(
            [['id_str' => '21', 'full_text' => 'احجز مكانك', 'card_uri' => 'card://21']],
            [[
                'card_uri' => 'card://21', 'card_type' => 'IMAGE_WEBSITE',
                'components' => [
                    ['type' => 'MEDIA', 'media_key' => '3_21'],
                    ['type' => 'DETAILS', 'title' => 'تذاكر المباراة', 'destination' => ['type' => 'WEBSITE', 'url' => 'https://tickets.example/match']],
                ],
                'media_metadata' => ['3_21' => ['type' => 'IMAGE', 'url' => 'https://pbs.twimg.com/media/card21.jpg']],
            ]],
        );

        $this->assertSame('image', $c['21']['format']);
        $this->assertSame('https://pbs.twimg.com/media/card21.jpg', $c['21']['thumbnail_url'] ?? null);
        $this->assertSame('تذاكر المباراة', $c['21']['headline'] ?? null);
        $this->assertSame('https://tickets.example/match', $c['21']['destination_url'] ?? null);
    }

    public function test_a_video_card_poster_is_a_film_and_its_video_link_is_never_a_still(): void
    {
        $c = $this->creatives(
            [['id_str' => '31', 'full_text' => 'شاهد', 'card_uri' => 'card://31']],
            [[
                'card_uri' => 'card://31', 'card_type' => 'VIDEO_WEBSITE',
                'media_url' => 'https://video.twimg.com/amplify_video/vmap/31.vmap',
                'poster_media_url' => 'https://pbs.twimg.com/amplify_video_thumb/31/img/p.jpg',
            ]],
        );

        $this->assertSame('video', $c['31']['format']);
        $this->assertSame('https://pbs.twimg.com/amplify_video_thumb/31/img/p.jpg', $c['31']['thumbnail_url'] ?? null);
        $this->assertArrayNotHasKey('asset_url', $c['31']);
    }

    /**
     * X-LEGACY-CARDS-001 — Production 2026-11-21, Ettifaq: 9 promoted tweets, each with a card_uri and no media of its own;
     * `cards/all` answered `data: []` because `include_legacy_cards` defaults to false.
     */
    public function test_a_composer_website_card_is_asked_for_as_a_legacy_card(): void
    {
        $c = $this->creatives(
            [['id_str' => '2116860102603088099', 'full_text' => 'المواجهة أقوى بحضورك!', 'card_uri' => 'card://2116860099381649408']],
            [[
                '_legacy' => true,
                'card_uri' => 'card://2116860099381649408', 'card_type' => 'WEBSITE',
                'image' => 'https://pbs.twimg.com/card_img/2116860099381649408/legacy.jpg',
                'website_title' => 'تذاكر الاتفاق',
                'website_dest_url' => 'https://webook.com/ettifaq',
            ]],
        );

        $this->assertSame('image', $c['2116860102603088099']['format']);
        $this->assertSame('https://pbs.twimg.com/card_img/2116860099381649408/legacy.jpg', $c['2116860102603088099']['thumbnail_url'] ?? null);
        $this->assertSame('تذاكر الاتفاق', $c['2116860102603088099']['headline'] ?? null);
        $this->assertSame('https://webook.com/ettifaq', $c['2116860102603088099']['destination_url'] ?? null);
    }

    /**
     * X-CARD-IMAGE-FALLBACK-001 — the Ads API returns no card (Ettifaq, 2026-11-21); the public embed read carries it.
     */
    public function test_a_card_the_ads_api_hides_takes_its_picture_from_the_embed_read(): void
    {
        $embedAsked = [];
        $c = $this->creatives(
            [['id_str' => '2116860102603088099', 'full_text' => 'المواجهة أقوى بحضورك!', 'card_uri' => 'card://2116860099381649408']],
            [],
            null,
            function (string $url) use (&$embedAsked) {
                $embedAsked[] = $url;

                return ['card' => ['name' => 'summary_large_image', 'binding_values' => [
                    'photo_image_full_size_large' => ['type' => 'IMAGE', 'image_value' => ['url' => 'https://pbs.twimg.com/card_img/2116860099381649408/embed.jpg']],
                ]]];
            },
        );

        $this->assertSame('image', $c['2116860102603088099']['format']);
        $this->assertSame('https://pbs.twimg.com/card_img/2116860099381649408/embed.jpg', $c['2116860102603088099']['thumbnail_url'] ?? null);
        $this->assertSame('x_embed', $c['2116860102603088099']['raw']['media_source'] ?? null);
        $this->assertCount(1, $embedAsked);
        $this->assertStringContainsString('id=2116860102603088099', $embedAsked[0]);
    }

    public function test_an_embed_read_with_a_foreign_host_is_refused(): void
    {
        $c = $this->creatives(
            [['id_str' => '51', 'full_text' => 'نص', 'card_uri' => 'card://51']],
            [],
            null,
            fn () => ['photos' => [['url' => 'https://evil.example/p.jpg']]],
        );

        $this->assertSame('text_card', $c['51']['format']);
        $this->assertArrayNotHasKey('thumbnail_url', $c['51']);
    }

    public function test_a_card_x_gives_no_picture_for_stays_text_and_invents_nothing(): void
    {
        $c = $this->creatives(
            [['id_str' => '41', 'full_text' => 'نص فقط', 'card_uri' => 'card://41']],
            [['card_uri' => 'card://41', 'components' => [['type' => 'MEDIA', 'media_key' => '3_41']], 'media_url' => 'https://video.twimg.com/x/41.mp4']],
        );

        $this->assertSame('text_card', $c['41']['format']);
        $this->assertArrayNotHasKey('thumbnail_url', $c['41']);
    }

    private function bound(): ApiAdvertisingConnector
    {
        foreach (PlatformCredentials::for('x')->requires() as $key) {
            config()->set("ad_platforms.platforms.x.{$key}", "test-{$key}");
        }

        $this->connection = app(TokenVault::class)->open(
            tenantId: $this->tenant->id,
            provider: 'x',
            tokens: new OAuthTokens('AT-secret', 'RT', Carbon::now()->addDay(), null, ['token_secret' => 'ts-secret']),
            connectionName: 'x',
        );

        return app(AdvertisingConnectorRegistry::class)->get('x')->withConnection($this->connection);
    }
}
