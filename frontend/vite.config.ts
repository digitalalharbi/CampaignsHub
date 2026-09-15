import type { Plugin } from 'vite'
import { realpathSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

const API_TARGET = process.env.VITE_API_TARGET ?? 'http://127.0.0.1:8000'

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
      /*
       * SHORT-LINK-PRODUCTION-001 — the hop belongs to Laravel here too.
       *
       * `/l/{slug}` is a web route. Without this the dev server answers it from the SPA fallback,
       * which is EXACTLY the production defect — a minted link rendering the app's not-found page —
       * and it would make the gate's hop test unable to tell a fixed edge from a broken one.
       *
       * The trailing slash is load-bearing. A Vite proxy key is a PREFIX, so `'/l'` also captures
       * `/login` — every auth setup timed out on a sign-in page that was being proxied to Laravel.
       */
      '/l/': { target: API_TARGET, changeOrigin: true },
      /*
       * AD-MEDIA-RECOVERY-001 — the app's OWN media, served by the app.
       *
       * A creative asset we host is stored as a path so it survives a port, a host and a deploy
       * (`AD-MEDIA-RECOVERY-001` in `CreativePresenter::safe()`). In production the SPA and the API
       * share an origin and the path resolves to Laravel's `public/`. In development they do not, so
       * without this the browser asked VITE for the file, Vite answered with the SPA shell — 200,
       * `text/html`, three kilobytes — and the player failed with `DEMUXER_ERROR_COULD_NOT_OPEN`
       * against a document pretending to be a video.
       *
       * That is the worst shape of this bug: every layer reports success and the user sees a dead
       * player, which is why it survived unit tests that assert the payload and the markup.
       */
      '/demo': { target: API_TARGET, changeOrigin: true },
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

/**
 * MKT-FIX-001 — the two faces the first paint needs, fetched at parse time.
 *
 * Measured on a genuinely cold load of the deployed site: CLS 0.0375 from a SINGLE shift, four runs
 * of four at 390px and 393px, and a controlled experiment settles the cause — fonts allowed 0.037
 * three times of three, fonts blocked 0 three times of three. It is the swap.
 *
 * The obvious fix is a metric-matched fallback, and it was tried and WITHDRAWN: WebKit applies
 * `size-adjust` and ignores `ascent-override`, so the same declaration rendered a 150px line in
 * Chromium and 171px in WebKit — turning a -8.7% error into +14% on the engine the owner's phone
 * runs. A fix that only works where things were already fine is not a fix.
 *
 * What works on every engine is not painting twice. `@fontsource` is imported from `main.tsx`, so
 * the font URLs only exist after hashing and `index.html` cannot name them — hence a plugin: it
 * reads the built bundle and writes the preload links for exactly the two faces the first screen
 * needs, the Latin Inter subset and Arabic Plex 400.
 *
 * Preload alone is not enough and `font-display: optional` alone is not either. Together the fetch
 * starts with the HTML and the face is almost always ready inside its block period, so the first
 * paint IS the brand face; and on a connection slow enough to miss that window, `optional` declines
 * to swap at all rather than reflowing the page under the reader. Nothing is hidden, delayed or
 * faded — see the owner's rule. The page paints once.
 */
function preloadCriticalFonts(): Plugin {
  const CRITICAL = [/inter-latin-wght-normal/, /ibm-plex-sans-arabic-arabic-400-normal/]
  let hrefs: string[] = []

  return {
    name: 'campaignshub:preload-critical-fonts',
    apply: 'build',
    generateBundle(_options, bundle) {
      hrefs = Object.keys(bundle)
        .filter((file) => file.endsWith('.woff2') && CRITICAL.some((p) => p.test(file)))
        .map((file) => `/${file}`)
    },
    transformIndexHtml() {
      /*
       * `crossorigin` is required even same-origin: a font is always fetched in CORS mode, and a
       * preload without it fetches the bytes a SECOND time — the opposite of the point.
       */
      return hrefs.map((href) => ({
        tag: 'link',
        attrs: { rel: 'preload', as: 'font', type: 'font/woff2', href, crossorigin: '' },
        injectTo: 'head' as const,
      }))
    },
  }
}
