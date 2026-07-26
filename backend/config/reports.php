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
        // real joinable Arabic. Visual glyphs are untouched. Best-effort: a failure never blocks
        // the export (the visual PDF is already correct). Requires python3 + pikepdf.
        'arabic_textlayer_fix' => env('REPORTS_ARABIC_TEXTLAYER_FIX', true),
        'python_bin' => env('REPORTS_PYTHON_BIN', 'python3'),
        'textlayer_script' => base_path('scripts/fix-arabic-textlayer.py'),

        // Stamped onto each export as provenance; a download whose renderer_version differs from this
        // is treated as stale and regenerated. Bump when the Chromium engine/pipeline changes.
        'renderer_version' => env('REPORTS_RENDERER_VERSION', 'chromium-1228'),
    ],
];
