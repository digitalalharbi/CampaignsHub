<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CompressJsonResponses;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * API-PAYLOAD-COMPRESSION-001 — the API stopped shipping a megabyte of JSON raw.
 *
 * Measured on the owner's own shared report: 1.02MB, no `content-encoding` at all. Narrowed to
 * thirteen creatives it was 56KB, TTFB 1.16s → 0.62s, total 1.83s → 0.82s — so of the roster's
 * cost, about half a second is the server and a full second is the wire. The wire was the larger
 * half and it was being sent uncompressed.
 *
 * The cases below are about the things a compression middleware breaks when it is written
 * carelessly, because each of them turns a performance improvement into an outage:
 *
 * - encoding a body for a client that never asked for one;
 * - encoding something already encoded, so the client unzips once and gets gzip back;
 * - draining a STREAMED response, which is the one thing it must never do;
 * - answering with an empty body when the encoder fails;
 * - omitting `Vary`, which lets a shared cache hand a gzipped body to a client that cannot read it.
 */
final class ApiCompressionTest extends TestCase
{
    private function through(Request $request, HttpResponse $response): HttpResponse
    {
        return (new CompressJsonResponses)->handle($request, static fn () => $response);
    }

    private function jsonOf(int $bytes): HttpResponse
    {
        /* Repetitive on purpose: this is what report JSON looks like, and what gzip is good at. */
        $payload = json_encode(['rows' => array_fill(0, max(1, intdiv($bytes, 40)), [
            'name' => 'Creative', 'state' => 'available', 'url' => 'https://cdn.test/asset.jpg',
        ])], JSON_THROW_ON_ERROR);

        return new HttpResponse($payload, 200, ['Content-Type' => 'application/json']);
    }

    private function asking(string $encodings = 'gzip, deflate, br'): Request
    {
        return Request::create('/api/v1/anything', 'GET', server: ['HTTP_ACCEPT_ENCODING' => $encodings]);
    }

    public function test_a_large_json_response_is_compressed_for_a_client_that_asked(): void
    {
        $original = $this->jsonOf(200_000);
        $raw = strlen((string) $original->getContent());

        $out = $this->through($this->asking(), $original);

        $this->assertSame('gzip', $out->headers->get('Content-Encoding'));
        $this->assertLessThan($raw, strlen((string) $out->getContent()), 'the body did not get smaller');
        $this->assertSame((string) strlen((string) $out->getContent()), $out->headers->get('Content-Length'));

        // And it is really gzip — a reader has to get the JSON back out.
        $back = gzdecode((string) $out->getContent());
        $this->assertIsString($back);
        $this->assertSame($raw, strlen($back));
    }

    /** `Vary`, or a shared cache serves the gzipped copy to somebody who cannot read it. */
    public function test_it_declares_that_the_answer_varies_by_encoding(): void
    {
        $out = $this->through($this->asking(), $this->jsonOf(200_000));

        $this->assertStringContainsString('Accept-Encoding', (string) $out->headers->get('Vary'));
    }

    /** A client that did not ask gets exactly what it would have got before. */
    public function test_a_client_that_did_not_ask_gets_plain_json(): void
    {
        $out = $this->through(Request::create('/api/v1/anything'), $this->jsonOf(200_000));

        $this->assertNull($out->headers->get('Content-Encoding'));
        $this->assertJson((string) $out->getContent());
    }

    /** Small responses are left alone: the saving is bytes and the cost is a round trip through gzip. */
    public function test_a_small_response_is_left_alone(): void
    {
        $small = new HttpResponse('{"ok":true}', 200, ['Content-Type' => 'application/json']);

        $this->assertNull($this->through($this->asking(), $small)->headers->get('Content-Encoding'));
    }

    /** Not JSON, not touched — a PDF or a spreadsheet is already compressed or is binary. */
    public function test_a_non_json_response_is_left_alone(): void
    {
        $pdf = new HttpResponse(str_repeat('A', 200_000), 200, ['Content-Type' => 'application/pdf']);

        $this->assertNull($this->through($this->asking(), $pdf)->headers->get('Content-Encoding'));
    }

    /** Already encoded by somebody else — encoding it again hands the client gzip inside gzip. */
    public function test_an_already_encoded_response_is_left_alone(): void
    {
        $already = new HttpResponse(gzencode(str_repeat('{"a":1}', 20_000)), 200, [
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
        ]);

        $out = $this->through($this->asking(), $already);

        $this->assertSame('gzip', $out->headers->get('Content-Encoding'));
        $this->assertIsString(gzdecode((string) $out->getContent()), 'the body was gzipped twice');
    }

    /**
     * A streamed response is never read.
     *
     * Reading it is the one thing that defeats streaming, and `getContent()` on a
     * `StreamedResponse` returns false rather than a body — so a careless middleware would answer
     * with an empty string and the download would arrive as nothing.
     */
    public function test_a_streamed_response_is_never_drained(): void
    {
        $drained = false;
        $streamed = new StreamedResponse(function () use (&$drained): void {
            $drained = true;
        }, 200, [
            'Content-Type' => 'application/json',
        ]);

        $out = (new CompressJsonResponses)->handle($this->asking(), static fn () => $streamed);

        $this->assertFalse($drained, 'the middleware ran the stream');
        $this->assertNull($out->headers->get('Content-Encoding'));
    }

    /** And a file download keeps being a file download. */
    public function test_a_binary_file_response_is_left_alone(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cmp');
        file_put_contents($path, str_repeat('x', 100_000));

        try {
            $file = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/json']);
            $out = (new CompressJsonResponses)->handle($this->asking(), static fn () => $file);

            $this->assertNull($out->headers->get('Content-Encoding'));
        } finally {
            @unlink($path);
        }
    }

    /**
     * And it is actually WIRED — a large JSON response, through the real API pipeline.
     *
     * Every case above constructs the middleware directly, which proves the logic and would go on
     * passing if `bootstrap/app.php` never mentioned the class. This registers a route on the `api`
     * stack and asks for it the way a browser does, so the assertion covers the registration, the
     * ordering and the middleware together — the only version of the question that catches a class
     * nobody wired in.
     */
    public function test_the_api_pipeline_compresses_a_large_json_response(): void
    {
        Route::middleware('api')->get('/__compression_probe', fn () => response()->json([
            'rows' => array_fill(0, 4000, ['name' => 'Creative', 'state' => 'available']),
        ]));

        $response = $this->get('/__compression_probe', ['Accept-Encoding' => 'gzip']);

        $response->assertOk();
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'), 'the pipeline did not compress');

        $body = gzdecode((string) $response->getContent());

        $this->assertIsString($body, 'the body is not gzip');
        $this->assertGreaterThan(16384, strlen($body));
        $this->assertStringContainsString('Accept-Encoding', (string) $response->headers->get('Vary'));
    }

    /** The same route, for a client that did not ask, arrives as plain JSON. */
    public function test_the_api_pipeline_leaves_it_alone_for_a_client_that_did_not_ask(): void
    {
        Route::middleware('api')->get('/__compression_probe_plain', fn () => response()->json([
            'rows' => array_fill(0, 4000, ['name' => 'Creative', 'state' => 'available']),
        ]));

        $response = $this->get('/__compression_probe_plain');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
        $response->assertJsonPath('rows.0.name', 'Creative');
    }
}
