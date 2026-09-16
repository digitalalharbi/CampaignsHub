import { expect, test, type Page } from '@playwright/test'

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

/** What the live payload says about each roster creative, keyed by the handle the page opens it by. */
async function payloadStates(page: Page): Promise<Map<string, string>> {
  // Relative to the page: the report and its API are one origin wherever this runs.
  const body = await page.evaluate(async () => {
    const r = await fetch('/api/v1/reports/shared/demo-live-report-token/live')

    return r.ok ? await r.json() : null
  })

  const report = body?.data?.data ?? body?.data ?? null

  expect(report, 'the live payload could not be read').not.toBeNull()

  return new Map((report.ads_roster ?? []).map((row: { content_key?: string; preview?: { state?: string } }) =>
    [row?.content_key ?? 'NO_KEY', row?.preview?.state ?? 'NO_PREVIEW_KEY'] as [string, string]))
}

/**
 * What each content tile actually drew — an image, or the reason it could not — by its content key.
 *
 * The roster moved from the bottom of one long page into the Content mode, and it is matched by KEY
 * rather than by position: the page lets a client re-sort it, and a positional comparison would pass
 * or fail on the sort order rather than on the media.
 */
async function drawnStates(page: Page): Promise<Array<[string, string]>> {
  return page.evaluate(() => {
    const out: Array<[string, string]> = []

    document.querySelectorAll('[data-testid="live-content-all"] [data-testid="live-content-tile"]').forEach((tile) => {
      const key = tile.getAttribute('data-content-key') ?? 'NO_KEY'
      if (tile.querySelector('img[data-testid="live-content-poster"]')) out.push([key, 'IMAGE'])
      else {
        const absent = tile.querySelector('[data-testid="live-content-poster-absent"]')
        out.push([key, absent?.getAttribute('data-absence') ?? 'NOTHING_DRAWN'])
      }
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

        await page.goto(`/r/${TOKEN}?view=content`)
        await expect(page.getByTestId('live-content-all')).toBeVisible({ timeout: 30000 })
        await page.waitForLoadState('networkidle')

        const states = await payloadStates(page)
        const drawn = await drawnStates(page)

        expect(states.size, 'the report listed no creatives, so this proves nothing').toBeGreaterThan(0)
        expect(drawn.length, 'the content inventory drew no tiles at all').toBeGreaterThan(0)

        /*
         * NO row may be missing its envelope. This is the defect itself: the roster carried none,
         * so every row fell to the renderer's most pessimistic reading.
         */
        expect(
          [...states.values()].filter((s) => s === 'NO_PREVIEW_KEY').length,
          'roster rows arrived with no preview envelope — the renderer then claims «no file» about '
          + 'a creative nobody asked it about, in a document the client keeps',
        ).toBe(0)

        /* And what was drawn follows from what was said about THAT creative. */
        for (const [key, shown] of drawn) {
          expect(states.has(key), `a tile carries a key the payload does not know: ${key}`).toBe(true)
          const said = states.get(key)
          expect(shown, `content ${key} drew something other than what the payload says about it`)
            .toBe(said === 'available' ? 'IMAGE' : said)
        }
      })
    }
  }
})
