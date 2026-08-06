import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

const API_TARGET = process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      /*
       * Proxy API calls to the Laravel backend during development.
       *
       * The target is overridable because the E2E gate runs its own backend on :8100 against its own
       * database (E2E-ISO-001). Hard-coding :8000 here would send the gate's requests to whatever
       * dev server happened to be listening — i.e. to the development database, which is the exact
       * leak that isolation removes. `playwright.config.ts` sets `VITE_API_TARGET`; a developer's
       * `npm run dev` sets nothing and keeps the default.
       */
      '/api': { target: API_TARGET, changeOrigin: true },
      '/sanctum': { target: API_TARGET, changeOrigin: true },
    },
  },
  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: './src/test/setup.ts',
    css: false,
    // Playwright e2e specs live in ./e2e and run via `npx playwright test`, not vitest.
    exclude: ['e2e/**', 'node_modules/**', 'dist/**'],
    /*
     * 15s, against vitest's 5s default — because the default was failing correct tests.
     *
     * A full run showed 7 failures, then 2 on the next run, then 0 with those files in isolation, and
     * 0 again with the session's changes stashed. The number gives it away: the slowest failure was
     * logged at 5180ms against a 5000ms budget. Nothing was wrong with the assertions — a hundred
     * jsdom environments in parallel simply pushed a few `findBy*` waits past the line.
     *
     * That is worse than a slow suite: a timeout and a real failure look identical in a gate, so the
     * suite reports a defect that is not there and teaches everyone to re-run until it is green —
     * which is exactly the habit that hides a genuine intermittent failure when one appears. The
     * budget is what was wrong, so the budget is what changed. A test that hangs still fails, three
     * times slower.
     */
    testTimeout: 15_000,
  },
})
