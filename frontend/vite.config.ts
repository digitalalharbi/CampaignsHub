import { realpathSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import { preloadCriticalFonts } from './src/build/preloadCriticalFonts.js'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

const API_TARGET = process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000'

/**
 * GATE-BUILT-APP-001 — the same hops for the dev server AND for `vite preview`.
 *
 * `server.proxy` and `preview.proxy` are separate options and neither inherits the other, so a gate
 * that serves the BUILT app needs this map twice or not at all. Written once and referenced twice,
 * because two copies of «`/l/` goes to Laravel, and the trailing slash is load-bearing» is two
 * chances to fix one of them and ship the other.
 */
const HOPS = {
  '/api': { target: API_TARGET, changeOrigin: true },
  '/sanctum': { target: API_TARGET, changeOrigin: true },
  '/l/': { target: API_TARGET, changeOrigin: true },
  '/demo': { target: API_TARGET, changeOrigin: true },
} as const

/**
 * GATE-VITE-001 — one dependency cache per dev server, never one shared between two.
 *
 * The gate runs TWO dev servers at once: the tests' own, and a second one that exists solely so the
 * PDF print browser stops pulling the SPA's module graph out from under a `page.goto` (GATE-WK-001,
 * documented in `playwright.config.ts`). Both defaulted to `node_modules/.vite`.
 *
 * Two Vite processes cannot share a dependency cache. Each runs its own optimizer, and the moment
 * either one encounters a dep it has not pre-bundled it rewrites that directory and bumps the hash
 * every client URL carries. The other server's in-flight requests are then asking for a `?v=` that
 * no longer exists — the browser reports «Load failed», the proxy answers 502, and a navigation
 * waiting on a module being rewritten underneath it never fires `load` at all.
 *
 * That is the signature the chromium leg produced: a burst of full page loads (the login specs
 * navigate on almost every test) stalling for tens of minutes, including a test that never calls the
 * backend, and then recovering completely once the burst passed. Laravel was never the problem —
 * a client-side validation test cannot take seventeen minutes because of the API.
 *
 * `VITE_CACHE_DIR` gives the second server its own. A developer's `npm run dev` sets nothing and
 * keeps the default, so nothing about the ordinary workflow changes.
 */
const CACHE_DIR = process.env.VITE_CACHE_DIR

// https://vite.dev/config/
export default defineConfig({
  ...(CACHE_DIR === undefined ? {} : { cacheDir: CACHE_DIR }),
  plugins: [react(), tailwindcss(), preloadCriticalFonts()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    /*
     * WORKTREE-NODE-MODULES-001 — serve the packages this checkout actually resolves.
     *
     * Vite refuses to serve files outside the project root, and rightly. A git WORKTREE commonly
     * symlinks `node_modules` at a sibling checkout to avoid installing the tree four times, and the
     * fonts then resolve to a real path outside this root: Vite answers 403, the browser logs
     * «downloadable font: download failed», and every spec that asserts a clean console fails for a
     * reason that has nothing to do with the product. It cost a full three-browser verification run
     * to tell those apart from real defects.
     *
     * `realpathSync` on the directory rather than a hard-coded sibling: an ordinary checkout resolves
     * to its own root and this adds nothing, and a worktree resolves to wherever its packages really
     * are. Dev server only — it has no bearing on what is built or shipped.
     */
    fs: {
      allow: [
        fileURLToPath(new URL('.', import.meta.url)),
        realpathSync(fileURLToPath(new URL('./node_modules', import.meta.url))),
      ],
    },
    /*
     * The same hops, named once above — every comment that used to live here moved with them.
     */
    proxy: HOPS,
  },
  /*
   * GATE-BUILT-APP-001 — what the E2E gate serves.
   *
   * The gate used to run `vite dev`, so the FIRST visit to a route paid for an on-demand transform of
   * that route's module graph. `/agency/tasks` has the largest graph in the agency rail and webkit is
   * the slowest of the three browsers, which is why one route on one browser kept timing out on
   * commits whose code was byte-identical to a green run — five times, each reproduced locally and
   * none of them a defect in the page. `railWalkTimeout.ts` carries that history.
   *
   * A built app has no transform step at all: `dist` is bytes on disk, the same bytes production
   * serves. The hops still have to work, which is why they are shared rather than copied, and the SPA
   * fallback is `vite preview`'s default for a built `index.html`, which is what keeps a deep link
   * like `/agency/tasks` resolving to the app instead of a 404.
   *
   * A developer's `npm run dev` is untouched.
   */
  preview: {
    proxy: HOPS,
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
