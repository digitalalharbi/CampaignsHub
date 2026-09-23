<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AD-MEDIA-RECOVERY-001 — a film the product hosts can be SEEKED, wherever the product runs.
 *
 * Every environment this project runs serves the app with PHP's built-in server: `php artisan serve`
 * is the CMD of `deploy/backend.production.Dockerfile` and the E2E gate's backend command alike. It
 * does not implement HTTP Range — measured, not assumed:
 *
 *   curl -H 'Range: bytes=0-1023' http://127.0.0.1:8123/demo/creative-sample.mp4
 *   → HTTP/1.1 200 OK, Content-Length: 27649, no Accept-Ranges
 *
 * WebKit will not start a video it cannot seek. It reports `MediaError 2` (NETWORK) and the card
 * shows nothing, which is the owner's «no real preview» and what CI's webkit leg reported on #494 as
 * «the browser could not decode the film». Serving the file through Laravel answers the Range with a
 * 206, so the seek succeeds on the same server that could not do it before.
 */
final class AppMediaRangeTest extends TestCase
{
    public function test_a_film_the_product_hosts_answers_a_range_request_with_a_partial_body(): void
    {
        $response = $this->call('GET', '/demo/creative-sample.mp4', server: ['HTTP_RANGE' => 'bytes=0-1023']);

        $response->assertStatus(206);
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertMatchesRegularExpression('~^bytes 0-1023/\d+$~', (string) $response->headers->get('Content-Range'));
        $this->assertSame('1024', $response->headers->get('Content-Length'));
    }

    public function test_the_whole_film_is_still_served_when_nothing_is_asked_for(): void
    {
        $response = $this->get('/demo/creative-sample.mp4');

        $response->assertOk();
        $this->assertSame('video/mp4', $response->headers->get('Content-Type'));
        $this->assertGreaterThan(1024, (int) $response->headers->get('Content-Length'));
    }

    /** A path is not a permission: the resolved file has to live inside the directory this route owns. */
    public function test_a_path_that_climbs_out_of_the_media_directory_is_refused(): void
    {
        $this->get('/demo/../../.env')->assertNotFound();
        $this->get('/demo/'.urlencode('../../.env'))->assertNotFound();
    }

    /** An extension this product does not host is not served on the strength of being present. */
    public function test_an_unknown_kind_of_file_is_not_served(): void
    {
        $path = storage_path('app/media/demo/not-media.txt');
        file_put_contents($path, 'not media');

        try {
            $this->get('/demo/not-media.txt')->assertNotFound();
        } finally {
            @unlink($path);
        }
    }
}
