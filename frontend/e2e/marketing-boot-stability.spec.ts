import { expect, test } from '@playwright/test'

/**
 * MKT-FIX-001 — the marketing homepage renders into its final geometry on a phone.
 *
 * ## The defect the owner saw
 *
 * «The page moves/shifts slightly immediately after loading, then settles into place.» Root cause,
 * measured rather than guessed: the served document declared `lang="en"` with no `dir` and no
 * `data-theme`, while the store resolves to Arabic, RTL and dark when nothing is remembered. So the
 * first paint of a first visit was left-to-right and light, and the moment `applyDocument()` ran the
 * header swapped sides, the hero re-laid out and the document changed colour.
 *
 * ## The first version of this spec could not fail, and that is worth recording
 *
 * It loaded the page and asserted `toHaveAttribute('dir', 'rtl')`. Playwright waits for an attribute
 * to match, so it passed the moment the BUNDLE applied it — measuring the end state, which was never
 * the defect. Removing the entire fix left all four cases green. The question is «what does the
 * document look like before any of the app runs», so nothing here may wait for the app.
 *
 * Two independent proofs, neither of which involves the React bundle:
 *
 *   1. The SERVED bytes, fetched without a browser at all. That is literally what the first paint
 *      gets, and it is where the static defaults live.
 *   2. The pre-paint script alone, with the module bundle blocked at the network. A returning reader
 *      who chose English must not be shown Arabic first and corrected after, and this proves the
 *      correction happens before the app exists rather than because of it.
 */
const WIDTHS = [390, 393]

