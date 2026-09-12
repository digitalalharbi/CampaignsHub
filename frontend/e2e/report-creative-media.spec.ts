import { expect, test, type Page } from '@playwright/test'
import { E2E_ORIGIN } from './helpers'

/**
 * REPORT-CREATIVE-MEDIA-001 — the report's rows say about a creative what the product knows.
 *
 * ## The owner's production defect, and what it actually claimed
 *
 * A real Detailed Report showed «لا يوجد غلاف» for creatives the Content library displays. Measured
 * on the seeded report across the fix, the difference is not merely a missing picture:
 *
 * - before: `data-absence="unavailable"` — «لا يوجد ملف», there is no file for this ad;
 * - after:  `data-absence="never_fetched"` — «لم يُجلب», the platform link was never fetched.
 *
 * The first is a claim about the CLIENT's advertising and it was false. The roster carried no
 * preview envelope at all, so the renderer fell to its most pessimistic reading, and a document a
 * client keeps told them their agency had run ads with no creative behind them.
 *
 * ## Why this compares the page against the payload
 *
 * A test asserting «some row draws an image» depends on what the seed happens to hold. A test
 * asserting «no row says unavailable» would pass on a report where unavailable is the truth. The
 * property that is always true, and that the defect broke, is CORRESPONDENCE: what the row draws
 * follows from what the payload says about that creative. So the spec reads both and checks them
 * against each other, which fails whenever the roster loses its envelopes again.
 */
const TOKEN = 'demo-live-report-token'

/** What the live payload says about each roster creative, in payload order. */
async function payloadStates(page: Page): Promise<string[]> {
  const body = await page.evaluate(async (origin) => {
    const r = await fetch(`${origin}/api/v1/reports/shared/${'demo-live-report-token'}/live`)

    return r.ok ? await r.json() : null
  }, E2E_ORIGIN)

  const report = body?.data?.data ?? body?.data ?? null

  expect(report, 'the live payload could not be read').not.toBeNull()

  return (report.ads_roster ?? []).map((row: { preview?: { state?: string } }) =>
    row?.preview?.state ?? 'NO_PREVIEW_KEY')
}

/** What each roster row actually drew, in page order: an image, or the reason it could not. */
async function drawnStates(page: Page): Promise<string[]> {
  return page.evaluate(() => {
    const out: string[] = []

    document.querySelectorAll('[data-testid^="report-roster-poster-"]').forEach((el) => {
      /* The absence span and its inner label share the prefix; only the outer one carries the reason. */
      if (el.tagName === 'IMG') out.push('IMAGE')
      else if (el.hasAttribute('data-absence')) out.push(el.getAttribute('data-absence') ?? '')
    })

    return out
  })
}

test.describe('the report says about a creative what the product knows', () => {
  for (const viewport of [{ width: 1440, height: 900 }, { width: 390, height: 844 }]) {
    for (const locale of ['ar', 'en'] as const) {
      test(`roster media matches the payload at ${viewport.width}px in ${locale}`, async ({ page }) => {
        await page.setViewportSize(viewport)

        if (locale === 'en') {
          await page.addInitScript(() => window.localStorage.setItem('campaign-hub-locale', 'en'))
        }

        await page.goto(`/r/${TOKEN}`)
        await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 30000 })
        await page.waitForLoadState('networkidle')

        const states = await payloadStates(page)
        const drawn = await drawnStates(page)

        expect(states.length, 'the report listed no creatives, so this proves nothing').toBeGreaterThan(0)
        expect(drawn.length, 'the roster drew no posters at all').toBeGreaterThan(0)

        /*
         * NO row may be missing its envelope. This is the defect itself: the roster carried none,
         * so every row fell to the renderer's most pessimistic reading.
         */
        expect(
          states.filter((s) => s === 'NO_PREVIEW_KEY').length,
          'roster rows arrived with no preview envelope — the renderer then claims «no file» about '
          + 'a creative nobody asked it about, in a document the client keeps',
        ).toBe(0)

        /*
         * And what was drawn follows from what was said. The page renders a bounded first page of
         * the roster, so the drawn rows are compared against the payload rows they correspond to.
         */
        const expected = states.slice(0, drawn.length).map((s) => (s === 'available' ? 'IMAGE' : s))

        expect(
          drawn,
          'a roster row drew something other than what the payload says about that creative',
        ).toEqual(expected)
      })
    }
  }
})
