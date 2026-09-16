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
  /*
   * The faces the FIRST SCREEN paints, which is three and not two.
   *
   * The first version preloaded the Latin Inter subset and Arabic Plex 400, and the production
   * measurement after it deploying is what named the third: CLS fell 0.0375 → 0.0232 and did not
   * reach zero. The card that used to GROW was stable; what still moved was everything below the
   * hero, by 25.2px, at 1237ms.
   *
   * 25.2px is exactly one line of the hero `h1` — 21px Arabic at line-height 25.2px — so this is the
   * heading RE-WRAPPING, not a line box changing height. The heading is weight 700, a separate file
   * that was still `font-display: swap` and still unpreloaded: it painted in the system Arabic face,
   * wrapped to a different number of lines, and re-wrapped when Plex 700 landed.
   *
   * Found by measuring the element rather than by reasoning about fonts: the shift's own numbers
   * said «one line of THIS heading», and the heading's computed weight said which file.
   */
  const CRITICAL = [
    /inter-latin-wght-normal/,
    /ibm-plex-sans-arabic-arabic-400-normal/,
    /ibm-plex-sans-arabic-arabic-700-normal/,
  ]
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
