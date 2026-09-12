import { expect, test } from '@playwright/test'
import { AUTH, seededProject, selectProject } from './helpers'

/**
 * CONTENT-MEDIA-SHAPES-001 — every card shows the media, or says why it cannot. Never a blank frame.
 *
 * «Do NOT show a blank rectangle. Do NOT show "no cover" while usable media exists. If media is
 * genuinely unavailable only after the canonical recovery chain fails, render a small compact
 * absence state.»
 *
 * ## What this asserts, and what it deliberately does not
 *
 * It does not assert a count of pictures: that would be a test about the seed, and it would start
 * failing the day somebody adds a creative rather than the day something breaks. It asserts the
 * RULE — every card resolves to one of three things, a picture, a film, or a stated reason, and
 * never to an empty frame.
 *
 * The shapes on the seeded library are worth recording because each is a different path through the
 * resolver and a reader of this file should know they are all exercised: images, a film, a carousel
 * drawing its own strip, and a catalog ad — «إعلان كتالوج — تُركّب المنصة صورته لكل منتج عند العرض»
 * — which has no single asset by its nature and says so rather than drawing a box. That last one is
 * the case most likely to be mistaken for the defect, and it is the product being correct.
 */
test.use({ storageState: AUTH.owner })

for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
  test(`no content card is a blank frame at ${viewport.width}px`, async ({ page, request }) => {
    await page.setViewportSize(viewport)
    await selectProject(page, await seededProject(request, 'متجر تجريبي — Demo'))
    await page.goto('/agency/content')

    await expect(page.locator('article').first()).toBeVisible({ timeout: 30000 })
    await page.waitForLoadState('networkidle')

    const shapes = await page.evaluate(() => {
      const out: Record<string, number> = {}

      document.querySelectorAll('article').forEach((card) => {
        const kind = card.querySelector('img')
          ? 'picture'
          : card.querySelector('video')
            ? 'film'
            : card.querySelector('[data-absence]')
              ? 'stated absence'
              : 'BLANK'

        out[kind] = (out[kind] ?? 0) + 1
      })

      return out
    })

    expect(Object.values(shapes).reduce((a, b) => a + b, 0), 'the library drew no cards').toBeGreaterThan(0)

    expect(
      shapes.BLANK ?? 0,
      'a card drew neither media nor a reason: a blank rectangle where a picture belongs, which is '
      + `the thing the absence state exists to replace — shapes seen: ${JSON.stringify(shapes)}`,
    ).toBe(0)
  })
}
