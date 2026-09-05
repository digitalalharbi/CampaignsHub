import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * A background class the theme does not define produces NO CSS rule, and the element paints nothing.
 *
 * This product has shipped that defect twice. `bg-surface-muted` left the «no data» pill with no
 * surface — recorded on NO-DATA-NOT-RED-001, where the rule was right and the tone had no colour.
 * `bg-primary` made the LEADING bar of the content reading invisible: the one element that card
 * exists to point at, computing to rgba(0, 0, 0, 0).
 *
 * Neither is catchable by a unit test. jsdom does not resolve Tailwind, so a class name that means
 * nothing is indistinguishable from one that means something, and «the element is in the document»
 * stays true throughout. Only a real engine resolving a real stylesheet can tell them apart.
 *
 * The measurement is passed as a FUNCTION rather than a string. A string argument to `evaluate` is
 * treated as an expression, which is how an earlier guard in this suite came to evaluate its own
 * function without ever calling it — and a template literal holding JavaScript also has to survive
 * two parsers, which this one did not.
 */
test.use({ storageState: AUTH.advertiser })

const SURFACES = [
  '/app/dashboard',
  '/app/analytics?tab=quality',
  '/app/analytics?tab=budget',
  '/app/analytics?tab=funnel',
]

/** Runs in the page: every element whose background utility resolves to nothing. */
function deadBackgrounds(): Array<{ cls: string; tag: string; text: string }> {
  const CLASS = /^bg-([a-z][a-z0-9-]*)(\/(\d{1,3}))?$/

  // Shipped by Tailwind itself, so an absent theme token proves nothing about them.
  const BUILT_IN = /^(transparent|current|inherit|white|black|none|(slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)-\d{2,3})$/

  /*
   * `bg-` is not only a colour prefix. Gradients, sizing, position, repeat, attachment, clipping and
   * blend modes share it and every one of them sets something other than background-color, so a
   * transparent background-color is correct for all of them. `bg-gradient-to-br` was this guard's
   * first finding, and it was the guard being wrong rather than the product.
   */
  const NOT_A_COLOUR = /^(gradient-|clip-|origin-|blend-|auto$|cover$|contain$|center$|top|bottom|left$|right$|repeat|fixed$|local$|scroll$)/

  const dead: Array<{ cls: string; tag: string; text: string }> = []

  for (const el of Array.from(document.querySelectorAll('*'))) {
    const raw = (el as HTMLElement).className
    const classes = typeof raw === 'string' ? raw : ''
    if (!classes) continue

    for (const cls of classes.split(/\s+/)) {
      const m = CLASS.exec(cls)
      if (!m || BUILT_IN.test(m[1]) || NOT_A_COLOUR.test(m[1])) continue

      const bg = getComputedStyle(el).backgroundColor || ''
      if (!/^(transparent$|rgba\([^)]*,\s*0\s*\))/.test(bg)) continue

      const r = el.getBoundingClientRect()
      if (r.width === 0 || r.height === 0) continue

      dead.push({ cls, tag: el.tagName.toLowerCase(), text: (el.textContent || '').trim().slice(0, 30) })
    }
  }

  return dead
}

for (const surface of SURFACES) {
  test(`every background class on ${surface} resolves to a real colour`, async ({ page }) => {
    await page.goto(surface)
    await expect(page.locator('main')).toBeVisible({ timeout: 20000 })
    await page.waitForTimeout(2500)

    /*
     * A sweep that examined nothing proves nothing, and is counted separately from the failures — a
     * page that rendered no elements would otherwise report a clean run.
     */
    const examined = await page.evaluate(() => document.querySelectorAll('[class*="bg-"]').length)
    expect(examined, `${surface} carries no background classes — the guard read nothing`).toBeGreaterThan(5)

    const dead = await page.evaluate(deadBackgrounds)

    expect(
      [...new Set(dead.map((d) => `${d.cls} on <${d.tag}> «${d.text}»`))],
      `${surface}: these background classes paint nothing`,
    ).toEqual([])
  })
}
