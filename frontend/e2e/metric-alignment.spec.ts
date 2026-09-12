import { expect, test, type Page } from '@playwright/test'
import { AUTH, seededProject, selectProject, switchToEnglish } from './helpers'

/**
 * KPI-ALIGNMENT-002 — the owner acceptance test: a figure sits under its own label, on screen.
 *
 * ## Why this had to be a browser test
 *
 * The previous guard read the source and asserted that every `dir="ltr"` numeral also declared
 * `text-start`. It passed. The screen was unchanged, because `text-align: start` inside a
 * `dir="ltr"` box means LEFT — so on an Arabic page the label sat at the right edge of its card and
 * its own figure at the left, exactly as before, and the check that was supposed to catch it had
 * been taught to require the thing causing it.
 *
 * The owner reported it from a screenshot, which was the only place it was ever visible. So this
 * measures BOXES in a real browser: the label's leading edge and the value's leading edge, in the
 * page's own direction. There is no arrangement of classes that satisfies this and still renders
 * the defect.
 *
 * ## What «the same edge» means in pixels
 *
 * In Arabic the leading edge is the RIGHT edge, so the two boxes must share their right; in English
 * their left. A few pixels of optical difference is not the complaint — a figure across the card
 * from its title is, and that is a gap of most of a card's width.
 */
const EDGE_TOLERANCE = 12

/**
 * Where the label's text and the figure's text actually SIT, measured as text runs.
 *
 * ## Two earlier drafts of this helper were unable to fail
 *
 * The first found the figure by `bdi`, which is today's mechanism — so removing the isolation failed
 * with «no isolated figure» before the geometry was ever compared, and any correct alternative would
 * have failed too.
 *
 * The second measured the ELEMENT box. A `block` span fills its row whatever its text alignment is,
 * so the box's edges are the card's edges in both the fixed and the broken rendering, and the
 * injected defect passed. That is the same shape as the bug itself: a check that looks like it is
 * about where the number is, and is not.
 *
 * A `Range` over the element's contents returns the rectangle of the TEXT, which is the only thing
 * the owner can see. Nothing about the implementation is asserted — a different way of isolating the
 * digits passes, and a figure on the wrong edge cannot.
 */
async function edges(page: Page, card: string) {
  const el = page.locator(card)
  const label = el.getByTestId('metric-label').first()
  const value = el.getByTestId('metric-value').first()

  await expect(label, 'the card drew no label').toBeVisible()
  await expect(value, 'the card drew no value row').toBeVisible()

  /*
   * The union of every TEXT NODE beneath the element, each measured with its own range.
   *
   * `selectNodeContents` on the element itself is not enough: when the only child is a `block` span
   * the range returns that span's box, which fills the row whatever its text alignment is — so an
   * injected misalignment measured identically to the fix, and a third draft of this helper passed
   * the defect. Walking to the text nodes is what makes this about the glyphs.
   */
  const runOf = (locator: typeof label) => locator.evaluate((node) => {
    const doc = node.ownerDocument
    const walker = doc.createTreeWalker(node, NodeFilter.SHOW_TEXT)
    let left = Infinity
    let right = -Infinity

    for (let t = walker.nextNode(); t !== null; t = walker.nextNode()) {
      if ((t.textContent ?? '').trim() === '') continue

      const range = doc.createRange()
      range.selectNodeContents(t)
      const r = range.getBoundingClientRect()

      if (r.width === 0 && r.height === 0) continue

      left = Math.min(left, r.left)
      right = Math.max(right, r.right)
    }

    return { x: left, width: right - left }
  })

  return { label: await runOf(label), value: await runOf(value) }
}

test.describe('a metric card’s title and figure share one edge', () => {
  test.use({ storageState: AUTH.owner })

  test('Arabic: the figure sits under its title at the right edge', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    const card = '[data-testid="content-metrics"] [data-testid^="metric-"]'
    await expect(page.locator(card).first()).toBeVisible({ timeout: 30000 })
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl')

    const { label, value } = await edges(page, `${card} >> nth=0`)

    /*
     * The RIGHT edges, because Arabic reads from there. A value whose right edge is a card's width
     * from its label's is the defect — reported twice, and invisible to every check but this one.
     */
    const labelRight = label.x + label.width
    const valueRight = value.x + value.width

    expect(
      Math.abs(labelRight - valueRight),
      `the figure's right edge is ${Math.round(Math.abs(labelRight - valueRight))}px from its label's `
      + 'right edge — in Arabic the two must start together',
    ).toBeLessThan(EDGE_TOLERANCE)
  })

  test('English: the figure sits under its title at the left edge', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')
    await switchToEnglish(page)

    const card = '[data-testid="content-metrics"] [data-testid^="metric-"]'
    await expect(page.locator(card).first()).toBeVisible({ timeout: 30000 })

    const { label, value } = await edges(page, `${card} >> nth=0`)

    expect(
      Math.abs(label.x - value.x),
      `the figure's left edge is ${Math.round(Math.abs(label.x - value.x))}px from its label's — `
      + 'in English the two must start together at the left',
    ).toBeLessThan(EDGE_TOLERANCE)
  })

  /**
   * And the dashboard's strip, which is the same primitive on a different surface.
   *
   * One card fixed and another not is how this defect survived the first attempt: the rule lives in
   * `MetricCard`, so a second surface is what proves the fix is in the primitive and not in a page.
   * Analytics rather than the dashboard because the dashboard route renders no metric strip at all —
   * a separate question, and not one this case can answer by failing.
   */
  test('Arabic: Analytics’ own cards follow the same rule', async ({ page, request }) => {
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/analytics')

    const card = '[data-testid="analytics-metrics"] [data-testid^="metric-"]'
    await expect(page.locator(card).first()).toBeVisible({ timeout: 30000 })

    const { label, value } = await edges(page, `${card} >> nth=0`)

    expect(Math.abs((label.x + label.width) - (value.x + value.width))).toBeLessThan(EDGE_TOLERANCE)
  })
})
