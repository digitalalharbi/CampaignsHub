import type { Plugin } from 'vite'

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
export function preloadCriticalFonts(): Plugin {
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
