<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domains\Campaigns\Support\DrawableImage;
use PHPUnit\Framework\TestCase;

/**
 * The rule every fetch check now shares: a browser draws BYTES, not a declared type.
 *
 * Production run 35478164776 — `content:census --fetch --raw` on three promoted Snapchat collection
 * stills: «not an image (content type multipart/form-data; bytes: png image that decodes)». The media
 * was never the defect; two readers judging it by its header were.
 */
final class DrawableImageTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** The exact Production shape: a real PNG whose server calls it something else. */
    public function test_a_real_png_draws_whatever_its_server_calls_it(): void
    {
        $bytes = (string) base64_decode(self::PNG, true);

        $this->assertSame('png', DrawableImage::sniff($bytes));
        $this->assertTrue(DrawableImage::draws($bytes), 'a PNG the browser draws was judged undrawable');
    }

    /** A genuine multipart envelope draws nothing, and the rescue must not reach it. */
    public function test_a_multipart_envelope_draws_nothing(): void
    {
        $this->assertNull(DrawableImage::sniff("--b1\r\nContent-Type: image/png\r\n\r\nnope\r\n--b1--\r\n"));
        $this->assertFalse(DrawableImage::draws("--b1\r\nContent-Type: image/png\r\n\r\nnope\r\n--b1--\r\n"));
    }

    /** Nor an HTML error page under a 200 — the shape an expired CDN grant returns. */
    public function test_an_html_error_page_draws_nothing(): void
    {
        $this->assertFalse(DrawableImage::draws('<html>Access denied</html>'));
    }

    /**
     * A signature alone is not enough: four bytes of coincidence are not an image.
     *
     * Both halves of the rule are load-bearing, and a test that only fed it real files could not tell.
     */
    public function test_a_signature_over_rubbish_does_not_draw(): void
    {
        $bytes = "\x89PNG\r\n\x1A\n".str_repeat("\x00", 16);

        $this->assertSame('png', DrawableImage::sniff($bytes), 'the signature is there');
        $this->assertFalse(DrawableImage::draws($bytes), 'and nothing decodes from it');
    }

    /** Empty bytes are not an image either — a refused body must never read as one. */
    public function test_nothing_draws_nothing(): void
    {
        $this->assertNull(DrawableImage::sniff(''));
        $this->assertFalse(DrawableImage::draws(''));
    }
}
