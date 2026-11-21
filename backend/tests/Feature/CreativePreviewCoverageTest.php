<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Campaigns\Models\ExternalCreative;
use App\Domains\Campaigns\Services\CreativePresenter;
use App\Domains\Integrations\Support\AssetExpiry;
use Tests\TestCase;

/**
 * CONTENT-PREVIEW-COVERAGE-001 — a creative that draws nothing says WHY, in the connector's stored word.
 */
final class CreativePreviewCoverageTest extends TestCase
{
    public function test_a_tiktok_creative_states_its_stored_reason_not_the_generic_sentence(): void
    {
        $c = new ExternalCreative(['provider' => 'tiktok', 'format' => 'video', 'raw' => ['media' => 'spark', 'media_reason' => 'spark_identity_unknown']]);
        $p = app(CreativePresenter::class)->preview($c);

        $this->assertSame('unavailable', $p['state']);
        $this->assertStringContainsString('identity', $p['note_en']);
        $this->assertStringNotContainsString('exposed no asset', $p['note_en']);
    }

    public function test_an_asset_library_refusal_is_not_called_a_platform_that_exposes_nothing(): void
    {
        $c = new ExternalCreative(['provider' => 'tiktok', 'format' => 'video', 'raw' => ['media' => 'video', 'media_reason' => 'video_info_missing']]);
        $p = app(CreativePresenter::class)->preview($c);

        $this->assertStringContainsString('Asset Library', $p['note_en']);
    }

    public function test_a_row_with_no_stored_reason_keeps_the_generic_sentence_and_no_reason_is_invented(): void
    {
        $c = new ExternalCreative(['provider' => 'tiktok', 'format' => 'video', 'raw' => ['media' => 'spark']]);
        $this->assertStringContainsString('exposed no asset', app(CreativePresenter::class)->preview($c)['note_en']);
    }

    public function test_tiktoks_cdn_expiry_is_read_and_an_implausible_value_is_not(): void
    {
        $at = AssetExpiry::fromUrl('https://p16.tiktokcdn.com/x.mp4?x-expires=1790000000&x-signature=abc');
        $this->assertSame(1790000000, $at?->getTimestamp());
        $this->assertNull(AssetExpiry::fromUrl('https://p16.tiktokcdn.com/c.jpg?x-expires=1'));
    }
}
