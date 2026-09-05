import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * MUTED-TEXT-CONTRAST — every text tone clears WCAG AA against the ground it is actually painted on.
 *
 * `--text-muted` shipped at 2.85:1 on the light page and 3.79:1 on a dark card, against AA's 4.5:1
 * for normal text — across 1,042 usages that are mostly 13px, which gets no large-text exemption:
 * hints under KPI cards, table metadata, the sentence under a chart.
 *
 * ## Why this is an E2E and not a unit test
 *
 * The first attempt read `tokens.css` in vitest and could not: `?raw` CSS does not reach the test
 * environment, so the guard passed having read nothing until its own vacuity check caught it. This
 * reads what the browser COMPUTED, which is stronger than reading the file anyway — it resolves the
 * cascade, the media query and the `data-theme` stamp, and it would catch a tone that is correct in
 * the palette and overridden somewhere else.
 *
 * The ratio is computed here rather than compared against remembered numbers, so a future palette
 * change is re-checked rather than silently trusted.
 */
test.use({ storageState: AUTH.advertiser })

const AA = 4.5

/** Computed in the page: every element carrying one of the tone classes, against its own backdrop. */
const MEASURE = `() => {
  /*
   * Only rgb()/rgba() are parsed as numbers. A computed background can also come back as
   * oklab(... / 0.85) — a translucent overlay — and reading its first three components as if they
   * were RGB produced 2.77:1 for text that is perfectly legible. A measurement that invents a
   * failure is as bad as one that misses a real one, so an unparseable colour is composited past
   * rather than guessed at.
   */
  const parse = (c) => {
    const m = /rgba?\\(([^)]+)\\)/.exec(c || '')
    if (!m) return null
    const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number)
    return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }
  }
  const lumOf = ({ r, g, b }) => {
    const f = (x) => { const v = x / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4) }
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
  }
  const over = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
    a: 1,
  })
  const ratio = (a, b) => { const [hi, lo] = [lumOf(a), lumOf(b)].sort((x, y) => y - x); return (hi + 0.05) / (lo + 0.05) }

  /*
   * The real backdrop: walk up compositing every translucent layer onto the one behind it, and stop
   * at the first opaque one. Taking the nearest painted ancestor alone is wrong whenever a card sits
   * on a tint — the tint is not what the eye sees behind the text.
   */
  const groundOf = (el) => {
    const layers = []
    let n = el
    while (n && n !== document.documentElement) {
      const c = parse(getComputedStyle(n).backgroundColor)
      if (c && c.a > 0) { layers.push(c); if (c.a === 1) break }
      n = n.parentElement
    }
    const base = parse(getComputedStyle(document.body).backgroundColor) || { r: 255, g: 255, b: 255, a: 1 }
    return layers.reduceRight((acc, layer) => over(layer, acc), base)
  }

  const out = []
  for (const el of document.querySelectorAll('.text-text-muted, .text-text-secondary, .text-text-primary')) {
    const text = (el.textContent || '').trim()
    const r = el.getBoundingClientRect()
    if (!text || r.width === 0 || r.height === 0) continue
    const style = getComputedStyle(el)
    if (style.visibility === 'hidden' || style.opacity === '0') continue
    const fg = parse(style.color)
    // A colour this function cannot read is skipped rather than scored — see the note on parse().
    if (!fg) continue
    out.push({ ratio: ratio(over(fg, groundOf(el)), groundOf(el)), size: parseFloat(style.fontSize), text: text.slice(0, 40) })
  }
  return out
}`

for (const theme of ['light', 'dark'] as const) {
  test(`no muted text falls below AA in ${theme}`, async ({ page }) => {
    /*
     * The theme is STAMPED, not emulated.
     *
     * `emulateMedia({ colorScheme })` only moves `prefers-color-scheme`, and this product resolves
     * its palette from a `data-theme` attribute set from the signed-in user's stored preference —
     * so the «light» run measured whatever theme that user happened to hold, which was dark. The
     * guard passed against the ORIGINAL failing tone, and only injecting it revealed that: a test
     * that cannot fail is not evidence, and this one had been reporting success for both themes
     * while exercising one.
     *
     * Stamping is also what the palette contract itself defines as the deciding signal, so this
     * measures the case a reader with an explicit preference actually gets.
     */
    await page.emulateMedia({ colorScheme: theme })
    await page.goto('/app/analytics')
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
    await page.evaluate((t) => document.documentElement.setAttribute('data-theme', t), theme)
    await page.waitForTimeout(2500)

    // And the stamp took: a run that measured the other theme would pass while proving nothing.
    expect(await page.evaluate(() => document.documentElement.getAttribute('data-theme'))).toBe(theme)

    /*
     * Called, not merely evaluated. `page.evaluate(string)` treats its argument as an EXPRESSION, so
     * passing an arrow function produced the function object — unserialisable, so `measured` came
     * back undefined and the run failed on `.length`. The vacuity check below is what turned that
     * into a visible failure rather than a guard that measured nothing and passed.
     */
    const measured = (await page.evaluate(`(${MEASURE})()`)) as Array<{ ratio: number; size: number; text: string }>

    // A sweep that measured nothing proves nothing — this page carries hundreds of these nodes.
    expect(measured.length, 'no toned text was found on the page').toBeGreaterThan(10)

    /*
     * The 3:1 large-text floor applies only at 18.66px bold or 24px normal. Everything this tone is
     * actually used for is 13px, so the threshold below is AA for normal text and the exemption is
     * deliberately not granted.
     */
    const failing = measured.filter((m) => m.ratio < AA - 0.01)

    expect(
      failing.map((m) => `${m.ratio.toFixed(2)}:1 at ${m.size}px — «${m.text}»`),
      `${theme}: text below WCAG AA`,
    ).toEqual([])
  })
}
