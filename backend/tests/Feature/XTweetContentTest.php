<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
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
 * X-TWEET-CONTENT-001 — the promoted tweets X returned no row for.
 *
 * Production 2026-11-13 (read-only): 49 X creatives in three projects stored as `format=text` with nothing else — the
 * connector's fallback for a tweet id `GET accounts/:account_id/tweets` did not return. The bodies below follow the shapes
 * documented at docs.x.com/x-ads-api/creatives/reference («Tweets», «Cards Fetch», media library) and
 * docs.x.com/x-ads-api/campaign-management/reference («Promotable Users»). The fake answers exactly as the documented
 * defaults would: with no `timeline_type` only nullcasted tweets, with no `include_mentions_and_replies` no replies, and
 * with no `user_id` only the FULL promotable user's tweets.
 */
final class XTweetContentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private ?ProviderConnection $connection = null;

    /** @var list<array<string, mixed>> */
    private array $tweetCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['name' => 'X', 'slug' => 'xt-'.uniqid(), 'status' => 'active']);
        app(TenantContext::class)->setTenantId($this->tenant->id);
    }

    /**
     * The account: tweet 100 is promoted-only (nullcast) by the FULL user; 200 is the FULL user's ORGANIC post; 300 is an organic
     * reply; 400 belongs to a RETWEETS_ONLY promotable user (id 777); 500 is a website card with no media in its entities;
     * 600 is nowhere (deleted).
     *
     * @return list<array<string,mixed>>
     */
    private function ads(): array
    {
        $tweet = static fn (string $id, string $text, array $extra = []): array => array_replace([
            'id' => (int) $id, 'id_str' => $id, 'tweet_id' => $id, 'full_text' => $text, 'tweet_type' => 'PUBLISHED',
            'entities' => ['hashtags' => [], 'symbols' => [], 'user_mentions' => [], 'urls' => []], 'user' => ['id_str' => '111'],
        ], $extra);

        $byUser = [
            'full' => [
                '100' => ['nullcast' => true, 'reply' => false, 'body' => $tweet('100', 'Promoted only', ['nullcast' => true, 'extended_entities' => ['media' => [['type' => 'photo', 'media_url_https' => 'https://pbs.twimg.com/media/p100.jpg']]]])],
                '200' => ['nullcast' => false, 'reply' => false, 'body' => $tweet('200', 'Our organic post', ['nullcast' => false, 'extended_entities' => ['media' => [['type' => 'video', 'media_url_https' => 'https://pbs.twimg.com/ext_tw_video_thumb/200.jpg']]]])],
                '300' => ['nullcast' => false, 'reply' => true, 'body' => $tweet('300', '@someone thanks for asking', ['nullcast' => false, 'in_reply_to_status_id_str' => '1'])],
                '500' => ['nullcast' => true, 'reply' => false, 'body' => $tweet('500', 'Visit our store', ['nullcast' => true, 'card_uri' => 'card://5000'])],
            ],
            '777' => [
                '400' => ['nullcast' => false, 'reply' => false, 'body' => $tweet('400', 'Partner post', ['nullcast' => false, 'entities' => ['media' => [['type' => 'photo', 'media_url_https' => 'https://pbs.twimg.com/media/p400.jpg']]]])],
            ],
        ];

        Http::fake(function (Request $r) use ($byUser) {
            $u = $r->url();
            parse_str((string) parse_url($u, PHP_URL_QUERY), $q);

            if (str_contains($u, '/promoted_tweets')) {
                return Http::response(['data' => array_map(static fn (string $t) => ['id' => "pt{$t}", 'tweet_id' => $t, 'line_item_id' => 'li1', 'entity_status' => 'ACTIVE'], ['100', '200', '300', '400', '500', '600'])]);
            }
            if (str_contains($u, '/promotable_users')) {
                return Http::response(['data' => [
                    ['user_id' => '111', 'id' => 'a1', 'promotable_user_type' => 'FULL', 'deleted' => false],
                    ['user_id' => '777', 'id' => 'a2', 'promotable_user_type' => 'RETWEETS_ONLY', 'deleted' => false],
                ]]);
            }
            if (str_contains($u, '/cards/all')) {
                return Http::response(['data' => [[
                    'card_uri' => 'card://5000', 'card_type' => 'IMAGE_WEBSITE', 'name' => 'Store card', 'title' => 'Shop the sale',
                    'website_dest_url' => 'https://shop.example/sale', 'components' => [['type' => 'MEDIA', 'media_key' => '3_5000']],
                ]]]);
            }
            if (str_contains($u, '/media_library/')) {
                return Http::response(['data' => ['media_key' => '3_5000', 'media_type' => 'IMAGE', 'media_url' => 'https://pbs.twimg.com/media/card5000.jpg', 'poster_media_url' => null]]);
            }
            if (str_contains($u, '/accounts/acc1/tweets')) {
                $this->tweetCalls[] = $q;
                $timeline = $q['timeline_type'] ?? 'NULLCAST';
                $replies = ($q['include_mentions_and_replies'] ?? 'false') === 'true';
                $user = $q['user_id'] ?? 'full';
                $ids = explode(',', (string) ($q['tweet_ids'] ?? ''));
                $data = [];
                foreach ($byUser[$user] ?? [] as $id => $t) {
                    if (! in_array((string) $id, $ids, true)) {
                        continue;
                    }
                    if ($timeline === 'NULLCAST' && ! $t['nullcast'] || $timeline === 'ORGANIC' && $t['nullcast'] || $t['reply'] && ! $replies) {
                        continue;
                    }
                    $data[] = $t['body'];
                }

                return Http::response(['request' => ['params' => $q], 'next_cursor' => null, 'data' => $data]);
            }

            return Http::response(['data' => []]);
        });

        $c = $this->bound();

        $ads = (new \ReflectionMethod($c, 'fetchAds'))->invoke($c, app(TokenVault::class)->stored($this->connection), 'acc1');
        $byTweet = [];
        foreach ($ads as $ad) {
            $byTweet[$ad['creative']['external_id']] = $ad['creative'];
        }

        return $byTweet;
    }

    public function test_an_organic_post_the_advertiser_promotes_has_its_words_and_picture(): void
    {
        $c = $this->ads();

        $this->assertSame('Our organic post', $c['200']['body'] ?? null, 'the documented NULLCAST default hid an organic promoted post');
        $this->assertSame('video', $c['200']['format']);
        $this->assertSame('https://pbs.twimg.com/ext_tw_video_thumb/200.jpg', $c['200']['thumbnail_url']);
        $this->assertSame('ALL', $this->tweetCalls[0]['timeline_type'] ?? null);
    }

    public function test_a_promoted_reply_is_not_filtered_out(): void
    {
        $c = $this->ads();

        $this->assertSame('@someone thanks for asking', $c['300']['body'] ?? null);
        $this->assertSame('text_card', $c['300']['format']);
    }

    public function test_a_tweet_of_another_promotable_user_is_asked_for_under_that_user(): void
    {
        $c = $this->ads();

        $this->assertSame('Partner post', $c['400']['body'] ?? null);
        $this->assertSame('https://pbs.twimg.com/media/p400.jpg', $c['400']['thumbnail_url'] ?? null);
        $this->assertContains('777', array_column($this->tweetCalls, 'user_id'));
    }

    public function test_a_website_card_tweet_shows_the_cards_picture_title_and_destination(): void
    {
        $c = $this->ads();

        $this->assertSame('image', $c['500']['format']);
        $this->assertSame('https://pbs.twimg.com/media/card5000.jpg', $c['500']['thumbnail_url'] ?? null);
        $this->assertSame('Shop the sale', $c['500']['headline'] ?? null);
        $this->assertSame('https://shop.example/sale', $c['500']['destination_url'] ?? null);
        $this->assertSame('Visit our store', $c['500']['body'] ?? null);
    }

    public function test_a_promoted_only_tweet_still_reads_as_before(): void
    {
        $c = $this->ads();

        $this->assertSame('image', $c['100']['format']);
        $this->assertSame('https://pbs.twimg.com/media/p100.jpg', $c['100']['thumbnail_url']);
    }

    public function test_a_tweet_x_never_returns_says_so_and_invents_nothing(): void
    {
        $c = $this->ads();

        $this->assertSame('text', $c['600']['format']);
        $this->assertArrayNotHasKey('thumbnail_url', $c['600']);
        $this->assertSame('x_tweet_not_returned', $c['600']['raw']['media_reason'] ?? null);

        $preview = app(CreativePresenter::class)->preview(new ExternalCreative(['provider' => 'x', 'name' => 'Tweet 600', 'format' => 'text', 'raw' => $c['600']['raw']]));
        $this->assertSame('unavailable', $preview['state']);
        $this->assertStringContainsString('did not return this post', (string) $preview['note_en']);
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
