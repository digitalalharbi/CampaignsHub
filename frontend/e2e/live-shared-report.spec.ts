import { expect, test } from '@playwright/test'

/**
 * LIVEREP-001 — the client's live report, driven as a client drives it.
 *
 * No session: these tests never sign in, because the whole point of the surface is that a client with
 * a link and no account can open it. `test.use({ storageState: … })` is deliberately absent — if a
 * cookie were leaking in, every assertion here would pass for the wrong reason.
 *
 * The demo link is seeded with a fixed token (`DemoReportsSeeder::DEMO_LIVE_TOKEN`) and only exists in
 * local/testing/demo. That is what makes «works before any credentials are connected» testable at all.
 */
const TOKEN = 'demo-live-report-token'
const URL = `/reports/share/${TOKEN}`

test.use({ storageState: { cookies: [], origins: [] } })

test.describe('a client opens their live report', () => {
  test('the figures render, from real seeded metrics', async ({ page }) => {
    await page.goto(URL)

    const report = page.getByTestId('live-report')
    await expect(report).toBeVisible({ timeout: 20000 })

    // A real figure, not a zero and not a placeholder: the seeded store has spend in every window.
    const spend = report.locator('.tnum').first()
    await expect(spend).not.toHaveText(/^0$|^—$/)
    await expect(spend).toContainText(/SAR/)
  })

  /**
   * The requirement in the brief, stated as a test: filters update the data **without reloading**.
   *
   * Proved by pinning a value on `window` before the click and asserting it survives. A reload wipes
   * it; a re-render does not. Asserting only that the numbers changed would pass just as happily if
   * the page had navigated, which is the thing being ruled out.
   */
  test('changing the period updates the figures without reloading the page', async ({ page }) => {
    await page.goto(URL)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })

    /*
     * The KPI block, not «whatever `.tnum` comes first in the document».
     *
     * CLIENT-FACING-PRESENTATION-001 moved the objective split above the platform and campaign
     * charts, and the first `.tnum` on the page moved with it — onto a cost that can legitimately
     * read the same in both windows, or «—» in both. The test then failed for a layout change while
     * claiming the filter was dead.
     *
     * The claim is «the figures update», so it is asserted on the figures: the executive KPI cards,
     * which are the headline numbers by definition and are what a reader watches when they change
     * the period.
     */
    const headline = page.getByTestId('live-kpis').locator('.tnum').first()

    const before = await headline.innerText()
    await page.evaluate(() => { (window as unknown as { __kept: string }).__kept = 'survived' })

    await page.getByTestId('live-range-7').click()

    await expect
      .poll(async () => headline.innerText(), { timeout: 20000 })
      .not.toBe(before)

    expect(
      await page.evaluate(() => (window as unknown as { __kept?: string }).__kept),
      'the page reloaded instead of re-rendering — the filter is not live',
    ).toBe('survived')
  })

  test('narrowing to one platform changes the figures', async ({ page }) => {
    await page.goto(URL)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })

    // The headline figures, for the same reason as the period test above: the composition changed,
    // and «the first `.tnum` in the document» is not what «the figures» means.
    const headline = page.getByTestId('live-kpis').locator('.tnum').first()

    const all = await headline.innerText()
    await page.getByTestId('live-platform-meta').click()

    await expect.poll(async () => headline.innerText(), { timeout: 20000 }).not.toBe(all)
  })

  /**
   * CLIENT-DIAGNOSTIC-SEPARATION-001 — the client is told what is MISSING, not when we last synced.
   *
   * This asserted the opposite: that a per-platform freshness block is always visible, showing each
   * platform and when its data was last read. That was the right rule for an operator surface and
   * the wrong one here. «ميتا: 18 أغسطس 23:59» is a fact about our plumbing — a client cannot act on
   * it, cannot ask anyone to move it, and cannot tell from it whether the figures above are wrong.
   * The owner found exactly that on their own live link.
   *
   * What a client must still be told survives, in their vocabulary: a platform absent from these
   * figures, because a total that silently omits one is worse than any diagnostic. So the block is
   * now CONDITIONAL — present when something is excluded, absent when nothing is — and the test is
   * about which sentence appears rather than about the block always being there.
   *
   * A block that always renders «everything is current» is one a reader learns to skip, including
   * on the day it says something else.
   */
  test('the page names a platform missing from the figures, and says nothing when none is', async ({ page }) => {
    await page.goto(URL)
    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })

    const notice = page.getByTestId('live-freshness')

    if (await notice.count() === 0) {
      // Every platform reported: the honest output is nothing at all.
      return
    }

    /*
     * When it IS shown, it names the platform rather than its key.
     *
     * An earlier version of this test read `/meta/i`, which the page satisfied by rendering the
     * column value `meta` under a `capitalize` class — passing while showing a client a database
     * identifier dressed up to look like a brand.
     */
    const text = await notice.innerText()

    expect(text, `the notice shows a raw key:\n${text}`).not.toMatch(/[a-z]+_[a-z]+/)

    // And it speaks about THEIR figures, not about our sync.
    expect(text).toMatch(/لا تشمل|do not include/)
    expect(text, 'the client was shown our sync clock').not.toMatch(/آخر مزامنة|Last sync|بيانات الاعتماد/)
  })

  /**
   * REPORT-OBJECTIVE-003/004 — the client's own link states Direct against Blended.
   *
   * The headline above it rolls the whole scope together: its cost per order divides every
   * campaign's spend by the orders the SALES campaigns produced. That is the right answer to «what
   * did this programme cost» and the wrong one to «what does an order cost» — and this is the page
   * where the second question is asked, by the person paying for it.
   */
  test('the client’s link separates direct cost from blended', async ({ page }) => {
    await page.goto(URL)

    await expect(page.getByTestId('live-objective-split')).toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('live-objective-direct')).toBeVisible()
    await expect(page.getByTestId('live-objective-blended')).toBeVisible()

    // The two blocks are not the same figure under two names.
    const direct = await page.getByTestId('live-objective-direct').innerText()
    const blended = await page.getByTestId('live-objective-blended').innerText()
    expect(direct).not.toBe(blended)
  })

  test('the link is marked as demo data rather than passing seeded figures off as real', async ({ page }) => {
    await page.goto(URL)
    await expect(page.getByText('Demo').first()).toBeVisible({ timeout: 20000 })
  })
})

