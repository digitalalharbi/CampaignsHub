import { expect, test } from '@playwright/test'
import { AUTH } from './helpers'

/**
 * `/agency` after SIMPLIFY-002 — the rail is grouped by job, and filters fold.
 *
 * Two things are asserted, and neither is about how the page looks:
 *
 * 1. **Nothing became unreachable.** Regrouping a menu is the change most likely to strand a page:
 *    a link that quietly stops being rendered is invisible until somebody needs it. Every one of the
 *    fifteen destinations is opened here, by URL, and asserted to render.
 * 2. **Folding hid no state.** Every page that moved its filters into a dialog must say what is
 *    applied, in words. A filtered list that looks unfiltered is worse than the toolbar it replaced.
 *
 * The pages carry the same controls they always did; what changed is that the reader meets the data
 * first. So the tests open the dialog, use a control inside it, and assert the summary line follows.
 */

/** Every destination the rail offers, in rail order. Kept here so a dropped link fails loudly. */
const AGENCY_PATHS = [
  '/agency/dashboard',
  '/agency/clients', '/agency/projects',
  '/agency/campaigns', '/agency/content',
  '/agency/requests', '/agency/tasks', '/agency/messages', '/agency/alerts',
  '/agency/reports', '/agency/files',
  '/agency/billing', '/agency/subscriptions',
  '/agency/team', '/agency/settings',
] as const

test.describe('the agency rail', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * Every destination still resolves to a page with content.
   *
   * Walked by URL rather than by clicking the rail, because a deep link and a bookmark are how people
   * actually return to a page — and because a link the regrouping dropped would still be reachable
   * this way, which is the distinction worth knowing.
   */
  test('every destination opens, and none was lost in the regrouping', async ({ page }) => {
    test.setTimeout(20_000 + AGENCY_PATHS.length * 8_000)

    for (const path of AGENCY_PATHS) {
      await page.goto(path)
      await expect(page.locator('main'), `${path} rendered nothing`).toBeVisible({ timeout: 20000 })
      await expect(page.locator('main').getByRole('heading').first(), `${path} has no heading`)
        .toBeVisible({ timeout: 20000 })
      // A route that fell through to a not-found or a portal refusal is the failure this catches.
      await expect(page.locator('main'), `${path} is a not-found page`)
        .not.toContainText(/غير متاحة لحسابك|not available to your account|الصفحة غير موجودة|Page not found/i)
    }
  })

  /** The rail groups are named for the job, and there are no third-level menus. */
  test('the rail is two levels and named for what somebody came to do', async ({ page }) => {
    await page.goto('/agency/dashboard')
    const nav = page.getByRole('navigation').first()
    await expect(nav).toBeVisible({ timeout: 20000 })

    const text = await nav.innerText()
    for (const group of ['العملاء والمشاريع', 'الحملات', 'المهام والطلبات', 'التقارير والملفات', 'المالية', 'الإعدادات']) {
      expect(text, `the rail is missing the «${group}» group`).toContain(group)
    }

    // «العمل» and «التشغيل» were the two headings that described nothing; they must not return.
    expect(text, 'the rail still has the old catch-all groups').not.toMatch(/^\s*العمل\s*$/m)
  })
})

