<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reports — export / print engine
|--------------------------------------------------------------------------
| The creative Arabic reports are printed with headless Chromium (Playwright)
| over the React print route, so the PDF matches the interactive report. When
| Chromium is unavailable the exporter falls back to the simple Dompdf layout.
*/

return [
    'chromium' => [
        // Master switch. Off by default so environments without Node/Chromium keep working.
        'enabled' => env('REPORTS_CHROMIUM_ENABLED', false),

        // Where the SPA print route is reachable by Chromium (dev: Vite; prod: served SPA origin).
        'app_url' => env('REPORTS_PRINT_APP_URL', 'http://localhost:5173'),

        // Node + the print script + Playwright resolution base (frontend package.json).
        'node_bin' => env('REPORTS_NODE_BIN', 'node'),
        'script' => base_path('scripts/report-print.mjs'),
        'require_base' => env('REPORTS_REQUIRE_BASE', base_path('../frontend/package.json')),

        // Optional explicit Chromium binary (else Playwright's managed download is used).
        'chromium_path' => env('REPORTS_CHROMIUM_PATH'),

        'timeout_ms' => (int) env('REPORTS_PRINT_TIMEOUT_MS', 45000),

        // Post-process step that rewrites the Arabic text layer (ToUnicode) from Chromium's
        // presentation-form glyphs to canonical base letters, so copy/search/screen-readers get
        // real joinable Arabic. Visual glyphs are untouched. Requires python3 + pikepdf.
        //
        // This gate is FAIL-CLOSED, not best-effort: ChromiumPdfRenderer::normalizeArabicTextLayer()
        // throws when the script is missing, the interpreter cannot run it, or the pass cannot drive
        // presentation forms to zero — and the export is marked failed with the code
        // ExportFailureReason::TEXT_LAYER_FAILED rather than shipping a client PDF whose Arabic
        // cannot be copied, searched or read aloud. Turning this switch off skips the pass entirely;
        // it does not downgrade a failure to a warning.
        'arabic_textlayer_fix' => env('REPORTS_ARABIC_TEXTLAYER_FIX', true),
        'python_bin' => env('REPORTS_PYTHON_BIN', 'python3'),
        'textlayer_script' => base_path('scripts/fix-arabic-textlayer.py'),

        // Stamped onto each export as provenance; a download whose renderer_version differs from this
        // is treated as stale and regenerated. Bump when the Chromium engine/pipeline changes.
        'renderer_version' => env('REPORTS_RENDERER_VERSION', 'chromium-1228'),
    ],

    /*
    |----------------------------------------------------------------------
    | SHARE-PREVIEW-CARD-001 — the picture a pasted link renders as
    |----------------------------------------------------------------------
    | The same headless Chromium, pointed at a local 1200x630 document and
    | asked for a PNG instead of a PDF. It needs a browser and nothing else:
    | no app URL, no token, no network, because the card is composed from
    | values the caller already resolved.
    |
    | There is no `enabled` switch of its own on purpose. The card can be
    | drawn exactly when `chromium.enabled` is on and the binary is there,
    | and a second switch would let an install be configured into the one
    | state that is impossible to diagnose: a crawler pointed at an image
    | route the server has decided not to answer.
    */
    'og' => [
        'script' => base_path('scripts/og-card.mjs'),

        // The frame WhatsApp, X, LinkedIn, Slack and Telegram all crop to.
        'width' => 1200,
        'height' => 630,

        // Shorter than the PDF's. A crawler waits a few seconds and then shows
        // the card without a picture, so a render that has not finished by then
        // has already lost; holding the request open past that only delays the
        // HTML the crawler is actually reading.
        'timeout_ms' => (int) env('REPORTS_OG_TIMEOUT_MS', 20000),

        // Where a drawn card is kept. A crawler fetches the image once per
        // service and then again for every recipient who opens the message, so
        // launching a browser per request would make a forwarded link a load
        // test. Keyed by what the card DRAWS, so a rebrand supersedes it.
        'cache_disk' => env('REPORTS_OG_CACHE_DISK', 'local'),
        'cache_dir' => 'share-previews',

        // How long a browser may serve the picture it already has. A day: the
        // card carries an identity and a period, neither of which changes
        // often, and a crawler's own cache is usually longer than ours anyway.
        'http_max_age' => (int) env('REPORTS_OG_MAX_AGE', 86400),
    ],
];