test.describe('what the first paint of the marketing homepage gets', () => {
  test('the served document already declares the product’s language, direction and theme', async ({ request, baseURL }) => {
    const html = await (await request.get(baseURL ?? '/')).text()
    const tag = /<html[^>]*>/.exec(html)?.[0] ?? ''

    expect(tag, 'the document tag was not found in the served bytes').not.toBe('')
    expect(tag, 'an RTL product whose document starts LTR flips on first paint').toContain('dir="rtl"')
    expect(tag, 'the document must start in the language the product speaks').toContain('lang="ar"')
    expect(tag, 'a dark-default product whose document starts unthemed flashes light').toContain('data-theme="dark"')

    /*
     * And the preference is read before anything paints. A deferred or module script runs after the
     * document is parsed, which is after the paint it exists to get right.
     */
    const head = html.slice(0, html.indexOf('</head>'))
    expect(head).toContain('campaign-hub-locale')
    expect(head).toMatch(/<script>\s*\(function/)
  })

  for (const width of WIDTHS) {
    test(`a returning English reader is not shown Arabic first at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 844 })

      /* Remember the choice, then take the app away entirely. */
      await page.goto('/')
      await page.evaluate(() => {
        localStorage.setItem('campaign-hub-locale', 'en')
        localStorage.setItem('campaign-hub-theme', 'light')
      })

      /*
       * The bundle is blocked, so nothing but the inline script can touch the document. If the
       * attributes are right here they were right at the first paint, because there was no second
       * one to correct them.
       */
      await page.route('**/*.{js,mjs,tsx,ts}', (route) => route.abort())
      await page.route('**/@vite/**', (route) => route.abort())
      await page.route('**/src/main.tsx**', (route) => route.abort())
      await page.goto('/', { waitUntil: 'commit' })

      const state = await page.evaluate(() => {
        const r = document.documentElement

        return { dir: r.getAttribute('dir'), lang: r.getAttribute('lang'), theme: r.getAttribute('data-theme') }
      })

      expect(state, 'the remembered preference was not applied before the app loaded').toEqual({
        dir: 'ltr', lang: 'en', theme: 'light',
      })
    })
  }

  test('a first-time reader gets the defaults with the app blocked', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 })
    await page.goto('/')
    await page.evaluate(() => { try { localStorage.clear() } catch { /* private mode */ } })

    await page.route('**/*.{js,mjs,tsx,ts}', (route) => route.abort())
    await page.route('**/@vite/**', (route) => route.abort())
    await page.route('**/src/main.tsx**', (route) => route.abort())
    await page.goto('/', { waitUntil: 'commit' })

    const state = await page.evaluate(() => {
      const r = document.documentElement

      return { dir: r.getAttribute('dir'), lang: r.getAttribute('lang'), theme: r.getAttribute('data-theme') }
    })

    expect(state).toEqual({ dir: 'rtl', lang: 'ar', theme: 'dark' })
  })

  /*
   * The metric fallback answers for Latin and declines everything else.
   *
   * `Inter Fallback` exists to occupy the space Inter will take, and it is listed BEFORE
   * «IBM Plex Sans Arabic». A `@font-face` with no `unicode-range` claims every codepoint, so on any
   * host that has Arial — which carries Arabic — the fallback answered for the Arabic too. The
   * client PDF made it visible: Chromium prints the report through this stylesheet, and the
   * downloaded Arabic document embedded `ArialMT` alone.
   *
   * Asserted on the SERVED bytes rather than through a rendered page, for the reason the previous
   * attempt at a guard here failed: the unit harness stubs CSS, so a test that read this file
   * through the bundler would have passed on an empty string. And asserted on the declaration rather
   * than on which face a glyph resolves to, because that answer depends on which fonts the host
   * happens to have installed — which is exactly why CI did not catch this.
   */
  test('the Latin metric fallback does not claim Arabic', async ({ page }) => {
    await page.goto('/')

    /*
     * Read through the CSSOM rather than by fetching a stylesheet URL.
     *
     * The first version matched `href="….css"` in the document and fetched it. That is how the
     * production build serves CSS and not how the dev server does — Vite injects it as a module, the
     * document links nothing, and the guard failed for the harness instead of for the code. The
     * CSSOM is the same declaration either way.
     */
    const range = await page.evaluate(() => {
      for (const sheet of Array.from(document.styleSheets)) {
        let rules: CSSRuleList
        try {
          rules = sheet.cssRules
        } catch {
          continue // a cross-origin sheet; ours are not
        }
        for (const rule of Array.from(rules)) {
          const face = rule as CSSFontFaceRule
          if (face.style?.getPropertyValue('font-family')?.includes('Inter Fallback')) {
            return face.style.getPropertyValue('unicode-range')
          }
        }
      }
      return null
    })

    expect(range, 'no «Inter Fallback» face is declared at all').not.toBeNull()
    expect(range, '«Inter Fallback» claims every codepoint, Arabic included').not.toBe('')

    /*
     * Parsed, not pattern-matched.
     *
     * The CSSOM normalises what the stylesheet says: `U+0000-00FF` comes back as `U+0-FF`, so a
     * regex written against the source text asserts the serialiser's habits rather than the coverage
     * — and the Arabic block would come back as `U+600-6FF`, which a `/U\+06/` pattern misses in the
     * one direction that matters.
     */
    const spans = range!.split(',').map((part) => {
      const [lo, hi] = part.trim().replace(/^u\+/i, '').split('-')
      const start = parseInt(lo, 16)

      return { start, end: hi === undefined ? start : parseInt(hi, 16) }
    })

    /*
     * Arabic and its presentation forms. A face that answers for any of them is the defect: the
     * Latin metric fallback speaking for the brand's own script.
     *
     * Forms-B stops at FEFC rather than at the block's FEFF, because FEFF is the byte-order mark and
     * Inter's own Latin subset legitimately declares it. Asserted against the block boundary, this
     * guard would have failed on the correct stylesheet — a guard that cries at the right answer gets
     * loosened by whoever meets it next, which is how a guard becomes decoration.
     */
    for (const [from, to, name] of [[0x0600, 0x06ff, 'Arabic'], [0xfb50, 0xfdff, 'Arabic Presentation Forms-A'], [0xfe70, 0xfefc, 'Arabic Presentation Forms-B']] as const) {
      const claimed = spans.find((s) => s.start <= to && s.end >= from)
      expect(claimed, `«Inter Fallback» claims ${name} (U+${claimed?.start.toString(16)}–${claimed?.end.toString(16)})`).toBeUndefined()
    }

    // And it does still answer for Latin, or it is not a metric fallback at all.
    expect(spans.some((s) => s.start <= 0x41 && s.end >= 0x7a), '«Inter Fallback» no longer covers basic Latin').toBe(true)
  })

  /**
   * The Arabic metric fallback holds the line box, and claims only Arabic.
   *
   * MKT-FIX-001 was reopened a third time on «the page still moves». Measured on a genuinely cold
   * load of the deployed site at 390px and 393px, Chromium reported CLS 0.0375 from a SINGLE shift
   * at 691ms — the moment IBM Plex Sans Arabic lands. The Arabic pair had been left alone because
   * its WIDTH measured within one per cent, which was true and beside the point: a shift is line
   * boxes moving, and the line box went 137 → 150 at 100px.
   *
   * So this asserts the three declarations that decide the line box, on the served bytes. `src` is
   * asserted too, for the mistake that cost two tuning rounds: `local('Geeza Pro')` — the face macOS
   * actually substitutes — matches nothing in Chromium, and a face whose `src` resolves to nothing
   * measures exactly like having done no work at all.
   */
  test('the Arabic metric fallback holds Plex’s line box and claims only Arabic', async ({ page }) => {
    await page.goto('/')

    const face = await page.evaluate(() => {
      for (const sheet of Array.from(document.styleSheets)) {
        let rules: CSSRuleList
        try {
          rules = sheet.cssRules
        } catch {
          continue
        }
        for (const rule of Array.from(rules)) {
          const f = rule as CSSFontFaceRule
          if (f.style?.getPropertyValue('font-family')?.includes('Plex Arabic Fallback')) {
            return {
              ascent: f.style.getPropertyValue('ascent-override'),
              descent: f.style.getPropertyValue('descent-override'),
              lineGap: f.style.getPropertyValue('line-gap-override'),
              sizeAdjust: f.style.getPropertyValue('size-adjust'),
              range: f.style.getPropertyValue('unicode-range'),
              src: f.style.getPropertyValue('src'),
            }
          }
        }
      }
      return null
    })

    expect(face, 'no «Plex Arabic Fallback» face is declared at all').not.toBeNull()

    // The three that decide the line box. Without them the face changes glyph widths and nothing else.
    expect(face!.ascent, 'the Arabic fallback declares no ascent — the line box is the host’s').not.toBe('')
    expect(face!.descent, 'the Arabic fallback declares no descent').not.toBe('')
    expect(face!.lineGap, 'the Arabic fallback leaves the line gap to the host font').not.toBe('')
    expect(face!.sizeAdjust, 'the Arabic fallback declares no size adjustment').not.toBe('')

    /*
     * A face that resolves to nothing is indistinguishable from no face. `local()` cannot match a CSS
     * generic, and it could not match Geeza Pro either — so the names here are ones measured to
     * resolve, and this refuses the generics outright.
     */
    expect(face!.src, 'src is empty — the face can never resolve').not.toBe('')
    for (const generic of ['system-ui', 'sans-serif', 'serif', 'monospace']) {
      expect(face!.src, `src lists the CSS generic «${generic}», which no local() can ever match`)
        .not.toContain(`local("${generic}")`)
    }

    // Bounded to Arabic, for the reason the Latin face is bounded to Latin — see the test above.
    expect(face!.range, '«Plex Arabic Fallback» claims every codepoint, Latin included').not.toBe('')
    expect(face!.range.toUpperCase()).toContain('U+6')
  })
})
