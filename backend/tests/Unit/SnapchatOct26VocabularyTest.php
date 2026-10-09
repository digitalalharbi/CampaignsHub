<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Integrations\Providers\Snapchat\SnapchatVocabulary;
use App\Domains\Integrations\Support\PlatformHttp;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

/**
 * SNAP-OCT26 — Chat Feed, the AI-media declaration, sub-request warnings and E9001, as the product reads them.
 */
final class SnapchatOct26VocabularyTest extends TestCase
{
    public function test_chat_feed_is_a_known_placement_and_an_unknown_token_is_kept_verbatim(): void
    {
        $squad = ['id' => 's1', 'placement_v2' => ['config' => 'CUSTOM', 'platforms' => ['SNAPCHAT'], 'snapchat_positions' => ['FEED', 'CHAT_FEED', 'INTERSTITIAL_USER', 'INSTREAM_SPOTLIGHT']]];

        $placements = SnapchatVocabulary::placements($squad);

        self::assertSame('custom', $placements['placement_config']);
        self::assertSame(['FEED', 'CHAT_FEED', 'INTERSTITIAL_USER', 'INSTREAM_SPOTLIGHT'], $placements['placements']);
        self::assertSame('خلاصة الدردشة', SnapchatVocabulary::placementLabel('CHAT_FEED', 'ar'));
        self::assertSame('Chat Feed', SnapchatVocabulary::placementLabel('CHAT_FEED', 'en'));
        // Not named by the product: the token itself, never hidden, never guessed at.
        self::assertSame('INSTREAM_SPOTLIGHT', SnapchatVocabulary::placementLabel('INSTREAM_SPOTLIGHT', 'ar'));
        self::assertNull(SnapchatVocabulary::placements(['id' => 's2']));
        self::assertSame(['placement_config' => 'automatic', 'placements' => []], SnapchatVocabulary::placements(['placement_v2' => ['config' => 'AUTOMATIC']]));
    }

    public function test_the_ai_media_declaration_is_read_from_the_media_object_and_absent_is_absent(): void
    {
        self::assertSame('USER_AI_GEN', SnapchatVocabulary::aiContentSource(['id' => 'm1', 'ai_content_source' => 'user_ai_gen']));
        self::assertSame('SNAP_AI_GENERATED', SnapchatVocabulary::aiContentSource(['ai_content_source' => 'SNAP_AI_GENERATED']));
        self::assertNull(SnapchatVocabulary::aiContentSource(['id' => 'm1']));
        self::assertNull(SnapchatVocabulary::aiContentSource(['ai_content_source' => '  ']));
        self::assertNull(SnapchatVocabulary::aiContentSource(null));
        self::assertArrayHasKey('USER_AI_GEN', SnapchatVocabulary::AI_MEDIA);
    }

    public function test_a_failed_sub_request_is_named_with_the_reason_snapchat_gave(): void
    {
        $ok = ['sub_request_status' => 'SUCCESS', 'adsquad' => ['id' => 'a']];
        $bad = ['sub_request_status' => 'ERROR', 'sub_request_error_reason' => 'Ad Squad not found', 'adsquad' => ['id' => 'b']];

        self::assertNull(SnapchatVocabulary::subRequestWarning($ok, 'adsquad', 'ad squads'));
        self::assertSame(
            ['what' => 'ad squads', 'id' => 'b', 'status' => 'ERROR', 'reason' => 'Ad Squad not found'],
            SnapchatVocabulary::subRequestWarning($bad, 'adsquad', 'ad squads'),
        );
        self::assertNull(SnapchatVocabulary::subRequestWarning(['adsquad' => ['id' => 'c']], 'adsquad', 'ad squads'), 'no status at all is not a warning');
    }

    public function test_e9001_is_explained_with_a_retry_rule_and_never_shown_bare(): void
    {
        $explained = SnapchatVocabulary::explainError('e9001');
        self::assertSame('split_window', $explained['retry']);
        self::assertStringContainsString('fewer fields or a shorter window', $explained['message_en']);
        self::assertStringContainsString('E9001', $explained['message_ar']);
        self::assertNull(SnapchatVocabulary::explainError('E1234'));

        $response = new Response(new PsrResponse(400, ['Content-Type' => 'application/json'], json_encode([
            'request_status' => 'ERROR', 'request_id' => 'req-1', 'error_code' => 'E9001',
            'debug_message' => 'Response size too large', 'display_message' => 'Response size too large',
        ])));

        $reason = PlatformHttp::reason($response);
        self::assertStringStartsWith('Snapchat refused the stats request because the answer would be larger than its limit (E9001)', $reason);
        self::assertStringContainsString('Retry: split_window.', $reason);
        self::assertStringContainsString('Response size too large', $reason, 'the provider\'s own sentence stays');
        self::assertStringContainsString('request req-1', $reason);
    }
}