/**
 * A tampered URL gets the link's own data — never an error, and never somebody else's numbers.
 *
 * This is the browser-level companion to `LiveReportShareTest`: the backend proves the intersection,
 * this proves the page still renders normally when somebody edits the query string, because a client
 * who breaks their own link by fiddling should see their report, not a stack trace.
 */
test.describe('the link cannot be talked into showing more', () => {
  test('a made-up campaign id in the URL still renders the client\'s own report', async ({ page }) => {
    await page.goto(`${URL}?campaigns[]=00000000-0000-0000-0000-000000000000`)

    await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })
    await expect(page.getByTestId('live-report').locator('.tnum').first()).toContainText(/SAR/)
  })

  test('an unknown token is refused', async ({ page }) => {
    await page.goto('/reports/share/not-a-real-token')

    await expect(page.getByTestId('live-report')).toHaveCount(0)
    await expect(page.locator('body')).toContainText(/تعذّر|الرابط غير صالح/)
  })
})

/**
 * CLIENT-FACING-PRESENTATION-001 — the last block of the composition, in a real browser.
 *
 * The unit tests hold the derivation against fixtures. What they cannot hold is the claim this
 * requirement is actually about: that a NUMBER SITS UNDER ITS OWN HEADER, in a real layout engine, in
 * both writing directions. Every drift this requirement exists to fix was invisible to jsdom — a
 * `text-end` on a numeric column reads as the LEFT edge under RTL, and a test asserting the class
 * would have passed on the broken build.
 *
 * ## One page load, deliberately
 *
 * The public link is rationed at sixty requests a minute per IP, and every test in this file spends
 * from that one bucket — three more page loads here took the FILE over it, and the failure surfaced
 * as «تعذّر فتح التقرير — Too many requests» in a neighbouring spec rather than in this one. So the
 * second direction is measured by flipping `dir` on the live document instead of reloading: the claim
 * is about the writing direction, not about the language, and a real engine relays out for it.
 */
test.describe('what the report says needs attention', () => {
  test('the budget figures sit under their own headers, in both directions', async ({ page }) => {
    await page.goto(URL)

    const attention = page.getByTestId('live-attention')
    await expect(attention, 'the budget block never rendered').toBeVisible({ timeout: 20000 })

    const table = attention.locator('table').first()
    const drift = () =>
      table.evaluate((el) => {
        const heads = [...el.querySelectorAll('thead th')]
        const cells = [...(el.querySelector('tbody tr')?.children ?? [])]

        return heads.map((th, i) => {
          const a = th.getBoundingClientRect()
          const b = cells[i].getBoundingClientRect()

          return Math.abs((a.left + a.right) / 2 - (b.left + b.right) / 2)
        })
      })

    const rtl = await drift()
    expect(rtl.length, 'the table rendered no columns').toBeGreaterThan(3)
    // A column whose header centre is more than a pixel off its cell's is a column that drifted.
    expect(Math.max(...rtl), 'rtl: a value is not under its header').toBeLessThanOrEqual(1)

    await page.evaluate(() => document.documentElement.setAttribute('dir', 'ltr'))
    const ltr = await drift()
    expect(Math.max(...ltr), 'ltr: a value is not under its header').toBeLessThanOrEqual(1)

    /*
     * «Nothing needs you» is a RESULT, and the section says it rather than rendering empty. The
     * seeded pacing decides which branch runs, so the invariant is asserted rather than the branch: a
     * reader always leaves this block knowing whether anything is off plan.
     */
    const said = await page.getByTestId('live-attention-findings').evaluate((el) => ({
      clear: !!el.querySelector('[data-testid="live-attention-clear"]'),
      items: el.querySelectorAll('[data-testid^="live-attention-"]').length,
    }))

    expect(said.clear || said.items > 0, 'the section rendered with nothing to say').toBe(true)
  })
})

/** The client is on a phone more often than not, and in whichever language and theme they arrived in. */
test.describe('the live report holds together on a phone', () => {
  for (const locale of ['ar', 'en'] as const) {
    for (const theme of ['light', 'dark'] as const) {
      test(`375px · ${locale} · ${theme}: no sideways scroll`, async ({ page }) => {
        await page.addInitScript(([l, t]) => {
          window.localStorage.setItem('campaign-hub-locale', l)
          window.localStorage.setItem('campaign-hub-theme', t)
        }, [locale, theme] as const)
        await page.setViewportSize({ width: 375, height: 812 })
        await page.goto(URL)

        await expect(page.getByTestId('live-report')).toBeVisible({ timeout: 20000 })
        // Measure once the charts have laid out — a chart's width is what pushed this page sideways.
        await page.waitForLoadState('networkidle')

        expect(
          await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1),
          `${locale}/${theme} scrolls sideways on a phone`,
        ).toBe(false)
      })
    }
  }
})