test.describe('agency filters fold, and say what is applied', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * Each page states what it is showing, before anybody opens anything.
   *
   * The wording differs per page on purpose — «كل العملاء» and «كل المهام» read as sentences where a
   * shared «No filters» would read as a system message.
   */
  const PAGES = [
    /*
     * `narrow` names the exact control to use, per page.
     *
     * A generic «click the first button that isn't All» does not work here and produced four
     * identical failures: Clients filters with dropdowns and tick-boxes, not chips, so the first
     * such button was a select that opens rather than a choice that applies. Naming the control is
     * the same lesson this suite has now learned five times — a selector that guesses guesses wrong.
     */
    {
      id: 'clients', path: '/agency/clients', empty: /كل العملاء|All clients/,
      narrow: (d: import('@playwright/test').Locator) =>
        d.getByRole('checkbox').first().check(),
    },
    {
      id: 'tasks', path: '/agency/tasks', empty: /كل المهام|All tasks/,
      narrow: (d: import('@playwright/test').Locator) =>
        d.getByRole('button').filter({ hasNotText: /^\s*(الكل|All)\s*$/ }).first().click(),
    },
    {
      id: 'alerts', path: '/agency/alerts', empty: /كل الأهميات|Every severity/,
      narrow: (d: import('@playwright/test').Locator) =>
        d.getByRole('button').filter({ hasNotText: /^\s*(الكل|All)\s*$/ }).first().click(),
    },
    {
      id: 'content', path: '/agency/content', empty: /كل المحتوى|All content/,
      narrow: (d: import('@playwright/test').Locator) =>
        d.getByRole('button').filter({ hasNotText: /^\s*(الكل|All)\s*$/ }).first().click(),
    },
  ] as const

  for (const p of PAGES) {
    test(`${p.id}: opens on its data, with one control and a plain summary`, async ({ page }) => {
      await page.goto(p.path)

      await expect(page.getByTestId(`${p.id}-customise`), 'no single filter control')
        .toBeVisible({ timeout: 20000 })
      await expect(page.getByTestId(`${p.id}-applied`), 'the page never says what it is showing')
        .toContainText(p.empty)
    })

    test(`${p.id}: the folded controls still work and the summary follows`, async ({ page }) => {
      await page.goto(p.path)
      await expect(page.getByTestId(`${p.id}-customise`)).toBeVisible({ timeout: 20000 })

      const before = await page.getByTestId(`${p.id}-applied`).innerText()

      await page.getByTestId(`${p.id}-customise`).click()
      await expect(page.getByRole('dialog')).toBeVisible()

      /*
       * Scoped to the dialog's BODY, not the dialog.
       *
       * The modal's close «X» is a button with no text, so it survived a «not the All option» filter
       * and got clicked first — the dialog shut and nothing was filtered, three times over. The body
       * testid contains only the page's own controls.
       */
      const controls = page.getByTestId(`${p.id}-customise-body`)
      await expect(controls).toBeVisible()

      // Use the control this page actually filters with — see `narrow` above.
      await p.narrow(controls)

      await expect
        .poll(async () => page.getByTestId(`${p.id}-applied`).innerText(), { timeout: 20000 })
        .not.toBe(before)

      // Having narrowed it, the page offers a way back to everything.
      await expect(page.getByTestId(`${p.id}-clear`)).toBeVisible()
    })
  }
})

test.describe('the agency portal holds together at every width', () => {
  test.use({ storageState: AUTH.owner })

  /**
   * 343px is the narrowest case in the brief, and the dialog is the thing most likely to burst it.
   *
   * The `/app/dashboard` regression that got through was exactly this shape — fine in Arabic, too
   * wide in English, because «Customise» and «Campaigns» are longer than their Arabic labels. So both
   * languages are measured, at the width where it would show.
   */
  for (const locale of ['ar', 'en'] as const) {
    test(`no sideways scroll at 343px in ${locale}, dialog open`, async ({ page }) => {
      await page.addInitScript((l) => window.localStorage.setItem('campaign-hub-locale', l), locale)
      await page.setViewportSize({ width: 343, height: 760 })
      await page.goto('/agency/clients')

      await expect(page.getByTestId('clients-customise')).toBeVisible({ timeout: 20000 })
      expect(
        await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth),
        'the page scrolls sideways before the dialog is even opened',
      ).toBe(false)

      await page.getByTestId('clients-customise').click()
      await expect(page.getByRole('dialog')).toBeVisible()

      expect(
        await page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth),
        'the filters dialog pushes the page sideways',
      ).toBe(false)
    })
  }
})
