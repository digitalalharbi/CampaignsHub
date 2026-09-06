<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domains\Integrations\Support\AssetExpiry;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * AD-MEDIA-RECOVERY-001 — a stale media link must say so, not render as a broken rectangle.
 *
 * ## The defect this closes
 *
 * `external_creatives.asset_expires_at` has existed since the table did. `CreativePresenter::assetExpired()`
 * reads it, the `expired` state and its sentence are written and tested, and the importer writes the
 * column from the connector's `asset_expires_at`.
 *
 * **No connector had ever emitted one.** The column was always null, `assetExpired()` was always
 * false, and the `expired` state was unreachable in production — so a media URL that had gone stale
 * was served to the browser as `available`, the CDN refused the `<img>`, and the reader got a broken
 * rectangle with no explanation. The one outcome this requirement forbids by name, reached through
 * the state that exists to prevent it.
 *
 * Both providers state the expiry in the URL, because that is how a signed CDN grant works — the
 * timestamp is part of what the signature covers, so it cannot be wrong without the link being
 * invalid anyway. Meta uses `oe` in hex; Snapchat uses `e` in decimal.
 *
 * A plain unit test: this is a parser, and it needs no application to be true.
 */
final class AssetExpiryReachesTheCardTest extends TestCase
{
    /** Meta states `oe` in HEX — 0x66E1F2A3 is 1726083747, 2024-09-11T19:42:27Z. */
    public function test_it_reads_metas_hex_expiry(): void
    {
        $at = AssetExpiry::fromUrl('https://scontent.xx.fbcdn.net/v/t45.1600-4/x.jpg?_nc_cat=1&oh=00_AfC&oe=66E1F2A3');

        $this->assertNotNull($at);
        $this->assertSame('2024-09-11T19:42:27+00:00', $at->toIso8601String());
    }

    /** Snapchat states `e` in DECIMAL — the shape this repo's own signed-URL fixture uses. */
    public function test_it_reads_snapchats_decimal_expiry(): void
    {
        $at = AssetExpiry::fromUrl('https://cf.snapchat.com/media/me-2.jpg?sig=abc123&e=1790000000');

        $this->assertNotNull($at);
        $this->assertSame(1790000000, $at->getTimestamp());
    }

    /**
     * A link that states no expiry returns null — «this link states no expiry», not «never expires».
     *
     * The difference is the whole point: the first is a fact about the URL, and the second would be a
     * promise this product cannot keep.
     */
    public function test_a_link_with_no_expiry_parameter_states_none(): void
    {
        $this->assertNull(AssetExpiry::fromUrl('https://cdn.example/plain.jpg'));
        $this->assertNull(AssetExpiry::fromUrl('https://cdn.example/plain.jpg?width=200'));
        $this->assertNull(AssetExpiry::fromUrl(null));
        $this->assertNull(AssetExpiry::fromUrl(''));
    }

    /**
     * An `e` that is not a plausible epoch second is NOT an expiry.
     *
     * `e` is a common parameter name — a variant, a version, an experiment id. Reading one as a date
     * would either expire a perfectly good asset or push it a thousand years out, and a wrong expiry
     * that hides a working image is worse than no expiry at all.
     */
    public function test_a_parameter_that_is_not_a_date_is_ignored(): void
    {
        $this->assertNull(AssetExpiry::fromUrl('https://cdn.example/x.jpg?e=3'));
        $this->assertNull(AssetExpiry::fromUrl('https://cdn.example/x.jpg?e=99999999999'));
        $this->assertNull(AssetExpiry::fromUrl('https://cdn.example/x.jpg?e=variant-b'));
    }

    /**
     * An expiry in the PAST is kept, and that is the case the whole thing is for.
     *
     * A link that ran out yesterday is exactly what the `expired` state exists to report; discarding
     * it as implausible would put the product back where it started.
     */
    public function test_an_expiry_already_past_is_still_read(): void
    {
        $at = AssetExpiry::fromUrl('https://cf.snapchat.com/media/x.jpg?e=1300000000');

        $this->assertNotNull($at);
        $this->assertTrue($at->lt(Carbon::now()));
    }

    /** Meta's own `oe` wins over a stray `e` on the same URL — the more specific key is the safer read. */
    public function test_metas_key_is_preferred_when_both_appear(): void
    {
        $at = AssetExpiry::fromUrl('https://scontent.xx.fbcdn.net/v/x.jpg?e=1300000000&oe=66E1F2A3');

        $this->assertSame('2024-09-11T19:42:27+00:00', $at?->toIso8601String());
    }
}
