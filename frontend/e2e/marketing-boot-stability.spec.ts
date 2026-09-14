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
})
