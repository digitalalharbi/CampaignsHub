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
    ],
];
