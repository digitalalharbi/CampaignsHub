import { expect, test } from '@playwright/test'
import { AUTH, switchToEnglish } from './helpers'
import { formatPeriodLabel } from '../src/lib/period/formatPeriodLabel'

/**
 * PERIOD-LABEL-001 — the filter button prints the Owner's one format, and prints exactly what the shared
 * `formatPeriodLabel` returns for the same days (clock held at Sunday 15 November 2026, 09:00 Riyadh, so «آخر 7 أيام»
 * is 8 → 14 November). Arabic and English, a desktop width and a phone width; and the Arabic range reads right to left
 * (the start date is the RIGHT-most one) while the English one reads left to right.
 */
const NOW = new Date('2026-11-15T06:00:00Z')
const SPAN = { from: '2026-11-08', to: '2026-11-14' }

test.describe('the period label', () => {
  test.use({ storageState: AUTH.owner })

  test.beforeEach(async ({ page }) => {
    await page.clock.install({ time: NOW })
  })

  for (const size of [{ width: 1366, height: 768 }, { width: 375, height: 812 }]) {
    test(`the button says «آخر 7 أيام» in the one format (ar, ${size.width}px)`, async ({ page }) => {
      await page.setViewportSize(size)
      await page.goto('/agency/analytics')
      const summary = page.getByTestId('analytics-period-summary')
      const want = formatPeriodLabel({ key: 'last_7', ...SPAN, locale: 'ar' })
      expect(want).toBe('آخر 7 أيام: 8 نوفمبر – 14 نوفمبر 2026')
      await expect(summary).toHaveText(want, { timeout: 30_000 })

      // Nothing is cut off with an ellipsis at either width.
      expect(await summary.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true)

      // The start date sits to the right of the end date in an Arabic line (measured at desktop width, where the line is not clipped by the header).
      if (size.width < 1000) return
      const boxes = await summary.evaluate((el) => {
        const text = el.firstChild as Text
        const at = (needle: string) => {
          const i = text.data.indexOf(needle)
          const r = document.createRange(); r.setStart(text, i); r.setEnd(text, i + needle.length)
          return r.getBoundingClientRect().left
        }
        return { start: at('8 نوفمبر'), end: at('14 نوفمبر') }
      })
      expect(boxes.start, 'the Arabic start date is the right-most one').toBeGreaterThan(boxes.end)
    })

    test(`the button says «Last 7 days» in the one format (en, ${size.width}px)`, async ({ page }) => {
      await page.setViewportSize(size)
      await page.goto('/agency/analytics')
      await switchToEnglish(page)
      const want = formatPeriodLabel({ key: 'last_7', ...SPAN, locale: 'en' })
      expect(want).toBe('Last 7 days: Nov 8 – Nov 14, 2026')
      await expect(page.getByTestId('analytics-period-summary')).toHaveText(want, { timeout: 30_000 })
    })
  }
})
