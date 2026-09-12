<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * API-PAYLOAD-COMPRESSION-001 — the API answers uncompressed, and one report pays a second for it.
 *
 * ## How this was found
 *
 * Measuring the owner's own shared report after REPORT-CREATIVE-MEDIA-001 landed: 1.02MB on the
 * wire, no `content-encoding` header at all. Narrowing the same report to thirteen creatives took
 * it to 56KB, TTFB from 1.16s to 0.62s and the total from 1.83s to 0.82s — so of the roster's cost,
 * roughly half a second is the server resolving 1,539 previews and a full second is shipping the
 * bytes. The transfer is the larger half, and it was being sent raw.
 *
 * JSON is the most compressible thing this product emits: repeated keys, repeated CDN prefixes,
 * long runs of nulls. A report payload of this shape goes to roughly a tenth of its size.
 *
 * ## Why in the application rather than in the web server
 *
 * Because the web server's configuration is not in this repository and this is. A reverse proxy is
 * the better place for it and nothing here prevents one being configured later: a proxy will not
 * re-compress a response that already declares `Content-Encoding`, so the two cannot fight.
 *
 * ## What it deliberately does not touch
 *
 * Anything that is not JSON — a PDF export, a spreadsheet, a logo — is already compressed or is
 * binary, and gzipping it costs CPU to make it slightly bigger. A STREAMED response has no body to
 * read without draining the stream, which would defeat the reason it is streamed. And a small
 * response is not worth the round trip through gzip: below the threshold the saving is bytes and
 * the cost is real.
 */
final class CompressJsonResponses
{
    /**
     * Below this, compressing costs more than it saves.
     *
     * Sixteen kilobytes is comfortably above the size at which gzip's own header and the CPU to
     * produce it stop being a meaningful share of the response, and comfortably below the payloads
     * this exists for — the report that prompted it is sixty times this.
     */
    private const MINIMUM_BYTES = 16384;

    /** Six is zlib's default: the knee of the curve, where more effort stops buying much. */
    private const LEVEL = 6;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $body = (string) $response->getContent();

        if (strlen($body) < self::MINIMUM_BYTES) {
            return $response;
        }

        $compressed = gzencode($body, self::LEVEL);

        /*
         * A failed encode returns the response untouched rather than an empty one.
         *
         * `gzencode` returns false rather than throwing, and a middleware that turned that into
         * `(string) false` would answer every large request with nothing at all — a compression
         * feature that empties the API is worse than no compression.
         */
        if ($compressed === false || strlen($compressed) >= strlen($body)) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));

        /*
         * Without `Vary`, a shared cache can hand a gzipped body to a client that never asked for
         * one — the classic way this optimisation breaks somebody else's browser rather than ours.
         */
        $response->headers->set('Vary', trim($response->headers->get('Vary', '').', Accept-Encoding', ', '));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        // Somebody else already encoded it — a proxy, or this middleware running twice.
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if (! str_contains(strtolower((string) $response->headers->get('Content-Type')), 'json')) {
            return false;
        }

        return str_contains(strtolower($request->headers->get('Accept-Encoding', '')), 'gzip');
    }
}
